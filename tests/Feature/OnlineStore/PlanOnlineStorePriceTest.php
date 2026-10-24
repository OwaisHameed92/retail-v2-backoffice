<?php

use App\Domain\Billing\Data\TenantBilling;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\OnlineStore\Actions\SetOnlineStoreAddOn;
use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * OS-7: the add-on's price is the business's own (its Billing tab card), else its plan's when the plan includes the
 * online store, else the default £20. Once per business, its own line on the same invoice. £0 is a price.
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

function ospCloudSync(object $test, Company $company): Company
{
    foreach ($test->licencesOf($company) as $licence) {
        $licence->forceFill(['features' => [Feature::Loyalty->value, Feature::CloudSync->value]])->save();
    }

    return $company;
}

function ospSet(Company $company, ?string $price = null): void
{
    app(SetOnlineStoreAddOn::class)->handle($company, new OnlineStoreAddOnInput(true, $price, StoreTemplate::FreshMarket));
}

function ospLine(Invoice $invoice): ?string
{
    $line = $invoice->lines->first(fn ($l) => str_starts_with($l->description, 'Online store'));

    return $line === null ? null : "{$line->unit_price} / {$line->vat} / {$line->gross}";
}

test('the business price wins over the plan price, which wins over the default; £0 is allowed', function () {
    $company = ospCloudSync($this, $this->payingTenant());
    $plan = $this->standardPlan();
    $plan->forceFill(['includes_online_store' => true, 'online_store_monthly_price' => '15.00'])->save();
    ospSet($company);

    expect(ospLine($this->draftFor($company)))->toBe('15.00 / 3.00 / 18.00')
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['onlineStore'])->toBe('15.00')
        ->and(TenantBilling::for($company, true)['onlineStore'])->toMatchArray([
            'defaultPrice' => '15.00', 'priceLabel' => '£15.00', 'priceSource' => 'plan', 'byPlan' => true, 'planName' => 'Standard', 'custom' => false,
        ]);
    Invoice::withoutCompanyScope()->where('company_id', $company->id)->delete();

    ospSet($company, '12.00');
    expect(ospLine($this->draftFor($company)))->toBe('12.00 / 2.40 / 14.40')
        ->and(TenantBilling::for($company, true)['onlineStore'])->toMatchArray(['priceSource' => 'business', 'priceLabel' => '£12.00']);
    Invoice::withoutCompanyScope()->where('company_id', $company->id)->delete();

    ospSet($company, '0');
    expect(ospLine($this->draftFor($company)))->toBe('0.00 / 0.00 / 0.00')
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe('60.00');
    Invoice::withoutCompanyScope()->where('company_id', $company->id)->delete();

    // The plan's price blank: the default.
    ospSet($company);
    $plan->forceFill(['online_store_monthly_price' => null])->save();
    expect(ospLine($this->draftFor($company)))->toBe('20.00 / 4.00 / 24.00')
        ->and(TenantBilling::for($company, true)['onlineStore'])->toMatchArray(['priceSource' => 'default', 'defaultPrice' => '20.00', 'byPlan' => true]);
});

test('a plan without the online store never sets the price, even with a price stored on it', function () {
    $company = ospCloudSync($this, $this->payingTenant());
    $this->standardPlan()->forceFill(['includes_online_store' => false, 'online_store_monthly_price' => '5.00'])->save();
    ospSet($company);

    expect(ospLine($this->draftFor($company)))->toBe('20.00 / 4.00 / 24.00')
        ->and(TenantBilling::for($company, true)['onlineStore'])->toMatchArray(['byPlan' => false, 'priceSource' => 'default', 'defaultPrice' => '20.00']);
});

test('the UK test business: switched on by hand at £0 on "Setup + monthly" (without the store) works exactly as before', function () {
    $company = ospCloudSync($this, $this->payingTenant());
    $this->standardPlan()->forceFill(['name' => 'Setup + monthly', 'billing_type' => PlanBillingType::SetupAndRecurring, 'setup_fee' => '299.00'])->save();
    ospSet($company, '0.00');

    $invoice = $this->draftFor($company);
    expect(ospLine($invoice))->toBe('0.00 / 0.00 / 0.00')
        ->and($invoice->total)->toBe('60.00')
        ->and(CompanyPricing::for($company, $this->billingAccountOf($company))->onlineStoreMonthly)->toBe('0.00')
        ->and(TenantBilling::for($company, true)['onlineStore'])->toMatchArray([
            'on' => true, 'billed' => true, 'available' => true, 'custom' => true, 'priceSource' => 'business', 'priceLabel' => '£0.00', 'byPlan' => false,
        ]);
});

test('two businesses made on a plan with the store: each pays one line at its own price, whatever its tills', function () {
    $plan = $this->standardPlan();
    $plan->forceFill(['includes_online_store' => true, 'online_store_monthly_price' => '15.00'])->save();
    $a = ospCloudSync($this, $this->payingTenant('Khan Mini Mart', 3, 'KHN'));
    $b = ospCloudSync($this, $this->payingTenant('Stoney Mini Mart', 1, 'STN'));
    ospSet($a, '9.00');

    $invoice = $this->draftFor($a);
    expect(ospLine($invoice))->toBe('9.00 / 1.80 / 10.80')
        ->and($invoice->lines->filter(fn ($l) => str_starts_with($l->description, 'Online store')))->toHaveCount(1)
        ->and($invoice->lines)->toHaveCount(4)
        ->and(ospLine($this->draftFor($b)))->toBe('15.00 / 3.00 / 18.00');
});
