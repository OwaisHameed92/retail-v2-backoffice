<?php

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Models\Licence;
use App\Domain\OnlineStore\Actions\SetOnlineStoreAddOn;
use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\OnlineStore\OnlineStoreOnlyHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * OS-13: an online store only business pays one "Online store" line per period (its plan's fee, VAT as usual), by the
 * same Direct Debit path. Non-payment follows the monthly rules: suspension, which takes the store offline (there are
 * no tills to lock), and touches no other business. Every other business pays exactly what it paid before.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class, OnlineStoreOnlyHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-26 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    config(['online_store.enabled' => true]);
    $this->standardPlan();
});

/** Every till of the business with cloud sync (the store needs it). */
function oobCloudSync(object $test, Company $company): Company
{
    foreach ($test->licencesOf($company) as $licence) {
        $licence->forceFill(['features' => [Feature::Loyalty->value, Feature::CloudSync->value]])->save();
    }

    return $company->refresh();
}

function oobAddOn(Company $company): void
{
    app(SetOnlineStoreAddOn::class)->handle($company, new OnlineStoreAddOnInput(true, null, StoreTemplate::FreshMarket));
}

/** [gross a period, the online store net (or null), lines, pricing without tills]. */
function oobAmounts(object $test, Company $company): array
{
    $account = $test->billingAccountOf($company);
    $amount = SubscriptionAmount::for($company, $account);

    return [$amount['gross'], $amount['onlineStore'], $amount['units'], CompanyPricing::for($company, $account)->withoutTills];
}

test('existing businesses pay what they paid before: per till, per branch, with and without the add-on, no tills, setup only', function () {
    $perTill = $this->directDebitTenant('Per Till Stores', 2, 'PTS');
    $perBranch = $this->directDebitTenant('Per Branch Stores', 3, 'PBS');
    $account = $this->billingAccountOf($perBranch);
    $account->forceFill(['pricing_mode_override' => PricingMode::PerBranch, 'price_monthly_override' => '40.00'])->save();
    $withStore = oobCloudSync($this, $this->directDebitTenant('Store Stores', 2, 'STS'));
    oobAddOn($withStore);
    $noCloudSync = $this->directDebitTenant('Plain Stores', 2, 'PLS');
    OnlineStore::withoutCompanyScope()->create(['company_id' => $noCloudSync->id, 'slug' => 'plain-stores', 'addon_on' => true, 'is_live' => false, 'taking_orders' => false]);
    $noTills = oobCloudSync($this, $this->directDebitTenant('Closed Stores', 1, 'CLS'));
    oobAddOn($noTills);

    foreach ($this->licencesOf($noTills) as $licence) {
        app(RevokeLicence::class)->handle($licence, 'Shop closed');
    }

    expect(oobAmounts($this, $perTill))->toBe(['60.00', null, 2, false])
        ->and(oobAmounts($this, $perBranch))->toBe(['48.00', null, 1, false])
        ->and(oobAmounts($this, $withStore))->toBe(['84.00', '20.00', 2, false])
        ->and(oobAmounts($this, $noCloudSync))->toBe(['60.00', null, 2, false])
        ->and(oobAmounts($this, $noTills->refresh()))->toBe(['0.00', null, 0, false])
        ->and(fn () => $this->draftFor($noTills))->toThrow(ValidationException::class, 'no active tills');

    $setupOnly = $this->licensedTenant('Setup Stores', 1, 'SUS');
    $plan = $this->standardPlan();
    $plan->forceFill(['billing_type' => PlanBillingType::SetupOnly, 'setup_fee' => '500.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00'])->save();
    expect(oobAmounts($this, $setupOnly)[0])->toBe('0.00');
});

test('an online store only business pays the single store line; yearly is 12 months; its plan bills even when no longer offered', function () {
    $company = $this->webOnlyBusiness($this->webOnlyPlan('30.00'));
    expect(oobAmounts($this, $company))->toBe(['36.00', '30.00', 0, true]);

    $invoice = $this->draftFor($company);
    expect($invoice->lines->map(fn ($l) => [$l->description, $l->unit_price, $l->vat, $l->gross, $l->licence_id, $l->branch_id])->all())
        ->toBe([['Online store · 26 Oct – 25 Nov 2026', '30.00', '6.00', '36.00', null, null]]);
    $invoice->delete();

    $account = $this->billingAccountOf($company);
    $account->forceFill(['cycle' => BillingCycle::Yearly])->save();
    expect(oobAmounts($this, $company)[0])->toBe('432.00');
    $account->forceFill(['cycle' => BillingCycle::Monthly])->save();

    $company->plan->forceFill(['is_active' => false])->save();
    expect(oobAmounts($this, $company->refresh())[0])->toBe('36.00');
});

test('Direct Debit: the mandate subscription collects the store fee; without a mandate by the deadline the business is suspended and only its store goes offline', function () {
    $plan = $this->webOnlyPlan('30.00');
    $late = $this->webOnlyBusiness($plan);
    $paying = $this->webOnlyBusiness($plan, 'Marco Deli', 'marco@marcodeli.test');
    $this->webOnlyLive($late);
    $this->webOnlyLive($paying);
    $this->setUpMandate($paying);

    expect($this->billingAccountOf($paying)->gc_subscription_amount)->toBe('36.00')
        ->and($this->billingAccountOf($late)->mandate_deadline_at)->not->toBeNull();

    $this->runBillingOn('2026-10-29');
    $this->runBillingOn('2026-10-30');

    expect($late->refresh()->status)->toBe(CompanyStatus::Suspended)
        ->and(app(FindStoreBySlug::class)->handle($this->storeOf($late)->slug))->toBeNull()
        ->and(Licence::withoutCompanyScope()->whereIn('company_id', [$late->id, $paying->id])->count())->toBe(0)
        ->and($paying->refresh()->status)->not->toBe(CompanyStatus::Suspended)
        ->and(app(FindStoreBySlug::class)->handle($this->storeOf($paying)->slug))->not->toBeNull();
});

test('an unpaid store invoice goes overdue, then suspends the business (store offline); paying it brings the store back', function () {
    $company = $this->webOnlyBusiness($this->webOnlyPlan('30.00'));
    $this->webOnlyLive($company);
    $account = $this->billingAccountOf($company);
    $account->forceFill(['billing_mode' => BillingMode::UpfrontCash, 'mandate_deadline_at' => null])->save();
    $invoice = $this->issuedFor($company);
    $slug = $this->storeOf($company)->slug;

    $this->runBillingOn('2026-11-04');
    expect($company->refresh()->status)->toBe(CompanyStatus::Overdue)
        ->and(app(FindStoreBySlug::class)->handle($slug))->not->toBeNull();

    $this->runBillingOn('2026-11-20');
    expect($company->refresh()->status)->toBe(CompanyStatus::Suspended)
        ->and($invoice->fresh()->status->value)->toBe('overdue')
        ->and(app(FindStoreBySlug::class)->handle($slug))->toBeNull();

    $this->pay($company, '36.00');
    expect($company->refresh()->status)->not->toBe(CompanyStatus::Suspended)
        ->and(app(FindStoreBySlug::class)->handle($slug))->not->toBeNull();
});
