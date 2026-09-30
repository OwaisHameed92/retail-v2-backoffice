<?php

use App\Domain\Catalogue\Actions\ArchiveProduct;
use App\Domain\Catalogue\Actions\RestoreProduct;
use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\ProductUnit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/** Module 4.2: SaveProduct, ArchiveProduct, RestoreProduct, and tills receiving the edits in their pull. */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->ids = CatalogueFixtures::seed($this->company);
    $this->as = fn (Closure $fn) => app(CurrentCompany::class)->runAs($this->company, $fn);
    $this->save = function (?Product $product, array $overrides = []) {
        $form = CatalogueFixtures::form($this->ids, $overrides);

        return ($this->as)(fn () => app(SaveProduct::class)->handle($product, Arr::except($form, ['barcodes', 'units']), $form['barcodes'], $form['units']));
    };
});

test('creates a product with a ULID, its barcode and units; row version 1; audited', function () {
    $saved = ($this->save)(null, ['units' => [
        ['id' => null, 'unit_id' => $this->ids['case'], 'conversion_factor' => '12', 'sell_price_inc_vat' => '15.00', 'cost' => '10.5', 'is_default_sell_unit' => false, 'is_purchase_unit' => true, 'is_default_purchase_unit' => true],
    ]]);
    $row = DB::table('products')->where('id', $saved->product->id)->first();

    expect($saved->created)->toBeTrue()
        ->and($saved->product->id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($row->sell_price)->toEqual('1.45')
        ->and((int) $row->row_version)->toBe(1)
        ->and($row->origin_branch_id)->toBeNull()
        ->and($row->is_age_restricted)->toEqual(0)
        ->and(DB::table('product_barcodes')->where('product_id', $row->id)->value('barcode'))->toBe('5010044000701')
        ->and(DB::table('product_units')->where('product_id', $row->id)->value('conversion_factor'))->toEqual('12.0000')
        ->and(AuditLog::query()->where('action', 'product.created')->count())->toBe(1);
});

test('an edit changes only what it names, keeps every id, bumps the row version and soft deletes removed rows', function () {
    $saved = ($this->save)(null, ['units' => [
        ['id' => null, 'unit_id' => $this->ids['case'], 'conversion_factor' => '12', 'sell_price_inc_vat' => '15.00', 'cost' => '10', 'is_default_sell_unit' => false, 'is_purchase_unit' => true, 'is_default_purchase_unit' => true],
    ], 'barcodes' => [
        ['id' => null, 'barcode' => '5010044000701', 'pack_qty' => 1, 'is_primary' => true],
        ['id' => null, 'barcode' => '5010044000702', 'pack_qty' => 1, 'is_primary' => false],
    ]]);
    $product = $saved->product;
    $unitId = ProductUnit::withoutCompanyScope()->value('id');
    $keep = ProductBarcode::withoutCompanyScope()->where('barcode', '5010044000701')->value('id');
    DB::table('products')->where('id', $product->id)->update(['nearest_expiry_date' => '2026-10-05']);   // till-owned: never touched

    $edit = ($this->save)(($this->as)(fn () => Product::query()->find($product->id)), [
        'sell_price' => '1.55',
        'barcodes' => [['id' => $keep, 'barcode' => '5010044000701', 'pack_qty' => 1, 'is_primary' => true]],
        'units' => [['id' => $unitId, 'unit_id' => $this->ids['case'], 'conversion_factor' => '6', 'sell_price_inc_vat' => '8.00', 'cost' => '5', 'is_default_sell_unit' => false, 'is_purchase_unit' => true, 'is_default_purchase_unit' => true]],
    ]);
    $row = DB::table('products')->where('id', $product->id)->first();

    expect($edit->changed)->toBe(['sell_price', 'barcodes', 'units'])
        ->and($row->sell_price)->toEqual('1.55')
        ->and((int) $row->row_version)->toBe(2)
        ->and($row->nearest_expiry_date)->toBe('2026-10-05')
        ->and(DB::table('product_units')->pluck('id')->all())->toBe([$unitId])
        ->and(DB::table('product_units')->value('conversion_factor'))->toEqual('6.0000')
        ->and(DB::table('product_barcodes')->where('id', $keep)->value('deleted_at'))->toBeNull()
        ->and(DB::table('product_barcodes')->where('barcode', '5010044000702')->value('deleted_at'))->not->toBeNull();
});

test('saving the same values writes nothing: no new pull version, no row version, no audit', function () {
    $product = ($this->save)(null)->product;
    $version = DB::table('products')->where('id', $product->id)->value('hub_version');
    $barcodeId = ProductBarcode::withoutCompanyScope()->value('id');

    $again = ($this->save)(($this->as)(fn () => Product::query()->find($product->id)), [
        'barcodes' => [['id' => $barcodeId, 'barcode' => '5010044000701', 'pack_qty' => 1, 'is_primary' => true]],
    ]);

    expect($again->changed)->toBe([])
        ->and(DB::table('products')->where('id', $product->id)->value('hub_version'))->toBe($version)
        ->and((int) DB::table('products')->where('id', $product->id)->value('row_version'))->toBe(1)
        ->and(AuditLog::query()->where('action', 'product.updated')->count())->toBe(0);
});

test('refuses a barcode on another product, a category of another department, a wrong sub-category and unknown ids', function () {
    ($this->save)(null);

    expect(fn () => ($this->save)(null, ['sku' => 'NEW-1']))->toThrow(ValidationException::class, 'already on Toastie White 800g');
    expect(fn () => ($this->save)(null, ['sku' => 'WAR-800', 'barcodes' => []]))->toThrow(ValidationException::class, 'already has the code WAR-800');

    try {
        ($this->save)(null, ['sku' => 'X', 'barcodes' => [], 'category_id' => $this->ids['sub'], 'sub_category_id' => $this->ids['category'], 'vat_rate_id' => '01K5T0Q8C4000000000000V999']);
        $this->fail('Expected a validation error');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['sub_category_id', 'vat_rate_id']);
    }
});

test('archive and restore are updates every till receives; the row is never deleted; repeats are no-ops', function () {
    $product = ($this->save)(null)->product;

    ($this->as)(fn () => app(ArchiveProduct::class)->handle(Product::query()->find($product->id)));
    ($this->as)(fn () => app(ArchiveProduct::class)->handle(Product::query()->find($product->id)));
    $archived = DB::table('products')->where('id', $product->id)->first();

    expect((bool) $archived->is_active)->toBeFalse()->and($archived->archived_at)->not->toBeNull()->and($archived->deleted_at)->toBeNull()
        ->and((int) $archived->row_version)->toBe(2);

    ($this->as)(fn () => app(RestoreProduct::class)->handle(Product::query()->find($product->id)));

    expect((bool) DB::table('products')->where('id', $product->id)->value('is_active'))->toBeTrue()
        ->and(DB::table('products')->where('id', $product->id)->value('archived_at'))->toBeNull();
});

test('tills pull the portal\'s product, barcode and edits (every shop), with the same ids', function () {
    $product = ($this->save)(null)->product;
    $first = $this->sync->pull(0)->assertOk();
    $changes = collect(Pull::changes($first))->keyBy('entity');

    expect($changes['Product']['entityId'])->toBe($product->id)
        ->and($changes['Product']['op'])->toBe('I')
        ->and($changes['Product']['branchId'])->toBe('')
        ->and($changes['Product']['payload']['sellPrice'])->toEqual(1.45)
        ->and($changes['ProductBarcode']['payload']['productId'])->toBe($product->id);

    $this->travel(2)->minutes();
    ($this->save)(($this->as)(fn () => Product::query()->find($product->id)), ['sell_price' => '1.60', 'barcodes' => [
        ['id' => $changes['ProductBarcode']['entityId'], 'barcode' => '5010044000701', 'pack_qty' => 1, 'is_primary' => true],
    ]]);
    $next = Pull::changes($this->sync->pull($first->json('highestVersion'), bradford: true)->assertOk());

    expect(array_map(fn ($c) => [$c['entity'], $c['op'], $c['entityId']], $next))->toBe([['Product', 'U', $product->id]])
        ->and($next[0]['payload']['sellPrice'])->toEqual(1.6)
        ->and($next[0]['payload']['rowVersion'])->toBe(2);
});
