<?php

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\PortalUsers\PortalUsersHelpers as P;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Handheld (PDA) API phase 2: `PUT /api/pda/products/{id}/cost` (the product form's path, `can_change_cost`), the new
 * `can_change_cost` / `can_adjust_stock` permissions (login, me, the portal screen) and plan gating of the new calls.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-10-22 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->plan = H::monthlyPlan($this->company);
    F::catalogue($this->company);
    H::catalogueGroups($this->company);
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->cost = fn (string $token, mixed $cost, string $product = F::COLA) => $this->putJson("/api/pda/products/{$product}/cost", ['cost' => $cost], H::headers($token));
    $this->costOf = fn (string $id) => DB::table('products')->where('id', $id)->value('cost_price');
});

test('without can_change_cost the cost is refused with 403 COST_CHANGE_NOT_ALLOWED, even for a price changer', function () {
    $token = H::token($this, H::user($this->company, $this->sync->leeds, ['can_change_prices' => true]));

    ($this->cost)($token, '0.9100')->assertForbidden()->assertJson(['success' => false, 'code' => 'COST_CHANGE_NOT_ALLOWED']);
    expect(($this->costOf)(F::COLA))->toEqual('0.9800')->and(Pull::changes($this->sync->pull($this->since)))->toBe([]);
});

test('an allowed user changes the cost through the product form\'s path: every till pulls it, audited as them', function () {
    $handheld = H::user($this->company, $this->sync->leeds, ['can_change_cost' => true]);
    $token = H::token($this, $handheld);

    ($this->cost)($token, '0.9125')->assertOk()->assertJson(['success' => true, 'type' => 'product_cost', 'data' => [
        'id' => F::COLA, 'product_name' => 'Coca-Cola 500ml', 'previous_cost' => '0.9800', 'cost' => '0.9125', 'changed' => true, 'is_synced' => false,
    ]]);

    $product = Product::withoutCompanyScope()->findOrFail(F::COLA);
    expect($product->cost_price)->toEqual('0.9125')->and($product->sell_price)->toBe('1.45');

    foreach ([false, true] as $bradford) {
        $changes = collect(Pull::changes($this->sync->pull($this->since, bradford: $bradford)))->where('entity', 'Product')->values();
        expect($changes)->toHaveCount(1)->and((string) $changes[0]['payload']['costPrice'])->toBe('0.9125');
    }

    $audit = AuditLog::query()->where('action', 'product.updated')->sole();
    expect($audit->actor_type)->toBe((new HandheldUser)->getMorphClass())->and($audit->actor_id)->toBe($handheld->id)
        ->and($audit->before)->toBe(['cost_price' => '0.9800'])->and($audit->after)->toBe(['cost_price' => '0.9125']);

    ($this->cost)($token, 0.9125)->assertOk()->assertJsonPath('data.changed', false);
});

test('cost format: up to 4 decimals and 8 whole digits; archived 422; another business 404', function () {
    $token = H::token($this, H::user($this->company, $this->sync->leeds, ['can_change_cost' => true]));

    ($this->cost)($token, '99999999.9999')->assertOk();

    foreach (['1.23456', '-1', 'abc', '1e3', '', '123456789'] as $bad) {
        ($this->cost)($token, $bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonStructure(['errors' => ['cost']]);
    }

    ($this->cost)($token, '1.00', H::otherBusiness()['product'])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    DB::table('products')->where('id', F::WATER)->update(['is_active' => false]);
    ($this->cost)($token, '1.00', F::WATER)->assertStatus(422)->assertJsonPath('code', 'PRODUCT_ARCHIVED');
});

test('login and me return can_change_cost and can_adjust_stock', function () {
    $user = H::user($this->company, $this->sync->leeds, ['can_change_cost' => true]);

    $login = H::login($this, ['email' => $user->email, 'password' => H::PASSWORD])->assertOk()
        ->assertJsonPath('user.can_change_prices', false)->assertJsonPath('user.can_change_cost', true)->assertJsonPath('user.can_adjust_stock', false);

    $user->forceFill(['can_adjust_stock' => true])->save();
    $this->getJson('/api/pda/me', H::headers($login->json('token')))->assertOk()
        ->assertJsonPath('user.can_change_cost', true)->assertJsonPath('user.can_adjust_stock', true);
});

test('the stock calls stay 501 for a user who may adjust stock', function () {
    $headers = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['can_adjust_stock' => true])));

    foreach ([['POST', '/api/pda/stock-adjustments'], ['GET', '/api/pda/stock-takes'], ['POST', '/api/pda/stock-takes']] as [$method, $url]) {
        $this->json($method, $url, [], $headers)->assertStatus(501)->assertJsonPath('code', 'NOT_AVAILABLE_YET');
    }
});

test('the portal screen sets both new permissions, off by default', function () {
    $owner = P::member($this->company, CompanyRole::Owner);
    $body = ['name' => 'Stock room 2', 'email' => 'stock2@kirkgate.test', 'branch_id' => $this->sync->leeds->id, 'can_change_prices' => false,
        'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];

    $this->actingAs($owner)->post('/app/handheld-users', $body)->assertSessionHasNoErrors();
    $added = HandheldUser::withoutCompanyScope()->where('email', 'stock2@kirkgate.test')->sole();
    expect($added->can_change_cost)->toBeFalse()->and($added->can_adjust_stock)->toBeFalse();

    $this->actingAs($owner)->put("/app/handheld-users/{$added->id}", [...$body, 'can_change_cost' => true, 'can_adjust_stock' => true])->assertSessionHas('success');
    expect($added->fresh())->can_change_cost->toBeTrue()->can_adjust_stock->toBeTrue()
        ->and(AuditLog::query()->where('action', 'handheld_user.updated')->sole()->after)->toBe(['can_change_cost' => true, 'can_adjust_stock' => true]);

    $this->actingAs($owner)->get('/app/handheld-users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('users.0.canChangeCost', true)->where('users.0.canAdjustStock', true));
});

test('the new calls follow plan gating: 403 PLAN_NOT_INCLUDED off a monthly plan', function () {
    $handheld = H::user($this->company, $this->sync->leeds, ['can_change_prices' => true, 'can_change_cost' => true]);
    $token = H::token($this, $handheld);
    $this->plan->forceFill(['includes_handheld' => false])->saveQuietly();

    foreach ([['/api/pda/products/'.F::COLA.'/shop-price', ['retail' => '1.00']], ['/api/pda/products/'.F::COLA.'/cost', ['cost' => '1.00']],
        ['/api/pda/products/'.F::COLA.'/units/'.F::WATER.'/price', ['retail_price' => '1.00']]] as [$url, $body]) {
        $this->putJson($url, $body, H::headers($token))->assertForbidden()->assertJsonPath('code', 'PLAN_NOT_INCLUDED');
    }

    $this->postJson('/api/pda/purchase-orders', ['client_reference' => '3f2c9a5e-5b1d-4d0e-9a51-3c2f9e8b7a10', 'supplier_id' => F::SUPPLIER,
        'items' => [['product_id' => F::COLA, 'quantity_ordered' => 1]]], H::headers($token))->assertForbidden()->assertJsonPath('code', 'PLAN_NOT_INCLUDED');

    expect(($this->costOf)(F::COLA))->toEqual('0.9800')->and(DB::table('branch_prices')->count())->toBe(0)
        ->and(DB::table('handheld_order_references')->count())->toBe(0);
});
