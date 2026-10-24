<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Partners\PartnerTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * OS-7: a new business on a plan that includes the online store gets the add-on switched on (store row with the chosen
 * template, not live, not taking orders); its line is billed once a till has cloud sync. Partner onboarding: Fresh
 * Market. Plans without it, and Pakistan: no store.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, PartnerTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    config(['online_store.enabled' => true]);
    $this->standardPlan();
    $this->storePlan = Plan::factory()->create([
        'name' => 'Monthly + website', 'code' => 'monthly-web', 'billing_type' => PlanBillingType::RecurringOnly, 'setup_fee' => '0.00',
        'price_monthly' => '30.00', 'price_yearly' => '300.00', 'features' => [Feature::Loyalty, Feature::CloudSync],
        'includes_online_store' => true, 'online_store_monthly_price' => '18.00', 'sort_order' => 20,
    ]);
});

function osoForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Patel News', 'legal_name' => 'Patel News Ltd', 'email' => 'hello@patelnews.test', 'phone' => '0113 496 0000',
        'contact_name' => 'Raj Patel', 'address' => '1 Market St, Leeds', 'status' => 'trial', 'trial_ends_at' => '',
        'branch_code' => 'lds', 'branch_name' => 'Leeds', 'branch_nation' => 'england', 'branch_address' => '1 Market St',
        'tills' => 2, 'owner_name' => 'Raj Patel', 'owner_email' => 'raj@patelnews.test',
    ], $overrides);
}

function osoStore(?Company $company): ?OnlineStore
{
    return $company === null ? null : OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->first();
}

function osoCompany(string $name = 'Patel News'): ?Company
{
    return Company::query()->where('name', $name)->first();
}

test('the wizard on a plan with the online store makes the store with the chosen template, not live; audited', function () {
    $this->actingAs($this->admin(AdminRole::Owner), 'admin')
        ->post('/admin/tenants', osoForm(['plan_id' => $this->storePlan->id, 'store_template' => 'butcher']))
        ->assertRedirect()->assertSessionHasNoErrors();

    $company = osoCompany();
    $store = osoStore($company);
    expect($store)->not->toBeNull()
        ->and($store->addon_on)->toBeTrue()
        ->and($store->template)->toBe(StoreTemplate::Butcher)
        ->and($store->slug)->toBe('patel-news')
        ->and($store->is_live)->toBeFalse()->and($store->taking_orders)->toBeFalse()
        ->and($store->monthly_price)->toBeNull()
        ->and(AuditLog::query()->where('action', 'online_store.addon_updated')->where('company_id', $company->id)->sole()->meta)->toEqual(['plan' => 'monthly-web'])
        // Its tills are licensed with cloud sync (the plan's features): the plan's price is on its period invoices.
        ->and(CompanyPricing::for($company, $this->billingAccountOf($company))->onlineStoreMonthly)->toBe('18.00');

    // Without cloud sync on its tills it is not charged (the store needs it), as for an add-on switched on by hand.
    foreach ($this->licencesOf($company) as $licence) {
        $licence->forceFill(['features' => [Feature::Loyalty->value]])->save();
    }

    expect(CompanyPricing::for($company->refresh(), $this->billingAccountOf($company))->onlineStoreMonthly)->toBeNull();
});

test('the wizard needs the template for a plan with the online store; nothing is made without it', function () {
    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', osoForm(['plan_id' => $this->storePlan->id]))
        ->assertSessionHasErrors(['store_template' => 'Choose the online store’s template: Monthly + website includes the online store.']);
    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', osoForm(['plan_id' => $this->storePlan->id, 'store_template' => 'pharmacy']))
        ->assertSessionHasErrors('store_template');

    expect(osoCompany())->toBeNull()->and(OnlineStore::withoutCompanyScope()->count())->toBe(0);
});

test('a plan without the online store makes no store (a template sent is ignored), as before', function () {
    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', osoForm(['plan_id' => $this->standardPlan()->id, 'store_template' => 'butcher']))
        ->assertSessionHasNoErrors();

    expect(osoCompany())->not->toBeNull()->and(OnlineStore::withoutCompanyScope()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'online_store.%')->count())->toBe(0);
});

test('with online stores switched off on the server, a plan with the store is refused and nothing is made', function () {
    config(['online_store.enabled' => false]);

    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', osoForm(['plan_id' => $this->storePlan->id, 'store_template' => 'freshMarket']))
        ->assertSessionHasErrors(['plan_id' => 'Monthly + website includes the online store. Online stores are switched off on this server (ONLINE_STORE), so the add-on cannot be switched on yet.']);

    expect(osoCompany())->toBeNull()->and(OnlineStore::withoutCompanyScope()->count())->toBe(0);
});

test('the create page lists the plans with the online store and the templates; Pakistan shows nothing', function () {
    $this->actingAs($this->admin(), 'admin')->get('/admin/tenants/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('onlineStore.plans', [$this->storePlan->id => ['price' => '18.00', 'priceLabel' => '£18.00']])
            ->where('onlineStore.templates', [['value' => 'freshMarket', 'label' => 'Fresh Market'], ['value' => 'butcher', 'label' => 'Butcher\'s Block']])
            ->where('onlineStore.serverOn', true));

    $this->asPakistan();
    $this->actingAs($this->admin(), 'admin')->get('/admin/tenants/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onlineStore', null));
});

test('Pakistan: a plan marked with the online store makes no store', function () {
    $this->asPakistan();
    $company = app(CreateTenant::class)->handle(new NewTenant(
        company: $this->newTenant('Lahore Mart')->company,
        branch: $this->newTenant()->branch,
        tills: 1,
        ownerName: 'Ali',
        ownerEmail: 'ali@lahore.test',
        planId: $this->storePlan->id,
    ));

    expect(osoStore($company))->toBeNull();
});

test('partner onboarding on a plan with the online store: Fresh Market by default', function () {
    Http::preventStrayRequests();
    Http::fake(['zivaro.test/*' => Http::response(['ok' => true])]);
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody(['plan' => 'monthly-web', 'setupFeePerTill' => null]))->assertCreated();

    $this->approveAs($this->requestFor())->assertRedirect()->assertSessionHasNoErrors();

    $store = osoStore(osoCompany('Istanbul Market'));
    expect($store)->not->toBeNull()
        ->and($store->addon_on)->toBeTrue()
        ->and($store->template)->toBe(StoreTemplate::FreshMarket)
        ->and($store->slug)->toBe('istanbul-market')
        ->and($store->is_live)->toBeFalse();
});

test('two businesses on the plan get their own stores: neither sees nor changes the other\'s', function () {
    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', osoForm(['plan_id' => $this->storePlan->id, 'store_template' => 'butcher']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', osoForm([
        'name' => 'Patel News', 'legal_name' => 'Patel News Two Ltd', 'branch_code' => 'brd', 'owner_email' => 'two@patelnews.test',
        'plan_id' => $this->storePlan->id, 'store_template' => 'freshMarket',
    ]))->assertSessionHasNoErrors();

    $companies = Company::query()->where('name', 'Patel News')->orderBy('created_at')->get();
    [$a, $b] = [$companies[0], $companies[1]];
    expect(osoStore($a)->slug)->toBe('patel-news')->and(osoStore($a)->template)->toBe(StoreTemplate::Butcher)
        ->and(osoStore($b)->slug)->toBe('patel-news-2')->and(osoStore($b)->template)->toBe(StoreTemplate::FreshMarket)
        ->and(OnlineStore::withoutCompanyScope()->where('company_id', $a->id)->count())->toBe(1)
        ->and(OnlineStore::withoutCompanyScope()->where('company_id', $b->id)->count())->toBe(1);
});
