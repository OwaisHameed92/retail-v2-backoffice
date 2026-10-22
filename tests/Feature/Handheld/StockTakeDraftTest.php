<?php

use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\Tenancy\Actions\PurgeCompany;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Handheld\StockDraftFixtures as S;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Handheld stock takes with PDA_STOCK_DRAFTS on (contract proposal pda-stock-drafts §5.2): drafted as the till's own
 * StockTake (origin headOffice, HS- number, status review) with counted lines, replaced while pending, pulled by that
 * shop only, and approved (expected / variance per line) or refused by the till's push.
 */

beforeEach(function () {
    $this->travelTo('2026-10-22 09:00:00');
    S::on();
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    H::monthlyPlan($this->company);
    F::catalogue($this->company);
    $this->headers = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['can_adjust_stock' => true])));
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->ref = '7a1f9c2e-1b2d-4e3f-8a9b-0c1d2e3f4a5b';
    $this->body = fn (array $overrides = []) => ['client_reference' => $this->ref, 'name' => 'Aisle 3 — soft drinks', 'note' => 'Morning count',
        'lines' => [['product_id' => F::COLA, 'counted_quantity' => '34.0000'], ['product_id' => F::WATER, 'counted_quantity' => '0']], ...$overrides];
});

test('create, repeat, show, replace the lines, cancel; 409 STOCK_TAKE_CANCELLED after', function () {
    $id = $this->postJson('/api/pda/stock-takes', ($this->body)(), $this->headers)->assertCreated()
        ->assertJson(['type' => 'stock_take', 'data' => ['reference' => 'HS-LDS-000001', 'name' => 'Aisle 3 — soft drinks', 'portal_state' => 'pending', 'editable' => true,
            'lines' => [['product_id' => F::COLA, 'product_name' => 'Coca-Cola 500ml', 'counted_quantity' => '34.0000', 'expected_quantity' => null, 'variance' => null],
                ['product_id' => F::WATER, 'counted_quantity' => '0.0000']]]])->json('data.id');

    $this->postJson('/api/pda/stock-takes', ($this->body)(['lines' => [['product_id' => F::COLA, 'counted_quantity' => 1]]]), $this->headers)->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonCount(2, 'data.lines');
    $this->getJson("/api/pda/stock-takes/{$id}", $this->headers)->assertOk()->assertJsonPath('data.reference', 'HS-LDS-000001');

    $this->putJson("/api/pda/stock-takes/{$id}", ($this->body)(['lines' => [['product_id' => F::COLA, 'counted_quantity' => 30]]]), $this->headers)->assertOk()
        ->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.counted_quantity', '30.0000');
    $this->postJson("/api/pda/stock-takes/{$id}/cancel", [], $this->headers)->assertOk()->assertJsonPath('data.portal_state', 'cancelled');
    $this->putJson("/api/pda/stock-takes/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'STOCK_TAKE_CANCELLED');

    $second = $this->postJson('/api/pda/stock-takes', ($this->body)(['client_reference' => null]), $this->headers)->assertCreated();
    expect($second->json('data.reference'))->toBe('HS-LDS-000002')->and(S::drafted($this->sync->pull($this->since)))->toHaveCount(3);
});

test('pulled once by its shop as StockTake + lines (schema-valid, header first); 409 STOCK_TAKE_OWNED_BY_TILL after', function () {
    $id = $this->postJson('/api/pda/stock-takes', ($this->body)(), $this->headers)->json('data.id');

    $drafted = S::drafted($this->sync->pull($this->since));
    expect(array_column($drafted, 'entity'))->toBe(['StockTake', 'StockTakeLine', 'StockTakeLine'])
        ->and($drafted[0]['payload'])->toMatchArray(['id' => $id, 'origin' => 'headOffice', 'status' => 'review', 'reference' => 'HS-LDS-000001', 'scope' => 'productList'])
        ->and($drafted[1]['payload'])->toMatchArray(['stockTakeId' => $id, 'productId' => F::COLA, 'countedQty' => 34, 'snapshotQty' => 0, 'isCounted' => true])
        ->and($drafted[2]['version'])->toBeGreaterThan($drafted[1]['version'])->and($drafted[1]['version'])->toBeGreaterThan($drafted[0]['version'])
        ->and(S::drafted($this->sync->pull(0, bradford: true)))->toBe([]);

    $this->putJson("/api/pda/stock-takes/{$id}", ($this->body)(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'STOCK_TAKE_OWNED_BY_TILL');
    $this->postJson("/api/pda/stock-takes/{$id}/cancel", [], $this->headers)->assertStatus(409)->assertJsonPath('code', 'STOCK_TAKE_OWNED_BY_TILL');
});

test('the till approves it: expected and variance per line, confirmed; or cancels it with a reason: rejected', function () {
    $id = $this->postJson('/api/pda/stock-takes', ($this->body)(), $this->headers)->json('data.id');
    $other = $this->postJson('/api/pda/stock-takes', ($this->body)(['client_reference' => null]), $this->headers)->json('data.id');
    [$take, $cola, $water, $take2] = S::drafted($this->sync->pull($this->since));

    S::pushOutcome($this->sync, [
        [$cola, ['snapshotQty' => 35, 'varianceQty' => -1, 'varianceCost' => -0.98, 'unitCost' => 0.98]],
        [$water, ['snapshotQty' => 3, 'varianceQty' => -3, 'varianceCost' => -2.94, 'unitCost' => 0.98]],
        [$take, ['status' => 'approved', 'approvedAt' => '2026-10-22T09:20:00Z', 'isClosed' => true]],
        [$take2, ['status' => 'cancelled', 'cancelledAt' => '2026-10-22T09:21:00Z', 'cancelReason' => 'Counted twice']],
    ])->assertOk()->assertJsonPath('accepted', 4);

    $this->getJson("/api/pda/stock-takes/{$id}", $this->headers)->assertOk()->assertJsonPath('data.portal_state', 'confirmed')
        ->assertJsonPath('data.confirmed_at', '2026-10-22T09:20:00Z')
        ->assertJsonPath('data.lines.0.expected_quantity', '35.0000')->assertJsonPath('data.lines.0.variance', '-1.0000')
        ->assertJsonPath('data.lines.1.expected_quantity', '3.0000')->assertJsonPath('data.lines.1.variance', '-3.0000');
    $this->getJson("/api/pda/stock-takes/{$other}", $this->headers)->assertJsonPath('data.portal_state', 'rejected')->assertJsonPath('data.reject_reason', 'Counted twice');
    expect(S::drafted($this->sync->pull(0)))->toBe([]);
});

test('purging the business removes its stock drafts and their lines', function () {
    $this->postJson('/api/pda/stock-takes', ($this->body)(), $this->headers)->assertCreated();

    app(PurgeCompany::class)->handle($this->company->refresh(), force: true);

    expect(StockDraft::withoutCompanyScope()->count())->toBe(0)->and(DB::table('stock_draft_lines')->count())->toBe(0);
});

test('refusals: permission, a product twice, an unknown or archived product names the line; isolation', function () {
    $plain = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['email' => 'plain@kirkgate.test'])));
    $this->postJson('/api/pda/stock-takes', ($this->body)(), $plain)->assertForbidden()->assertJsonPath('code', 'STOCK_CHANGE_NOT_ALLOWED');
    $this->postJson('/api/pda/stock-takes', ($this->body)(['lines' => [['product_id' => F::COLA, 'counted_quantity' => 1], ['product_id' => F::COLA, 'counted_quantity' => 2]]]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['lines.1.product_id']]);
    $this->postJson('/api/pda/stock-takes', ($this->body)(['lines' => [['product_id' => F::COLA, 'counted_quantity' => -1]]]), $this->headers)->assertStatus(422);
    $this->postJson('/api/pda/stock-takes', ($this->body)(['lines' => [['product_id' => F::COLA, 'counted_quantity' => 1], ['product_id' => H::otherBusiness()['product'], 'counted_quantity' => 1]]]), $this->headers)
        ->assertStatus(422)->assertJsonStructure(['errors' => ['lines.1.product_id']]);
    expect(StockDraft::withoutCompanyScope()->count())->toBe(0);

    $id = $this->postJson('/api/pda/stock-takes', ($this->body)(), $this->headers)->json('data.id');
    $bradford = H::headers(H::token($this, H::user($this->company, $this->sync->bradford, ['email' => 'brd@kirkgate.test', 'can_adjust_stock' => true])));
    $this->getJson("/api/pda/stock-takes/{$id}", $bradford)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    $this->getJson('/api/pda/stock-takes', $bradford)->assertJsonPath('data.total', 0);
});
