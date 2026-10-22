<?php

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Ulid;
use App\Domain\TillData\Models\BranchPrice;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Handheld (PDA) API phase 2: `PUT /api/pda/products/{id}/shop-price` and `…/units/{product_unit_id}/price`. The
 * portal shop price screen's actions (a new BranchPrice row pulled by that shop only; null ends it) and the product
 * form's path for a unit's business price; `can_change_prices` only; the product form's price limits.
 */

beforeEach(function () {
    $this->travelTo('2026-10-22 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    H::monthlyPlan($this->company);
    F::catalogue($this->company);
    H::catalogueGroups($this->company);

    $this->case = Ulid::new();
    $this->colaCase = Ulid::new();
    Pull::portalCreate($this->company, 'Unit', Pull::payload('Unit', $this->case, ['code' => 'CASE', 'name' => 'Case', 'isActive' => true, 'decimalsAllowed' => false, 'decimalPlaces' => 0]));
    Pull::portalCreate($this->company, 'ProductUnit', Pull::payload('ProductUnit', $this->colaCase, [
        'productId' => F::COLA, 'unitId' => $this->case, 'conversionFactor' => 24, 'sellPriceIncVat' => 30.0, 'cost' => 18.72,
        'isDefaultSellUnit' => false, 'isPurchaseUnit' => true, 'isDefaultPurchaseUnit' => true, 'position' => 0,
    ]));

    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->sinceBradford = $this->sync->pull(0, bradford: true)->json('highestVersion');
    $this->allowed = H::user($this->company, $this->sync->leeds, ['can_change_prices' => true]);
    $this->headers = H::headers(H::token($this, $this->allowed));
    $this->shopPrice = fn (mixed $retail, string $product = F::COLA, ?array $headers = null) => $this->putJson("/api/pda/products/{$product}/shop-price", ['retail' => $retail], $headers ?? $this->headers);
    $this->unitPrice = fn (array $body, ?string $unit = null, string $product = F::COLA) => $this->putJson("/api/pda/products/{$product}/units/".($unit ?? $this->colaCase).'/price', $body, $this->headers);
    $this->entities = fn ($reply, string $entity) => collect(Pull::changes($reply))->where('entity', $entity)->values();
});

test('a user without the price permission gets 403 on shop and unit prices; nothing is written', function () {
    $headers = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['email' => 'look@kirkgate.test'])));

    ($this->shopPrice)('1.35', headers: $headers)->assertForbidden()->assertJsonPath('code', 'PRICE_CHANGE_NOT_ALLOWED');
    $this->putJson('/api/pda/products/'.F::COLA."/units/{$this->colaCase}/price", ['retail_price' => '29.00'], $headers)
        ->assertForbidden()->assertJsonPath('code', 'PRICE_CHANGE_NOT_ALLOWED');
    $this->putJson('/api/pda/products/'.F::COLA."/units/{$this->colaCase}/price", ['retail_price' => '29.00', 'scope' => 'shop'], $headers)
        ->assertForbidden()->assertJsonPath('code', 'PRICE_CHANGE_NOT_ALLOWED');

    expect(BranchPrice::withoutCompanyScope()->count())->toBe(0)
        ->and(DB::table('product_units')->where('id', $this->colaCase)->value('sell_price_inc_vat'))->toEqual('30.00');
});

test('a shop price is a new BranchPrice through the portal\'s action: only this shop\'s till pulls it, audited as the handheld user', function () {
    ($this->shopPrice)('1.35')->assertOk()->assertJson(['success' => true, 'type' => 'shop_price', 'data' => [
        'id' => F::COLA, 'product_name' => 'Coca-Cola 500ml', 'previous_shop_retail' => '1.45', 'shop_retail' => '1.35',
        'business_retail' => '1.45', 'has_shop_price' => true, 'changed' => true, 'is_synced' => false,
    ]]);

    $row = BranchPrice::withoutCompanyScope()->sole();
    expect($row->branch_id)->toBe($this->sync->leeds->id)->and($row->product_unit_id)->toBeNull()->and($row->price)->toBe('1.35')
        ->and(DB::table('products')->where('id', F::COLA)->value('sell_price'))->toEqual('1.45');

    $leeds = ($this->entities)($this->sync->pull($this->since), 'BranchPrice');
    expect($leeds)->toHaveCount(1)->and($leeds[0]['payload']['productId'])->toBe(F::COLA)->and((string) $leeds[0]['payload']['price'])->toBe('1.35')
        ->and(($this->entities)($this->sync->pull($this->sinceBradford, bradford: true), 'BranchPrice'))->toHaveCount(0);

    $audit = AuditLog::query()->where('action', 'price.shop_set')->sole();
    expect($audit->actor_type)->toBe((new HandheldUser)->getMorphClass())->and($audit->actor_id)->toBe($this->allowed->id);

    // The products list now shows this shop's price.
    $this->getJson('/api/pda/products?barcode=&search=Coca', $this->headers)->assertOk()
        ->assertJsonPath('data.data.0.retail', '1.35')->assertJsonPath('data.data.0.has_shop_price', true);
});

test('the same shop price again writes nothing; a new one supersedes it', function () {
    ($this->shopPrice)('1.35')->assertOk();
    ($this->shopPrice)(1.35)->assertOk()->assertJsonPath('data.changed', false)->assertJsonPath('data.previous_shop_retail', '1.35');
    expect(BranchPrice::withoutCompanyScope()->count())->toBe(1);

    $this->travel(5)->minutes();
    ($this->shopPrice)('1.29')->assertOk()->assertJsonPath('data.previous_shop_retail', '1.35')->assertJsonPath('data.shop_retail', '1.29');
    expect(BranchPrice::withoutCompanyScope()->count())->toBe(2);
});

test('null removes the shop\'s own price: the shop sells at the business price; only its till is sent the end', function () {
    H::shopPrice($this->company, $this->sync->leeds, F::COLA, '1.20');
    H::shopPrice($this->company, $this->sync->bradford, F::COLA, '1.10');
    $since = $this->sync->pull($this->since)->json('highestVersion');
    $sinceBradford = $this->sync->pull($this->sinceBradford, bradford: true)->json('highestVersion');

    ($this->shopPrice)(null)->assertOk()->assertJson(['data' => [
        'previous_shop_retail' => '1.20', 'shop_retail' => '1.45', 'business_retail' => '1.45', 'has_shop_price' => false, 'changed' => true,
    ]])->assertJsonPath('message', fn (string $m) => str_contains($m, 'business price'));

    $leeds = ($this->entities)($this->sync->pull($since), 'BranchPrice');
    expect($leeds)->toHaveCount(1)->and($leeds[0]['payload']['validToUtc'])->toBe('2026-10-22T09:00:00Z')
        ->and(($this->entities)($this->sync->pull($sinceBradford, bradford: true), 'BranchPrice'))->toHaveCount(0)
        ->and(BranchPrice::withoutCompanyScope()->where('branch_id', $this->sync->bradford->id)->sole()->valid_to_utc)->toBeNull();

    // Nothing left to remove.
    ($this->shopPrice)(null)->assertOk()->assertJsonPath('data.changed', false)->assertJsonPath('data.has_shop_price', false);
});

test('shop price rules: GB 99,999.99 and 2 decimals, retail must be sent, archived 422, another business 404', function () {
    ($this->shopPrice)('99999.99')->assertOk();

    foreach (['100000.00', '1.999', '-1', 'abc', '1e3'] as $bad) {
        ($this->shopPrice)($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonStructure(['errors' => ['retail']]);
    }

    $this->putJson('/api/pda/products/'.F::COLA.'/shop-price', [], $this->headers)->assertStatus(422)->assertJsonStructure(['errors' => ['retail']]);
    ($this->shopPrice)('1.00', H::otherBusiness()['product'])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

    DB::table('products')->where('id', F::WATER)->update(['is_active' => false]);
    ($this->shopPrice)('1.00', F::WATER)->assertStatus(422)->assertJsonPath('code', 'PRODUCT_ARCHIVED');
});

test('PK shop prices go up to 9,999,999.99', function () {
    CountryModulesFixtures::pakistan();

    ($this->shopPrice)('9999999.99')->assertOk()->assertJsonPath('data.shop_retail', '9999999.99');
    ($this->shopPrice)('10000000.00')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
})->group('country-pk');

test('a unit\'s business price goes through the product form\'s path: every till pulls the unit, audited as the handheld user', function () {
    ($this->unitPrice)(['retail_price' => '28.50'])->assertOk()->assertJson(['type' => 'unit_price', 'data' => [
        'id' => F::COLA, 'product_unit_id' => $this->colaCase, 'unit_code' => 'CASE', 'unit_name' => 'Case', 'conversion_to_base' => '24.0000',
        'scope' => 'business', 'previous_retail_price' => '30.00', 'retail_price' => '28.50', 'business_retail_price' => '28.50',
        'has_shop_price' => false, 'changed' => true, 'is_synced' => false,
    ]]);

    expect(DB::table('product_units')->where('id', $this->colaCase)->value('sell_price_inc_vat'))->toEqual('28.50')
        ->and(DB::table('product_units')->where('id', $this->colaCase)->value('cost'))->toEqual('18.7200');

    foreach ([[$this->since, false], [$this->sinceBradford, true]] as [$since, $bradford]) {
        $units = ($this->entities)($this->sync->pull($since, bradford: $bradford), 'ProductUnit');
        expect($units)->toHaveCount(1)->and((string) $units[0]['payload']['sellPriceIncVat'])->toBe('28.5');
    }

    $audit = AuditLog::query()->where('action', 'product.updated')->sole();
    expect($audit->actor_id)->toBe($this->allowed->id)->and($audit->meta['name'] ?? null)->toBe('Coca-Cola 500ml');

    ($this->unitPrice)(['retail_price' => '28.50', 'scope' => 'business'])->assertOk()->assertJsonPath('data.changed', false);
});

test('a unit\'s shop price is a BranchPrice for that unit, pulled by this shop only; null ends it', function () {
    ($this->unitPrice)(['retail_price' => '27.00', 'scope' => 'shop'])->assertOk()->assertJson(['data' => [
        'scope' => 'shop', 'previous_retail_price' => '30.00', 'retail_price' => '27.00', 'business_retail_price' => '30.00', 'has_shop_price' => true, 'changed' => true,
    ]]);

    $row = BranchPrice::withoutCompanyScope()->sole();
    expect($row->product_unit_id)->toBe($this->colaCase)->and($row->branch_id)->toBe($this->sync->leeds->id)
        ->and(DB::table('product_units')->where('id', $this->colaCase)->value('sell_price_inc_vat'))->toEqual('30.00')
        ->and(($this->entities)($this->sync->pull($this->since), 'BranchPrice'))->toHaveCount(1)
        ->and(($this->entities)($this->sync->pull($this->sinceBradford, bradford: true), 'BranchPrice'))->toHaveCount(0);

    // The units list shows this shop's case price.
    $units = collect($this->getJson('/api/pda/products/'.F::COLA.'/units', $this->headers)->assertOk()->json('data'))->keyBy('product_unit_id');
    expect($units[$this->colaCase]['retail_price'])->toBe('27.00');

    ($this->unitPrice)(['retail_price' => null, 'scope' => 'shop'])->assertOk()
        ->assertJsonPath('data.retail_price', '30.00')->assertJsonPath('data.has_shop_price', false)->assertJsonPath('data.changed', true);
});

test('unit price rules: required for the business scope, limits, scope values, another product\'s unit 404', function () {
    ($this->unitPrice)(['retail_price' => null])->assertStatus(422)->assertJsonStructure(['errors' => ['retail_price']]);
    ($this->unitPrice)(['retail_price' => '100000.00'])->assertStatus(422)->assertJsonStructure(['errors' => ['retail_price']]);
    ($this->unitPrice)(['retail_price' => '1.00', 'scope' => 'everywhere'])->assertStatus(422)->assertJsonStructure(['errors' => ['scope']]);
    ($this->unitPrice)(['retail_price' => '1.00'], product: F::WATER)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    ($this->unitPrice)(['retail_price' => '1.00'], unit: Ulid::new())->assertNotFound();
    ($this->unitPrice)(['retail_price' => '1.00'], product: H::otherBusiness()['product'])->assertNotFound();

    expect(DB::table('product_units')->where('id', $this->colaCase)->value('sell_price_inc_vat'))->toEqual('30.00');
});
