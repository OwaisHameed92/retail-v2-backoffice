<?php

use App\Domain\Accounts\Export\ExportDefaults;
use App\Domain\Accounts\Export\ExportTarget;
use App\Domain\MasterCatalogue\Data\PriceRule;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\PortalUsers\Support\RoleMatrix;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\CountryModules;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionRule;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\TillData\Models\TillUser;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

/*
 * Pakistan plan P10, GB golden: every UK till module the PK profile hides (deposit return, lottery, alcohol licensing,
 * HFSS, vaping duty, the master catalogue starter set, pharmacy) is shown and works on GB exactly as before, and the
 * accounting export and "end in 9" keep their UK rules. The Pakistan side is CountryModulesPkTest.
 */

uses(TenancyTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-10-05 09:00:00');
    $this->f = CountryModulesFixtures::build($this, fn ($company, $role) => $this->memberOf($company, $role));
});

it('has every UK module on and shares the same features as before', function () {
    foreach (CountryModules::ALL as $module) {
        expect(CountryModules::on($module))->toBeTrue($module);
    }

    expect(app(Country::class)->toFrontend()['features'])->toBe(['vatReturn' => true, 'fbr' => false]);
    $this->actingAs($this->f->owner)->get('/app/products')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('country.features', ['vatReturn' => true, 'fbr' => false]));
});

it('keeps the pharmacy menu, pages, medicine class saves and permission row', function () {
    $this->actingAs($this->f->owner)->get('/app')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('abilities', fn ($abilities) => collect($abilities)->contains('pharmacy.view')));
    $this->actingAs($this->f->owner)->get('/app/pharmacy')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/pharmacy/dispensing'));
    $this->actingAs($this->f->owner)->get('/app/pharmacy/medicines')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/pharmacy/medicines'));
    $this->actingAs($this->f->owner)->put("/app/pharmacy/medicines/{$this->f->product->id}", ['class' => 'pharmacyOnly'])->assertSessionHasNoErrors();

    expect(collect(RoleMatrix::rows())->pluck('key'))->toContain('pharmacy.view');
});

it('keeps the till settings for bottle deposit lines and points on lottery', function () {
    expect(SettingCatalogue::find('receipt.show_drs_lines'))->toMatchArray(['label' => 'Bottle deposit lines', 'help' => 'Deposit return scheme charges as their own lines.'])
        ->and(SettingCatalogue::find('customers.loyalty_points_on_lottery'))->toMatchArray(['label' => 'Points on lottery', 'help' => 'On: lottery sales earn points too.']);

    $keys = fn (array $props) => collect($props['sections'])->flatMap(fn ($s) => collect($s['settings'])->pluck('key'))->all();
    $props = $this->actingAs($this->f->owner)->get('/app/settings')->assertOk()->inertiaProps();
    expect($keys($props))->toContain('receipt.show_drs_lines', 'customers.loyalty_points_on_lottery');

    $this->actingAs($this->f->owner)->put('/app/settings', ['shop' => null, 'values' => ['receipt.show_drs_lines' => true, 'customers.loyalty_points_on_lottery' => true]])
        ->assertSessionHasNoErrors();
    expect(TillSetting::query()->whereIn('setting_key', ['receipt.show_drs_lines', 'customers.loyalty_points_on_lottery'])->pluck('value')->all())->toBe(['true', 'true']);
});

it('saves the deposit, lottery, HFSS and vaping duty flags and offers the lottery age rule on the product form', function () {
    $this->actingAs($this->f->owner)->get("/app/products/{$this->f->product->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('options.ageRules', fn ($rules) => collect($rules)->contains(fn ($r) => $r['value'] === 'lottery18' && $r['label'] === 'Lottery (18)'))
        ->where('values.is_lottery', true)->where('values.is_deposit_item', true)->where('values.deposit_amount', '0.20'));

    $off = ['is_deposit_item' => false, 'deposit_amount' => null, 'is_lottery' => false, 'is_hfss' => false, 'vape_duty_applies' => false, 'age_rule' => 'none'];
    $this->actingAs($this->f->owner)->put("/app/products/{$this->f->product->id}", $this->f->productForm($off))->assertSessionHasNoErrors();

    $stored = Product::withoutCompanyScope()->find($this->f->product->id);
    expect($stored->only(['is_deposit_item', 'is_lottery', 'is_hfss', 'vape_duty_applies']))
        ->toBe(['is_deposit_item' => false, 'is_lottery' => false, 'is_hfss' => false, 'vape_duty_applies' => false])
        ->and($stored->age_rule->value)->toBe('none');

    $this->actingAs($this->f->owner)->put("/app/products/{$this->f->product->id}", $this->f->productForm())->assertSessionHasNoErrors();
    expect(Product::withoutCompanyScope()->find($this->f->product->id))
        ->is_deposit_item->toBeTrue()->deposit_amount->toEqual('0.20')->is_lottery->toBeTrue()->is_hfss->toBeTrue()->vape_duty_applies->toBeTrue();
});

it('saves the personal licence holder, the deposit return tender and the HFSS offer flag', function () {
    $this->actingAs($this->f->owner)->put("/app/staff/{$this->f->staff->id}", $this->f->staffForm(['is_personal_licence_holder' => false]))->assertSessionHasNoErrors();
    $this->actingAs($this->f->owner)->put("/app/payment-types/{$this->f->tender->id}", ['name' => 'Bottle return', 'is_drs_refund' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->f->owner)->put("/app/promotions/{$this->f->offer->id}", PricingFixtures::offer($this->f->product->id, ['is_hfss_safe' => false]))
        ->assertSessionHasNoErrors();

    expect(TillUser::withoutCompanyScope()->find($this->f->staff->id)->is_personal_licence_holder)->toBeFalse()
        ->and(PaymentType::withoutCompanyScope()->find($this->f->tender->id)->is_drs_refund)->toBeFalse()
        ->and(PromotionRule::withoutCompanyScope()->find($this->f->offer->id)->is_hfss_safe)->toBeFalse();
});

it('saves a branch\'s deposit return point and licensed hours from the admin form', function () {
    $branch = $this->f->sync->bradford;
    $this->actingAs(CountryModulesFixtures::admin(), 'admin')->put("/admin/tenants/{$this->f->company->id}/branches/{$branch->id}", [
        'code' => 'BRD', 'name' => 'Bradford', 'nation' => 'england', 'is_drs_return_point' => true, 'licensed_hours_json' => '{"mon":"10:00-23:00"}',
    ])->assertSessionHas('success');

    expect($branch->fresh())->is_drs_return_point->toBeTrue()->licensed_hours_json->toBe('{"mon":"10:00-23:00"}');
});

it('loads the UK starter set from the admin catalogue', function () {
    $this->actingAs(CountryModulesFixtures::admin(), 'admin')->post('/admin/catalogue/starter')->assertRedirect()->assertSessionHas('success');

    expect(MasterProduct::query()->count())->toBeGreaterThan(500);
});

it('rounds "end in 9" to 9p on GB', function () {
    $rule = new PriceRule('margin', 30.0, true);

    expect($rule->sellPrice(null, '86.38', null, 0))->toBe('123.49')
        ->and($rule->sellPrice(null, '840.70', null, 0))->toBe('1201.09')
        ->and($rule->sellPrice(null, '0.80', null, 20))->toBe('1.39')
        ->and((new PriceRule('margin', 30.0, false))->sellPrice(null, '86.38', null, 0))->toBe('123.40');
});

it('keeps the UK accounting packages\' default tax codes', function () {
    expect(ExportDefaults::vat(ExportTarget::Xero)['S'])->toBe(['No VAT', 'No VAT'])
        ->and(ExportDefaults::vat(ExportTarget::Sage50)['S'])->toBe(['T1', 'T1'])
        ->and(ExportDefaults::vatCodes())->toBe(ExportDefaults::VAT_CODES);

    $this->actingAs($this->f->owner)->get('/app/accounts/export/mappings?target=xero')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('vat.0.code', 'S')->where('vat.0.name', 'Standard 20%')->where('vat.0.defaultSales', 'No VAT'));
});
