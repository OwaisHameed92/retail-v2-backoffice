<?php

use App\Domain\Billing\Actions\ApplySetupFeeTerms;
use App\Domain\Billing\Actions\GenerateDueInvoices;
use App\Domain\Billing\Actions\MarkOverdueInvoices;
use App\Domain\Billing\Actions\RefreshSetupFeeTerms;
use App\Domain\Billing\Actions\RunBilling;
use App\Domain\Billing\Actions\SendTrialEmails;
use App\Domain\Billing\Actions\SuspendForUnpaidInvoices;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Actions\EnforceDirectDebit;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Mail\Mailables\TrialReminderMail;
use App\Domain\Shared\Support\BusinessFailures;
use App\Domain\Tenancy\Enums\CompanyStatus;
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
 * Audit DATA-7: every billing:run step isolates its businesses. One business's error (a bad row, a lost connection,
 * a mailer error) is reported with its company id; every other business and every later step still runs, and
 * billing:run exits non-zero naming the business. Healthy businesses are billed exactly as before.
 */

beforeEach(function () {
    Mail::fake();
    Exceptions::fake();
    $this->atLondon('2026-10-01 10:00');
    $this->setVat(true);
});

/** The business that failed was reported with the simulated error, and is the only one the run remembers. */
function assertOnlyFailed(FailingBusiness $failing): void
{
    expect($failing->failed)->not->toBeNull()
        ->and(app(BusinessFailures::class)->companyIds(RunBilling::TASK))->toBe([$failing->failed]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === "Simulated failure for business {$failing->failed}");
}

/** @return list<string> */
function idsOf(Company ...$companies): array
{
    return array_map(fn (Company $c) => $c->id, $companies);
}

test('setup fee terms: one failing business does not stop the next', function () {
    $ids = idsOf($this->payingTenant('Due Stores', 1, 'DUE'), $this->payingTenant('Khan Mini Mart', 1, 'KMM'));
    $first = null;
    $seen = [];
    $this->mock(ApplySetupFeeTerms::class, function ($mock) use (&$first, &$seen) {
        $mock->shouldReceive('handle')->andReturnUsing(function (Company $company) use (&$first, &$seen) {
            $seen[] = $company->id;
            $first ??= $company->id;

            return $company->id === $first ? throw new RuntimeException("Simulated failure for business {$company->id}") : 2;
        });
    });

    expect(app(RefreshSetupFeeTerms::class)->handle(CarbonImmutable::now()))->toBe(2)
        ->and($seen)->toEqualCanonicalizing($ids)
        ->and(app(BusinessFailures::class)->companyIds(RunBilling::TASK))->toBe([$first]);
    Exceptions::assertReported(RuntimeException::class);
});

test('creating due invoices: one failing business does not stop the next', function () {
    $ids = idsOf($this->payingTenant('Due Stores', 1, 'DUE'), $this->payingTenant('Khan Mini Mart', 1, 'KMM'));
    $this->atLondon('2026-10-26 06:00');
    $failing = FailingBusiness::on('companies', $ids);

    $created = app(GenerateDueInvoices::class)->handle(CarbonImmutable::now());
    $failing->stop();

    expect($created)->toHaveCount(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $failing->other($ids))->count())->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $failing->failed)->count())->toBe(0);
    assertOnlyFailed($failing);
});

test('marking invoices overdue: one failing business does not stop the next', function () {
    $a = $this->payingTenant('Late Payers', 1, 'LTE');
    $b = $this->payingTenant('Slow Payers', 1, 'SLW');
    $this->issuedFor($a);
    $this->issuedFor($b);
    $ids = idsOf($a, $b);
    $this->atLondon('2026-10-09 06:00');
    $failing = FailingBusiness::on('companies', $ids);

    $marked = app(MarkOverdueInvoices::class)->handle(CarbonImmutable::now());
    $failing->stop();

    expect($marked)->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $failing->other($ids))->sole()->status)->toBe(InvoiceStatus::Overdue)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $failing->failed)->sole()->status)->toBe(InvoiceStatus::Issued);
    assertOnlyFailed($failing);
});

test('suspending unpaid businesses: one failing business does not stop the next', function () {
    $a = $this->payingTenant('Late Payers', 1, 'LTE');
    $b = $this->payingTenant('Slow Payers', 1, 'SLW');
    $this->issuedFor($a);
    $this->issuedFor($b);
    $ids = idsOf($a, $b);
    $this->atLondon('2026-10-09 06:00');
    app(MarkOverdueInvoices::class)->handle(CarbonImmutable::now());
    $this->atLondon('2026-10-23 06:00');
    $failing = FailingBusiness::on('companies', $ids);

    $suspended = app(SuspendForUnpaidInvoices::class)->handle(CarbonImmutable::now());
    $failing->stop();

    expect($suspended)->toBe(1)
        ->and(Company::findOrFail($failing->other($ids))->status)->toBe(CompanyStatus::Suspended)
        ->and(Company::findOrFail($failing->failed)->status)->toBe(CompanyStatus::Overdue);
    assertOnlyFailed($failing);
});

test('trial emails: one failing business does not stop the next', function () {
    $ids = idsOf($this->trialTenant('Trial Stores', 1, '2026-10-28 10:00', 'TRL'), $this->trialTenant('Patel News', 1, '2026-10-28 10:00', 'PTL'));
    $this->atLondon('2026-10-26 06:00');
    $failing = FailingBusiness::on('companies', $ids);

    $sent = app(SendTrialEmails::class)->handle(CarbonImmutable::now());
    $failing->stop();

    expect($sent)->toBe(['reminders' => 1, 'ended' => 0])
        ->and($this->billingAccountOf(Company::findOrFail($failing->other($ids)))->trial_reminder_for)->not->toBeNull()
        ->and($this->billingAccountOf(Company::findOrFail($failing->failed))->trial_reminder_for)->toBeNull();
    Mail::assertQueued(TrialReminderMail::class, 1);
    assertOnlyFailed($failing);
});

test('Direct Debit enforcement: one failing business does not stop the next', function () {
    $this->fakeGoCardless();
    $companies = [$this->directDebitTenant('Khan Mini Mart', 1, 'KMM'), $this->directDebitTenant('Patel News', 1, 'PTL')];

    foreach ($companies as $company) {
        $account = $this->billingAccountOf($company);
        $account->mandate_deadline_at = CarbonImmutable::now()->subHour();
        $account->save();
    }

    $ids = idsOf(...$companies);
    $failing = FailingBusiness::on('companies', $ids);

    $result = app(EnforceDirectDebit::class)->handle(CarbonImmutable::now());
    $failing->stop();

    expect($result['suspended'])->toBe(1)
        ->and(Company::findOrFail($failing->other($ids))->status)->toBe(CompanyStatus::Suspended)
        ->and(Company::findOrFail($failing->failed)->status)->not->toBe(CompanyStatus::Suspended);
    assertOnlyFailed($failing);
});

test('billing:run carries on with every other business and step, then exits non-zero naming the business', function () {
    $broken = $this->payingTenant('Late Payers', 1, 'LTE');
    $healthy = $this->payingTenant('Slow Payers', 1, 'SLW');
    $this->issuedFor($broken);
    $healthyInvoice = $this->issuedFor($healthy);
    $this->trialTenant('Trial Stores', 1, '2026-10-11 10:00', 'TRL');
    $this->atLondon('2026-10-09 06:00');
    $failing = FailingBusiness::on('invoices', [$broken->id]);

    $this->artisan('billing:run')
        ->expectsOutputToContain('Billing run finished.')
        ->expectsOutputToContain('Invoices now overdue: 1')
        ->expectsOutputToContain('Trial reminders sent: 1')
        ->expectsOutputToContain($broken->id)
        ->assertFailed();
    $failing->stop();

    expect($this->fresh($healthyInvoice)->status)->toBe(InvoiceStatus::Overdue)
        ->and($this->companyFresh($broken)->status)->not->toBe(CompanyStatus::Overdue);
    Mail::assertQueued(TrialReminderMail::class, 1);
});

test('a healthy billing:run still exits successfully and remembers no failure', function () {
    [$due, $late] = $this->billingRunScenario();
    $this->atLondon('2026-10-26 06:00');

    $this->artisan('billing:run')->doesntExpectOutputToContain('failed for')->assertSuccessful();

    expect(app(RunBilling::class)->failedCompanyIds())->toBe([]);
    Exceptions::assertNothingReported();
});
