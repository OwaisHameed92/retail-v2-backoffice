<?php

use App\Domain\Catalogue\Actions\ApplyProductImportChunk;
use App\Domain\Catalogue\Models\ProductImport;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

uses(TenancyTestHelpers::class);

/** Module 4.2: product CSV import (upload → mapping → preview → queued apply in chunks → result). */
beforeEach(function () {
    Storage::fake('local');
    $this->company = Company::factory()->create();
    $this->ids = CatalogueFixtures::seed($this->company);
    $this->owner = $this->memberOf($this->company, CompanyRole::Owner);
    $this->upload = function (string $csv, string $name = 'products.csv') {
        $this->actingAs($this->owner)->post('/app/products/imports', ['file' => UploadedFile::fake()->createWithContent($name, $csv)])->assertRedirect();

        return ProductImport::query()->withoutGlobalScopes()->latest('created_at')->latest('id')->firstOrFail();
    };
    $this->run = function (ProductImport $import, ?array $mapping = null) {
        $this->actingAs($this->owner)->post("/app/products/imports/{$import->id}/preview", ['mapping' => $mapping ?? $import->mapping])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post("/app/products/imports/{$import->id}/apply")->assertSessionHas('success');

        return $import->fresh();
    };
    $this->csv = "EAN,Code,Product name,Department,Category,VAT,Price,Cost\n"
        ."5000112637922,COKE-330,Coca-Cola 330ml,Drinks,Soft drinks,S,0.99,0.42\n"
        ."5010044000701,,Toastie White 800g,Bakery,Bread,Z,£1.45,0.98\n"
        ."5000112637922,COKE-DUP,Coke again,Drinks,Soft drinks,S,1.00,0.40\n"
        ."5.01E+12,BAD-1,Shortened,Drinks,Soft drinks,S,1.00,0.40\n"
        ."5000000000009,NEW-2,No price,Drinks,Soft drinks,S,,0.40\n"
        ."5000000000016,NEW-3,Wrong VAT,Drinks,Soft drinks,Q,1.00,0.40\n";
});

test('upload guesses the mapping; preview reads every row, finds problems and writes nothing', function () {
    $import = ($this->upload)($this->csv);

    expect($import->mapping)->toBeIgnoringKeyOrder(['barcode' => 0, 'sku' => 1, 'name' => 2, 'department' => 3, 'category' => 4, 'vat' => 5, 'sell_price' => 6, 'cost_price' => 7]);

    $this->actingAs($this->owner)->post("/app/products/imports/{$import->id}/preview", ['mapping' => $import->mapping])->assertSessionHasNoErrors();
    $import->refresh();
    $messages = collect($import->errors)->keyBy('row')->map(fn ($e) => implode(' ', $e['messages']));

    expect([$import->total_rows, $import->valid_rows, $import->error_rows])->toBe([6, 2, 4])
        ->and($import->preview['new'])->toBe(2)
        ->and($messages[4])->toContain('Same barcode as line 2')
        ->and($messages[5])->toContain('shortened by a spreadsheet')
        ->and($messages[6])->toContain('needs a sell price')
        ->and($messages[7])->toContain('VAT rate "Q"')
        ->and(DB::table('products')->count())->toBe(0);

    $this->actingAs($this->owner)->get("/app/products/imports/{$import->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->component('app/products/imports/show')
        ->where('import.preview.new', 2)
        ->has('import.preview.sample', 6)
        ->where('import.preview.sample.0.action', 'create'));
});

test('a mapping without barcode or code is refused', function () {
    $import = ($this->upload)($this->csv);

    $this->actingAs($this->owner)->post("/app/products/imports/{$import->id}/preview", ['mapping' => ['name' => 2]])->assertSessionHasErrors('mapping');
});

test('apply creates products, barcodes, departments and categories for every till, skips bad rows and reports them', function () {
    $import = ($this->run)(($this->upload)($this->csv));
    $coke = DB::table('products')->where('sku', 'COKE-330')->first();

    expect($import->status->value)->toBe('completed')
        ->and([$import->created_count, $import->updated_count, $import->unchanged_count, $import->failed_count])->toBe([2, 0, 0, 4])
        ->and($coke->sell_price)->toEqual('0.99')
        ->and($coke->cost_price)->toEqual('0.4200')
        ->and($coke->vat_rate_id)->toBe($this->ids['vat'])
        ->and($coke->hub_version)->not->toBeNull()
        ->and(DB::table('product_barcodes')->where('product_id', $coke->id)->value('barcode'))->toBe('5000112637922')
        ->and(DB::table('departments')->where('name', 'Drinks')->value('id'))->toBe($coke->department_id)
        ->and(DB::table('categories')->where('name', 'Soft drinks')->value('department_id'))->toBe($coke->department_id)
        ->and(DB::table('products')->where('name', 'Toastie White 800g')->value('vat_rate_id'))->toBe($this->ids['zero'])
        ->and(collect($import->errors)->pluck('row')->all())->toBe([4, 5, 6, 7])
        ->and(AuditLog::query()->where('action', 'product_import.completed')->count())->toBe(1);
});

test('importing the same file again is idempotent; a changed price updates by barcode and a code adds a barcode', function () {
    ($this->run)(($this->upload)($this->csv));
    $ids = DB::table('products')->orderBy('id')->pluck('id')->all();
    $versions = DB::table('products')->orderBy('id')->pluck('hub_version')->all();

    $again = ($this->run)(($this->upload)($this->csv));

    expect([$again->created_count, $again->updated_count, $again->unchanged_count])->toBe([0, 0, 2])
        ->and(DB::table('products')->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(DB::table('products')->orderBy('id')->pluck('hub_version')->all())->toBe($versions)
        ->and(DB::table('departments')->where('name', 'Drinks')->count())->toBe(1);

    $update = ($this->run)(($this->upload)("Barcode,SKU,Price\n5000112637922,,1.09\n9999999999994,COKE-330,\n5010044000701,COKE-330,\n"));

    expect([$update->created_count, $update->updated_count, $update->unchanged_count, $update->failed_count])->toBe([0, 2, 0, 1])
        ->and(DB::table('products')->where('sku', 'COKE-330')->value('sell_price'))->toEqual('1.09')
        ->and(DB::table('products')->where('sku', 'COKE-330')->value('name'))->toBe('Coca-Cola 330ml')
        ->and(DB::table('product_barcodes')->where('barcode', '9999999999994')->value('product_id'))->toBe(DB::table('products')->where('sku', 'COKE-330')->value('id'))
        ->and(DB::table('products')->count())->toBe(2);
});

test('large files are applied in chunks from a byte offset', function () {
    $rows = "Barcode,Name,Department,Category,Price\n";
    foreach (range(1, ApplyProductImportChunk::CHUNK + 5) as $i) {
        $rows .= sprintf("%013d,Item %d,Bakery,Bread,1.%02d\n", 7000000000000 + $i, $i, $i % 100);
    }

    $import = ($this->run)(($this->upload)($rows));

    expect($import->status->value)->toBe('completed')
        ->and($import->created_count)->toBe(ApplyProductImportChunk::CHUNK + 5)
        ->and($import->processed_rows)->toBe(ApplyProductImportChunk::CHUNK + 5)
        ->and(DB::table('products')->count())->toBe(ApplyProductImportChunk::CHUNK + 5);
});

test('imports are the business\'s own; staff cannot import; the upload must be a CSV', function () {
    $import = ($this->upload)($this->csv);
    $other = Company::factory()->create();
    $stranger = $this->memberOf($other, CompanyRole::Owner);
    $staff = $this->memberOf($this->company, CompanyRole::Staff);

    $this->actingAs($stranger)->get("/app/products/imports/{$import->id}")->assertNotFound();
    $this->actingAs($stranger)->post("/app/products/imports/{$import->id}/apply")->assertNotFound();
    $this->actingAs($staff)->post('/app/products/imports', ['file' => UploadedFile::fake()->createWithContent('p.csv', $this->csv)])->assertForbidden();
    $this->actingAs($staff)->get("/app/products/imports/{$import->id}")->assertForbidden();
    $this->actingAs($this->owner)->post('/app/products/imports', ['file' => UploadedFile::fake()->create('photo.png', 10, 'image/png')])->assertSessionHasErrors('file');
    $this->actingAs($this->owner)->post("/app/products/imports/{$import->id}/apply")->assertSessionHas('error');   // not previewed yet

    expect(DB::table('products')->count())->toBe(0);
});
