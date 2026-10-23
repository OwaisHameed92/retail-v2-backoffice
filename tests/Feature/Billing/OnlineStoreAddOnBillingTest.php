<?php

use App\Domain\Billing\Actions\UpdateBillingSettings;
use App\Domain\Billing\Data\BillingSettingsInput;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\OnlineStore\Actions\SetOnlineStoreAddOn;
use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * OS-6 (owner 2026-10-24): the online store add-on is a line "Online store" on the monthly invoice of a business on a
 * monthly plan, collected by the same Direct Debit. Nobody's bill changes until an admin switches it on.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    config(['online_store.enabled' => true]);
});

/** Its tills get cloud sync (the online dashboard). */
function osbCloudSync(object $test, Company $company): Company
{
    foreach ($test->licencesOf($company) as $licence) {
        $licence->forceFill(['features' => [Feature::Loyalty->value, Feature::CloudSync->value]])->save();
    }

    return $company;
}

function osbSet(Company $company, bool $on = true, ?string $price = null, ?StoreTemplate $template = StoreTemplate::FreshMarket): OnlineStore
{
    return app(SetOnlineStoreAddOn::class)->handle($company, new OnlineStoreAddOnInput($on, $price, $template))['store'];
}

/** @return array<string, string> description => "unit / vat / gross" */
function osbLines(Invoice $invoice): array
{
    return $invoice->lines->sortBy('position')->mapWithKeys(fn ($l) => [$l->description => "{$l->unit_price} / {$l->vat} / {$l->gross}"])->all();
}

test('without the add-on the invoice is exactly as before; with it, an "Online store" line at £20 + VAT', function () {
    $without = osbCloudSync($this, $this->payingTenant('Stoney Mini Mart', 2, 'STN'));
    $with = osbCloudSync($this, $this->payingTenant('Khan Mini Mart', 2, 'KHN'));
    osbSet($with);

    $plain = $this->draftFor($without);
    expect($plain->lines)->toHaveCount(2)->and($plain->total)->toBe('60.00')
        ->and(SubscriptionAmount::for($without, $this->billingAccountOf($without))['onlineStore'])->toBeNull();

    $invoice = $this->draftFor($with);
    expect(osbLines($invoice))->toHaveCount(3)
        ->and(osbLines($invoice)['Online store · 1 Nov – 30 Nov 2026'])->toBe('20.00 / 4.00 / 24.00')
        ->and($invoice->lines->firstWhere('description', 'Online store · 1 Nov – 30 Nov 2026')->licence_id)->toBeNull()
        ->and($invoice->subtotal)->toBe('70.00')
        ->and($invoice->total)->toBe('84.00');

    $amount = SubscriptionAmount::for($with, $this->billingAccountOf($with));
    expect($amount['gross'])->toBe('84.00')->and($amount['units'])->toBe(2)->and($amount['unitPrice'])->toBe('25.00')->and($amount['onlineStore'])->toBe('20.00');
});

test('a business price overrides the default; VAT follows the business; a yearly cycle pays 12 months', function () {
    $company = osbCloudSync($this, $this->payingTenant());
    osbSet($company, price: '15.50');

    expect(osbLines($this->draftFor($company))['Online store · 1 Nov – 30 Nov 2026'])->toBe('15.50 / 3.10 / 18.60');
    Invoice::withoutCompanyScope()->where('company_id', $company->id)->delete();

    $this->setVat(true, false, $company);
    expect(osbLines($this->draftFor($company))['Online store · 1 Nov – 30 Nov 2026'])->toBe('15.50 / 0.00 / 15.50');
    Invoice::withoutCompanyScope()->where('company_id', $company->id)->delete();

    $this->setVat(true, true, $company);
    app(UpdateBillingSettings::class)->handle($company, new BillingSettingsInput(billingName: null, billingAddress: null, emails: [], cycle: BillingCycle::Yearly, paymentTermsDays: 7, vatApplies: true));
    expect(osbLines($this->draftFor($company))['Online store · 1 Nov 2026 – 31 Oct 2027'])->toBe('186.00 / 37.20 / 223.20');
});

test('switched on mid-period: nothing for the current period, on every invoice from the next; off: gone from the next', function () {
    $company = osbCloudSync($this, $this->payingTenant());
    $november = $this->issuedFor($company);
    expect($november->total)->toBe('60.00');

    osbSet($company); // 24 Oct: the November invoice is already out
    expect($this->fresh($november)->total)->toBe('60.00')
        ->and(Invoice::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1);

    $this->pay($company, '60.00');
    $december = $this->issuedFor($company);
    expect($december->period_start->format('Y-m-d'))->toBe('2026-12-01')->and($december->total)->toBe('84.00');

    osbSet($company, on: false);
    $this->pay($company, '84.00');
    expect($this->issuedFor($company)->total)->toBe('60.00')
        ->and($this->fresh($december)->total)->toBe('84.00'); // an invoice already raised stays as it is
});

test('the Direct Debit amount follows switching on, a new price and switching off, from the next payment', function () {
    $company = osbCloudSync($this, $this->directDebitTenant(tills: 2));
    $this->setUpMandate($company);
    $subscription = $this->gc->lastSubscription();
    expect($subscription->amountPence)->toBe(6000);

    osbSet($company);
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(8400)
        ->and($this->billingAccountOf($company)->gc_subscription_amount)->toBe('84.00')
        ->and(AuditLog::query()->where('action', 'billing.dd_subscription_amount_changed')->get()->pluck('meta.reason')->all())->toContain('onlineStore');

    osbSet($company, price: '10.00');
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(7200);

    osbSet($company, on: false);
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(6000);
});

test('a setup-only business is refused: move it to a monthly plan first; nothing changes', function () {
    $company = osbCloudSync($this, $this->payingTenant());
    $this->standardPlan()->forceFill(['billing_type' => PlanBillingType::SetupOnly, 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'setup_fee' => '500.00'])->save();
    $before = SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'];

    expect(fn () => osbSet($company))->toThrow(ValidationException::class, 'Move Khan Mini Mart to a monthly plan first (Change plan).');
    expect(OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe($before);
});

test('leaving the monthly plan, or losing cloud sync, stops the charge and takes the store off line', function () {
    $company = osbCloudSync($this, $this->payingTenant());
    $store = osbSet($company);
    $store->forceFill(['is_live' => true, 'taking_orders' => true])->save();
    expect(OnlineStoreSwitch::availableFor($company))->toBeTrue()
        ->and(app(FindStoreBySlug::class)->handle($store->slug)?->id)->toBe($store->id);

    // Lost cloud sync.
    foreach ($this->licencesOf($company) as $licence) {
        $licence->forceFill(['features' => [Feature::Loyalty->value]])->save();
    }
    expect(CompanyPricing::for($company, $this->billingAccountOf($company))->onlineStoreMonthly)->toBeNull()
        ->and(app(FindStoreBySlug::class)->handle($store->slug))->toBeNull();

    // Back with cloud sync, then off the monthly plan.
    osbCloudSync($this, $company);
    $this->standardPlan()->forceFill(['billing_type' => PlanBillingType::SetupOnly, 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'setup_fee' => '500.00'])->save();
    expect(CompanyPricing::for($company, $this->billingAccountOf($company))->onlineStoreMonthly)->toBeNull()
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['onlineStore'])->toBeNull()
        ->and(OnlineStoreSwitch::availableFor($company))->toBeFalse()
        ->and(app(FindStoreBySlug::class)->handle($store->slug))->toBeNull()
        ->and($this->draftFor($company)->lines->pluck('description')->filter(fn ($d) => str_starts_with($d, 'Online store')))->toBeEmpty();
});

test('a store made in the portal before OS-6 (add-on off) changes nobody\'s bill', function () {
    $company = osbCloudSync($this, $this->payingTenant());
    $store = new OnlineStore(['slug' => 'khan-old', 'template' => StoreTemplate::FreshMarket, 'monthly_price' => '99.00']);
    $store->company_id = $company->id;
    $store->save();

    expect($this->draftFor($company)->total)->toBe('60.00');
});

test('Pakistan: never switched on, never billed, even with a row marked on', function () {
    $company = osbCloudSync($this, $this->payingTenant());
    $store = new OnlineStore(['slug' => 'khan-pk', 'template' => StoreTemplate::FreshMarket, 'addon_on' => true]);
    $store->company_id = $company->id;
    $store->save();

    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);

    expect(CompanyPricing::for($company, $this->billingAccountOf($company))->onlineStoreMonthly)->toBeNull()
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['onlineStore'])->toBeNull()
        ->and(fn () => osbSet($company))->toThrow(ValidationException::class, 'Online stores are not offered in this country.');
});
