<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

uses(TenancyTestHelpers::class);

/** Module 4.2: the products and departments/categories screens (`/app/products*`): access, isolation, list, form. */
beforeEach(function () {
    $this->company = Company::factory()->create(['name' => 'Kirkgate Stores']);
    $this->ids = CatalogueFixtures::seed($this->company);
    $this->owner = $this->memberOf($this->company, CompanyRole::Owner);
    $this->actingAs($this->owner)->post('/app/products', CatalogueFixtures::form($this->ids))->assertSessionHasNoErrors();
    $this->productId = DB::table('products')->value('id');
});

test('guests are sent to the login page', function () {
    foreach (['/app/products', '/app/products/categories', '/app/products/create', "/app/products/{$this->productId}", '/app/products/imports'] as $url) {
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }
});

test('accountants get 403 everywhere; staff may look but not change anything', function () {
    $accountant = $this->memberOf($this->company, CompanyRole::Accountant);
    $staff = $this->memberOf($this->company, CompanyRole::Staff);

    $this->actingAs($accountant)->get('/app/products')->assertForbidden();
    $this->actingAs($accountant)->get("/app/products/{$this->productId}")->assertForbidden();
    $this->actingAs($accountant)->get('/app/products/categories')->assertForbidden();

    $this->actingAs($staff)->get('/app/products')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canManage', false));
    $this->actingAs($staff)->get("/app/products/{$this->productId}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('app/products/form')->where('canManage', false));

    foreach ([$accountant, $staff] as $user) {
        $this->actingAs($user)->get('/app/products/create')->assertForbidden();
        $this->actingAs($user)->post('/app/products', CatalogueFixtures::form($this->ids, ['sku' => 'NEW', 'barcodes' => []]))->assertForbidden();
        $this->actingAs($user)->put("/app/products/{$this->productId}", CatalogueFixtures::form($this->ids, ['sell_price' => '9.99']))->assertForbidden();
        $this->actingAs($user)->post("/app/products/{$this->productId}/archive")->assertForbidden();
        $this->actingAs($user)->post('/app/products/departments', ['name' => 'X'])->assertForbidden();
        $this->actingAs($user)->delete("/app/products/categories/{$this->ids['sub']}")->assertForbidden();
        $this->actingAs($user)->get('/app/products/imports')->assertForbidden();
    }

    expect(DB::table('products')->count())->toBe(1)->and(DB::table('products')->value('sell_price'))->toEqual('1.45');
});

test('a one-shop manager edits the catalogue as the role allows (catalogue.manage)', function () {
    $shop = Branch::factory()->forCompany($this->company)->create();
    $manager = $this->memberOf($this->company, CompanyRole::Manager);
    DB::table('company_user')->where('user_id', $manager->id)->update(['branch_id' => $shop->id]);

    $this->actingAs($manager)->get('/app/products')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('canManage', true));
    $this->actingAs($manager)->put("/app/products/{$this->productId}", CatalogueFixtures::form($this->ids, [
        'sell_price' => '1.50', 'barcodes' => [['id' => DB::table('product_barcodes')->value('id'), 'barcode' => '5010044000701', 'pack_qty' => 1, 'is_primary' => true]],
    ]))->assertSessionHasNoErrors();

    expect(DB::table('products')->value('sell_price'))->toEqual('1.5')
        ->and(DB::table('branch_prices')->count())->toBe(0);
});

test('another business\'s products, departments and categories are not found and cannot be changed', function () {
    $other = Company::factory()->create();
    $theirs = CatalogueFixtures::seed($other);
    $stranger = $this->memberOf($other, CompanyRole::Owner);

    $this->actingAs($stranger)->get("/app/products/{$this->productId}")->assertNotFound();
    $this->actingAs($stranger)->put("/app/products/{$this->productId}", CatalogueFixtures::form($theirs))->assertNotFound();
    $this->actingAs($stranger)->post("/app/products/{$this->productId}/archive")->assertNotFound();
    $this->actingAs($stranger)->put("/app/products/departments/{$this->ids['department']}", ['name' => 'Mine', 'colour_hex' => '#000000', 'is_active' => true, 'is_visible_on_till' => true, 'show_in_report' => true])->assertNotFound();
    $this->actingAs($stranger)->get('/app/products')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('products.data', 0));
    // Our department id is not theirs to file a product under.
    $this->actingAs($stranger)->post('/app/products', CatalogueFixtures::form($theirs, ['department_id' => $this->ids['department'], 'barcodes' => []]))
        ->assertSessionHasErrors('department_id');

    expect(DB::table('products')->count())->toBe(1)->and((bool) DB::table('products')->value('is_active'))->toBeTrue()
        ->and(DB::table('departments')->where('id', $this->ids['department'])->value('name'))->toBe('Bakery');
});

test('the list searches by name, code prefix and exact barcode, filters and pages', function () {
    $this->actingAs($this->owner)->post('/app/products', CatalogueFixtures::form($this->ids, [
        'name' => 'Seeded Batch Loaf', 'sku' => 'HOV-400', 'barcodes' => [['id' => null, 'barcode' => '5000169000001', 'pack_qty' => 1, 'is_primary' => true]],
    ]))->assertSessionHasNoErrors();

    $names = fn (string $query) => collect($this->actingAs($this->owner)->get('/app/products'.$query)->assertOk()->viewData('page')['props']['products']['data'])->pluck('name')->all();

    expect($names(''))->toBe(['Seeded Batch Loaf', 'Toastie White 800g'])
        ->and($names('?search=toastie'))->toBe(['Toastie White 800g'])
        ->and($names('?search=HOV'))->toBe(['Seeded Batch Loaf'])
        ->and($names('?search=5000169000001'))->toBe(['Seeded Batch Loaf'])
        ->and($names('?search=50001690'))->toBe([])
        ->and($names("?department={$this->ids['department']}&perPage=10&page=1"))->toHaveCount(2)
        ->and($names('?status=archived'))->toBe([])
        ->and($names('?sort=sell_price&direction=desc&perPage=10'))->toHaveCount(2);

    $this->actingAs($this->owner)->get('/app/products')->assertInertia(fn (AssertableInertia $p) => $p
        ->component('app/products/index')
        ->where('products.data.1.barcode', '5010044000701')
        ->where('products.data.1.vat', '20%')
        ->where('products.data.1.department', 'Bakery')
        ->where('counts.active', 2)
        ->where('products.meta.total', 2));
});

test('create validates formats and shows the business\'s data errors on the form', function () {
    $this->actingAs($this->owner)->post('/app/products', CatalogueFixtures::form($this->ids, [
        'name' => '', 'sell_price' => '1.999', 'tile_colour_hex' => 'red', 'barcodes' => [
            ['id' => null, 'barcode' => '5010044000701', 'pack_qty' => 1, 'is_primary' => true],
        ],
    ]))->assertSessionHasErrors(['name', 'sell_price', 'tile_colour_hex']);

    $this->actingAs($this->owner)->post('/app/products', CatalogueFixtures::form($this->ids, ['sku' => 'OTHER']))
        ->assertSessionHasErrors(['barcodes.0.barcode']);
});

test('archive and restore from the screen', function () {
    $this->actingAs($this->owner)->post("/app/products/{$this->productId}/archive")->assertRedirect()->assertSessionHas('success');
    expect((bool) DB::table('products')->value('is_active'))->toBeFalse();

    $this->actingAs($this->owner)->post("/app/products/{$this->productId}/restore")->assertSessionHas('success');
    expect((bool) DB::table('products')->value('is_active'))->toBeTrue();
});

test('departments and categories: tree, create, edit, and delete only when empty', function () {
    $this->actingAs($this->owner)->get('/app/products/categories')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->component('app/products/categories')
        ->where('departments.0.name', 'Bakery')
        ->where('departments.0.productCount', 1)
        ->where('departments.0.categories.0.children.0.name', 'Rolls'));

    $group = ['colour_hex' => '#123456', 'is_active' => true, 'is_visible_on_till' => true];
    $this->actingAs($this->owner)->post('/app/products/departments', [...$group, 'name' => 'Drinks', 'show_in_report' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/app/products/departments', [...$group, 'name' => 'drinks', 'show_in_report' => true])->assertSessionHasErrors('name');
    $drinks = DB::table('departments')->where('name', 'Drinks')->value('id');

    $this->actingAs($this->owner)->post('/app/products/categories', [...$group, 'name' => 'Soft drinks', 'department_id' => $drinks, 'age_rule_default' => 'none'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/app/products/categories', [...$group, 'name' => 'Cola', 'department_id' => $drinks, 'parent_category_id' => $this->ids['category'], 'age_rule_default' => 'none'])
        ->assertSessionHasErrors('parent_category_id');

    $this->actingAs($this->owner)->delete("/app/products/departments/{$this->ids['department']}")->assertSessionHas('error');
    $this->actingAs($this->owner)->delete("/app/products/categories/{$this->ids['sub']}")->assertSessionHas('success');

    expect(DB::table('departments')->whereNull('deleted_at')->count())->toBe(2)
        ->and(DB::table('categories')->where('id', $this->ids['sub'])->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('categories')->where('id', $this->ids['sub'])->value('hub_version'))->not->toBeNull();
});
