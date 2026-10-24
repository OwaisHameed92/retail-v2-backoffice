<?php

use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\Tenancy\CurrentCompany;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

beforeEach(function () {
    $this->travelTo(now('UTC')->setDate(2026, 11, 2)->setTime(7, 0));
    $this->f = OnlineStoreFixtures::make();
    $this->catalogue = app(StorefrontCatalogue::class);
    $this->ids = fn () => collect($this->catalogue->products($this->f->store, $this->f->shop, perPage: 60)->items)->pluck('id')->all();
});

test('an active product with a price is shown with its shop facts', function () {
    $id = $this->f->product('Heinz Beans 415g', ['description' => 'Classic beans', 'image_path' => 'products/beans.jpg', 'max_qty_per_sale' => 6]);

    $page = $this->catalogue->products($this->f->store, $this->f->shop);

    expect($page->total)->toBe(1)
        ->and($page->items[0]->toArray())->toBe([
            'id' => $id, 'name' => 'Heinz Beans 415g', 'description' => 'Classic beans', 'photoPath' => 'products/beans.jpg',
            'departmentId' => $this->f->department, 'price' => '1.00', 'isWeighed' => false, 'unitCode' => 'each', 'maxQtyPerSale' => 6,
            'stockTracked' => false, 'inStock' => true, 'orderable' => true, 'stockLimit' => null,
        ]);
});

test('never shown: :dataset', function (array $columns) {
    $this->f->product('Shown');
    $this->f->product('Excluded', $columns);

    expect(collect($this->catalogue->products($this->f->store, $this->f->shop)->items)->pluck('name')->all())->toBe(['Shown']);
})->with([
    'inactive' => [['is_active' => false]],
    'archived' => [['archived_at' => '2026-10-01 09:00:00']],
    'deleted' => [['deleted_at' => '2026-10-01 09:00:00']],
    'merged' => [['merged_into_product_id' => '01K5T0Q8C4000000000000P999']],
    'a variant parent' => [['is_variant_parent' => true]],
    'an over-18 age rule' => [['age_rule' => 'over18']],
    'an energy drink age rule' => [['age_rule' => 'energyDrink16']],
    'a paracetamol age rule' => [['age_rule' => 'paracetamol16']],
    'is_age_restricted' => [['is_age_restricted' => true]],
    'alcohol' => [['is_alcohol' => true]],
    'tobacco' => [['is_tobacco' => true]],
    'lottery' => [['is_lottery' => true]],
    'a knife' => [['is_knife' => true]],
    'banned' => [['is_banned' => true]],
    'vape duty' => [['vape_duty_applies' => true]],
    'an open price' => [['is_open_price' => true]],
    'no price' => [['sell_price' => null]],
    'a zero price' => [['sell_price' => '0.00']],
]);

test('an age rule of none, and null flags, are shown', function () {
    $this->f->product('Bread', ['age_rule' => 'none', 'is_alcohol' => null, 'is_tobacco' => null, 'is_variant_parent' => null]);

    expect(($this->ids)())->toHaveCount(1);
});

test('a category age rule keeps its products off line', function () {
    $this->f->product('Cupboard item');
    $knives = OnlineStoreFixtures::category($this->f->company, $this->f->department, 'Knives', ['age_rule_default' => 'knives18']);
    $this->f->product('Kitchen knife', ['category_id' => $knives]);

    expect(collect($this->catalogue->products($this->f->store, $this->f->shop)->items)->pluck('name')->all())->toBe(['Cupboard item']);
});

test('Pharmacy-only and Prescription-only medicines are never shown; General Sale ones are', function () {
    $gsl = $this->f->product('Paracetamol 16', ['age_rule' => 'none']);
    $p = $this->f->product('Co-codamol');
    $pom = $this->f->product('Amoxicillin');
    $this->f->medicineClass($gsl, 'generalSale');
    $this->f->medicineClass($p, 'pharmacyOnly');
    $this->f->medicineClass($pom, 'prescriptionOnly');

    expect(($this->ids)())->toBe([$gsl]);
});

test('a removed medicine class no longer hides the product', function () {
    $id = $this->f->product('Cough syrup');
    $this->f->medicineClass($id, 'pharmacyOnly');
    DB::table('medicine_classifications')->where('product_id', $id)->update(['deleted_at' => '2026-10-01 09:00:00']);

    expect(($this->ids)())->toBe([$id]);
});

test('a product hidden online is not shown; shown again it is, with the online description and photo', function () {
    $id = $this->f->product('Crisps', ['description' => 'Till text', 'image_path' => 'till.jpg']);
    app(SetOnlineProduct::class)->handle($this->f->company, $id, ['hidden' => true]);
    expect(($this->ids)())->toBe([]);

    app(SetOnlineProduct::class)->handle($this->f->company, $id, ['hidden' => false, 'description' => 'Ready salted, 25g', 'photo_path' => 'online/crisps.jpg']);
    $item = $this->catalogue->product($this->f->store, $this->f->shop, $id);

    expect($item?->description)->toBe('Ready salted, 25g')->and($item?->photoPath)->toBe('online/crisps.jpg');
});

test('a product the shop\'s till marks inactive there is not shown at that shop', function () {
    $id = $this->f->product('Local only');
    $this->f->stock($this->f->leeds, $id, '5', ['is_active' => false]);

    expect(($this->ids)())->toBe([]);
});

test('price: the shop\'s live price, else the business price', function () {
    $own = $this->f->product('Shop priced', ['sell_price' => '2.00']);
    $plain = $this->f->product('Business priced', ['sell_price' => '3.50']);
    $this->f->shopPrice($this->f->leeds, $own, '1.79');
    $this->f->shopPrice($this->f->bradford, $plain, '9.99');

    $items = $this->catalogue->items($this->f->store, $this->f->shop, [$own, $plain]);

    expect($items[$own]->price)->toBe('1.79')->and($items[$plain]->price)->toBe('3.50');
});

test('price: only a live shop price counts; the latest start wins', function () {
    $id = $this->f->product('Milk', ['sell_price' => '1.20']);
    $this->f->shopPrice($this->f->leeds, $id, '0.90', '2026-01-01 00:00:00', '2026-06-01 00:00:00');
    $this->f->shopPrice($this->f->leeds, $id, '0.80', '2026-12-01 00:00:00');
    expect($this->catalogue->product($this->f->store, $this->f->shop, $id)?->price)->toBe('1.20');

    $this->f->shopPrice($this->f->leeds, $id, '1.10', '2026-09-01 00:00:00');
    $this->f->shopPrice($this->f->leeds, $id, '1.05', '2026-10-01 00:00:00');
    expect($this->catalogue->product($this->f->store, $this->f->shop, $id)?->price)->toBe('1.05');
});

test('price: a unit price or a removed shop price does not count', function () {
    $id = $this->f->product('Cola', ['sell_price' => '1.50']);
    $this->f->shopPrice($this->f->leeds, $id, '7.99', overrides: ['product_unit_id' => '01K5T0Q8C4000000000000U001']);
    $this->f->shopPrice($this->f->leeds, $id, '0.99', overrides: ['deleted_at' => '2026-10-01 00:00:00']);

    expect($this->catalogue->product($this->f->store, $this->f->shop, $id)?->price)->toBe('1.50');
});

test('a business price of nothing is shown when the shop has its own price', function () {
    $id = $this->f->product('Shop only', ['sell_price' => null]);
    $this->f->shopPrice($this->f->leeds, $id, '2.25');

    expect($this->catalogue->product($this->f->store, $this->f->shop, $id)?->price)->toBe('2.25');
});

test('stock: tracked with none left is shown but not orderable; untracked is always orderable', function () {
    $out = $this->f->product('Out', ['track_stock' => true]);
    $negative = $this->f->product('Negative', ['track_stock' => true]);
    $noLine = $this->f->product('No line', ['track_stock' => true]);
    $in = $this->f->product('In', ['track_stock' => true]);
    $untracked = $this->f->product('Untracked', ['track_stock' => false]);
    $this->f->stock($this->f->leeds, $out, '0');
    $this->f->stock($this->f->leeds, $negative, '-2');
    $this->f->stock($this->f->leeds, $in, '3');
    $this->f->stock($this->f->bradford, $noLine, '50');

    $items = $this->catalogue->items($this->f->store, $this->f->shop, [$out, $negative, $noLine, $in, $untracked]);

    expect($items)->toHaveCount(5)
        ->and($items[$out]->orderable)->toBeFalse()
        ->and($items[$negative]->orderable)->toBeFalse()
        ->and($items[$noLine]->orderable)->toBeFalse()
        ->and($items[$in]->orderable)->toBeTrue()
        ->and($items[$untracked]->orderable)->toBeTrue()
        ->and($items[$out]->toArray()['inStock'])->toBeFalse();
});

test('stock: the shop\'s own tracking setting wins over the product\'s', function () {
    $shopTracks = $this->f->product('Shop tracks', ['track_stock' => false]);
    $shopDoesNot = $this->f->product('Shop does not', ['track_stock' => true]);
    $this->f->stock($this->f->leeds, $shopTracks, '0', ['is_stock_tracked' => true]);
    $this->f->stock($this->f->leeds, $shopDoesNot, '0', ['is_stock_tracked' => false]);

    $items = $this->catalogue->items($this->f->store, $this->f->shop, [$shopTracks, $shopDoesNot]);

    expect($items[$shopTracks]->orderable)->toBeFalse()->and($items[$shopDoesNot]->orderable)->toBeTrue();
});

test('weighed products and max per sale are carried', function () {
    $id = $this->f->product('Lamb mince', ['is_weighed' => true, 'unit_code' => 'kg', 'sell_price' => '9.80', 'max_qty_per_sale' => 3]);

    $item = $this->catalogue->product($this->f->store, $this->f->shop, $id);

    expect($item?->isWeighed)->toBeTrue()->and($item?->unitCode)->toBe('kg')->and($item?->maxQtyPerSale)->toBe(3);
});

test('departments with counts, in the till\'s order, only where something is shown', function () {
    $fresh = OnlineStoreFixtures::department($this->f->company, 'Fresh', 0);
    $empty = OnlineStoreFixtures::department($this->f->company, 'Off licence', 2);
    $this->f->product('Beans');
    $this->f->product('Soup');
    $this->f->product('Apples', ['department_id' => $fresh]);
    $this->f->product('Wine', ['department_id' => $empty, 'is_alcohol' => true]);

    expect($this->catalogue->departments($this->f->store, $this->f->shop))->toBe([
        ['id' => $fresh, 'name' => 'Fresh', 'count' => 1],
        ['id' => $this->f->department, 'name' => 'Grocery', 'count' => 2],
    ]);
});

test('search by name, brand, code prefix or exact barcode; department filter; pages by name', function () {
    $fresh = OnlineStoreFixtures::department($this->f->company, 'Fresh', 0);
    $beans = $this->f->product('Baked Beans', ['brand' => 'Heinz', 'sku' => 'BEAN-1']);
    $this->f->product('Tomato Soup', ['brand' => 'Heinz']);
    $this->f->product('Apples', ['department_id' => $fresh]);
    DB::table('product_barcodes')->insert(['id' => 'BC'.substr($beans, 2), 'company_id' => $this->f->company->id, 'product_id' => $beans, 'barcode' => '5000157024671', 'row_version' => 1]);

    $names = fn (?string $q, ?string $dept = null) => collect($this->catalogue->products($this->f->store, $this->f->shop, $q, $dept)->items)->pluck('name')->all();

    expect($names('bean'))->toBe(['Baked Beans'])
        ->and($names('heinz'))->toBe(['Baked Beans', 'Tomato Soup'])
        ->and($names('BEAN'))->toBe(['Baked Beans'])
        ->and($names('5000157024671'))->toBe(['Baked Beans'])
        ->and($names('%'))->toBe(['Apples', 'Baked Beans', 'Tomato Soup'])
        ->and($names(null, $fresh))->toBe(['Apples']);

    $page = $this->catalogue->products($this->f->store, $this->f->shop, page: 2, perPage: 2);
    expect($page->total)->toBe(3)->and($page->lastPage())->toBe(2)->and(collect($page->items)->pluck('name')->all())->toBe(['Tomato Soup']);
});

test('the catalogue runs scoped to the store\'s business with no current company', function () {
    $this->f->product('Beans');

    expect(app(CurrentCompany::class)->has())->toBeFalse()
        ->and($this->catalogue->products($this->f->store, $this->f->shop)->total)->toBe(1)
        ->and(app(CurrentCompany::class)->has())->toBeFalse();
});
