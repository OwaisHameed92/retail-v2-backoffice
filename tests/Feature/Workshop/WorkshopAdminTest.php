<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\Workshop\Actions\IssueWorkshopApiKey;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Workshop\WorkshopFixtures as W;

/*
 * The admin card per shop (WORKSHOP-BIKEBOOK.md §8): licence tick, connect, API key shown once, revoke, disconnect,
 * the sync log with Replay and CSV; tenants.view reads, licences.manage changes (403 otherwise); every change audited
 * without secrets. The tenant portal's card is read-only. `workshop.connector_status` follows every change.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    Http::preventStrayRequests();
    $this->sync = new SyncApiFixtures($this);
    W::on();
    F::catalogue($this->sync->company);
    $this->params = [$this->sync->company, $this->sync->leeds];
    $this->support = Admin::factory()->create(['role' => AdminRole::Support]);
});

function connectorStatus(): ?string
{
    return TillSetting::withoutCompanyScope()->where('setting_key', 'workshop.connector_status')->value('value');
}

test('the tenant page gets a workshop card per shop while the switch is on; the add-on tick, connect, key, revoke and disconnect work and are audited', function () {
    $this->actingAs($this->support, 'admin')->get(route('admin.tenants.show', $this->sync->company))->assertOk()
        ->assertInertia(fn ($page) => $page->where('workshop.canManage', true)->has('workshop.branches', 2)
            ->where('workshop.branches.0.branchName', 'Bradford')->where('workshop.branches.1.status', 'notConnected')->where('workshop.branches.1.addOn', false)->etc());

    // No add-on: connecting and keys are refused with "Contact Switch & Save".
    $this->actingAs($this->support, 'admin')->from('/admin')->post(route('admin.tenants.branches.workshop.connect', $this->params))
        ->assertSessionHasErrors(['workshop' => "Workshop integration is not in Leeds Kirkgate's licence. Contact Switch & Save."]);

    $this->actingAs($this->support, 'admin')->put(route('admin.tenants.branches.workshop.add-on', $this->params), ['on' => true])->assertRedirect();
    expect($this->sync->leeds->refresh()->licence_add_ons)->toBe(['workshop_integration']);

    $this->actingAs($this->support, 'admin')->post(route('admin.tenants.branches.workshop.connect', $this->params))->assertRedirect();
    expect(W::connection($this->sync->leeds)->status)->toBe(ConnectionStatus::Pending)->and(connectorStatus())->toBe('pending');

    $reply = $this->actingAs($this->support, 'admin')->postJson(route('admin.tenants.branches.workshop.key.store', $this->params))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $key = $reply->json('key');
    expect($key)->toStartWith('sswk_')->and(W::connection($this->sync->leeds)->status)->toBe(ConnectionStatus::Connected)->and(connectorStatus())->toBe('connected');
    W::call($this, 'GET', '/Stores', $key)->assertOk();

    // Taking the add-on off while connected is refused.
    $this->actingAs($this->support, 'admin')->from('/admin')->put(route('admin.tenants.branches.workshop.add-on', $this->params), ['on' => false])->assertSessionHasErrors('workshop');

    $this->actingAs($this->support, 'admin')->delete(route('admin.tenants.branches.workshop.key.destroy', $this->params))->assertRedirect();
    W::call($this, 'GET', '/Stores', $key)->assertUnauthorized();
    expect(W::connection($this->sync->leeds)->status)->toBe(ConnectionStatus::Pending);

    $this->actingAs($this->support, 'admin')->post(route('admin.tenants.branches.workshop.disconnect', $this->params))->assertRedirect();
    expect(connectorStatus())->toBe('disconnected');

    $actions = AuditLog::query()->where('company_id', $this->sync->company->id)->where('action', 'like', '%workshop%')->orWhere('action', 'like', 'branch.licence_add_on%')->pluck('action')->all();
    expect($actions)->toContain('branch.licence_add_on_added', 'workshop.connect_started', 'workshop.connected', 'workshop.api_key_created', 'workshop.api_key_revoked', 'workshop.disconnected')
        ->and(AuditLog::query()->get()->map(fn ($e) => json_encode([$e->before, $e->after, $e->meta]))->implode(' '))->not->toContain($key);
});

test('sales and accounts read but cannot change (403); a guest is sent to sign in; a tenant user never reaches the admin card', function () {
    W::addOn($this->sync->leeds);
    app(IssueWorkshopApiKey::class)->handle($this->sync->leeds);

    foreach ([AdminRole::Sales, AdminRole::Accounts] as $role) {
        $admin = Admin::factory()->create(['role' => $role]);
        $this->actingAs($admin, 'admin')->get(route('admin.tenants.branches.workshop.log', $this->params))->assertOk();
        $this->actingAs($admin, 'admin')->put(route('admin.tenants.branches.workshop.add-on', $this->params), ['on' => false])->assertForbidden();
        $this->actingAs($admin, 'admin')->post(route('admin.tenants.branches.workshop.connect', $this->params))->assertForbidden();
        $this->actingAs($admin, 'admin')->post(route('admin.tenants.branches.workshop.disconnect', $this->params))->assertForbidden();
        $this->actingAs($admin, 'admin')->postJson(route('admin.tenants.branches.workshop.key.store', $this->params))->assertForbidden();
        $this->actingAs($admin, 'admin')->delete(route('admin.tenants.branches.workshop.key.destroy', $this->params))->assertForbidden();
    }

    auth('admin')->logout();
    $this->get(route('admin.tenants.branches.workshop.log', $this->params))->assertRedirect(route('admin.login'));
    $this->post(route('admin.tenants.branches.workshop.disconnect', $this->params))->assertRedirect(route('admin.login'));

    $owner = F::member($this->sync->company, CompanyRole::Owner);
    $this->actingAs($owner)->get(route('admin.tenants.branches.workshop.log', $this->params))->assertRedirect(route('admin.login'));
});

test('sync log: calls and webhook deliveries with Replay, CSV export without keys', function () {
    $key = W::connected($this->sync, catalogue: false);
    Http::fake([W::WEBHOOK_URL => Http::sequence()->push('down', 500)->push(['ok' => true], 200)]);
    W::call($this, 'GET', '/Stores', $key, [], ['X-Request-Id' => 'bb-req-1'])->assertOk();
    W::webhook($this, $key)->assertCreated();
    W::pushStock($this->sync, F::COLA, 5)->assertOk();
    $delivery = WorkshopWebhookDelivery::withoutCompanyScope()->firstOrFail();

    $this->actingAs($this->support, 'admin')->get(route('admin.tenants.branches.workshop.log', $this->params))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/tenants/workshop-log')->where('tab', 'calls')->where('rows.data.0.path', '/connectors/bikebook/v1/api/Webhook')
            ->where('rows.data.1.requestId', 'bb-req-1')->where('canManage', true)->where('card.status', 'connected')->etc());
    $this->actingAs($this->support, 'admin')->get(route('admin.tenants.branches.workshop.log', $this->params).'?tab=deliveries')->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows.data.0.event', 'inventory.updated')->where('rows.data.0.state', 'retrying')->etc());

    $csv = $this->actingAs($this->support, 'admin')->get(route('admin.tenants.branches.workshop.export', $this->params))->assertOk()->streamedContent();
    expect($csv)->toContain('/connectors/bikebook/v1/api/Stores')->toContain('bb-req-1')->not->toContain($key);
    $deliveries = $this->actingAs($this->support, 'admin')->get(route('admin.tenants.branches.workshop.export', $this->params).'?kind=deliveries')->streamedContent();
    expect($deliveries)->toContain($delivery->id)->toContain('inventory.updated')->not->toContain(W::SECRET);

    $this->actingAs($this->support, 'admin')->post(route('admin.tenants.branches.workshop.replay', [...$this->params, $delivery->id]))->assertRedirect();
    expect($delivery->refresh()->delivered_at)->not->toBeNull()->and(AuditLog::query()->where('action', 'workshop.webhook_replayed')->count())->toBe(1);
});

test('the tenant shop page shows a read-only card: status and counts, no key, no webhook, "Contact Switch & Save" without the add-on', function () {
    $owner = F::member($this->sync->company, CompanyRole::Owner);
    $this->actingAs($owner)->get(route('app.shops.show', $this->sync->bradford->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('workshop.addOn', false)->where('workshop.status', 'notConnected')->etc());

    W::connected($this->sync, catalogue: false);
    $this->actingAs($owner)->get(route('app.shops.show', $this->sync->leeds->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('workshop.status', 'connected')->where('workshop.counts.productsInScope', 2)
            ->missing('workshop.apiKey')->missing('workshop.webhook')->missing('workshop.lastError')->etc());
});

test('workshop.connector_status goes down to that shop only, never workshop.connection_status', function () {
    W::connected($this->sync, catalogue: false);

    $leeds = collect($this->sync->pull(0)->json('changes'))->where('entity', 'Setting')->pluck('payload.value', 'payload.key');
    $bradford = collect($this->sync->pull(0, bradford: true)->json('changes'))->where('entity', 'Setting')->pluck('payload.key');

    expect($leeds->get('workshop.connector_status'))->toBe('connected')->and($leeds->has('workshop.connection_status'))->toBeFalse()
        ->and($bradford->all())->not->toContain('workshop.connector_status')
        ->and(TillSetting::withoutCompanyScope()->where('setting_key', 'workshop.connection_status')->exists())->toBeFalse();
});
