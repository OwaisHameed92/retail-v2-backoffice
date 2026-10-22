<?php

use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\TillData\Models\Product;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Handheld\StockDraftFixtures as S;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Handheld stock adjustments with PDA_STOCK_DRAFTS on (contract proposal pda-stock-drafts §5.1): a
 * StockAdjustmentDraft for the user's shop, idempotent per client_reference, editable until its till pulls it, sent
 * only in that shop's pull, and confirmed / rejected by the till's push.
 */

beforeEach(function () {
    $this->travelTo('2026-10-22 09:00:00');
    S::on();
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    H::monthlyPlan($this->company);
    F::catalogue($this->company);
    H::stock($this->company, $this->sync->leeds, F::COLA, '36');
    $this->handheld = H::user($this->company, $this->sync->leeds, ['can_adjust_stock' => true]);
    $this->headers = H::headers(H::token($this, $this->handheld));
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->sinceBradford = $this->sync->pull(0, bradford: true)->json('highestVersion');
    $this->ref = '3f2c9a5e-5b1d-4d0e-9a51-3c2f9e8b7a10';
    $this->body = fn (array $overrides = []) => ['client_reference' => $this->ref, 'product_id' => F::COLA, 'adjustment_type' => 'stockDecrease',
        'quantity' => '2.0000', 'unit_cost' => null, 'reason' => 'Damaged', ...$overrides];
});

test('create answers 201 with the pending adjustment; a repeat answers 200 with the same one and makes nothing', function () {
    $first = $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $this->headers)->assertCreated()
        ->assertJson(['success' => true, 'type' => 'stock_adjustment', 'data' => [
            'client_reference' => $this->ref, 'product_id' => F::COLA, 'product_name' => 'Coca-Cola 500ml', 'adjustment_type' => 'stockDecrease',
            'quantity' => '2.0000', 'unit_cost' => null, 'reason' => 'Damaged', 'reason_code' => 'damaged', 'status' => 'pending',
            'portal_state' => 'pending', 'editable' => true, 'locked_reason' => null, 'stock_on_hand_before' => '36.0000',
        ]]);

    $this->postJson('/api/pda/stock-adjustments', ($this->body)(['quantity' => 9]), $this->headers)->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.quantity', '2.0000')
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Already saved'));

    expect(StockDraft::withoutCompanyScope()->count())->toBe(1);
    $this->getJson('/api/pda/stock-adjustments?client_reference='.$this->ref, $this->headers)->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson('/api/pda/stock-adjustments?product_id='.F::WATER, $this->headers)->assertOk()->assertJsonPath('data.total', 0);
});

test('editing while pending, then cancel; a cancelled adjustment answers 409 ADJUSTMENT_CANCELLED and never reaches a till', function () {
    $id = $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $this->headers)->json('data.id');

    $this->putJson("/api/pda/stock-adjustments/{$id}", ($this->body)(['adjustment_type' => 'stockIncrease', 'quantity' => 6, 'reason' => null]), $this->headers)
        ->assertOk()->assertJsonPath('data.adjustment_type', 'stockIncrease')->assertJsonPath('data.unit_cost', '0.9800')->assertJsonPath('data.quantity', '6.0000');
    $this->postJson("/api/pda/stock-adjustments/{$id}/cancel", ['reason' => 'Typo'], $this->headers)->assertOk()
        ->assertJsonPath('data.portal_state', 'cancelled')->assertJsonPath('data.cancel_reason', 'Typo')->assertJsonPath('data.editable', false);

    $this->putJson("/api/pda/stock-adjustments/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'ADJUSTMENT_CANCELLED');
    $this->postJson("/api/pda/stock-adjustments/{$id}/cancel", [], $this->headers)->assertStatus(409)->assertJsonPath('code', 'ADJUSTMENT_CANCELLED');
    expect(S::drafted($this->sync->pull($this->since)))->toBe([]);
});

test('the shop\'s pull carries it once (schema-valid); then it is the till\'s: edit and cancel answer 409 ADJUSTMENT_OWNED_BY_TILL', function () {
    $id = $this->postJson('/api/pda/stock-adjustments', ($this->body)(['reason' => 'Dropped the crate']), $this->headers)->json('data.id');

    $drafted = S::drafted($leeds = $this->sync->pull($this->since));
    expect($drafted)->toHaveCount(1)
        ->and($drafted[0])->toMatchArray(['entity' => 'StockAdjustmentDraft', 'entityId' => $id, 'op' => 'I', 'branchId' => '01K5T0Q8C4000000000000B001'])
        ->and($drafted[0]['payload'])->toMatchArray(['productId' => F::COLA, 'type' => 'stockDecrease', 'quantity' => 2, 'reason' => 'other',
            'note' => 'Dropped the crate', 'status' => 'pending', 'enteredBy' => 'Stock room 1 (handheld)', 'rowVersion' => 0, 'qtyBefore' => null])
        ->and(S::drafted($this->sync->pull($this->sinceBradford, bradford: true)))->toBe([]);

    $this->putJson("/api/pda/stock-adjustments/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'ADJUSTMENT_OWNED_BY_TILL');
    $this->postJson("/api/pda/stock-adjustments/{$id}/cancel", [], $this->headers)->assertStatus(409)->assertJsonPath('code', 'ADJUSTMENT_OWNED_BY_TILL');
    $this->getJson('/api/pda/stock-adjustments', $this->headers)->assertJsonPath('data.data.0.status', 'withTill')
        ->assertJsonPath('data.data.0.portal_state', 'pending')->assertJsonPath('data.data.0.editable', false);

    // A repeated page (the till's reply was lost) carries the same row again; the next pull carries nothing new.
    expect(S::drafted($this->sync->pull($this->since)))->toBe($drafted)
        ->and(S::drafted($this->sync->pull($leeds->json('highestVersion'))))->toBe([]);
});

test('the till\'s push confirms it (quantities, movement) or rejects it; a decided adjustment is never sent again', function () {
    $id = $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $this->headers)->json('data.id');
    $other = $this->postJson('/api/pda/stock-adjustments', ($this->body)(['client_reference' => null, 'adjustment_type' => 'stockCount', 'quantity' => '0']), $this->headers)->json('data.id');
    [$first, $second] = S::drafted($this->sync->pull($this->since));

    S::pushOutcome($this->sync, [
        [$first, ['status' => 'confirmed', 'confirmedAt' => '2026-10-22T09:20:00Z', 'qtyBefore' => 36, 'qtyDelta' => -2, 'qtyAfter' => 34, 'stockMovementId' => '01K80B0J000000000000SM0001']],
        [$second, ['status' => 'rejected', 'rejectedAt' => '2026-10-22T09:20:00Z', 'rejectReason' => 'Coca-Cola 500ml is archived on this till.']],
    ])->assertOk()->assertJsonPath('accepted', 2);

    $this->getJson('/api/pda/stock-adjustments', $this->headers)->assertOk()
        ->assertJsonPath('data.data.1.id', $id)->assertJsonPath('data.data.1.portal_state', 'confirmed')
        ->assertJsonPath('data.data.1.quantity_before', '36.0000')->assertJsonPath('data.data.1.quantity_change', '-2.0000')
        ->assertJsonPath('data.data.1.quantity_after', '34.0000')->assertJsonPath('data.data.1.confirmed_at', '2026-10-22T09:20:00Z')
        ->assertJsonPath('data.data.0.id', $other)->assertJsonPath('data.data.0.portal_state', 'rejected')
        ->assertJsonPath('data.data.0.reject_reason', 'Coca-Cola 500ml is archived on this till.');

    expect(S::drafted($this->sync->pull(0)))->toBe([]);
    $this->putJson("/api/pda/stock-adjustments/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'ADJUSTMENT_OWNED_BY_TILL');
});

test('a push from another shop never decides this shop\'s adjustment', function () {
    $id = $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $this->headers)->json('data.id');
    [$pulled] = S::drafted($this->sync->pull($this->since));

    $this->sync->push([[...$pulled, 'seq' => 1, 'op' => 'U', 'version' => 1, 'branchId' => '01K5T0Q8C4000000000000B002', 'key' => "StockAdjustmentDraft:{$id}:1",
        'payload' => [...$pulled['payload'], 'status' => 'confirmed', 'rowVersion' => 1, 'branchId' => '01K5T0Q8C4000000000000B002']]], bradford: true)->assertOk();

    $this->getJson('/api/pda/stock-adjustments', $this->headers)->assertJsonPath('data.data.0.status', 'withTill');
});

test('refusals: permission 403, archived, not stock tracked, reason for a decrease, quantity, unknown product, other unit', function () {
    $noStock = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['email' => 'nostock@kirkgate.test'])));
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $noStock)->assertForbidden()->assertJsonPath('code', 'STOCK_CHANGE_NOT_ALLOWED');
    $this->getJson('/api/pda/stock-adjustments', $noStock)->assertOk();

    $this->postJson('/api/pda/stock-adjustments', ($this->body)(['reason' => null]), $this->headers)->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(['quantity' => 0]), $this->headers)->assertStatus(422)->assertJsonStructure(['errors' => ['quantity']]);
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(['quantity' => '1.23456']), $this->headers)->assertStatus(422)->assertJsonStructure(['errors' => ['quantity']]);
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(['adjustment_type' => 'locationMove']), $this->headers)->assertStatus(422);
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(['product_id' => H::otherBusiness()['product']]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['product_id']]);
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(['product_unit_id' => '01K5T0Q8C4000000000000X001']), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['product_unit_id']]);

    Product::query()->withoutGlobalScopes()->whereKey(F::COLA)->update(['track_stock' => false]);
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $this->headers)->assertStatus(422)->assertJsonPath('code', 'NOT_STOCK_TRACKED')
        ->assertJsonStructure(['errors' => ['product_id']]);
    Product::query()->withoutGlobalScopes()->whereKey(F::COLA)->update(['track_stock' => true, 'is_active' => false]);
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $this->headers)->assertStatus(422)->assertJsonPath('code', 'PRODUCT_ARCHIVED');

    expect(StockDraft::withoutCompanyScope()->count())->toBe(0);
});

test('shop and business isolation: another shop\'s adjustment is not found, its list is its own, the reference is per shop', function () {
    $id = $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $this->headers)->json('data.id');
    $bradford = H::headers(H::token($this, H::user($this->company, $this->sync->bradford, ['email' => 'brd@kirkgate.test', 'can_adjust_stock' => true])));

    $this->getJson('/api/pda/stock-adjustments', $bradford)->assertOk()->assertJsonPath('data.total', 0);
    $this->putJson("/api/pda/stock-adjustments/{$id}", ($this->body)(), $bradford)->assertNotFound();
    $this->postJson("/api/pda/stock-adjustments/{$id}/cancel", [], $bradford)->assertNotFound();
    $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $bradford)->assertCreated();

    $other = H::otherBusiness();
    H::monthlyPlan($other['company']);
    $outsider = H::headers(H::token($this, H::user($other['company'], $other['shop'], ['email' => 'out@other.test', 'can_adjust_stock' => true])));
    $this->postJson("/api/pda/stock-adjustments/{$id}/cancel", [], $outsider)->assertNotFound();
    $this->getJson('/api/pda/stock-adjustments', $outsider)->assertJsonPath('data.total', 0);
    expect(StockDraft::withoutCompanyScope()->count())->toBe(2);
});

test('race-safe: a create that loses the unique index to a parallel request answers the winner\'s adjustment', function () {
    $winner = $this->postJson('/api/pda/stock-adjustments', ($this->body)(['client_reference' => '11111111-2222-4333-8444-555555555555']), $this->headers)->json('data.id');
    $fired = false;
    $checks = 0;

    // The parallel request commits the same reference just after this request's last check found none, so this
    // request's insert hits the unique index.
    DB::listen(function (QueryExecuted $query) use (&$fired, &$checks, $winner) {
        if ($fired || ! str_starts_with($query->sql, 'select') || ! preg_match('/from [`"]stock_drafts[`"].*client_reference/', $query->sql) || ++$checks < 2) {
            return;
        }

        $fired = true;
        DB::table('stock_drafts')->where('id', $winner)->update(['client_reference' => $this->ref]);
    });

    $this->postJson('/api/pda/stock-adjustments', ($this->body)(), $this->headers)->assertOk()->assertJsonPath('data.id', $winner);
    expect($fired)->toBeTrue()->and(StockDraft::withoutCompanyScope()->count())->toBe(1);
});

test('flag on, the purchase order view fills quantity_returned and total_returned', function () {
    $id = $this->postJson('/api/pda/purchase-orders', ['supplier_id' => F::SUPPLIER, 'items' => [['product_id' => F::COLA, 'quantity_ordered' => 6]]], $this->headers)->json('data.id');

    $this->getJson("/api/pda/purchase-orders/{$id}", $this->headers)->assertOk()->assertJsonPath('data.total_returned', '0.0000')
        ->assertJsonPath('data.items.0.quantity_returned', '0.0000')->assertJsonPath('data.items.0.quantity_pending', '0.0000');
    expect(Pull::changes($this->sync->pull($this->since)))->not->toBe([]);
});
