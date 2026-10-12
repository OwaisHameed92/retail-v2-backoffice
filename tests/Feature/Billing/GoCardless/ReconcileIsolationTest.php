<?php

use App\Domain\Billing\GoCardless\Actions\ReconcileGoCardless;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Shared\FailingBusiness;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/*
 * Audit DATA-7: billing:reconcile-gocardless isolates any error of one business (not only a GoCardless API error):
 * reported and listed, and the other businesses are still reconciled.
 */

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();

    $this->companies = [$this->directDebitTenant('Alpha Stores', 1, 'ALP'), $this->directDebitTenant('Beta Stores', 1, 'BET')];

    foreach ($this->companies as $company) {
        $this->setUpMandate($company);
    }

    $this->ids = array_map(fn (Company $c) => $c->id, $this->companies);
});

test('an error that is not GoCardless\'s for one business is reported and the next business is still reconciled', function () {
    Exceptions::fake();
    $failing = FailingBusiness::on('billing_accounts', $this->ids);

    $report = app(ReconcileGoCardless::class)->handle(CarbonImmutable::now());
    $failing->stop();

    expect($report['checked'])->toBe(2)
        ->and($report['errors'])->toHaveCount(1)
        ->and($report['errors'][0])->toContain($failing->failed)
        ->and($this->billingAccountOf(Company::findOrFail($failing->other($this->ids)))->gc_reconciled_at)->not->toBeNull()
        ->and($this->billingAccountOf(Company::findOrFail($failing->failed))->gc_reconciled_at)->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === "Simulated failure for business {$failing->failed}");
});

test('the command exits non-zero when a business failed', function () {
    Exceptions::fake();
    FailingBusiness::on('billing_accounts', $this->ids);

    $this->artisan('billing:reconcile-gocardless')->expectsOutputToContain('RuntimeException')->expectsOutputToContain('2 checked, 0 fixed, 1 errors.')->assertFailed();
});
