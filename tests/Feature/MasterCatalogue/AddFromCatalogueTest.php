<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\MasterCatalogue\MasterFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

uses(TenancyTestHelpers::class);

/** Starter catalogue (gap #7), tenant side: add from catalogue, starter pack, barcode lookup, access and isolation. */
beforeEach(function () {
    $this->withoutVite();
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->ids = CatalogueFixtures::seed($this->company);
    $this->owner = $this->memberOf($this->company, CompanyRole::Owner);

    $this->beans = MasterFixtures::product(['barcode' => '5000157024671', 'name' => 'Heinz Baked Beans 415g', 'brand' => 'Heinz', 'department' => 'Bakery', 'category' => 'Bread', 'vat_rate' => '0', 'rrp' => '1.40', 'in_starter_packs' => true]);
    $this->coke = MasterFixtures::product(['barcode' => '5449000000996', 'name' => 'Coca-Cola 500ml', 'size_value' => '500', 'size_unit' => 'ml', 'department' => 'Soft drinks', 'category' => 'Cola', 'vat_rate' => '20', 'rrp' => '1.85', 'in_starter_packs' => true]);
    $this->vodka = MasterFixtures::product(['barcode' => '5410316952705', 'name' => 'Smirnoff Red Label Vodka 70cl', 'department' => 'Beers, wines and spirits', 'category' => 'Spirits', 'vat_rate' => '20', 'rrp' => null, 'age_rule' => 'over18', 'in_starter_packs' => true]);
    $this->add = fn (array $body) => $this->actingAs($this->owner)->post('/app/products/catalogue', ['price_rule' => 'rrp', 'end_in_9' => true, ...$body]);
});

test('the search lists the master catalogue, by words or any barcode form, marking what the business already sells', function () {
    $this->actingAs($this->owner)->post('/app/products', CatalogueFixtures::form($this->ids, ['barcodes' => [['id' => null, 'barcode' => '5000157024671', 'pack_qty' => 1, 'is_primary' => true]]]))->assertSessionHasNoErrors();

    $this->get('/app/products/catalogue')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('app/products/catalogue')
        ->has('products.data', 3)->where('hasVatRates', true)->where('sharing', true)
        ->where('products.data', fn ($rows) => collect($rows)->firstWhere('barcode', '5000157024671')['inCatalogue'] === true
            && collect($rows)->firstWhere('barcode', '5449000000996')['inCatalogue'] === false));

    $this->get('/app/products/catalogue?search=cola+500')->assertInertia(fn (AssertableInertia $p) => $p->has('products.data', 1)->where('products.data.0.barcode', '5449000000996'));
    $this->get('/app/products/catalogue?search=5449000000996')->assertInertia(fn (AssertableInertia $p) => $p->has('products.data', 1));
    $this->get('/app/products/catalogue?department=Bakery')->assertInertia(fn (AssertableInertia $p) => $p->has('products.data', 1));
});

test('bulk add creates products through SaveProduct (RRP or typed prices, mapped departments) and tills pull them', function () {
    $this->freezeSecond();
    ($this->add)([
        'items' => [['barcode' => '5000157024671'], ['barcode' => '5449000000996'], ['barcode' => '5410316952705', 'sell_price' => '17.50', 'cost_price' => '12.10']],
        'departments' => ['Soft drinks' => $this->ids['department']],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $products = Product::withoutCompanyScope()->get()->keyBy('name');
    $beans = $products['Heinz Baked Beans 415g'];
    $coke = $products['Coca-Cola 500ml'];
    $vodka = $products['Smirnoff Red Label Vodka 70cl'];
    $spirits = Department::withoutCompanyScope()->where('name', 'Beers, wines and spirits')->first();

    expect($products)->toHaveCount(3)
        ->and((string) $beans->sell_price)->toBe('1.40')
        ->and($beans->department_id)->toBe($this->ids['department'])
        ->and($beans->category_id)->toBe($this->ids['category'])
        ->and($beans->vat_rate_id)->toBe($this->ids['zero'])
        ->and($beans->brand)->toBe('Heinz')
        ->and($coke->department_id)->toBe($this->ids['department'])
        ->and($coke->vat_rate_id)->toBe($this->ids['vat'])
        ->and((string) $coke->volume_ml)->toEqual('500.0000')
        ->and($spirits)->not->toBeNull()
        ->and($vodka->department_id)->toBe($spirits?->id)
        ->and((string) $vodka->sell_price)->toBe('17.50')
        ->and((string) $vodka->cost_price)->toEqual('12.1000')
        ->and($vodka->age_rule?->value)->toBe('over18')
        ->and((bool) $vodka->is_age_restricted)->toBeTrue()
        ->and(ProductBarcode::withoutCompanyScope()->where('product_id', $coke->id)->value('barcode'))->toBe('5449000000996')
        ->and(AuditLog::query()->where('action', 'catalogue.products_added')->where('company_id', $this->company->id)->count())->toBe(1);

    $changes = collect(Pull::changes($this->sync->pull(0)->assertOk()));
    expect($changes->where('entity', 'Product')->pluck('entityId')->sort()->values()->all())->toBe($products->pluck('id')->sort()->values()->all())
        ->and($changes->where('entity', 'ProductBarcode')->pluck('payload.barcode')->sort()->values()->all())->toBe(['5000157024671', '5410316952705', '5449000000996']);
});

test('barcodes the business already has are skipped, never duplicated; a product with no RRP and no price is reported', function () {
    ($this->add)(['items' => [['barcode' => '5000157024671']]])->assertSessionHasNoErrors();
    ($this->add)(['items' => [['barcode' => '5000157024671'], ['barcode' => '5410316952705'], ['barcode' => '5449000000996']]])
        ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 product added') && str_contains($m, '1 skipped') && str_contains($m, '1 not added'));

    expect(Product::withoutCompanyScope()->count())->toBe(2)
        ->and(ProductBarcode::withoutCompanyScope()->where('barcode', '5000157024671')->count())->toBe(1)
        ->and(Product::withoutCompanyScope()->where('name', 'like', 'Smirnoff%')->exists())->toBeFalse();
});

test('cost plus margin prices from the typed cost, rounded up to end in 9p; RRP when there is no cost', function () {
    ($this->add)(['price_rule' => 'margin', 'margin' => '25', 'items' => [['barcode' => '5449000000996', 'cost_price' => '1.00'], ['barcode' => '5000157024671']]])
        ->assertSessionHasNoErrors();

    // 1.00 / 0.75 = 1.3333 net, + 20% VAT = 1.60 → 1.69.
    expect((string) Product::withoutCompanyScope()->where('name', 'Coca-Cola 500ml')->value('sell_price'))->toEqual('1.69')
        ->and((string) Product::withoutCompanyScope()->where('name', 'Heinz Baked Beans 415g')->value('sell_price'))->toEqual('1.40');
});

test('the starter pack adds the starter lines of the ticked departments, suggested from the business type', function () {
    $this->company->forceFill(['business_type' => BusinessType::Newsagent])->save();
    MasterFixtures::product(['barcode' => '5010044000701', 'name' => 'Not offered', 'department' => 'Bakery', 'in_starter_packs' => false]);

    $this->actingAs($this->owner)->get('/app/products/starter')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('app/products/starter')
        ->where('pack', 'newsagent')->where('suggested', 'newsagent')
        ->where('departments', fn ($rows) => collect($rows)->firstWhere('value', 'Soft drinks')['included'] === true
            && collect($rows)->firstWhere('value', 'Bakery')['included'] === false && collect($rows)->firstWhere('value', 'Bakery')['count'] === 1));

    $this->post('/app/products/starter', ['pack' => 'convenience', 'include' => ['Bakery', 'Soft drinks'], 'price_rule' => 'rrp'])
        ->assertRedirect('/app/products')->assertSessionHasNoErrors();

    expect(Product::withoutCompanyScope()->pluck('name')->sort()->values()->all())->toBe(['Coca-Cola 500ml', 'Heinz Baked Beans 415g']);

    $this->post('/app/products/starter', ['pack' => 'convenience', 'include' => [], 'price_rule' => 'rrp'])->assertSessionHasErrors('include');
});

test('barcode lookup pre-fills the product form with the business\'s own department, category and VAT rate', function () {
    $this->actingAs($this->owner)->getJson('/app/products/catalogue/lookup?barcode=5000157024671')->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('existing', null)
        ->assertJsonPath('product.name', 'Heinz Baked Beans 415g')
        ->assertJsonPath('product.size', '415g')
        ->assertJsonPath('product.departmentId', $this->ids['department'])
        ->assertJsonPath('product.categoryId', $this->ids['category'])
        ->assertJsonPath('product.vatRateId', $this->ids['zero'])
        ->assertJsonPath('product.rrp', '1.40');

    $this->getJson('/app/products/catalogue/lookup?barcode=5449000000996')->assertJsonPath('product.departmentId', null)
        ->assertJsonPath('product.vatRateId', $this->ids['vat'])->assertJsonPath('product.volumeMl', '500');
    $this->getJson('/app/products/catalogue/lookup?barcode=5000157024672')->assertJsonPath('found', false);

    ($this->add)(['items' => [['barcode' => '5000157024671']]]);
    $this->getJson('/app/products/catalogue/lookup?barcode=5000157024671')->assertJsonPath('existing.name', 'Heinz Baked Beans 415g');
});

test('guests sign in; accountants, staff and one-shop managers get 403', function () {
    $routes = [['get', '/app/products/catalogue'], ['post', '/app/products/catalogue'], ['get', '/app/products/catalogue/lookup?barcode=5000157024671'],
        ['put', '/app/products/catalogue/sharing'], ['get', '/app/products/starter'], ['post', '/app/products/starter']];

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url)->assertRedirect('/login');
    }

    $manager = $this->memberOf($this->company, CompanyRole::Manager);
    DB::table('company_user')->where('user_id', $manager->id)->update(['branch_id' => Branch::factory()->forCompany($this->company)->create()->id]);

    foreach ([$this->memberOf($this->company, CompanyRole::Accountant), $this->memberOf($this->company, CompanyRole::Staff), $manager] as $user) {
        foreach ($routes as [$method, $url]) {
            $this->actingAs($user)->{$method}($url, ['items' => [['barcode' => '5000157024671']], 'price_rule' => 'rrp', 'share' => false])->assertForbidden();
        }
    }

    expect(Product::withoutCompanyScope()->count())->toBe(0)->and($this->company->fresh()->share_unknown_barcodes)->toBeTrue();
});

test('company A never sees or maps into company B: B\'s barcodes do not count, B\'s department is refused', function () {
    $other = Company::factory()->create();
    $otherIds = CatalogueFixtures::seed($other);
    $otherOwner = $this->memberOf($other, CompanyRole::Owner);
    $this->actingAs($otherOwner)->post('/app/products/catalogue', ['price_rule' => 'rrp', 'items' => [['barcode' => '5000157024671']]])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get('/app/products/catalogue')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('products.data', fn ($rows) => collect($rows)->every(fn ($r) => $r['inCatalogue'] === false))
            ->where('yourDepartments', fn ($d) => collect($d)->pluck('value')->all() === [$this->ids['department']]));
    $this->getJson('/app/products/catalogue/lookup?barcode=5000157024671')->assertJsonPath('existing', null)
        ->assertJsonPath('product.departmentId', $this->ids['department']);

    ($this->add)(['items' => [['barcode' => '5449000000996']], 'departments' => ['Soft drinks' => $otherIds['department']]])->assertSessionHasErrors('departments');
    ($this->add)(['items' => [['barcode' => '5000157024671']]])->assertSessionHasNoErrors();

    expect(Product::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(1)
        ->and(Product::withoutCompanyScope()->where('company_id', $other->id)->count())->toBe(1)
        ->and(Product::withoutCompanyScope()->where('company_id', $this->company->id)->value('department_id'))->toBe($this->ids['department']);
});

test('an owner turns sharing off and on; it is audited', function () {
    $this->actingAs($this->owner)->put('/app/products/catalogue/sharing', ['share' => false])->assertSessionHasNoErrors();
    expect($this->company->fresh()->share_unknown_barcodes)->toBeFalse();

    $this->put('/app/products/catalogue/sharing', ['share' => true]);
    expect($this->company->fresh()->share_unknown_barcodes)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'catalogue.sharing_changed')->count())->toBe(2);
});
