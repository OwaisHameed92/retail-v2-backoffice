<?php

use App\Domain\Accounts\Export\ExportDefaults;
use App\Domain\Accounts\Export\ExportTarget;
use App\Domain\MasterCatalogue\Data\PriceRule;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\PortalUsers\Support\RoleMatrix;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\CountryModules;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\TillData\Actions\SaveTillSetting;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionRule;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\TillData\Models\TillUser;
use App\Domain\TillData\Sync\SyncRowIds;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Tenancy\TenancyTestHelpers;
use Tests\Feature\TillData\TillFixtures;

/*
 * Pakistan plan P10 (`country-pk`): the UK till modules are hidden on a Pakistan instance (shared flags, 404 routes,
 * no till settings or permission row), forms keep the hidden values as stored, a till's push of those fields is stored
 * exactly as on GB, "end in 9" rounds to whole rupees and the accounting export suggests Pakistani tax codes. The rows
 * were made as on GB (CountryModulesFixtures) before the instance turns to PK. GB golden: CountryModulesGbTest.
 */

uses(TenancyTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-10-05 09:00:00');
    $this->f = CountryModulesFixtures::build($this, fn ($company, $role) => $this->memberOf($company, $role));
});

it('turns every UK module off and shares only those flags with the front end', function () {
    CountryModulesFixtures::pakistan();

    foreach (CountryModules::ALL as $module) {
        expect(CountryModules::on($module))->toBeFalse($module);
    }

    $off = array_fill_keys(CountryModules::ALL, false);
    expect(app(Country::class)->toFrontend()['features'])->toBe(['vatReturn' => false, 'fbr' => false, ...$off]);
    $this->actingAs($this->f->owner)->get('/app/products')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('country.features', ['vatReturn' => false, 'fbr' => false, ...$off]));
})->group('country-pk');

it('hides pharmacy: no menu entry or permission row, and its routes answer 404 even for a pharmacy', function () {
    CountryModulesFixtures::pakistan();

    $this->actingAs($this->f->owner)->get('/app')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('abilities', fn ($abilities) => ! collect($abilities)->contains('pharmacy.view')));
    $this->actingAs($this->f->owner)->get('/app/pharmacy')->assertNotFound();
    $this->actingAs($this->f->owner)->get('/app/pharmacy/medicines')->assertNotFound();
    $this->actingAs($this->f->owner)->put("/app/pharmacy/medicines/{$this->f->product->id}", ['class' => 'pharmacyOnly'])->assertNotFound();
    $this->actingAs($this->f->owner)->delete("/app/pharmacy/medicines/{$this->f->product->id}")->assertNotFound();
    auth()->logout();
    $this->get('/app/pharmacy')->assertRedirect('/login');

    expect(collect(RoleMatrix::rows())->pluck('key'))->not->toContain('pharmacy.view')
        ->and(DB::table('medicine_classifications')->count())->toBe(0);
})->group('country-pk');

it('hides the bottle deposit and lottery till settings and refuses to save them; the stored values stay', function () {
    foreach (['receipt.show_drs_lines', 'customers.loyalty_points_on_lottery'] as $key) {
        app(SaveTillSetting::class)->handle($this->f->company, SettingScope::Company, null, $key, 'true'); // as the UK portal saved them
    }
    CountryModulesFixtures::pakistan();

    $keys = collect($this->actingAs($this->f->owner)->get('/app/settings')->assertOk()->inertiaProps('sections'))->flatMap(fn ($s) => collect($s['settings'])->pluck('key'));
    expect($keys)->not->toContain('receipt.show_drs_lines')->not->toContain('customers.loyalty_points_on_lottery')->toContain('receipt.footer_text')
        ->and(SettingCatalogue::find('receipt.show_drs_lines'))->toBeNull();

    $this->actingAs($this->f->owner)->put('/app/settings', ['shop' => null, 'values' => ['receipt.show_drs_lines' => false]])
        ->assertSessionHasErrors('values.receipt.show_drs_lines');
    $this->actingAs($this->f->owner)->put('/app/settings', ['shop' => null, 'values' => ['customers.loyalty_points_on_lottery' => false]])
        ->assertSessionHasErrors('values.customers.loyalty_points_on_lottery');

    expect(TillSetting::query()->whereIn('setting_key', ['receipt.show_drs_lines', 'customers.loyalty_points_on_lottery'])->pluck('value')->all())->toBe(['true', 'true']);
})->group('country-pk');

it('keeps a product\'s hidden deposit, lottery, HFSS and vaping duty values when the form is saved; a new product gets the defaults', function () {
    CountryModulesFixtures::pakistan();
    $id = $this->f->product->id;

    $this->actingAs($this->f->owner)->get("/app/products/{$id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('values.age_rule', 'lottery18'));
    $off = ['name' => 'Toastie Brown 800g', 'is_deposit_item' => false, 'deposit_amount' => null, 'is_lottery' => false, 'is_hfss' => false, 'vape_duty_applies' => false];
    $this->actingAs($this->f->owner)->put("/app/products/{$id}", $this->f->productForm($off))->assertSessionHasNoErrors();

    $stored = Product::withoutCompanyScope()->find($id);
    expect($stored->name)->toBe('Toastie Brown 800g')
        ->and($stored->only(['is_deposit_item', 'is_lottery', 'is_hfss', 'vape_duty_applies']))
        ->toBe(['is_deposit_item' => true, 'is_lottery' => true, 'is_hfss' => true, 'vape_duty_applies' => true])
        ->and($stored->deposit_amount)->toEqual('0.20')
        ->and($stored->age_rule->value)->toBe('lottery18')
        ->and($stored->is_alcohol)->toBeTrue();

    // A hidden field sent with junk is not even checked; a new product starts with the defaults.
    $this->actingAs($this->f->owner)->post('/app/products', $this->f->productForm(['sku' => 'NEW-1', 'name' => 'Rooh Afza', 'deposit_amount' => 'lots']))
        ->assertSessionHasNoErrors();
    $new = Product::withoutCompanyScope()->where('sku', 'NEW-1')->sole();
    expect($new->only(['is_deposit_item', 'is_lottery', 'is_hfss', 'vape_duty_applies']))
        ->toBe(['is_deposit_item' => false, 'is_lottery' => false, 'is_hfss' => false, 'vape_duty_applies' => false])
        ->and($new->deposit_amount)->toBeNull();
})->group('country-pk');

it('keeps the hidden personal licence holder, deposit return tender and HFSS offer flags when their forms are saved', function () {
    CountryModulesFixtures::pakistan();

    $this->actingAs($this->f->owner)->put("/app/staff/{$this->f->staff->id}", $this->f->staffForm(['name' => 'Aisha Khan', 'is_personal_licence_holder' => false]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->f->owner)->put("/app/payment-types/{$this->f->tender->id}", ['name' => 'Bottle refund', 'is_drs_refund' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->f->owner)->put("/app/promotions/{$this->f->offer->id}", PricingFixtures::offer($this->f->product->id, ['name' => 'Eid offer', 'is_hfss_safe' => false]))
        ->assertSessionHasNoErrors();

    expect(TillUser::withoutCompanyScope()->find($this->f->staff->id)->only(['name', 'is_personal_licence_holder']))->toBe(['name' => 'Aisha Khan', 'is_personal_licence_holder' => true])
        ->and(PaymentType::withoutCompanyScope()->find($this->f->tender->id)->only(['name', 'is_drs_refund']))->toBe(['name' => 'Bottle refund', 'is_drs_refund' => true])
        ->and(PromotionRule::withoutCompanyScope()->find($this->f->offer->id)->only(['name', 'is_hfss_safe']))->toBe(['name' => 'Eid offer', 'is_hfss_safe' => true]);

    // New rows get the defaults whatever the request says.
    $this->actingAs($this->f->owner)->post('/app/staff', [...$this->f->staffForm(['name' => 'Bilal']), 'pin' => '5820', 'pin_confirmation' => '5820'])->assertSessionHasNoErrors();
    $this->actingAs($this->f->owner)->post('/app/payment-types', ['name' => 'Easypaisa', 'is_drs_refund' => true])->assertSessionHasNoErrors();
    expect(TillUser::withoutCompanyScope()->where('name', 'Bilal')->sole()->is_personal_licence_holder)->toBeFalse()
        ->and(PaymentType::withoutCompanyScope()->where('name', 'Easypaisa')->sole()->is_drs_refund)->toBeFalse();
})->group('country-pk');

it('keeps a branch\'s hidden deposit return point and licensed hours when the admin saves it', function () {
    $branch = $this->f->sync->bradford;
    $branch->forceFill(['is_drs_return_point' => true, 'licensed_hours_json' => '{"mon":"10:00-23:00"}'])->saveQuietly();
    CountryModulesFixtures::pakistan();
    $admin = CountryModulesFixtures::admin();

    $this->actingAs($admin, 'admin')->put("/admin/tenants/{$this->f->company->id}/branches/{$branch->id}", [
        'code' => 'BRD', 'name' => 'Gulberg', 'nation' => 'england', 'is_drs_return_point' => false, 'licensed_hours_json' => 'not json',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($branch->fresh())->name->toBe('Gulberg')->is_drs_return_point->toBeTrue()->licensed_hours_json->toBe('{"mon":"10:00-23:00"}');

    $this->actingAs($admin, 'admin')->post("/admin/tenants/{$this->f->company->id}/branches", [
        'code' => 'DHA', 'name' => 'DHA', 'nation' => 'england', 'tills' => 0, 'is_drs_return_point' => true, 'licensed_hours_json' => '{"mon":"1"}',
    ])->assertSessionHasNoErrors();
    expect(DB::table('branches')->where('code', 'DHA')->first(['is_drs_return_point', 'licensed_hours_json']))
        ->is_drs_return_point->toBeFalsy()->licensed_hours_json->toBeNull();
})->group('country-pk');

it('stores a till push of deposit, lottery, licensing, HFSS, vaping duty and pharmacy fields exactly as sent', function () {
    CountryModulesFixtures::pakistan();
    $id = fn (string $suffix) => '01K5T0Q8C40000000000P1'.$suffix;
    $setting = ['scope' => 'branch', 'scopeId' => TillFixtures::LEEDS, 'key' => 'receipt.show_drs_lines', 'value' => 'true', 'updatedAt' => '2026-10-05T08:00:00Z'];

    $reply = $this->f->sync->push([
        TillFixtures::envelope('Product', Pull::payload('Product', $id('0001'), [
            'name' => 'Murree Beer', 'isDepositItem' => true, 'depositAmount' => 0.1, 'isLottery' => true, 'isHfss' => true, 'vapeDutyApplies' => true,
            'isAlcohol' => true, 'ageRule' => 'lottery18',
        ]), 1),
        TillFixtures::envelope('PaymentType', Pull::payload('PaymentType', $id('0002'), ['name' => 'Bottle return', 'isDrsRefund' => true]), 2),
        TillFixtures::envelope('PromotionRule', Pull::payload('PromotionRule', $id('0003'), [
            'type' => 'quantityPrice', 'branchId' => TillFixtures::LEEDS, 'effectiveFrom' => '2026-10-01', 'effectiveTo' => null, 'isActive' => true, 'isHfssSafe' => true,
        ]), 3),
        TillFixtures::envelope('MedicineClassification', Pull::payload('MedicineClassification', $id('0004'), ['productId' => $id('0001'), 'class' => 'pharmacyOnly']), 4),
        ['seq' => 5, 'entity' => 'Setting', 'entityId' => SyncRowIds::setting('branch', TillFixtures::LEEDS, 'receipt.show_drs_lines'), 'op' => 'U', 'version' => 5,
            'companyId' => TillFixtures::COMPANY, 'branchId' => '', 'registerId' => '', 'at' => $setting['updatedAt'], 'payload' => $setting, 'key' => 'Setting:5'],
    ])->assertOk();
    expect(TillFixtures::ack($reply->json()))->toBe(['acknowledgedSeq' => 5, 'accepted' => 5]);

    expect(Product::withoutCompanyScope()->find($id('0001')))
        ->is_deposit_item->toBeTrue()->deposit_amount->toEqual('0.10')->is_lottery->toBeTrue()->is_hfss->toBeTrue()->vape_duty_applies->toBeTrue()
        ->is_alcohol->toBeTrue()
        ->and(Product::withoutCompanyScope()->find($id('0001'))->age_rule->value)->toBe('lottery18')
        ->and(PaymentType::withoutCompanyScope()->find($id('0002'))->is_drs_refund)->toBeTrue()
        ->and(PromotionRule::withoutCompanyScope()->find($id('0003'))->is_hfss_safe)->toBeTrue()
        ->and(DB::table('medicine_classifications')->where('id', $id('0004'))->value('class'))->toBe('pharmacyOnly')
        ->and(TillSetting::withoutCompanyScope()->where('setting_key', 'receipt.show_drs_lines')->value('value'))->toBe('true');

    // The till's own Branch row with its deposit return point and licensed hours.
    $this->f->sync->leeds->forceFill(['name' => 'Gulberg'])->save();
    $branch = collect(Pull::changes($this->f->sync->pull(0)))->firstWhere('entity', 'Branch')['payload'];
    $this->f->sync->push([TillFixtures::envelope('Branch', [...$branch, 'isDrsReturnPoint' => true, 'licensedHoursJson' => '{"fri":"10:00-01:00"}', 'rowVersion' => 9,
        'updatedAt' => '2026-10-05T08:05:00Z'], 6, ['branchId' => TillFixtures::LEEDS, 'op' => 'U'])])->assertOk();
    expect($this->f->sync->leeds->fresh())->is_drs_return_point->toBeTrue()->licensed_hours_json->toBe('{"fri":"10:00-01:00"}');
})->group('country-pk');

it('answers 404 to "Load starter set" and refuses the console command', function () {
    CountryModulesFixtures::pakistan();

    $this->actingAs(CountryModulesFixtures::admin(), 'admin')->post('/admin/catalogue/starter')->assertNotFound();
    $this->artisan('catalogue:starter')->assertFailed();

    expect(MasterProduct::query()->count())->toBe(0);
})->group('country-pk');

it('rounds "end in 9" up to whole rupees ending in 9', function () {
    CountryModulesFixtures::pakistan();
    $rule = new PriceRule('margin', 30.0, true);

    expect($rule->sellPrice(null, '86.38', null, 0))->toBe('129.00')    // 123.40 → 129
        ->and($rule->sellPrice(null, '840.70', null, 0))->toBe('1209.00') // 1,201 → 1,209
        ->and($rule->sellPrice(null, '90.30', null, 0))->toBe('129.00')   // exactly 129 stays
        ->and($rule->sellPrice(null, '90.31', null, 0))->toBe('139.00')   // 129.01 → 139
        ->and($rule->sellPrice('150', '86.38', null, 0))->toBe('150.00')   // a typed price wins
        ->and((new PriceRule('margin', 30.0, false))->sellPrice(null, '86.38', null, 0))->toBe('123.40')
        ->and(PriceRule::wholeEndingIn9(99999))->toBe(1009);
})->group('country-pk');

it('suggests Pakistani tax codes for every accounting package', function () {
    CountryModulesFixtures::pakistan();

    foreach (ExportTarget::cases() as $target) {
        expect(ExportDefaults::vat($target))->toBe([
            'S' => ['GST 18% (sales)', 'GST 18% (purchases)'], 'R' => ['GST reduced rate (sales)', 'GST reduced rate (purchases)'],
            'Z' => ['Zero rated', 'Zero rated'], 'E' => ['GST exempt', 'GST exempt'], 'O' => ['No GST', 'No GST'], '-' => ['No GST', 'No GST'],
        ]);
    }

    $this->actingAs($this->f->owner)->get('/app/accounts/export/mappings?target=xero')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('vat.0.code', 'S')->where('vat.0.name', 'Standard 18%')->where('vat.0.defaultSales', 'GST 18% (sales)')
        ->where('vat', fn ($rows) => collect($rows)->every(fn ($r) => ! str_contains($r['defaultSales'].$r['defaultPurchases'].$r['name'], 'VAT'))));
})->group('country-pk');
