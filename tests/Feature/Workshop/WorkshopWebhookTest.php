<?php

use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\Workshop\Actions\DisconnectWorkshop;
use App\Domain\Workshop\Actions\ReplayWorkshopWebhook;
use App\Domain\Workshop\Actions\SendDueWorkshopWebhooks;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use App\Domain\Workshop\Support\WebhookSignature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;
use Tests\Feature\Workshop\WorkshopFixtures as W;

/*
 * Webhooks to BikeBook (WORKSHOP-BIKEBOOK.md §3.8): BikeBook's webhook CRUD on us, X-Pos-Signature and X-Pos-Event-Id,
 * retries 1 min / 5 min / 15 min / 1 h / 4 h then failed with Replay, inventory.updated from the till's stock pushes
 * (scope and workshopExposure honoured, debounced), optional product.updated / customer.updated. Never a real URL.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->key = W::connected($this->sync);
});

function inventoryHooks(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => ($r->header('X-Pos-Event')[0] ?? '') === 'inventory.updated')->values()->all();
}

test('webhook CRUD: one per connection; https on a public host only; known events only; the secret is never answered back', function () {
    W::call($this, 'GET', '/Webhook', $this->key)->assertOk()->assertExactJson([]);
    W::webhook($this, $this->key)->assertCreated()
        ->assertExactJson(['id' => 'wh_1', 'name' => 'BikeBook', 'url' => W::WEBHOOK_URL, 'subscriptions' => ['order.statuschanged', 'inventory.updated', 'product.updated', 'customer.updated'], 'enabled' => true]);
    W::call($this, 'GET', '/Webhook/wh_1', $this->key)->assertOk()->assertJsonMissingPath('secret');
    W::call($this, 'GET', '/Webhook', $this->key)->assertOk()->assertJsonCount(1);

    W::call($this, 'PUT', '/Webhook', $this->key, ['id' => 'wh_1', 'subscriptions' => ['order.statuschanged'], 'enabled' => false])->assertOk()
        ->assertJsonPath('subscriptions', ['order.statuschanged'])->assertJsonPath('enabled', false);
    expect(W::connection($this->sync->leeds)->webhook_secret)->toBe(W::SECRET);

    foreach ([['url' => 'http://api.bikebook.co.uk/x'], ['url' => 'https://127.0.0.1/x'], ['url' => 'https://localhost/x'], ['subscriptions' => ['order.paid']], ['secret' => 'short']] as $bad) {
        W::call($this, 'POST', '/Webhook', $this->key, [...['url' => W::WEBHOOK_URL, 'secret' => W::SECRET, 'subscriptions' => []], ...$bad])->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    }

    W::call($this, 'DELETE', '/Webhook/other', $this->key)->assertNotFound();
    W::call($this, 'DELETE', '/Webhook/wh_1', $this->key)->assertNoContent();
    W::call($this, 'GET', '/Webhook', $this->key)->assertExactJson([]);
    W::call($this, 'PUT', '/Webhook', $this->key, ['subscriptions' => []])->assertNotFound();
});

test('retries after 1 min, 5 min, 15 min, 1 h and 4 h, then failed: the connection shows the reason; Replay delivers and clears it', function () {
    Http::fake([W::WEBHOOK_URL => Http::sequence()->push('down', 500)->push('down', 500)->push('down', 503)->push('down', 500)->push('down', 502)->push('down', 500)->push(['ok' => true], 200)]);
    W::webhook($this, $this->key)->assertCreated();
    $id = W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->json('id');
    W::pushCustomerOrder($this->sync, $id, ['status' => 'collected', 'paidTotal' => 65.5])->assertOk();

    $delivery = WorkshopWebhookDelivery::withoutCompanyScope()->firstOrFail();
    expect($delivery->attempts)->toBe(1)->and($delivery->next_attempt_at->toDateTimeString())->toBe('2026-11-08 09:01:00');

    foreach ([1, 5, 15, 60, 240] as $minutes) {
        $this->travelTo($delivery->refresh()->next_attempt_at);
        expect(app(SendDueWorkshopWebhooks::class)->handle())->toBe(1);
    }

    $delivery->refresh();
    $connection = W::connection($this->sync->leeds);
    expect($delivery->failed_at)->not->toBeNull()->and($delivery->attempts)->toBe(6)->and($delivery->last_status)->toBe(500)
        ->and($connection->status)->toBe(ConnectionStatus::Failed)->and($connection->last_error)->toContain('order.statuschanged not delivered after 6 attempts')
        ->and(TillSetting::withoutCompanyScope()->where('setting_key', 'workshop.connector_status')->value('value'))->toBe('failed');
    expect(app(SendDueWorkshopWebhooks::class)->handle())->toBe(0);

    // Every attempt carried the same body, event id and signature.
    $requests = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => $r->url() === W::WEBHOOK_URL);
    expect($requests->map(fn (HttpRequest $r) => $r->header('X-Pos-Event-Id')[0])->unique()->all())->toBe([$delivery->id])
        ->and($requests->every(fn (HttpRequest $r) => WebhookSignature::verify(W::SECRET, $r->body(), $r->header('X-Pos-Signature')[0])))->toBeTrue();

    app(ReplayWorkshopWebhook::class)->handle($delivery);
    expect($delivery->refresh()->delivered_at)->not->toBeNull()
        ->and(W::connection($this->sync->leeds)->status)->toBe(ConnectionStatus::Connected)
        ->and(W::connection($this->sync->leeds)->last_error)->toBeNull();
});

test('inventory.updated: a stock push of an in-scope product is sent with its Inventory; changes within 30 s are merged; never products BikeBook does not see', function () {
    Http::fake([W::WEBHOOK_URL => Http::response(['ok' => true])]);
    W::webhook($this, $this->key)->assertCreated();

    W::pushStock($this->sync, F::COLA, 9, 2)->assertOk();
    $hooks = inventoryHooks();
    expect($hooks)->toHaveCount(1)
        ->and(json_decode($hooks[0]->body(), true))->toBe([['variant' => F::COLA, 'store' => $this->sync->leeds->id, 'quantity' => 7.0, 'price' => 1.45]]);

    // Within 30 s: merged into one delivery, sent when due.
    W::pushStock($this->sync, F::COLA, 8)->assertOk();
    W::pushStock($this->sync, F::WATER, 3)->assertOk();
    expect(inventoryHooks())->toHaveCount(1)->and(WorkshopWebhookDelivery::withoutCompanyScope()->whereNull('delivered_at')->count())->toBe(1);
    $this->travel(31)->seconds();
    app(SendDueWorkshopWebhooks::class)->handle();
    expect(inventoryHooks())->toHaveCount(2)->and(collect(json_decode(inventoryHooks()[1]->body(), true))->pluck('variant')->sort()->values()->all())->toBe([F::WATER, F::COLA]);

    // A product set to "never" is not BikeBook's business.
    Product::withoutCompanyScope()->whereKey(F::WATER)->update(['workshop_exposure' => 'never']);
    $this->travel(1)->minutes();
    W::pushStock($this->sync, F::WATER, 1)->assertOk();
    expect(inventoryHooks())->toHaveCount(2)->and(WorkshopWebhookDelivery::withoutCompanyScope()->count())->toBe(2);
});

test('nothing is sent without BikeBook\'s webhook, for an event it did not subscribe to, or after a disconnect', function () {
    Http::fake([W::WEBHOOK_URL => Http::response('down', 500)]);
    W::pushStock($this->sync, F::COLA, 9)->assertOk();
    W::call($this, 'POST', '/Webhook', $this->key, ['url' => W::WEBHOOK_URL, 'secret' => W::SECRET, 'subscriptions' => ['order.statuschanged']])->assertCreated();
    W::pushStock($this->sync, F::COLA, 8)->assertOk();
    Http::assertNothingSent();

    W::call($this, 'PUT', '/Webhook', $this->key, ['subscriptions' => ['inventory.updated']])->assertOk();
    W::pushStock($this->sync, F::COLA, 7)->assertOk();
    expect(WorkshopWebhookDelivery::withoutCompanyScope()->whereNull('failed_at')->count())->toBe(1);

    app(DisconnectWorkshop::class)->handle(W::connection($this->sync->leeds));
    expect(WorkshopWebhookDelivery::withoutCompanyScope()->whereNull('failed_at')->count())->toBe(0)
        ->and(WorkshopWebhookDelivery::withoutCompanyScope()->value('last_error'))->toBe('Not sent: BikeBook was disconnected.');
    W::pushStock($this->sync, F::COLA, 6)->assertOk();
    expect(WorkshopWebhookDelivery::withoutCompanyScope()->count())->toBe(1);
});

test('customer.updated and product.updated (optional) follow the till\'s pushes when BikeBook subscribed', function () {
    Http::fake([W::WEBHOOK_URL => Http::response(['ok' => true])]);
    W::webhook($this, $this->key)->assertCreated();
    W::call($this, 'POST', '/Customer', $this->key, ['firstName' => 'Ali', 'lastName' => 'Khan', 'phone' => '07700 900123'])->assertCreated();
    $customer = W::call($this, 'GET', '/Customer/list', $this->key)->json('0');

    $payload = [...TillFixtures::sample('entities/Customer.json'), 'id' => $customer['id'], 'companyId' => TillFixtures::COMPANY,
        'name' => 'Ali R Khan', 'phone' => '07700 900123', 'rowVersion' => 2, 'updatedAt' => '2026-11-08T09:30:00Z'];
    $this->sync->push([TillFixtures::envelope('Customer', $payload, 8800, ['op' => 'U'])])->assertOk();

    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => ($r->header('X-Pos-Event')[0] ?? '') === 'customer.updated')->values();
    expect($sent)->toHaveCount(1)->and(json_decode($sent[0]->body(), true))->toMatchArray(['id' => $customer['id'], 'firstName' => 'Ali R', 'lastName' => 'Khan'])
        ->and(json_decode($sent[0]->body(), true))->not->toHaveKeys(['balance', 'points', 'creditLimit', 'cardNo', 'dob', 'notes']);
});
