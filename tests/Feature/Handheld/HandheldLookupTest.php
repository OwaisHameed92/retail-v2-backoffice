<?php

use App\Domain\Shared\Support\Ulid;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Handheld (PDA) API phase 1: pick lists. Products with the user's shop's price (own shop price beats the business
 * price) and stock, barcode scans (base unit, pack, extra unit), a product's units and suppliers. Only the user's
 * business: another business's product or supplier is never listed and its id is 404.
 */

beforeEach(function () {
    $this->travelTo('2026-10-20 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    F::catalogue($this->company);
    $this->handheld = H::user($this->company, $this->sync->leeds);
    $this->headers = H::headers(H::token($this, $this->handheld));
    $this->other = H::otherBusiness();

    $this->each = Ulid::new();
    $this->case = Ulid::new();
    Pull::portalCreate($this->company, 'Unit', Pull::payload('Unit', $this->each, ['code' => 'PCS', 'name' => 'Each', 'isActive' => true, 'decimalsAllowed' => false, 'decimalPlaces' => 0]));
    Pull::portalCreate($this->company, 'Unit', Pull::payload('Unit', $this->case, ['code' => 'CASE', 'name' => 'Case', 'isActive' => true, 'decimalsAllowed' => false, 'decimalPlaces' => 0]));
    $this->colaCase = Ulid::new();
    Pull::portalCreate($this->company, 'ProductUnit', Pull::payload('ProductUnit', $this->colaCase, [
        'productId' => F::COLA, 'unitId' => $this->case, 'conversionFactor' => 24, 'sellPriceIncVat' => 30.0, 'cost' => 18.72,
        'isDefaultSellUnit' => false, 'isPurchaseUnit' => true, 'isDefaultPurchaseUnit' => true, 'position' => 1,
    ]));

    H::barcode($this->company, F::WATER, '5010044000701');
    H::barcode($this->company, F::COLA, '5449000000996');
    H::barcode($this->company, F::COLA, '5449000000910', packQty: 6, primary: false, unitCode: 'PK6');
    H::barcode($this->company, F::COLA, '5449000054227', productUnitId: $this->colaCase, primary: false);
    H::stock($this->company, $this->sync->leeds, F::COLA, '36.0000');
    H::stock($this->company, $this->sync->bradford, F::COLA, '5.0000');
    H::shopPrice($this->company, $this->sync->leeds, F::COLA, '1.35');
    H::shopPrice($this->company, $this->sync->bradford, F::WATER, '1.10');
});

test('products: the business\'s active products with this shop\'s price, stock and primary barcode', function () {
    $reply = $this->getJson('/api/pda/products', $this->headers)->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.current_page', 1)->assertJsonPath('data.last_page', 1)->assertJsonPath('data.per_page', 25);

    $rows = collect($reply->json('data.data'))->keyBy('id');
    expect($rows->keys()->all())->toBe([F::COLA, F::WATER])
        ->and($rows[F::COLA])->toMatchArray([
            'product_name' => 'Coca-Cola 500ml', 'barcode' => '5449000000996', 'product_code' => 'COLA-500', 'cost' => '0.9800',
            'retail' => '1.35', 'business_retail' => '1.45', 'has_shop_price' => true, 'unit_type' => 'pcs', 'unit_code' => 'PCS',
            'stock_on_hand' => '36.0000',
        ])
        // Bradford's own water price is not Leeds's; Leeds has no stock row for water.
        ->and($rows[F::WATER])->toMatchArray(['retail' => '1.45', 'has_shop_price' => false, 'stock_on_hand' => null, 'barcode' => '5010044000701']);
});

test('products: exact barcode scan, search by name, SKU or part of a barcode, and paging', function () {
    $this->getJson('/api/pda/products?barcode=5449000000910', $this->headers)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', F::COLA);
    $this->getJson('/api/pda/products?barcode=544900', $this->headers)->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson('/api/pda/products?search=toastie', $this->headers)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', F::WATER);
    $this->getJson('/api/pda/products?search=COLA-5', $this->headers)->assertOk()->assertJsonPath('data.data.0.id', F::COLA);
    $this->getJson('/api/pda/products?search=0044000', $this->headers)->assertOk()->assertJsonPath('data.data.0.id', F::WATER);
    $this->getJson('/api/pda/products?per_page=1&page=2', $this->headers)->assertOk()
        ->assertJsonPath('data.last_page', 2)->assertJsonPath('data.current_page', 2)->assertJsonPath('data.data.0.id', F::WATER);
    $this->getJson('/api/pda/products?per_page=500', $this->headers)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
});

test('another business\'s products and suppliers are never listed, and their ids are 404', function () {
    $this->getJson('/api/pda/products?barcode=5000000000999', $this->headers)->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson('/api/pda/products?search=Secret', $this->headers)->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson('/api/pda/resolve-barcode?barcode=5000000000999', $this->headers)->assertOk()->assertJsonPath('matches', []);
    $this->getJson('/api/pda/suppliers?search=Secret', $this->headers)->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson("/api/pda/products/{$this->other['product']}/units", $this->headers)->assertNotFound()
        ->assertJsonPath('success', false)->assertJsonPath('code', 'NOT_FOUND');
});

test('resolve-barcode maps the base unit, a pack barcode and a unit barcode like the legacy multi-unit shape', function () {
    $base = $this->getJson('/api/pda/resolve-barcode?barcode=5449000000996', $this->headers)->assertOk()->json('matches');
    expect($base)->toHaveCount(1)->and($base[0])->toMatchArray([
        'product_id' => F::COLA, 'product_unit_id' => null, 'selected_unit_id' => $this->each, 'selected_unit_code' => 'PCS', 'selected_unit_name' => 'Each',
        'conversion_to_base' => '1.0000', 'retail_price' => '1.35', 'purchase_cost' => '0.9800', 'is_base_unit' => true, 'allow_decimal' => false,
        'decimal_places' => 0, 'matched_barcode' => '5449000000996', 'is_primary' => true,
    ]);

    $pack = $this->getJson('/api/pda/resolve-barcode?barcode=5449000000910', $this->headers)->json('matches.0');
    expect($pack)->toMatchArray(['product_unit_id' => null, 'selected_unit_code' => 'PK6', 'selected_unit_name' => 'Pack of 6', 'conversion_to_base' => '6.0000',
        'retail_price' => '8.10', 'purchase_cost' => '5.8800', 'is_base_unit' => false, 'is_primary' => false]);

    $case = $this->getJson('/api/pda/resolve-barcode?barcode=5449000054227', $this->headers)->json('matches.0');
    expect($case)->toMatchArray(['product_unit_id' => $this->colaCase, 'selected_unit_id' => $this->case, 'selected_unit_code' => 'CASE',
        'selected_unit_name' => 'Case', 'conversion_to_base' => '24.0000', 'retail_price' => '30.00', 'purchase_cost' => '18.7200', 'is_base_unit' => false]);

    $this->getJson('/api/pda/resolve-barcode?barcode=000', $this->headers)->assertOk()->assertJsonPath('matches', []);
    $this->getJson('/api/pda/resolve-barcode', $this->headers)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
});

test('a product\'s units: the base unit first, then its extra units with this shop\'s unit price', function () {
    H::shopPrice($this->company, $this->sync->leeds, F::COLA, '28.50', $this->colaCase);

    $units = $this->getJson('/api/pda/products/'.F::COLA.'/units', $this->headers)->assertOk()->json('data');

    expect(array_column($units, 'selected_unit_code'))->toBe(['PCS', 'CASE'])
        ->and($units[0])->toMatchArray(['is_base_unit' => true, 'conversion_to_base' => '1.0000', 'retail_price' => '1.35'])
        ->and($units[1])->toMatchArray(['product_unit_id' => $this->colaCase, 'conversion_to_base' => '24.0000', 'retail_price' => '28.50']);

    expect($this->getJson('/api/pda/products/'.F::WATER.'/units', $this->headers)->json('data'))->toHaveCount(1);
});

test('suppliers: active suppliers of the business, searchable', function () {
    $this->getJson('/api/pda/suppliers', $this->headers)->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', F::SUPPLIER)
        ->assertJsonPath('data.data.0.supplier_name', 'Aire Valley Cash & Carry')
        ->assertJsonPath('data.data.0.phone_number', '0113 496 0321')
        ->assertJsonPath('data.data.0.email', 'orders@airevalley.example');

    $this->getJson('/api/pda/suppliers?search=avcc', $this->headers)->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson('/api/pda/suppliers?search=booker', $this->headers)->assertOk()->assertJsonPath('data.total', 0);
});

test('every lookup needs a token', function () {
    foreach (['/api/pda/products', '/api/pda/suppliers', '/api/pda/resolve-barcode?barcode=1', '/api/pda/products/'.F::COLA.'/units'] as $url) {
        $this->getJson($url)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }
});
