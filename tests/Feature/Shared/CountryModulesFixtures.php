<?php

namespace Tests\Feature\Shared;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Promotions\Actions\SavePromotion;
use App\Domain\Setup\Actions\SavePaymentType;
use App\Domain\Shared\Country\Country;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionRule;
use App\Domain\TillData\Models\TillUser;
use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Staff\StaffFixtures as Staff;
use Tests\Feature\Sync\SyncApiFixtures;

/**
 * Pakistan plan P10 (the UK till modules a country profile hides): one business with what every module touches, built
 * the same way for the GB golden tests (CountryModulesGbTest) and the Pakistan ones (CountryModulesPkTest). Every UK
 * module flag is set on the rows, as a UK till or the UK portal would have stored it.
 */
final class CountryModulesFixtures
{
    public SyncApiFixtures $sync;

    public Company $company;

    public User $owner;

    /** @var array<string, string> */
    public array $ids;

    public Product $product;

    public TillUser $staff;

    public PaymentType $tender;

    public PromotionRule $offer;

    /** The flags of a UK product with every module on. */
    public const PRODUCT_FLAGS = [
        'is_deposit_item' => true, 'deposit_amount' => '0.20', 'is_lottery' => true, 'is_hfss' => true, 'vape_duty_applies' => true,
    ];

    public static function build(object $test, Closure $memberOf): self
    {
        $f = new self;
        $f->sync = new SyncApiFixtures($test);
        $f->company = $f->sync->company;
        $f->company->update(['business_type' => BusinessType::Pharmacy]);
        $f->ids = CatalogueFixtures::seed($f->company);
        Staff::roles($f->company);
        $f->owner = $memberOf($f->company, CompanyRole::Owner);
        $f->product = PricingFixtures::product($f->company, $f->ids, ['age_rule' => 'lottery18', 'is_alcohol' => true, ...self::PRODUCT_FLAGS]);
        $f->staff = Staff::member($f->company, 'Aisha Patel', '4821', Staff::CASHIER, ['is_personal_licence_holder' => true]);
        $f->tender = app(SavePaymentType::class)->handle($f->company, null, ['name' => 'Bottle return', 'is_drs_refund' => true]);
        $f->offer = $f->as(fn () => app(SavePromotion::class)->handle(null, Arr::except(PricingFixtures::offer($f->product->id, ['is_hfss_safe' => true]), ['items'])));

        return $f;
    }

    public function as(Closure $fn): mixed
    {
        return app(CurrentCompany::class)->runAs($this->company, $fn);
    }

    public static function admin(): Admin
    {
        return Admin::factory()->role(AdminRole::Owner)->create();
    }

    /** The product form for the fixture product with the given changes. */
    public function productForm(array $overrides = []): array
    {
        return CatalogueFixtures::form($this->ids, ['barcodes' => [], 'age_rule' => 'lottery18', 'is_alcohol' => true, ...self::PRODUCT_FLAGS, ...$overrides]);
    }

    /** The staff form for the fixture member with the given changes. */
    public function staffForm(array $overrides = []): array
    {
        return ['name' => 'Aisha Patel', 'role_id' => Staff::CASHIER, 'is_active' => true, 'simple_mode_override' => 'role', 'rate_per_hour' => '11.44',
            'branch_ids' => [], 'is_personal_licence_holder' => true, ...$overrides];
    }

    /**
     * A Pakistan instance (as P9's sweep test): COUNTRY=PK, the profile singleton rebuilt, the zone-derived config
     * loaded again and the till settings catalogue read afresh.
     */
    public static function pakistan(): void
    {
        config(['country.code' => 'PK']);
        app()->forgetInstance(Country::class);
        config(['reporting' => require config_path('reporting.php'), 'till-health' => require config_path('till-health.php')]);
        Closure::bind(fn () => SettingCatalogue::$sections = null, null, SettingCatalogue::class)();
    }
}
