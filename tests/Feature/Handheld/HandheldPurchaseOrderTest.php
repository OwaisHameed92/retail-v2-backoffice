<?php

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Handheld (PDA) API phase 1: purchase orders. The shop's orders (head-office and the till's own), head-office orders
 * created, edited and cancelled for the user's shop through the portal's own actions (pulled by that shop only), the
 * till-owned refusal, shop and business isolation, and the phase 2 calls (501).
 */

beforeEach(function () {
    $this->travelTo('2026-10-20 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    F::catalogue($this->company);
    $this->handheld = H::user($this->company, $this->sync->leeds);
    $this->headers = H::headers(H::token($this, $this->handheld));
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->sinceBradford = $this->sync->pull(0, bradford: true)->json('highestVersion');
    $this->body = fn (array $overrides = []) => [
        'supplier_id' => F::SUPPLIER,
        'expected_delivery_date' => '2026-10-25',
        'notes' => 'Weekly top-up',
        'items' => [
            ['product_id' => F::WATER, 'quantity_ordered' => 2, 'unit_cost' => 11.76, 'conversion_factor' => 12],
            ['product_id' => F::COLA, 'quantity_ordered' => 48, 'unit_cost' => '0.78'],
        ],
        ...$overrides,
    ];
    $this->order = fn () => PurchaseOrder::withoutCompanyScope()->where('company_id', $this->company->id)->where('origin', 'headOffice')->sole();
});

test('creating an order drafts a head-office order for the user\'s shop that only its till pulls', function () {
    $reply = $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->assertCreated()
        ->assertJson(['success' => true, 'type' => 'purchase_order', 'data' => [
            'reference' => 'HO-LDS-000001', 'origin' => 'headOffice', 'status' => 'draft', 'portal_state' => 'pending', 'editable' => true,
            'supplier_id' => F::SUPPLIER, 'supplier_name' => 'Aire Valley Cash & Carry', 'expected_delivery_date' => '2026-10-25',
            'net_total' => '60.96', 'vat_total' => '7.49', 'gross_total' => '68.45', 'total_ordered' => '72.0000', 'total_received' => '0.0000',
            'total_remaining' => '72.0000',
        ]]);

    expect($reply->json('data.items'))->toHaveCount(2)
        ->and($reply->json('data.items.0'))->toMatchArray(['product_id' => F::WATER, 'quantity_ordered' => '24.0000', 'cases_ordered' => 2, 'case_qty' => 12,
            'unit_cost' => '0.9800', 'quantity_received' => '0.0000', 'quantity_remaining' => '24.0000', 'quantity_returned' => null])
        ->and($reply->json('data.items.1'))->toMatchArray(['product_id' => F::COLA, 'quantity_ordered' => '48.0000', 'case_qty' => 1, 'vat_percentage' => '20.0000']);

    $order = ($this->order)();
    expect($order->branch_id)->toBe($this->sync->leeds->id);

    $leeds = Pull::changes($this->sync->pull($this->since));
    expect(array_column($leeds, 'entity'))->toBe(['PurchaseOrder', 'PurchaseOrderLine', 'PurchaseOrderLine'])
        ->and($leeds[0]['payload'])->toMatchArray(['origin' => 'headOffice', 'status' => 'draft', 'reference' => 'HO-LDS-000001'])
        ->and(Pull::changes($this->sync->pull($this->sinceBradford, bradford: true)))->toBe([]);

    $audit = AuditLog::query()->where('action', 'purchase_order.head_office_drafted')->sole();
    expect($audit->actor_type)->toBe((new HandheldUser)->getMorphClass())->and($audit->actor_id)->toBe($this->handheld->id);
});

test('an omitted cost is the product\'s cost price; status sent places the order', function () {
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['status' => 'sent', 'items' => [['product_id' => F::COLA, 'quantity_ordered' => 10]]]), $this->headers)
        ->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.items.0.unit_cost', '0.9800')
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'placed'));
});

test('editing replaces the lines while the portal owns the order; cancelling sends the cancellation down', function () {
    $id = $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->json('data.id');

    $this->putJson("/api/pda/purchase-orders/{$id}", ($this->body)(['items' => [['product_id' => F::COLA, 'quantity_ordered' => 24, 'unit_cost' => '0.80']]]), $this->headers)
        ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.gross_total', '23.04')->assertJsonCount(1, 'data.items');
    expect(PurchaseOrderLine::withoutCompanyScope()->where('purchase_order_id', $id)->count())->toBe(1);

    $this->postJson("/api/pda/purchase-orders/{$id}/cancel", ['reason' => 'Supplier out of stock'], $this->headers)->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.portal_state', 'cancelled')->assertJsonPath('data.cancel_reason', 'Supplier out of stock');

    $this->putJson("/api/pda/purchase-orders/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJson(['success' => false, 'code' => 'PO_CANCELLED']);
    $this->postJson("/api/pda/purchase-orders/{$id}/cancel", [], $this->headers)->assertStatus(409)->assertJsonPath('code', 'PO_CANCELLED');

    expect(collect(Pull::changes($this->sync->pull($this->since)))->firstWhere('entity', 'PurchaseOrder')['payload']['status'])->toBe('cancelled')
        ->and(AuditLog::query()->where('action', 'purchase_order.head_office_changed')->count())->toBe(2);
});

test('once the shop\'s till owns the order, edits and cancels are refused with 409 PO_OWNED_BY_TILL', function () {
    $id = $this->postJson('/api/pda/purchase-orders', ($this->body)(['status' => 'sent']), $this->headers)->json('data.id');
    DB::table('purchase_orders')->where('id', $id)->update(['origin_branch_id' => $this->sync->leeds->id, 'status' => 'partReceived']);

    $this->getJson("/api/pda/purchase-orders/{$id}", $this->headers)->assertOk()
        ->assertJsonPath('data.portal_state', 'confirmed')->assertJsonPath('data.editable', false)->assertJsonPath('data.locked_reason', fn ($r) => is_string($r) && $r !== '');
    $this->putJson("/api/pda/purchase-orders/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJson(['success' => false, 'code' => 'PO_OWNED_BY_TILL']);
    $this->postJson("/api/pda/purchase-orders/{$id}/cancel", [], $this->headers)->assertStatus(409)->assertJsonPath('code', 'PO_OWNED_BY_TILL');
    expect(DB::table('purchase_orders')->where('id', $id)->value('status'))->toBe('partReceived');

    // The till's own order is read only too.
    $own = F::row('purchase_orders', $this->company, $this->sync->leeds, ['supplier_id' => F::SUPPLIER, 'number' => 7, 'status' => 'sent', 'origin' => 'branch',
        'order_no' => 'PO-000007', 'branch_code' => 'LDS', 'reference' => 'PO-LDS-000007', 'net_total' => '10.00', 'vat_total' => '0.00', 'gross_total' => '10.00']);
    $this->putJson("/api/pda/purchase-orders/{$own}", ($this->body)(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'PO_OWNED_BY_TILL');
});

test('the list shows this shop\'s orders only (head-office and the till\'s own), newest first', function () {
    $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->assertCreated();
    $this->travel(1)->hours();
    $own = F::row('purchase_orders', $this->company, $this->sync->leeds, ['supplier_id' => F::SUPPLIER, 'number' => 7, 'status' => 'sent', 'origin' => 'branch',
        'order_no' => 'PO-000007', 'branch_code' => 'LDS', 'reference' => 'PO-LDS-000007', 'net_total' => '10.00', 'vat_total' => '0.00', 'gross_total' => '10.00',
        'created_at' => '2026-10-20 10:30:00']);
    $bradford = F::row('purchase_orders', $this->company, $this->sync->bradford, ['supplier_id' => F::SUPPLIER, 'number' => 1, 'status' => 'sent', 'origin' => 'branch',
        'order_no' => 'PO-000001', 'branch_code' => 'BRD', 'reference' => 'PO-BRD-000001', 'net_total' => '1.00', 'vat_total' => '0.00', 'gross_total' => '1.00']);
    $other = H::otherBusiness();

    $this->getJson('/api/pda/purchase-orders', $this->headers)->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.data.0.id', $own)->assertJsonPath('data.data.0.origin', 'branch')->assertJsonPath('data.data.0.portal_state', 'confirmed')
        ->assertJsonPath('data.data.1.reference', 'HO-LDS-000001');
    $this->getJson('/api/pda/purchase-orders?status=draft', $this->headers)->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson('/api/pda/purchase-orders?search=PO-LDS', $this->headers)->assertOk()->assertJsonPath('data.total', 1);

    // Another shop's or business's order is 404 everywhere.
    foreach ([$bradford, $other['order']] as $id) {
        $this->getJson("/api/pda/purchase-orders/{$id}", $this->headers)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->putJson("/api/pda/purchase-orders/{$id}", ($this->body)(), $this->headers)->assertNotFound();
        $this->postJson("/api/pda/purchase-orders/{$id}/cancel", [], $this->headers)->assertNotFound();
    }

    expect(DB::table('purchase_orders')->where('id', $other['order'])->value('status'))->toBe('draft');
});

test('another business\'s supplier or product cannot go on an order; bad bodies are 422 with the app\'s field names', function () {
    $other = H::otherBusiness();

    $this->postJson('/api/pda/purchase-orders', ($this->body)(['supplier_id' => $other['supplier']]), $this->headers)
        ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonStructure(['errors' => ['supplier_id']]);
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['items' => [['product_id' => $other['product'], 'quantity_ordered' => 1]]]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.product_id']]);
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['items' => []]), $this->headers)->assertStatus(422)->assertJsonStructure(['errors' => ['items']]);
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['items' => [['product_id' => F::COLA, 'quantity_ordered' => 1.5]]]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.quantity_ordered']]);

    expect(PurchaseOrder::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(0);
});

test('receiving, receipts, returns and stock adjustments answer 501 NOT_AVAILABLE_YET until phase 2', function () {
    $id = '01K5T0Q8C4000000000000X001';
    $calls = [
        ['post', "/api/pda/purchase-orders/{$id}/receive"], ['get', "/api/pda/purchase-orders/{$id}/receipts"], ['get', "/api/pda/purchase-orders/{$id}/returnable"],
        ['get', '/api/pda/purchase-returns'], ['post', '/api/pda/purchase-returns'], ['put', "/api/pda/purchase-returns/{$id}"], ['post', "/api/pda/purchase-returns/{$id}/cancel"],
        ['get', '/api/pda/stock-adjustments'], ['post', '/api/pda/stock-adjustments'], ['put', "/api/pda/stock-adjustments/{$id}"], ['post', "/api/pda/stock-adjustments/{$id}/cancel"],
    ];

    foreach ($calls as [$method, $url]) {
        $this->json($method, $url, [], $this->headers)->assertStatus(501)
            ->assertJson(['success' => false, 'code' => 'NOT_AVAILABLE_YET'])->assertJsonPath('message', fn (string $m) => str_contains($m, 'phase 2'));
        $this->json($method, $url)->assertUnauthorized();
    }

    $this->getJson('/api/pda/stock-locations', $this->headers)->assertNotFound();
});
