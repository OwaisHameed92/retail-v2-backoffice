<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\MasterCatalogue\Actions\LoadStarterSet;
use App\Domain\MasterCatalogue\Enums\ContributionStatus;
use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Models\CatalogueContribution;
use App\Domain\MasterCatalogue\Models\MasterCatalogueImport;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Queries\BarcodeLookup;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\MasterCatalogue\MasterFixtures;

/** Starter catalogue (gap #7): the admin master catalogue screens, CSV loads, merges and the till review queue. */
beforeEach(function () {
    $this->withoutVite();
    $this->admin = Admin::factory()->create(['role' => AdminRole::Owner]);
});

test('owner and support manage the catalogue; sales and accounts get 403; guests are sent to sign in', function () {
    $product = MasterFixtures::product();
    $contribution = CatalogueContribution::query()->create(['barcode' => '5012345678900', 'name' => 'Mystery Crisps 40g', 'status' => ContributionStatus::Pending]);
    $routes = [
        ['get', '/admin/catalogue'], ['get', '/admin/catalogue/create'], ['post', '/admin/catalogue'], ['get', "/admin/catalogue/{$product->id}"],
        ['put', "/admin/catalogue/{$product->id}"], ['post', "/admin/catalogue/{$product->id}/merge"], ['post', '/admin/catalogue/starter'],
        ['get', '/admin/catalogue/imports'], ['get', '/admin/catalogue/imports/template'], ['post', '/admin/catalogue/imports'],
        ['get', '/admin/catalogue/contributions'], ['post', "/admin/catalogue/contributions/{$contribution->id}/approve"],
        ['post', "/admin/catalogue/contributions/{$contribution->id}/reject"],
    ];

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url)->assertRedirect();
    }

    foreach ([AdminRole::Sales, AdminRole::Accounts] as $role) {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($routes as [$method, $url]) {
            $this->actingAs($admin, 'admin')->{$method}($url)->assertForbidden();
        }
    }

    $this->actingAs(Admin::factory()->create(['role' => AdminRole::Support]), 'admin')->get('/admin/catalogue')->assertOk();
    expect(MasterProduct::query()->count())->toBe(1)->and($contribution->fresh()->status)->toBe(ContributionStatus::Pending);
});

test('the starter set loads the demo catalogue once, marked as the starter set; running it again changes nothing', function () {
    $first = app(LoadStarterSet::class)->handle();
    $beans = MasterProduct::query()->where('barcode', DemoProducts::get('beans')['barcode'])->firstOrFail();

    expect($first['created'])->toBeGreaterThan(500)
        ->and(MasterProduct::query()->where('source', '!=', MasterSource::Starter->value)->count())->toBe(0)
        ->and($beans->name)->toBe('Heinz Baked Beans 415g')
        ->and($beans->size()->label())->toBe('415g')
        ->and((string) $beans->vat_rate)->toBe('0.00')
        ->and((string) $beans->rrp)->toBe('1.40')
        ->and($beans->department)->toBe('Grocery')
        ->and($beans->in_starter_packs)->toBeTrue()
        ->and(MasterProduct::query()->where('barcode', DemoProducts::get('vodka')['barcode'])->value('age_rule'))->toBe('over18');

    $beans->forceFill(['source' => MasterSource::Admin, 'name' => 'Heinz Beanz 415g'])->save();
    $again = app(LoadStarterSet::class)->handle();

    expect($again['created'])->toBe(0)->and($again['updated'])->toBe(0)
        ->and($beans->fresh()->name)->toBe('Heinz Beanz 415g');
});

test('the list searches words in the name, any form of a barcode, and filters by source and duplicates', function () {
    MasterFixtures::product(['barcode' => '5000157024671', 'name' => 'Heinz Baked Beans 415g', 'brand' => 'Heinz']);
    MasterFixtures::product(['barcode' => '5010044000701', 'name' => 'Warburtons Toastie 800g'], MasterSource::Import);
    MasterFixtures::product(['barcode' => '0036000291452', 'name' => 'Warburtons Toastie 800g']);
    $this->actingAs($this->admin, 'admin');

    $names = fn (string $query) => collect($this->get('/admin/catalogue?'.$query)->assertOk()->viewData('page')['props']['products']['data'])->pluck('barcode')->all();

    expect($names('search=beans+heinz'))->toBe(['5000157024671'])
        ->and($names('search=036000291452'))->toBe(['0036000291452'])
        ->and($names('source=import'))->toBe(['5010044000701'])
        ->and($names('duplicates=1'))->toEqualCanonicalizing(['5010044000701', '0036000291452']);

    $this->get('/admin/catalogue')->assertInertia(fn (AssertableInertia $p) => $p->component('admin/catalogue/index')
        ->where('counts.total', 3)->where('counts.duplicates', 1));
});

test('an admin adds and edits a product; bad barcodes, in-store numbers and a barcode already listed are refused', function () {
    $this->actingAs($this->admin, 'admin');
    $form = MasterFixtures::form();

    $this->post('/admin/catalogue', [...$form, 'barcode' => '5000157024672'])->assertSessionHasErrors('barcode');
    $this->post('/admin/catalogue', [...$form, 'barcode' => '2012345678903'])->assertSessionHasErrors('barcode');
    $this->post('/admin/catalogue', $form)->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/admin/catalogue', $form)->assertSessionHasErrors('barcode');

    $product = MasterProduct::query()->firstOrFail();
    expect($product->source)->toBe(MasterSource::Admin)->and($product->size()->label())->toBe('415g')->and((string) $product->rrp)->toBe('1.40');

    $this->put("/admin/catalogue/{$product->id}", [...$form, 'rrp' => '1.50', 'age_rule' => 'none'])->assertSessionHasNoErrors();
    expect((string) $product->fresh()->rrp)->toBe('1.50');
});

test('merging keeps one product; the merged barcode still finds it and is no longer listed', function () {
    $keep = MasterFixtures::product(['barcode' => '5000157024671', 'name' => 'Heinz Baked Beans 415g', 'rrp' => null]);
    $dup = MasterFixtures::product(['barcode' => '5000157024688', 'name' => 'Heinz Baked Beans 415g', 'rrp' => '1.40', 'brand' => 'Heinz']);
    $this->actingAs($this->admin, 'admin')->post("/admin/catalogue/{$dup->id}/merge", ['keep' => $keep->id])->assertRedirect("/admin/catalogue/{$keep->id}");

    expect($dup->fresh()->merged_into_id)->toBe($keep->id)
        ->and((string) $keep->fresh()->rrp)->toBe('1.40')
        ->and($keep->fresh()->brand)->toBe('Heinz')
        ->and(MasterProduct::query()->current()->count())->toBe(1);

    $this->actingAs($this->admin, 'admin')->post("/admin/catalogue/{$keep->id}/merge", ['keep' => $keep->id])->assertSessionHasErrors('keep');
});

test('a CSV load is applied in queued chunks: new barcodes added, known ones updated, bad rows reported; loading it again changes nothing', function () {
    Storage::fake('local');
    MasterFixtures::product(['barcode' => '5000157024671', 'name' => 'Heinz Beans', 'rrp' => '1.20']);
    $csv = "EAN,Description,Brand,Pack size,Dept,VAT %,RRP,Age\n"
        ."5000157024671,Heinz Baked Beans 415g,Heinz,415g,Grocery,0,1.40,\n"
        ."5449000000996,Coca-Cola 500ml,Coca-Cola,500ml,Soft drinks,20,1.85,no\n"
        ."5410316952705,Smirnoff Red Label Vodka 70cl,Smirnoff,70cl,Beers wines and spirits,20,18.00,18\n"
        ."123,Broken,,,,,,\n";
    $this->actingAs($this->admin, 'admin')
        ->post('/admin/catalogue/imports', ['file' => UploadedFile::fake()->createWithContent('supplier.csv', $csv), 'source_ref' => 'Supplier X licensed file'])
        ->assertSessionHasNoErrors();

    $import = MasterCatalogueImport::query()->firstOrFail();
    $vodka = MasterProduct::query()->where('barcode', '5410316952705')->firstOrFail();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and([$import->created_count, $import->updated_count, $import->failed_count])->toBe([2, 1, 1])
        ->and($import->errors[0]['row'])->toBe(5)
        ->and((string) MasterProduct::query()->where('barcode', '5000157024671')->value('rrp'))->toBe('1.40')
        ->and($vodka->size()->label())->toBe('70cl')
        ->and($vodka->age_rule)->toBe('over18')
        ->and($vodka->source)->toBe(MasterSource::Import)
        ->and($vodka->source_ref)->toBe('Supplier X licensed file');

    $this->actingAs($this->admin, 'admin')->post('/admin/catalogue/imports', ['file' => UploadedFile::fake()->createWithContent('supplier.csv', $csv)]);
    $second = MasterCatalogueImport::query()->latest('created_at')->orderByDesc('id')->firstOrFail();

    expect([$second->created_count, $second->updated_count, $second->unchanged_count])->toBe([0, 0, 3]);
});

test('a CSV without a barcode or name column is refused', function () {
    Storage::fake('local');
    $this->actingAs($this->admin, 'admin')
        ->post('/admin/catalogue/imports', ['file' => UploadedFile::fake()->createWithContent('bad.csv', "sku,price\nA,1\n")])
        ->assertSessionHasErrors('file');

    expect(MasterCatalogueImport::query()->count())->toBe(0);
});

test('approving a till barcode adds it to the catalogue as "from tills"; rejecting keeps it out; each is reviewed once', function () {
    $one = CatalogueContribution::query()->create(['barcode' => '5012345678900', 'name' => 'Mystery Crisps 40g', 'status' => ContributionStatus::Pending, 'seen_count' => 3]);
    $two = CatalogueContribution::query()->create(['barcode' => '5000157024671', 'name' => 'Beans', 'status' => ContributionStatus::Pending]);
    $this->actingAs($this->admin, 'admin');

    $this->get('/admin/catalogue/contributions')->assertInertia(fn (AssertableInertia $p) => $p->component('admin/catalogue/contributions')
        ->has('contributions.data', 2)->where('contributions.data.0.barcode', '5012345678900'));

    $this->post("/admin/catalogue/contributions/{$one->id}/approve", [...MasterFixtures::form(), 'barcode' => null, 'name' => 'Mystery Crisps Ready Salted 40g', 'rrp' => '0.99'])
        ->assertSessionHasNoErrors();
    $this->post("/admin/catalogue/contributions/{$two->id}/reject")->assertSessionHasNoErrors();
    $this->post("/admin/catalogue/contributions/{$two->id}/reject")->assertSessionHasErrors('contribution');

    $product = MasterProduct::query()->where('barcode', '5012345678900')->firstOrFail();
    expect($product->source)->toBe(MasterSource::Contribution)
        ->and($product->name)->toBe('Mystery Crisps Ready Salted 40g')
        ->and($one->fresh()->status)->toBe(ContributionStatus::Approved)
        ->and($one->fresh()->master_product_id)->toBe($product->id)
        ->and($one->fresh()->reviewed_by)->toBe($this->admin->id)
        ->and($two->fresh()->status)->toBe(ContributionStatus::Rejected)
        ->and(MasterProduct::query()->where('barcode', '5000157024671')->exists())->toBeFalse();
});

test('lookup finds a barcode in any UPC/EAN form and follows merges', function () {
    $keep = MasterFixtures::product(['barcode' => '0036000291452', 'name' => 'Kleenex 70']);
    $dup = MasterFixtures::product(['barcode' => '5000157024671', 'name' => 'Kleenex']);
    $dup->forceFill(['merged_into_id' => $keep->id])->save();

    $find = fn (string $code) => app(CurrentCompany::class)->runAs(Company::factory()->create(), fn () => BarcodeLookup::find($code));

    expect($find('036000291452')['product']['name'] ?? null)->toBe('Kleenex 70')
        ->and($find('5000157024671')['product']['name'] ?? null)->toBe('Kleenex 70')
        ->and($find('5000157024672')['found'])->toBeFalse();
});
