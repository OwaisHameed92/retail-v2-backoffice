<?php

use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures as T;
use Tests\Feature\Workshop\WorkshopFixtures as W;

/*
 * Connector reads (WORKSHOP-BIKEBOOK.md §3.1–§3.4): stores, VAT rates (one default), products with variants, the
 * product scope (all / categories / workshopExposure), deltas by lastUpdated, paging, Product/{id} for any active
 * product (Verify), inventory per variant, by delta and in a batch of ≤ 500, shelf price inc VAT.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    Http::preventStrayRequests();
    $this->sync = new SyncApiFixtures($this);
    $this->key = W::connected($this->sync);
});

test('stores: the connected shop only; tax rates: the rates in force with exactly one default (joint test 1)', function () {
    W::call($this, 'GET', '/Stores', $this->key)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->sync->leeds->id);

    $rates = W::call($this, 'GET', '/TaxRates', $this->key)->assertOk()->json();
    expect($rates)->toHaveCount(2)
        ->and(collect($rates)->where('isDefault', true)->pluck('id')->all())->toBe([F::VAT_STD])
        ->and($rates[0])->toEqual(['id' => F::VAT_STD, 'name' => 'Standard', 'rate' => 20, 'isDefault' => true])
        ->and($rates[1]['rate'])->toEqual(0);
});

test('products: BikeBook\'s shape with one variant per single product, paged with X-Total-Count and Link', function () {
    $page = W::call($this, 'GET', '/Products?pageSize=1', $this->key)->assertOk()->assertHeader('X-Total-Count', '2');
    expect($page->headers->get('Link'))->toContain('page=2')->toContain('rel="next"');

    $product = $page->json('0');
    expect(array_keys($product))->toBe(['id', 'sku', 'name', 'description', 'brand', 'supplier', 'category', 'created', 'updated', 'version', 'enabled', 'images', 'variants'])
        ->and($product['id'])->toBe(F::WATER)->and($product['enabled'])->toBeTrue()
        ->and($product['variants'])->toHaveCount(1)->and($product['variants'][0]['id'])->toBe(F::WATER)
        ->and(array_keys($product['variants'][0]))->toBe(['id', 'sku', 'barcode', 'size', 'colour', 'model', 'weight', 'cost', 'sizeOrder']);

    W::call($this, 'GET', '/Products?page=2&pageSize=1', $this->key)->assertOk()->assertJsonPath('0.id', F::COLA)->assertHeaderMissing('Link');
    W::call($this, 'GET', '/Products?fields=id,updated', $this->key)->assertOk()->assertJsonPath('0', ['id' => F::WATER, 'updated' => '2026-11-08T09:00:00Z', 'enabled' => true]);
    W::call($this, 'GET', '/Products?pageSize=501', $this->key)->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    W::call($this, 'GET', '/Products?lastUpdated=yesterday', $this->key)->assertStatus(400);
});

test('variants: a variant parent lists its children with size, colour and model from the variant names', function () {
    Pull::portalCreate($this->sync->company, 'Product', [...T::sample('entities/Product.json'), 'id' => '01K5T0Q8C4000000000000P010', 'name' => 'Trek FX3', 'isVariantParent' => true, 'sku' => 'FX3']);

    foreach ([['P011', 'M', 'Red'], ['P012', 'L', 'Blue']] as [$id, $size, $colour]) {
        Pull::portalCreate($this->sync->company, 'Product', [...T::sample('entities/Product.json'), 'id' => "01K5T0Q8C4000000000000{$id}", 'name' => "Trek FX3 {$size}",
            'parentProductId' => '01K5T0Q8C4000000000000P010', 'isVariant' => true, 'variant1Name' => 'Size', 'variant1Value' => $size,
            'variant2Name' => 'Colour', 'variant2Value' => $colour, 'variant3Name' => 'Frame', 'variant3Value' => 'Alloy']);
    }

    $product = W::call($this, 'GET', '/Product/01K5T0Q8C4000000000000P011', $this->key)->assertOk()->json();
    expect($product['id'])->toBe('01K5T0Q8C4000000000000P010')
        ->and(collect($product['variants'])->map(fn ($v) => [$v['id'], $v['size'], $v['colour'], $v['model'], $v['sizeOrder']])->all())->toBe([
            ['01K5T0Q8C4000000000000P011', 'M', 'Red', 'Alloy', 1], ['01K5T0Q8C4000000000000P012', 'L', 'Blue', 'Alloy', 2],
        ]);
});

test('scope: categories limit the list, workshopExposure always / never win, and Product/{id} still answers any active product (Verify)', function () {
    W::setting($this->sync->company, $this->sync->leeds, 'workshop.product_scope', 'categories');
    W::setting($this->sync->company, $this->sync->leeds, 'workshop.scope_category_ids', '["01K5T0Q8C4000000000000E999"]');
    W::call($this, 'GET', '/Products', $this->key)->assertOk()->assertHeader('X-Total-Count', '0');

    Product::withoutCompanyScope()->whereKey(F::COLA)->update(['workshop_exposure' => 'always']);
    W::call($this, 'GET', '/Products', $this->key)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', F::COLA);

    W::setting($this->sync->company, $this->sync->leeds, 'workshop.scope_category_ids', '["01K5T0Q8C4000000000000E001"]');
    Product::withoutCompanyScope()->whereKey(F::WATER)->update(['workshop_exposure' => 'never']);
    W::call($this, 'GET', '/Products', $this->key)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', F::COLA);

    // Out of scope, but a "Verify" of its id still answers (enabled false); an unknown id is 404.
    W::call($this, 'GET', '/Product/'.F::WATER, $this->key)->assertOk()->assertJsonPath('enabled', false);
    W::call($this, 'GET', '/Product/01K5T0Q8C4000000000000P999', $this->key)->assertNotFound()->assertJsonPath('error', 'not_found');
});

test('delta: only products changed since lastUpdated; one that leaves the scope comes once more with enabled false', function () {
    $this->travel(1)->hours();
    $since = now('UTC')->format('Y-m-d\TH:i:s\Z');
    W::call($this, 'GET', '/Products?lastUpdated='.$since, $this->key)->assertOk()->assertJsonCount(0);

    $this->travel(5)->minutes();
    Pull::portalUpdate($this->sync->company, 'Product', F::COLA, ['is_active' => false]);
    W::call($this, 'GET', '/Products?lastUpdated='.$since, $this->key)->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.id', F::COLA)->assertJsonPath('0.enabled', false);

    // A stock change on the till counts as a change of the product.
    W::pushStock($this->sync, F::WATER, 7)->assertOk();
    W::call($this, 'GET', '/Products?fields=id,updated&lastUpdated='.$since, $this->key)->assertOk()->assertJsonCount(2);
});

test('inventory: on hand minus reserved, never below 0, null for untracked, shelf price inc VAT (shop price, override, sell price)', function () {
    W::pushStock($this->sync, F::COLA, 10, 3, 1.25)->assertOk();
    W::pushStock($this->sync, F::WATER, 1, 4)->assertOk();

    W::call($this, 'GET', '/Inventory?variant='.F::COLA, $this->key)->assertOk()
        ->assertExactJson([['variant' => F::COLA, 'store' => $this->sync->leeds->id, 'quantity' => 7.0, 'price' => 1.25]]);
    W::call($this, 'GET', '/Inventory?variant='.F::WATER, $this->key)->assertOk()->assertJsonPath('0.quantity', 0)->assertJsonPath('0.price', 1.45);

    Product::withoutCompanyScope()->whereKey(F::WATER)->update(['track_stock' => false]);
    W::call($this, 'POST', '/Inventory/batch', $this->key, ['variants' => [F::WATER, F::COLA, '01K5T0Q8C4000000000000P999']])->assertOk()
        ->assertJsonCount(2)->assertJsonPath('0.variant', F::WATER)->assertJsonPath('0.quantity', null);

    W::call($this, 'POST', '/Inventory/batch', $this->key, ['variants' => array_fill(0, 501, F::COLA)])->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    W::call($this, 'GET', '/Inventory', $this->key)->assertStatus(400);
});

test('inventory delta: every in-scope variant whose stock changed since lastUpdated (joint test 3)', function () {
    $this->travel(1)->hours();
    $since = now('UTC')->format('Y-m-d\TH:i:s\Z');
    W::pushStock($this->sync, F::COLA, 4)->assertOk();

    W::call($this, 'GET', '/Inventory?lastUpdated='.$since, $this->key)->assertOk()->assertHeader('X-Total-Count', '1')->assertJsonPath('0.variant', F::COLA);
});
