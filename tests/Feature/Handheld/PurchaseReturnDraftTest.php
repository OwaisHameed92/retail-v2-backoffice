<?php

use App\Domain\StockDrafts\Models\StockDraft;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Handheld\StockDraftFixtures as S;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Purchase returns on the handheld with PDA_STOCK_DRAFTS on (contract proposal pda-stock-drafts §5.4): drafted as the
 * till's own PurchaseReturn (origin headOffice, HR- number), optionally against an order line (OVER_RETURN), editable
 * while pending, pulled by the shop only, and booked (sent) or refused (cancelled + reason) by the till's push.
 */

beforeEach(function () {
    $this->travelTo('2026-10-22 09:00:00');
    S::on();
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    H::monthlyPlan($this->company);
    F::catalogue($this->company);
    $this->headers = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['can_adjust_stock' => true])));
    $order = $this->postJson('/api/pda/purchase-orders', ['supplier_id' => F::SUPPLIER, 'status' => 'sent', 'items' => [
        ['product_id' => F::WATER, 'quantity_ordered' => 2, 'unit_cost' => 11.76, 'conversion_factor' => 12],
    ]], $this->headers)->json('data');
    $this->orderId = $order['id'];
    $this->line = $order['items'][0]['id'];
    DB::table('purchase_order_lines')->where('id', $this->line)->update(['received_qty' => '10.0000']);   // the till booked 10 in
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->ref = 'c4d2e5f6-a7b8-4c9d-8e0f-1a2b3c4d5e6f';
    $this->body = fn (array $overrides = []) => ['client_reference' => $this->ref, 'supplier_id' => F::SUPPLIER, 'purchase_order_id' => $this->orderId,
        'reference_no' => 'RET-1001', 'return_reason' => 'Damaged in transit', 'items' => [
            ['product_id' => F::WATER, 'purchase_order_item_id' => $this->line, 'quantity' => '2.0000', 'cost_price' => '0.9800', 'reason' => 'Crushed'],
        ], ...$overrides];
});

test('create, repeat, edit, cancel; 409 RETURN_CANCELLED after; the order shows what is returned', function () {
    $id = $this->postJson('/api/pda/purchase-returns', ($this->body)(), $this->headers)->assertCreated()
        ->assertJson(['type' => 'purchase_return', 'data' => ['reference' => 'HR-LDS-000001', 'supplier_name' => 'Aire Valley Cash & Carry', 'purchase_order_id' => $this->orderId,
            'net_total' => '1.96', 'vat_total' => '0.00', 'gross_total' => '1.96', 'portal_state' => 'pending',
            'items' => [['product_id' => F::WATER, 'purchase_order_item_id' => $this->line, 'quantity' => '2.0000', 'cost_price' => '0.9800', 'reason' => 'Crushed', 'reason_code' => 'other']]]])
        ->json('data.id');
    $this->postJson('/api/pda/purchase-returns', ($this->body)(['items' => []]), $this->headers)->assertStatus(422);
    $this->postJson('/api/pda/purchase-returns', ($this->body)(), $this->headers)->assertOk()->assertJsonPath('data.id', $id);

    $this->getJson("/api/pda/purchase-orders/{$this->orderId}", $this->headers)->assertJsonPath('data.items.0.quantity_returned', '2.0000')->assertJsonPath('data.total_returned', '2.0000');
    $this->getJson("/api/pda/purchase-orders/{$this->orderId}/returnable", $this->headers)->assertJsonPath('data.0.quantity_returnable', '8.0000');

    $this->putJson("/api/pda/purchase-returns/{$id}", ($this->body)(['items' => [['product_id' => F::WATER, 'purchase_order_item_id' => $this->line, 'quantity' => 10, 'reason' => 'Damaged']]]), $this->headers)
        ->assertOk()->assertJsonPath('data.items.0.quantity', '10.0000')->assertJsonPath('data.items.0.reason_code', 'damaged')->assertJsonPath('data.items.0.cost_price', '0.9800');
    $this->postJson("/api/pda/purchase-returns/{$id}/cancel", [], $this->headers)->assertOk()->assertJsonPath('data.portal_state', 'cancelled');
    $this->putJson("/api/pda/purchase-returns/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'RETURN_CANCELLED');
    $this->getJson('/api/pda/purchase-returns?purchase_order_id='.$this->orderId, $this->headers)->assertJsonPath('data.total', 1);
    expect(S::drafted($this->sync->pull($this->since)))->toBe([]);
});

test('OVER_RETURN against an order line counts other returns; a line of another order or product is refused', function () {
    $this->postJson('/api/pda/purchase-returns', ($this->body)(['items' => [['product_id' => F::WATER, 'purchase_order_item_id' => $this->line, 'quantity' => 11]]]), $this->headers)
        ->assertStatus(422)->assertJsonPath('code', 'OVER_RETURN')->assertJsonPath('errors', ['items.0.quantity' => ['Only 10.0000 can be returned on this order line.']]);
    $this->postJson('/api/pda/purchase-returns', ($this->body)(['items' => [['product_id' => F::WATER, 'purchase_order_item_id' => $this->line, 'quantity' => 6]]]), $this->headers)->assertCreated();
    $this->postJson('/api/pda/purchase-returns', ($this->body)(['client_reference' => null, 'items' => [['product_id' => F::WATER, 'purchase_order_item_id' => $this->line, 'quantity' => 5]]]), $this->headers)
        ->assertStatus(422)->assertJsonPath('code', 'OVER_RETURN');

    $this->postJson('/api/pda/purchase-returns', ($this->body)(['client_reference' => null, 'items' => [['product_id' => F::COLA, 'purchase_order_item_id' => $this->line, 'quantity' => 1]]]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.product_id']]);
    $this->postJson('/api/pda/purchase-returns', ($this->body)(['client_reference' => null, 'purchase_order_id' => null]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.purchase_order_item_id']]);
    $other = H::otherBusiness();
    $this->postJson('/api/pda/purchase-returns', ($this->body)(['client_reference' => null, 'purchase_order_id' => $other['order']]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['purchase_order_id']]);
    $this->postJson('/api/pda/purchase-returns', ($this->body)(['client_reference' => null, 'supplier_id' => $other['supplier']]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['supplier_id']]);

    // A plain supplier return (no order) has no limit here; the till checks its stock.
    $this->postJson('/api/pda/purchase-returns', ['supplier_id' => F::SUPPLIER, 'items' => [['product_id' => F::COLA, 'quantity' => 3, 'reason' => 'Expired']]], $this->headers)
        ->assertCreated()->assertJsonPath('data.items.0.cost_price', '0.9800')->assertJsonPath('data.items.0.vat_percentage', '20.0000')
        ->assertJsonPath('data.vat_total', '0.59');
});

test('pulled by its shop as PurchaseReturn + line (schema-valid); 409 after; the till books one and refuses another', function () {
    $id = $this->postJson('/api/pda/purchase-returns', ($this->body)(), $this->headers)->json('data.id');
    $plain = $this->postJson('/api/pda/purchase-returns', ['supplier_id' => F::SUPPLIER, 'items' => [['product_id' => F::COLA, 'quantity' => 3]]], $this->headers)->json('data.id');

    [$return, $line, $return2] = $drafted = S::drafted($this->sync->pull($this->since));
    expect(array_column($drafted, 'entity'))->toBe(['PurchaseReturn', 'PurchaseReturnLine', 'PurchaseReturn', 'PurchaseReturnLine'])
        ->and($return['payload'])->toMatchArray(['id' => $id, 'origin' => 'headOffice', 'status' => 'draft', 'number' => 1, 'reference' => 'HR-LDS-000001',
            'note' => 'Damaged in transit — ref RET-1001', 'returnDate' => '2026-10-22', 'netAmount' => 1.96])
        ->and($line['payload'])->toMatchArray(['purchaseReturnId' => $id, 'qty' => 2, 'reason' => 'other', 'note' => 'Crushed', 'purchaseOrderLineId' => $this->line, 'fromStock' => true])
        ->and(S::drafted($this->sync->pull(0, bradford: true)))->toBe([]);

    $this->putJson("/api/pda/purchase-returns/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'RETURN_OWNED_BY_TILL');

    S::pushOutcome($this->sync, [
        [$return, ['status' => 'sent', 'sentAt' => '2026-10-22T09:20:00Z']],
        [$return2, ['status' => 'cancelled', 'cancelledAt' => '2026-10-22T09:20:00Z', 'cancelReason' => 'Supplier will not take it back']],
    ])->assertOk();

    $this->getJson('/api/pda/purchase-returns', $this->headers)->assertJsonPath('data.data.0.id', $plain)->assertJsonPath('data.data.0.portal_state', 'rejected')
        ->assertJsonPath('data.data.0.reject_reason', 'Supplier will not take it back')->assertJsonPath('data.data.1.portal_state', 'confirmed');
    $this->getJson("/api/pda/purchase-orders/{$this->orderId}/returnable", $this->headers)->assertJsonPath('data.0.quantity_returned', '2.0000');
    expect(S::drafted($this->sync->pull(0)))->toBe([])->and(StockDraft::withoutCompanyScope()->count())->toBe(2);
});
