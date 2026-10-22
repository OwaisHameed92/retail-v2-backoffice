<?php

use App\Domain\Purchasing\Actions\ChangeHeadOfficeOrderStatus;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Handheld\StockDraftFixtures as S;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Goods-in on the handheld with PDA_STOCK_DRAFTS on (contract proposal pda-stock-drafts §5.3): a delivery against an
 * order of the shop, drafted as the till's own GoodsReceipt (origin headOffice), partial deliveries as deltas,
 * OVER_RECEIPT, the order locked for portal edits while the till has not booked it, receipts and returnable, and the
 * till's posted / cancelled push.
 */

beforeEach(function () {
    $this->travelTo('2026-10-22 09:00:00');
    S::on();
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    H::monthlyPlan($this->company);
    F::catalogue($this->company);
    $this->headers = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['can_adjust_stock' => true])));
    $this->order = $this->postJson('/api/pda/purchase-orders', ['supplier_id' => F::SUPPLIER, 'status' => 'sent', 'items' => [
        ['product_id' => F::WATER, 'quantity_ordered' => 2, 'unit_cost' => 11.76, 'conversion_factor' => 12],
        ['product_id' => F::COLA, 'quantity_ordered' => 48, 'unit_cost' => '0.78'],
    ]], $this->headers)->assertCreated()->json('data');
    $this->water = $this->order['items'][0]['id'];
    $this->cola = $this->order['items'][1]['id'];
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->url = "/api/pda/purchase-orders/{$this->order['id']}";
    $this->ref = '9b1e2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
    $this->body = fn (array $overrides = []) => ['client_reference' => $this->ref, 'reference_no' => 'GRN-20261022-001', 'received_at' => '2026-10-22T09:15:00Z',
        'note' => '2 of 3 pallets', 'items' => [['purchase_order_item_id' => $this->water, 'quantity_received' => '12.0000']], ...$overrides];
});

test('a partial delivery is a delta; the order shows it as received (pending the till); a repeat answers the same delivery', function () {
    $reply = $this->postJson("{$this->url}/receive", ($this->body)(), $this->headers)->assertCreated()
        ->assertJsonPath('type', 'purchase_order')->assertJsonPath('data.items.0.quantity_received', '12.0000')
        ->assertJsonPath('data.items.0.quantity_pending', '12.0000')->assertJsonPath('data.items.0.quantity_remaining', '12.0000')
        ->assertJsonPath('data.editable', false)->assertJsonPath('receipt.portal_state', 'pending')->assertJsonPath('receipt.reference_no', 'GRN-20261022-001');

    $this->postJson("{$this->url}/receive", ($this->body)(['items' => [['purchase_order_item_id' => $this->water, 'quantity_received' => 1]]]), $this->headers)
        ->assertOk()->assertJsonPath('receipt.id', $reply->json('receipt.id'));
    $this->postJson("{$this->url}/receive", ($this->body)(['client_reference' => null, 'items' => [['product_id' => F::WATER, 'quantity_received' => 13]]]), $this->headers)
        ->assertStatus(422)->assertJsonPath('code', 'OVER_RECEIPT')->assertJsonPath('errors', ['items.0.quantity_received' => ['Only 12.0000 left to receive on this line.']]);

    // No items: everything still outstanding.
    $this->postJson("{$this->url}/receive", ['reference_no' => 'GRN-2'], $this->headers)->assertCreated()
        ->assertJsonPath('data.items.0.quantity_remaining', '0.0000')->assertJsonPath('data.items.1.quantity_received', '48.0000')
        ->assertJsonPath('data.total_remaining', '0.0000');
    $this->postJson("{$this->url}/receive", [], $this->headers)->assertStatus(422)->assertJsonStructure(['errors' => ['items']]);

    $this->getJson("{$this->url}/receipts", $this->headers)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.items.0', ['purchase_order_item_id' => $this->water, 'product_id' => F::WATER,
            'product_name' => 'Warburtons Toastie White Bread 800g', 'quantity_received' => '12.0000']);
});

test('the order is locked while a delivery waits for the till: handheld 409 PO_OWNED_BY_TILL, portal refuses', function () {
    $this->postJson("{$this->url}/receive", ($this->body)(), $this->headers)->assertCreated();

    $this->putJson($this->url, ['supplier_id' => F::SUPPLIER, 'items' => [['product_id' => F::COLA, 'quantity_ordered' => 1]]], $this->headers)
        ->assertStatus(409)->assertJsonPath('code', 'PO_OWNED_BY_TILL');
    $this->postJson("{$this->url}/cancel", [], $this->headers)->assertStatus(409)->assertJsonPath('code', 'PO_OWNED_BY_TILL');

    // The portal's own order screen goes through DraftHeadOfficeOrder, which refuses it too.
    $order = PurchaseOrder::withoutCompanyScope()->findOrFail($this->order['id']);
    expect(fn () => app(CurrentCompany::class)->runAs($this->company, fn () => app(ChangeHeadOfficeOrderStatus::class)->handle($order, PurchaseOrderStatus::Cancelled)))
        ->toThrow(ValidationException::class, 'booked in against this order from a handheld');
});

test('pulled by the shop after its order as GoodsReceipt + line (schema-valid); the till posts one and cancels another', function () {
    $id = $this->postJson("{$this->url}/receive", ($this->body)(), $this->headers)->json('receipt.id');
    $other = $this->postJson("{$this->url}/receive", ($this->body)(['client_reference' => null, 'items' => [['purchase_order_item_id' => $this->cola, 'quantity_received' => 48]]]), $this->headers)
        ->json('receipt.id');

    [$receipt, $line, $receipt2] = $drafted = S::drafted($this->sync->pull($this->since));
    expect(array_column($drafted, 'entity'))->toBe(['GoodsReceipt', 'GoodsReceiptLine', 'GoodsReceipt', 'GoodsReceiptLine'])
        ->and($receipt['payload'])->toMatchArray(['id' => $id, 'origin' => 'headOffice', 'status' => 'draft', 'purchaseOrderId' => $this->order['id'],
            'deliveryNoteNumber' => 'GRN-20261022-001', 'receivedDate' => '2026-10-22', 'netAmount' => 11.76, 'grossAmount' => 11.76])
        ->and($line['payload'])->toMatchArray(['purchaseOrderLineId' => $this->water, 'receivedQty' => 12, 'expectedQty' => 24, 'caseQty' => 12, 'unitCost' => 0.98])
        ->and(S::drafted($this->sync->pull(0, bradford: true)))->toBe([]);

    S::pushOutcome($this->sync, [
        [$receipt, ['status' => 'posted', 'postedAt' => '2026-10-22T09:20:00Z']],
        [$receipt2, ['status' => 'cancelled', 'cancelledAt' => '2026-10-22T09:20:00Z', 'cancelReason' => 'Wrong delivery']],
    ])->assertOk();
    DB::table('purchase_order_lines')->where('id', $this->water)->update(['received_qty' => '12.0000']);   // the till's own push of the order line

    $this->getJson("{$this->url}/receipts", $this->headers)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $other)->assertJsonPath('data.0.portal_state', 'rejected')->assertJsonPath('data.0.reject_reason', 'Wrong delivery')
        ->assertJsonPath('data.1.id', $id)->assertJsonPath('data.1.portal_state', 'confirmed');
    $this->getJson($this->url, $this->headers)->assertJsonPath('data.items.0.quantity_received', '12.0000')->assertJsonPath('data.items.0.quantity_pending', '0.0000')
        ->assertJsonPath('data.items.1.quantity_received', '0.0000');
    $this->getJson("{$this->url}/returnable", $this->headers)->assertOk()->assertJsonPath('data.0', ['purchase_order_item_id' => $this->water,
        'product_id' => F::WATER, 'product_name' => 'Warburtons Toastie White Bread 800g', 'unit_cost' => '0.9800',
        'quantity_received' => '12.0000', 'quantity_returned' => '0.0000', 'quantity_returnable' => '12.0000']);
    expect(S::drafted($this->sync->pull(0)))->toBe([]);
});

test('refusals: a cancelled order 409 PO_CANCELLED, a draft order 409 PO_NOT_RECEIVABLE, a line of another order, permission, another shop', function () {
    $draft = $this->postJson('/api/pda/purchase-orders', ['supplier_id' => F::SUPPLIER, 'items' => [['product_id' => F::COLA, 'quantity_ordered' => 6]]], $this->headers)->json('data.id');
    $this->postJson("/api/pda/purchase-orders/{$draft}/receive", [], $this->headers)->assertStatus(409)->assertJsonPath('code', 'PO_NOT_RECEIVABLE');
    $this->postJson("/api/pda/purchase-orders/{$draft}/cancel", [], $this->headers)->assertOk();
    $this->postJson("/api/pda/purchase-orders/{$draft}/receive", [], $this->headers)->assertStatus(409)->assertJsonPath('code', 'PO_CANCELLED');

    $this->postJson("{$this->url}/receive", ($this->body)(['items' => [['purchase_order_item_id' => '01K5T0Q8C4000000000000X001', 'quantity_received' => 1]]]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.purchase_order_item_id']]);
    $plain = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['email' => 'plain@kirkgate.test'])));
    $this->postJson("{$this->url}/receive", ($this->body)(), $plain)->assertForbidden()->assertJsonPath('code', 'STOCK_CHANGE_NOT_ALLOWED');
    $this->getJson("{$this->url}/receipts", $plain)->assertOk();

    $bradford = H::headers(H::token($this, H::user($this->company, $this->sync->bradford, ['email' => 'brd@kirkgate.test', 'can_adjust_stock' => true])));
    $this->postJson("{$this->url}/receive", ($this->body)(), $bradford)->assertNotFound();
    $this->getJson("{$this->url}/receipts", $bradford)->assertNotFound();
    $other = H::otherBusiness();
    $this->getJson("/api/pda/purchase-orders/{$other['order']}/returnable", $this->headers)->assertNotFound();

    expect(StockDraft::withoutCompanyScope()->count())->toBe(0)
        ->and(PurchaseOrder::withoutCompanyScope()->whereKey($this->order['id'])->value('status')?->value)->toBe('sent');
});
