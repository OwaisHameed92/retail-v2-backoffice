<?php

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Handheld (PDA) API phase 1: `PUT /api/pda/products/{id}/price`. Only for a user allowed to change prices; the
 * product form's path (SaveProduct), so the new business price is a hub-owned write every till pulls; the product
 * form's price limits (GB 99,999.99, PK 9,999,999.99).
 */

beforeEach(function () {
    $this->travelTo('2026-10-20 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    H::monthlyPlan($this->company);
    F::catalogue($this->company);
    H::catalogueGroups($this->company);
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->sinceBradford = $this->sync->pull(0, bradford: true)->json('highestVersion');
    $this->price = fn (string $token, string $product, mixed $retail) => $this->putJson("/api/pda/products/{$product}/price", ['retail' => $retail], H::headers($token));
    $this->sellPrice = fn (string $id) => DB::table('products')->where('id', $id)->value('sell_price');
});

test('a user without the price permission gets 403 PRICE_CHANGE_NOT_ALLOWED and nothing changes', function () {
    $token = H::token($this, H::user($this->company, $this->sync->leeds));

    ($this->price)($token, F::COLA, 2.49)->assertForbidden()
        ->assertJson(['success' => false, 'code' => 'PRICE_CHANGE_NOT_ALLOWED']);

    expect(($this->sellPrice)(F::COLA))->toEqual('1.45')
        ->and(Pull::changes($this->sync->pull($this->since)))->toBe([]);
});

test('an allowed user changes the business price through the product form\'s path: every till pulls it, audited as them', function () {
    $handheld = H::user($this->company, $this->sync->leeds, ['can_change_prices' => true]);
    $token = H::token($this, $handheld);

    ($this->price)($token, F::COLA, 2.49)->assertOk()->assertJson([
        'success' => true,
        'data' => ['id' => F::COLA, 'product_name' => 'Coca-Cola 500ml', 'previous_retail' => '1.45', 'retail' => '2.49',
            'shop_retail' => '2.49', 'has_shop_price' => false, 'changed' => true, 'is_synced' => false],
    ]);

    $product = Product::withoutCompanyScope()->findOrFail(F::COLA);
    expect($product->sell_price)->toBe('2.49')->and($product->row_version)->toBe(2)->and($product->hub_version)->toBeGreaterThan((int) $this->since);

    // Stamped for the pull (HubOwnedRow → HubVersions): both shops' tills get the new price.
    foreach ([[$this->since, false], [$this->sinceBradford, true]] as [$since, $bradford]) {
        $changes = collect(Pull::changes($this->sync->pull($since, bradford: $bradford)))->where('entity', 'Product')->values();
        expect($changes)->toHaveCount(1)->and($changes[0]['payload']['id'])->toBe(F::COLA)->and((string) $changes[0]['payload']['sellPrice'])->toBe('2.49');
    }

    $audit = AuditLog::query()->where('action', 'product.updated')->sole();
    expect($audit->actor_type)->toBe((new HandheldUser)->getMorphClass())->and($audit->actor_id)->toBe($handheld->id)
        ->and($audit->before)->toBe(['sell_price' => '1.45'])->and($audit->after)->toBe(['sell_price' => '2.49']);
});

test('a shop with its own price keeps it, and the reply says so', function () {
    H::shopPrice($this->company, $this->sync->leeds, F::COLA, '1.35');
    $token = H::token($this, H::user($this->company, $this->sync->leeds, ['can_change_prices' => true]));

    ($this->price)($token, F::COLA, '1.99')->assertOk()
        ->assertJsonPath('data.retail', '1.99')->assertJsonPath('data.shop_retail', '1.35')->assertJsonPath('data.has_shop_price', true)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'own price'));
});

test('GB prices follow the product form: 2 decimals, at most 99,999.99', function () {
    $token = H::token($this, H::user($this->company, $this->sync->leeds, ['can_change_prices' => true]));

    ($this->price)($token, F::COLA, '99999.99')->assertOk();
    expect(($this->sellPrice)(F::COLA))->toEqual('99999.99');

    foreach (['100000.00', '1.999', '-1', 'abc', '1e3', ''] as $bad) {
        ($this->price)($token, F::COLA, $bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonStructure(['errors' => ['retail']]);
    }

    expect(($this->sellPrice)(F::COLA))->toEqual('99999.99');
});

test('PK prices go up to 9,999,999.99', function () {
    CountryModulesFixtures::pakistan();
    $token = H::token($this, H::user($this->company, $this->sync->leeds, ['can_change_prices' => true]));

    ($this->price)($token, F::COLA, '9999999.99')->assertOk()->assertJsonPath('data.retail', '9999999.99');
    ($this->price)($token, F::COLA, '10000000.00')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    expect(($this->sellPrice)(F::COLA))->toEqual('9999999.99');
})->group('country-pk');

test('another business\'s product is 404 and an archived product is 422 PRODUCT_ARCHIVED', function () {
    $other = H::otherBusiness();
    $token = H::token($this, H::user($this->company, $this->sync->leeds, ['can_change_prices' => true]));

    ($this->price)($token, $other['product'], '9.99')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    expect(DB::table('products')->where('id', $other['product'])->value('sell_price'))->toEqual('1.45');

    DB::table('products')->where('id', F::WATER)->update(['is_active' => false]);
    ($this->price)($token, F::WATER, '9.99')->assertStatus(422)->assertJsonPath('code', 'PRODUCT_ARCHIVED');
    ($this->price)($token, 'not-a-ulid', '9.99')->assertNotFound();
});
