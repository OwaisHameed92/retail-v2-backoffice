<?php

use App\Domain\Handheld\Models\HandheldOrderReference;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Handheld (PDA) API phase 2 on purchase orders: `client_reference` idempotency (unique per business + shop, a repeat
 * answers 200 with the order already made, race-safe on the unique index, echoed, list filter) and decimal quantities
 * for weighed (kg) products (clarification 1).
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-10-22 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    H::monthlyPlan($this->company);
    F::catalogue($this->company);
    $this->handheld = H::user($this->company, $this->sync->leeds);
    $this->headers = H::headers(H::token($this, $this->handheld));
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->ref = '3f2c9a5e-5b1d-4d0e-9a51-3c2f9e8b7a10';
    $this->body = fn (array $overrides = []) => ['client_reference' => $this->ref, 'supplier_id' => F::SUPPLIER,
        'items' => [['product_id' => F::COLA, 'quantity_ordered' => 48, 'unit_cost' => '0.78']], ...$overrides];
    $this->orders = fn () => PurchaseOrder::withoutCompanyScope()->where('company_id', $this->company->id)->where('origin', 'headOffice');
});

test('a repeated client_reference answers 200 with the same order and drafts nothing new', function () {
    $first = $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->assertCreated()
        ->assertJsonPath('data.client_reference', $this->ref)->assertJsonPath('data.reference', 'HO-LDS-000001');

    // A retry after a lost reply, even with a changed body (the first order stands as it is).
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['client_reference' => strtoupper($this->ref), 'notes' => 'retry']), $this->headers)->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.client_reference', $this->ref)->assertJsonPath('data.notes', null)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Already saved'));

    expect(($this->orders)()->count())->toBe(1)->and(HandheldOrderReference::withoutCompanyScope()->count())->toBe(1)
        ->and(collect(Pull::changes($this->sync->pull($this->since)))->where('entity', 'PurchaseOrder'))->toHaveCount(1);

    // Edited or cancelled since: the repeat answers the order as it is now.
    $this->postJson('/api/pda/purchase-orders/'.$first->json('data.id').'/cancel', [], $this->headers)->assertOk();
    $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->assertOk()->assertJsonPath('data.status', 'cancelled');

    // Without a reference every create is a new order.
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['client_reference' => null]), $this->headers)->assertCreated()->assertJsonPath('data.client_reference', null);
    expect(($this->orders)()->count())->toBe(2);
});

test('the reference is per shop: another shop\'s handheld drafts its own order with the same reference', function () {
    $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->assertCreated();
    $bradford = H::headers(H::token($this, H::user($this->company, $this->sync->bradford, ['email' => 'bradford@kirkgate.test'])));

    $this->postJson('/api/pda/purchase-orders', ($this->body)(), $bradford)->assertCreated()->assertJsonPath('data.reference', 'HO-BRD-000001');
    expect(($this->orders)()->count())->toBe(2);
});

test('race-safe: a create that loses the unique index to a parallel request answers the winner\'s order and keeps nothing of its own', function () {
    $winner = $this->postJson('/api/pda/purchase-orders', ($this->body)(['client_reference' => '11111111-2222-4333-8444-555555555555']), $this->headers)->json('data.id');

    // The parallel request commits the same reference just after this request's check found none (outside its
    // transaction), so this request's insert hits the unique index.
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$fired, $winner) {
        if ($fired || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'from "handheld_order_references"')) {
            return;
        }

        $fired = true;
        DB::table('handheld_order_references')->insert(['id' => Ulid::new(), 'company_id' => $this->company->id, 'branch_id' => $this->sync->leeds->id,
            'client_reference' => $this->ref, 'purchase_order_id' => $winner, 'created_at' => now(), 'updated_at' => now()]);
    });

    $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->assertOk()->assertJsonPath('data.id', $winner);
    expect($fired)->toBeTrue()->and(($this->orders)()->count())->toBe(1)
        ->and(HandheldOrderReference::withoutCompanyScope()->where('client_reference', $this->ref)->count())->toBe(1);
});

test('a refused create keeps no reference, so the retry can succeed; client_reference must be a UUID; edits ignore it', function () {
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['supplier_id' => Ulid::new()]), $this->headers)->assertStatus(422);
    expect(HandheldOrderReference::withoutCompanyScope()->count())->toBe(0);

    $id = $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->assertCreated()->json('data.id');
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['client_reference' => 'not-a-uuid']), $this->headers)->assertStatus(422)
        ->assertJsonStructure(['errors' => ['client_reference']]);

    $this->putJson("/api/pda/purchase-orders/{$id}", ($this->body)(['client_reference' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']), $this->headers)->assertOk()
        ->assertJsonPath('data.client_reference', $this->ref);
});

test('the list filters on client_reference within this shop', function () {
    $id = $this->postJson('/api/pda/purchase-orders', ($this->body)(), $this->headers)->json('data.id');
    $this->postJson('/api/pda/purchase-orders', ($this->body)(['client_reference' => '11111111-2222-4333-8444-555555555555']), $this->headers)->assertCreated();

    $this->getJson('/api/pda/purchase-orders?client_reference='.$this->ref, $this->headers)->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $id)->assertJsonPath('data.data.0.client_reference', $this->ref);
    $this->getJson('/api/pda/purchase-orders?client_reference=99999999-2222-4333-8444-555555555555', $this->headers)->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson('/api/pda/purchase-orders', $this->headers)->assertOk()->assertJsonPath('data.total', 2);
});

test('weighed (kg) products take up to 3 decimals: loose kg on the line, as the till orders; the portal form and a cancel keep them', function () {
    DB::table('products')->where('id', F::WATER)->update(['unit_type' => 'kg', 'unit_code' => 'KG']);

    $reply = $this->postJson('/api/pda/purchase-orders', ($this->body)(['items' => [
        ['product_id' => F::WATER, 'quantity_ordered' => 2.5, 'unit_cost' => '4.00'],
        ['product_id' => F::COLA, 'quantity_ordered' => 12],
    ]]), $this->headers)->assertCreated()->assertJsonPath('data.items.0.quantity_ordered', '2.5000')->assertJsonPath('data.items.0.cases_ordered', 0)
        ->assertJsonPath('data.items.0.case_qty', 1)->assertJsonPath('data.items.0.line_net', '10.00')->assertJsonPath('data.total_ordered', '14.5000');

    $line = collect(Pull::changes($this->sync->pull($this->since)))->where('entity', 'PurchaseOrderLine')->firstWhere('payload.productId', F::WATER);
    expect($line['payload'])->toMatchArray(['orderedCases' => 0, 'caseQtySnapshot' => 1])->and((string) $line['payload']['orderedUnits'])->toBe('2.5');

    $id = $reply->json('data.id');
    $owner = F::member($this->company, CompanyRole::Owner);
    $this->actingAs($owner)->get("/app/purchasing/orders/{$id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.lines.0.looseUnits', 2.5)->where('order.lines.0.orderedCases', 0)->where('order.lines.1.looseUnits', 0));

    // Saved again from the portal form (whole and weighed lines), nothing is rounded away.
    $input = F::orderInput(null, 'draft', ['lines' => [
        ['productId' => F::WATER, 'orderedCases' => 0, 'caseQty' => 1, 'looseUnits' => 2.75, 'unitCost' => '4.00', 'vatRateId' => F::VAT_ZERO],
        ['productId' => F::COLA, 'orderedCases' => 12, 'caseQty' => 1, 'looseUnits' => 0, 'unitCost' => '0.78', 'vatRateId' => F::VAT_STD],
    ]]);
    $this->actingAs($owner)->put("/app/purchasing/orders/{$id}", $input)->assertSessionHasNoErrors();
    $this->actingAs($owner)->put("/app/purchasing/orders/{$id}", [...$input, 'lines' => [[...$input['lines'][0], 'looseUnits' => '2.7505']]])->assertSessionHasErrors('lines.0.looseUnits');

    $this->postJson("/api/pda/purchase-orders/{$id}/cancel", [], $this->headers)->assertOk();
    expect(PurchaseOrderLine::withoutCompanyScope()->where('purchase_order_id', $id)->where('product_id', F::WATER)->value('ordered_units'))->toEqual('2.7500');
});

test('decimals are refused for counted products, with a case size, or past 3 places', function () {
    DB::table('products')->where('id', F::WATER)->update(['unit_type' => 'kg', 'unit_code' => 'KG']);
    $create = fn (array $item) => $this->postJson('/api/pda/purchase-orders', ($this->body)(['client_reference' => null, 'items' => [$item]]), $this->headers);

    $create(['product_id' => F::COLA, 'quantity_ordered' => 1.5])->assertStatus(422)->assertJsonPath('errors', fn ($e) => isset($e['items.0.quantity_ordered']));
    $create(['product_id' => F::WATER, 'quantity_ordered' => 1.5, 'conversion_factor' => 12])->assertStatus(422)->assertJsonPath('errors', fn ($e) => isset($e['items.0.conversion_factor']));
    $create(['product_id' => F::WATER, 'quantity_ordered' => '1.2345'])->assertStatus(422)->assertJsonPath('errors', fn ($e) => isset($e['items.0.quantity_ordered']));
    $create(['product_id' => F::WATER, 'quantity_ordered' => 0])->assertStatus(422);

    expect(($this->orders)()->count())->toBe(0);
});
