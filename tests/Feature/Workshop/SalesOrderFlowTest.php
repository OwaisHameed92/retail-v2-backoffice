<?php

use App\Domain\Workshop\Models\WorkshopOrder;
use App\Domain\Workshop\Support\WebhookSignature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures as T;
use Tests\Feature\Workshop\WorkshopFixtures as W;
use Tests\Support\ContractSchema;

/*
 * The heart of it (WORKSHOP-BIKEBOOK.md §3.6, §3.7, §5): Send to POS → WorkshopOrder in the shop's pull (the till's
 * own entity schema) → the till pushes its CustomerOrder and the money → BikeBook's changes get 409 order_locked →
 * BikeBook gets order.statuschanged Complete, then the refund. Joint tests 5, 6, 7, 8, 9 and 12 as far as the
 * portal's half goes.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->key = W::connected($this->sync);
    Http::fake([W::WEBHOOK_URL => Http::response(['ok' => true])]);
    W::webhook($this, $this->key)->assertCreated();
    $this->since = (int) $this->sync->pull(0)->json('highestVersion');
});

function orderStatusHooks(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => ($r->header('X-Pos-Event')[0] ?? '') === 'order.statuschanged')->values()->all();
}

test('Send to POS: 201 with our SalesOrder; the same POST again (a retry after an outage) gives the same order, no second row (joint test 12)', function () {
    $created = W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->assertCreated();

    expect($created->json())->toMatchArray(['orderNumber' => 'INV#1042 [abcd]', 'reference' => 'WS-1042', 'status' => 'Saved', 'store' => $this->sync->leeds->id,
        'total' => 65.5, 'paidTotal' => 0, 'version' => 1])
        ->and(collect($created->json('lines'))->map(fn ($l) => [$l['variantId'], $l['quantity'], $l['paidPrice'], $l['lineType']])->all())->toEqual([
            [F::COLA, 2, 6, 'Product'], [null, 1, 45, 'Labour'], [null, 1, 8.5, 'Custom'],
        ]);

    W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->assertOk()->assertJsonPath('id', $created->json('id'))->assertJsonPath('version', 1);
    W::call($this, 'GET', '/SalesOrder?id='.$created->json('id'), $this->key)->assertOk()->assertJsonPath('status', 'Saved');
    expect(WorkshopOrder::withoutCompanyScope()->count())->toBe(1);
});

test('the order goes to that shop\'s pull only, as the till\'s own WorkshopOrder entity (schema), with its till ids; the same version is not sent again (joint test 5)', function () {
    $id = W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->json('id');

    $pull = $this->sync->pull($this->since)->assertOk();
    $rows = W::workshopRows($pull);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['entity' => 'WorkshopOrder', 'entityId' => $id, 'op' => 'I', 'branchId' => T::LEEDS, 'companyId' => T::COMPANY, 'key' => "WorkshopOrder:{$id}:1"])
        ->and(ContractSchema::errors($rows[0]['payload'], 'schemas/entities/WorkshopOrder.schema.json'))->toBe([])
        ->and(ContractSchema::pullErrors($pull->json()))->toBe([])
        ->and($rows[0]['payload'])->toMatchArray(['source' => 'connector', 'provider' => 'bikebook', 'status' => 'open', 'reference' => 'WS-1042',
            'externalOrderNumber' => 'INV#1042 [abcd]', 'externalJobNumber' => '1042', 'goodsTotal' => 65.5, 'paidElsewhere' => 0, 'rowVersion' => 1,
            'customerName' => 'Ali Khan', 'customerPhone' => '07700 900123', 'branchId' => T::LEEDS, 'isOpen' => true])
        ->and(collect(json_decode($rows[0]['payload']['linesJson'], true))->map(fn ($l) => [$l['lineType'], $l['lineTotal'], $l['vatAmount']])->all())
        ->toEqual([['part', 12, 2], ['labour', 45, 7.5], ['custom', 8.5, 1.42]]);

    expect(W::workshopRows($this->sync->pull($pull->json('highestVersion'))))->toBe([])
        ->and(W::workshopRows($this->sync->pull(0, bradford: true)))->toBe([]);
});

test('a change before the till takes money is a new version (U); after a deposit every change is 409 order_locked (joint test 6)', function () {
    $id = W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->json('id');
    $since = $this->sync->pull($this->since)->json('highestVersion');

    $body = W::order($this->sync->leeds, ['id' => $id]);
    $body['lines'][] = ['id' => 'l4', 'variantId' => F::WATER, 'name' => 'Bottle', 'quantity' => 1, 'paidPrice' => 1.45, 'taxRate' => F::VAT_ZERO, 'lineType' => 'Product'];
    W::call($this, 'PUT', '/SalesOrder', $this->key, $body)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('total', 66.95);

    $rows = W::workshopRows($this->sync->pull($since));
    expect($rows)->toHaveCount(1)->and($rows[0]['op'])->toBe('U')->and($rows[0]['payload']['rowVersion'])->toBe(2);

    // The till makes its CustomerOrder (no money yet): still open to changes.
    W::pushCustomerOrder($this->sync, $id)->assertOk();
    W::call($this, 'PUT', '/SalesOrder/'.$id.'/status/Saved', $this->key)->assertOk();

    W::pushPayment($this->sync, $id, 'deposit', 20.0)->assertOk();
    $locked = ['error' => 'order_locked', 'message' => 'The shop has started taking payment for this order. Change it at the till.'];
    W::call($this, 'PUT', '/SalesOrder', $this->key, W::order($this->sync->leeds, ['id' => $id]))->assertStatus(409)->assertExactJson($locked);
    W::call($this, 'DELETE', '/SalesOrder?id='.$id, $this->key)->assertStatus(409)->assertExactJson($locked);
    W::call($this, 'POST', '/SalesOrderLine', $this->key, ['salesOrderId' => $id, 'name' => 'Extra', 'quantity' => 1, 'paidPrice' => 1, 'taxRate' => F::VAT_STD])->assertStatus(409);
    W::call($this, 'PUT', '/SalesOrder/'.$id.'/status/Complete', $this->key)->assertStatus(409);

    expect(WorkshopOrder::withoutCompanyScope()->find($id)->version)->toBe(2)->and(WorkshopOrder::withoutCompanyScope()->find($id)->locked_at)->not->toBeNull();
});

test('the till takes the money: BikeBook gets a signed order.statuschanged Complete, then the refund; the same push again sends nothing (joint tests 7, 9)', function () {
    $id = W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->json('id');
    W::pushCustomerOrder($this->sync, $id)->assertOk();
    W::pushPayment($this->sync, $id, 'deposit', 20.0)->assertOk();
    expect(orderStatusHooks())->toBe([]);   // a deposit is not reported (Q5, Q11)

    $sale = '01K9SAXE000000000000000001';
    W::pushCustomerOrder($this->sync, $id, ['status' => 'collected', 'paidTotal' => 65.5, 'saleId' => $sale, 'collectedAt' => '2026-11-08T12:00:00Z'], 2)->assertOk();

    $hooks = orderStatusHooks();
    expect($hooks)->toHaveCount(1);
    $body = $hooks[0]->body();
    expect(json_decode($body, true))->toBe(['id' => $id, 'orderStatus' => 'Complete', 'paidTotal' => 65.5, 'refundedTotal' => 0.0, 'saleId' => $sale, 'paidAt' => '2026-11-08T12:00:00Z'])
        ->and(WebhookSignature::verify(W::SECRET, $body, $hooks[0]->header('X-Pos-Signature')[0]))->toBeTrue()
        ->and($hooks[0]->header('X-Pos-Signature')[0])->toBe(base64_encode(hash_hmac('sha256', $body, W::SECRET, true)))
        ->and($hooks[0]->header('X-Pos-Event-Id')[0])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    W::call($this, 'GET', '/SalesOrder?id='.$id, $this->key)->assertJsonPath('status', 'Complete')->assertJsonPath('paidTotal', 65.5);

    W::pushCustomerOrder($this->sync, $id, ['status' => 'collected', 'paidTotal' => 65.5, 'saleId' => $sale, 'collectedAt' => '2026-11-08T12:00:00Z'], 2)->assertOk();
    expect(orderStatusHooks())->toHaveCount(1);

    W::pushPayment($this->sync, $id, 'refund', -8.5)->assertOk();
    $hooks = orderStatusHooks();
    expect($hooks)->toHaveCount(2)->and(json_decode($hooks[1]->body(), true)['refundedTotal'])->toBe(8.5);
});

test('cancel: DELETE makes the pull row cancelled once; a cancel after the till cancelled is 409 (joint test 8)', function () {
    $id = W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->json('id');
    $since = $this->sync->pull($this->since)->json('highestVersion');

    W::call($this, 'DELETE', '/SalesOrder?id='.$id, $this->key)->assertOk()->assertJsonPath('status', 'Cancelled')->assertJsonPath('version', 2);
    W::call($this, 'DELETE', '/SalesOrder?id='.$id, $this->key)->assertOk()->assertJsonPath('version', 2);

    $rows = W::workshopRows($this->sync->pull($since));
    expect($rows)->toHaveCount(1)->and($rows[0]['payload'])->toMatchArray(['status' => 'cancelled', 'isOpen' => false, 'rowVersion' => 2])
        ->and($rows[0]['payload']['cancelledAt'])->toBe('2026-11-08T09:00:00Z');

    // Sent again with content: open again (a new version).
    W::call($this, 'PUT', '/SalesOrder/'.$id.'/status/Saved', $this->key)->assertOk()->assertJsonPath('status', 'Saved')->assertJsonPath('version', 3);
});

test('BikeBook says Complete itself: sent again open with paidElsewhere = the total, and never reported back (§5.4)', function () {
    $id = W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->json('id');
    $since = $this->sync->pull($this->since)->json('highestVersion');

    W::call($this, 'PUT', '/SalesOrder/'.$id.'/status/Complete', $this->key)->assertOk()->assertJsonPath('status', 'Complete')->assertJsonPath('paidTotal', 65.5);
    W::call($this, 'PUT', '/SalesOrder/'.$id.'/status/Complete', $this->key)->assertOk()->assertJsonPath('version', 2);
    $rows = W::workshopRows($this->sync->pull($since));
    expect($rows[0]['payload'])->toMatchArray(['status' => 'open', 'paidElsewhere' => 65.5]);

    W::pushCustomerOrder($this->sync, $id, ['status' => 'collected', 'paidTotal' => 0.0])->assertOk();
    expect(orderStatusHooks())->toBe([]);
    W::call($this, 'PUT', '/SalesOrder/'.$id.'/status/Unknown', $this->key)->assertStatus(400);
});

test('an order the till found itself in Direct mode (externalOrderId we never sent) is never reported', function () {
    $foreign = '01K9DXRECT0000000000000001';
    W::pushCustomerOrder($this->sync, $foreign, ['status' => 'collected', 'paidTotal' => 30.0, 'externalOrderId' => 'bb-inv-991', 'externalReference' => '991'])->assertOk();

    expect(orderStatusHooks())->toBe([]);
});

test('lines: add, change and remove one line, each a new version; the same line again (BikeBook\'s line id) changes nothing', function () {
    $order = W::call($this, 'POST', '/SalesOrder', $this->key, W::order($this->sync->leeds))->json();
    $line = ['salesOrderId' => $order['id'], 'id' => 'l9', 'variantId' => F::WATER, 'name' => 'Bottle', 'quantity' => 2, 'paidPrice' => 1.45, 'taxRate' => F::VAT_ZERO, 'lineType' => 'Product'];

    W::call($this, 'POST', '/SalesOrderLine', $this->key, $line)->assertOk()->assertJsonPath('version', 2)->assertJsonCount(4, 'lines');
    W::call($this, 'POST', '/SalesOrderLine', $this->key, $line)->assertOk()->assertJsonPath('version', 2)->assertJsonCount(4, 'lines');

    $ours = $order['lines'][1]['id'];
    W::call($this, 'PUT', '/SalesOrderLine', $this->key, ['id' => $ours, 'name' => 'Gear tune (full)', 'quantity' => 1, 'paidPrice' => 55, 'taxRate' => F::VAT_STD, 'lineType' => 'Labour'])
        ->assertOk()->assertJsonPath('version', 3)->assertJsonPath('lines.1.id', $ours)->assertJsonPath('lines.1.paidPrice', 55)->assertJsonPath('total', 78.4);

    W::call($this, 'DELETE', '/SalesOrderLine?id=l9', $this->key)->assertOk()->assertJsonPath('version', 4)->assertJsonCount(3, 'lines');
    W::call($this, 'DELETE', '/SalesOrderLine?id=NOPE', $this->key)->assertNotFound();
});

test('validation: 400 invalid_request with a clear message (unknown store, VAT rate, product; quantity, price; more than 200 lines)', function () {
    $leeds = $this->sync->leeds;
    $cases = [
        [W::order($leeds, ['store' => $this->sync->bradford->id]), "store {$this->sync->bradford->id} is not the connected shop. Use {$leeds->id}."],
        [W::order($leeds, ['orderNumber' => '']), 'orderNumber is required (at most 120 characters).'],
        [W::order($leeds, ['lines' => [['variantId' => '01K5T0Q8C4000000000000P999', 'quantity' => 1, 'paidPrice' => 1, 'taxRate' => F::VAT_STD]]]), 'Line 1: variantId 01K5T0Q8C4000000000000P999 is not an active product of this shop. Send it as a custom line.'],
        [W::order($leeds, ['lines' => [['name' => 'X', 'quantity' => 1, 'paidPrice' => 1, 'taxRate' => '01K5T0Q8C4000000000000V999']]]), 'Line 1: taxRate 01K5T0Q8C4000000000000V999 is not one of this shop\'s VAT rates.'],
        [W::order($leeds, ['lines' => [['name' => 'X', 'quantity' => 0, 'paidPrice' => 1, 'taxRate' => F::VAT_STD]]]), 'Line 1: quantity must be more than 0.'],
        [W::order($leeds, ['lines' => [['name' => 'X', 'quantity' => 1, 'paidPrice' => -1, 'taxRate' => F::VAT_STD]]]), 'Line 1: paidPrice cannot be below 0.'],
        [W::order($leeds, ['lines' => array_fill(0, 201, ['name' => 'X', 'quantity' => 1, 'paidPrice' => 1, 'taxRate' => F::VAT_STD])]), 'An order can have at most 200 lines.'],
        [W::order($leeds, ['lines' => []]), 'lines must be a list with at least one line.'],
    ];

    foreach ($cases as [$body, $message]) {
        W::call($this, 'POST', '/SalesOrder', $this->key, $body)->assertStatus(400)->assertExactJson(['error' => 'invalid_request', 'message' => $message]);
    }

    // A quantity with decimals is fine.
    W::call($this, 'POST', '/SalesOrder', $this->key, W::order($leeds, ['lines' => [['name' => 'Cable', 'quantity' => 1.5, 'paidPrice' => 2, 'taxRate' => F::VAT_STD]]]))
        ->assertCreated()->assertJsonPath('total', 3);
    W::call($this, 'GET', '/SalesOrder?id=01K5T0Q8C4000000000000X999', $this->key)->assertNotFound();
});
