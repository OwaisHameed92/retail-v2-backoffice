<?php

use App\Domain\Mail\Data\WelcomeTenantData;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Owner 2026-10-15 (Kingsbury): a welcome email held since onboarding asks for a Direct Debit only while the business
 * still has a monthly or yearly fee when it is sent.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    $this->atLondon('2026-10-15 10:00');
});

function welcomeFor(string $companyId): string
{
    return (new WelcomeTenantMail(new WelcomeTenantData(
        businessName: 'Kingsbury Halal', ownerName: 'Usman Tahir', ownerEmail: 'owner@example.test',
        loginUrl: 'https://portal.test/login', tills: [], companyId: $companyId,
        billingUrl: 'https://portal.test/app/billing', directDebitDays: 3,
    )))->render();
}

test('a business with a monthly fee is asked to set up its Direct Debit', function () {
    $company = $this->payingTenant();

    expect(welcomeFor($company->id))->toContain('Set up your Direct Debit');
});

test('a business with nothing recurring by the time it is sent is not', function () {
    $company = $this->payingTenant();
    $this->standardPlan()->forceFill(['price_monthly' => '0.00', 'price_yearly' => '0.00'])->save();   // now a setup-only price

    expect(welcomeFor($company->id))->not->toContain('Set up your Direct Debit')->toContain('Thanks for choosing Switch');
});
