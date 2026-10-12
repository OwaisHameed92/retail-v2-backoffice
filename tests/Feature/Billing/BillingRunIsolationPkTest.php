<?php

use App\Domain\Billing\Actions\IssueManualInvoices;
use App\Domain\Billing\Actions\RunBilling;
use App\Domain\Billing\Actions\SendPaymentReminders;
use App\Domain\Billing\GoCardless\Support\FakeGoCardlessClient;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Mail\Mailables\PaymentReminderMail;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\BusinessFailures;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Shared\FailingBusiness;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

/*
 * Audit DATA-7 on a manual-collection (Pakistan) instance: the steps that replace Direct Debit isolate their
 * businesses too.
 */

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Exceptions::fake();
    config(['country.code' => 'PK', 'billing.suspend_after_days' => 7, 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    FakeGoCardlessClient::install();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Karachi'));

    $this->shops = [$this->payingTenant('Lahore Mart', 1, 'LHR'), $this->payingTenant('Karachi Mart', 1, 'KHI')];
    $this->standardPlan()->forceFill(['price_monthly' => '2500.00', 'price_yearly' => '25000.00', 'setup_fee' => '0.00', 'billing_type' => null])->save();
    $this->ids = array_map(fn (Company $c) => $c->id, $this->shops);
});

test('issuing period invoices by hand: one failing business does not stop the next', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 06:00', 'Asia/Karachi'));
    $failing = FailingBusiness::on('companies', $this->ids);

    $issued = app(IssueManualInvoices::class)->handle(CarbonImmutable::now());
    $failing->stop();

    expect($issued)->toHaveCount(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $failing->other($this->ids))->count())->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $failing->failed)->count())->toBe(0)
        ->and(app(BusinessFailures::class)->companyIds(RunBilling::TASK))->toBe([$failing->failed]);
    Exceptions::assertReported(RuntimeException::class);
});

test('payment reminders: one failing business does not stop the next', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 06:00', 'Asia/Karachi'));
    expect(app(IssueManualInvoices::class)->handle(CarbonImmutable::now()))->toHaveCount(2);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00', 'Asia/Karachi'));
    $failing = FailingBusiness::on('companies', $this->ids);

    $sent = app(SendPaymentReminders::class)->handle(CarbonImmutable::now());
    $failing->stop();

    expect($sent)->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $failing->other($this->ids))->sole()->due_soon_reminded_at)->not->toBeNull()
        ->and(Invoice::withoutCompanyScope()->where('company_id', $failing->failed)->sole()->due_soon_reminded_at)->toBeNull()
        ->and(app(BusinessFailures::class)->companyIds(RunBilling::TASK))->toBe([$failing->failed]);
    Mail::assertQueued(PaymentReminderMail::class, 1);
    Exceptions::assertReported(RuntimeException::class);
});
