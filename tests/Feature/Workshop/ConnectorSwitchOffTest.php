<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\Workshop\Actions\SaveSalesOrder;
use App\Domain\Workshop\Actions\WriteConnectorStatus;
use App\Domain\Workshop\Data\SalesOrderInput;
use App\Domain\Workshop\Queries\ConnectorReference;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Workshop\WorkshopFixtures as W;

/*
 * WORKSHOP_CONNECTOR off (the default): every connector and OAuth route answers 404 (chosen over 501: indistinguishable
 * from a route that does not exist), no workshop table is read by a pull or a push, every pull is byte-identical (even
 * with a connection and orders made while it was on), the portal's connector status never goes down, and no admin or
 * tenant page gets a workshop prop.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    Http::fake();
});

function readsWorkshopTables(callable $run): bool
{
    $read = false;
    DB::listen(function (QueryExecuted $query) use (&$read) {
        $read = $read || preg_match('/[`"]workshop_/', $query->sql) === 1;
    });
    $run();

    return $read;
}

test('off by default: every connector and OAuth route answers 404 in BikeBook\'s shape, even with valid credentials', function () {
    expect(config('workshop.connector'))->toBeFalse();
    W::on();
    $key = W::connected($this->sync);
    config(['workshop.connector' => false]);

    $calls = [
        ['GET', '/Stores'], ['GET', '/TaxRates'], ['GET', '/Products'], ['GET', '/Product/'.F::COLA], ['GET', '/Inventory?variant='.F::COLA],
        ['POST', '/Inventory/batch'], ['GET', '/Customer/list'], ['GET', '/Customer/X'], ['POST', '/Customer'], ['PUT', '/Customer'],
        ['PUT', '/Customer/X/Address'], ['POST', '/SalesOrder'], ['GET', '/SalesOrder?id=X'], ['PUT', '/SalesOrder'], ['DELETE', '/SalesOrder?id=X'],
        ['PUT', '/SalesOrder/X/status/Complete'], ['POST', '/SalesOrderLine'], ['PUT', '/SalesOrderLine'], ['DELETE', '/SalesOrderLine?id=X'],
        ['GET', '/Webhook'], ['GET', '/Webhook/X'], ['POST', '/Webhook'], ['PUT', '/Webhook'], ['DELETE', '/Webhook/X'],
    ];

    foreach ($calls as [$method, $path]) {
        W::call($this, $method, $path, $key)->assertNotFound()->assertExactJson(['error' => 'not_found', 'message' => 'Not found.']);
    }

    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token'])->assertNotFound()->assertJsonPath('error', 'not_found');

    $owner = F::member($this->sync->company, CompanyRole::Owner);
    $this->actingAs($owner)->get('/oauth/authorize?client_id=bikebook-test-client')->assertNotFound();

    $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
    $this->actingAs($admin, 'admin')->get(route('admin.tenants.branches.workshop.log', [$this->sync->company, $this->sync->leeds]))->assertNotFound();
    $this->actingAs($admin, 'admin')->put(route('admin.tenants.branches.workshop.add-on', [$this->sync->company, $this->sync->leeds]), ['on' => true])->assertNotFound();
});

test('off: the pull is byte-identical and never reads a workshop table, even with orders and a status made while it was on', function () {
    F::catalogue($this->sync->company);
    $before = $this->sync->pull(0)->getContent();
    $beforeBradford = $this->sync->pull(0, bradford: true)->getContent();

    W::connected($this->sync, catalogue: false);
    $connection = W::connection($this->sync->leeds);
    app(CurrentCompany::class)->runAs($this->sync->company, fn () => app(SaveSalesOrder::class)
        ->handle($connection, SalesOrderInput::from(W::order($this->sync->leeds), $connection, new ConnectorReference)));
    expect(TillSetting::withoutCompanyScope()->where('setting_key', WriteConnectorStatus::KEY)->value('value'))->toBe('connected');

    config(['workshop.connector' => false]);
    $this->travel(1)->minutes();

    $read = readsWorkshopTables(function () use (&$after, &$afterBradford) {
        $after = $this->sync->pull(0)->getContent();
        $afterBradford = $this->sync->pull(0, bradford: true)->getContent();
    });

    // The product scope setting saved for the test is the only new row; the connector's own rows never go down.
    $changes = collect(json_decode((string) $after, true)['changes']);
    expect($read)->toBeFalse()
        ->and($changes->pluck('entity')->unique()->values()->all())->not->toContain('WorkshopOrder')
        ->and($changes->where('entity', 'Setting')->pluck('payload.key')->all())->not->toContain('workshop.connector_status')
        ->and(json_decode((string) $afterBradford, true)['changes'])->toBe(json_decode((string) $beforeBradford, true)['changes'])
        ->and($changes->reject(fn ($c) => $c['entity'] === 'Setting' && ($c['payload']['key'] ?? '') === 'workshop.product_scope')->values()->all())
        ->toBe(json_decode((string) $before, true)['changes']);
});

test('off: a shop with no workshop data gets exactly the same pull bytes as before W5 existed, and a push reads no workshop table', function () {
    F::catalogue($this->sync->company);
    $first = $this->sync->pull(0)->getContent();

    $read = readsWorkshopTables(function () use (&$again) {
        $again = $this->sync->pull(0)->getContent();
        W::pushStock($this->sync, F::COLA, 5)->assertOk();
    });

    expect($again)->toBe($first)->and($read)->toBeFalse();
    Http::assertNothingSent();
});

test('on but the shop is not connected: its pull is byte-identical and carries no workshop row', function () {
    F::catalogue($this->sync->company);
    $before = $this->sync->pull(0)->getContent();
    W::on();

    expect($this->sync->pull(0)->getContent())->toBe($before);
});

test('off: the admin tenant page has no workshop prop', function () {
    $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
    $this->actingAs($admin, 'admin')->get(route('admin.tenants.show', $this->sync->company))
        ->assertOk()->assertInertia(fn ($page) => $page->missing('workshop')->etc());
});

test('off: the tenant shop page has no workshop prop', function () {
    $owner = F::member($this->sync->company, CompanyRole::Owner);
    $this->actingAs($owner)->get(route('app.shops.show', $this->sync->leeds->id))
        ->assertOk()->assertInertia(fn ($page) => $page->missing('workshop')->etc());
});
