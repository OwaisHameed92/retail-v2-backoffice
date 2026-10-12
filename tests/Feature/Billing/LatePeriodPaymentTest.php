<?php

use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit BILL-7 (docs/audit/2026-10-11-billing.md; docs/billing-flow.md "Paid late"): a period invoice paid by hand
 * after its period ended buys one period from the day it is paid, for the tills that ran out; billing:run then
 * invoices the next period as usual. Pakistan (every period by hand) and UK businesses not on Direct Debit alike. A
 * Direct Debit business, a still-paid till and a period written off with a credit note are unchanged.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    // The live value (owner 2026-10-05); the suite pins 14 for older tests.
    config(['billing.suspend_after_days' => 7]);
});

function latePaidTillSees(Licence $licence): LicenceState
{
    $licence = Licence::withoutCompanyScope()->with(['company', 'branch', 'register', 'plan'])->findOrFail($licence->id);

    return LicenceState::for($licence, CarbonImmutable::now());
}

function latePaidAtKarachi(object $test, string $datetime): void
{
    $test->travelTo(CarbonImmutable::parse($datetime, 'Asia/Karachi'));
}

/** A UK business paying by hand (not Direct Debit), tills paid to 31 Oct, with its November invoice issued on 24 Oct. */
function latePaidGbNovember(object $test, BillingMode $mode = BillingMode::UpfrontCash): array
{
    $test->setVat(true);
    $test->atLondon('2026-10-24 10:00');
    $company = $test->payingTenant('Leeds News', 1, 'LDN');
    $test->billingAccountOf($company)->forceFill(['billing_mode' => $mode])->save();
    $november = $test->issuedFor($company);
    expect($november->period_start->format('Y-m-d'))->toBe('2026-11-01');

    return [$company, $november];
}

test('PK: an invoice paid after its period ended renews the tills for one period from the payment day, and billing:run invoices the next one', function () {
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    latePaidAtKarachi($this, '2026-10-20 10:00');
    $company = $this->payingTenant('Lahore Mart', 1, 'LHR'); // paid to 31 Oct
    $this->standardPlan()->forceFill(['price_monthly' => '2500.00', 'price_yearly' => '25000.00', 'setup_fee' => '0.00', 'billing_type' => null])->save();
    latePaidAtKarachi($this, '2026-10-25 06:00');
    expect($this->runBilling()['invoicesCreated'])->toBe(1);
    $november = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();
    expect($november->period_start->format('Y-m-d'))->toBe('2026-11-01');

    foreach (['2026-11-02', '2026-11-09'] as $day) {
        latePaidAtKarachi($this, $day.' 06:00');
        $this->runBilling();
    }
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);

    // The customer pays the November invoice on 2 December.
    latePaidAtKarachi($this, '2026-12-02 12:00');
    $paid = $this->pay($company, $november->total, [$november->id => $november->total], PaymentMethod::JazzCash);
    [$licence] = $this->licencesOf($company);

    expect($this->fresh($november)->status)->toBe(InvoiceStatus::Paid)
        ->and($paid->licencesRenewed)->toBe(1)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active)
        ->and($this->licenceFresh($licence)->expires_at->setTimezone('Asia/Karachi')->format('Y-m-d H:i:s'))->toBe('2027-01-01 23:59:59')
        ->and(latePaidTillSees($licence)->status)->toBe(LicenceStatus::Active)
        ->and(AuditLog::query()->where('action', 'invoice.licences_renewed')->where('subject_id', $november->id)->sole()->meta)
        ->toMatchArray(['renewed' => 1, 'until' => '2027-01-01', 'paid_late' => true]);
    Mail::assertQueued(LicenceRenewedMail::class);

    // Nothing more to pay until the next period comes up, 7 days before the tills run out.
    $created = 0;
    foreach (['2026-12-03', '2026-12-10', '2026-12-24'] as $day) {
        latePaidAtKarachi($this, $day.' 06:00');
        $created += $this->runBilling()['invoicesCreated'];
    }
    expect($created)->toBe(0);

    latePaidAtKarachi($this, '2026-12-26 06:00');
    expect($this->runBilling()['invoicesCreated'])->toBe(1);
    $next = Invoice::withoutCompanyScope()->where('company_id', $company->id)->whereKeyNot($november->id)->sole();
    expect($next->period_start->format('Y-m-d'))->toBe('2027-01-02')
        ->and($next->status)->toBe(InvoiceStatus::Issued)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active);
})->group('country-pk');

test('GB, paying by hand: the November invoice paid on 2 December renews to 1 January and the next invoice follows', function () {
    [$company, $november] = latePaidGbNovember($this);
    [$licence] = $this->licencesOf($company);

    $this->atLondon('2026-12-02 12:00');
    $this->pay($company, $november->total, [$november->id => $november->total], PaymentMethod::BankTransfer);

    expect($this->fresh($november)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2027-01-01'))
        ->and(latePaidTillSees($licence)->status)->toBe(LicenceStatus::Active);

    // The UK makes a draft 7 days before the tills run out, for the period after the paid one.
    expect($this->runBillingOn('2026-12-26')['invoicesCreated'])->toBe(1);
    $next = Invoice::withoutCompanyScope()->where('company_id', $company->id)->whereKeyNot($november->id)->sole();
    expect($next->period_start->format('Y-m-d'))->toBe('2027-01-02');
});

test('GB: paid on time, the invoice renews to its own period end as before', function () {
    [$company, $november] = latePaidGbNovember($this);
    [$licence] = $this->licencesOf($company);

    $this->atLondon('2026-11-30 18:00');
    $this->pay($company, $november->total, [$november->id => $november->total]);

    expect($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'))
        ->and(AuditLog::query()->where('action', 'invoice.licences_renewed')->where('subject_id', $november->id)->sole()->meta)
        ->not->toHaveKey('paid_late');
});

test('GB Direct Debit: an invoice paid by hand after its period renews nothing (the subscription invoices each period)', function () {
    [$company, $november] = latePaidGbNovember($this, BillingMode::DirectDebit);
    [$licence] = $this->licencesOf($company);

    $this->atLondon('2026-12-02 12:00');
    $result = $this->pay($company, $november->total, [$november->id => $november->total]);

    expect($this->fresh($november)->status)->toBe(InvoiceStatus::Paid)
        ->and($result->licencesRenewed)->toBe(0)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'));
});

test('a late invoice for time already paid (arrears) does not move a till that is still paid', function () {
    $this->setVat(true);
    $this->atLondon('2026-10-24 10:00');
    $company = $this->payingTenant('Arrears Ltd', 1, 'ARR', paidUntil: '2026-12-31');
    $september = $this->issuedFor($company, new NewInvoice(periodStart: CarbonImmutable::parse('2026-09-01'), allowOverlap: true));
    [$licence] = $this->licencesOf($company);

    $result = $this->pay($company, $september->total, [$september->id => $september->total]);

    expect($this->fresh($september)->status)->toBe(InvoiceStatus::Paid)
        ->and($result->licencesRenewed)->toBe(0)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-12-31'));
});

test('a past period written off with a credit note (no money received) renews nothing', function () {
    [$company, $november] = latePaidGbNovember($this);
    [$licence] = $this->licencesOf($company);

    $this->atLondon('2026-12-02 12:00');
    $this->creditIt($this->fresh($november), '10.00', 'Goodwill');
    $this->creditIt($this->fresh($november), $this->fresh($november)->balance, 'Shop closed in November');

    expect($this->fresh($november)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'));
});

test('a late payment only renews the business’s own tills (company A’s payment never touches company B)', function () {
    [$company, $november] = latePaidGbNovember($this);
    $other = $this->payingTenant('Other Shop', 1, 'OTH');
    [$otherLicence] = $this->licencesOf($other);

    $this->atLondon('2026-12-02 12:00');
    $this->pay($company, $november->total, [$november->id => $november->total]);

    expect($this->licenceFresh($otherLicence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'))
        ->and(Company::query()->findOrFail($other->id)->status)->toBe($other->status);
});
