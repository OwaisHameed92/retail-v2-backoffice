<?php

use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Support\FakeGoCardlessClient;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Data\PaymentReminderData;
use App\Domain\Mail\Mailables\AccountReactivatedMail;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\PaymentReminderMail;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Pakistan plan P5: billing without Direct Debit on a manual-collection instance. Every period is an invoice paid by
 * hand (bank transfer, JazzCash, Easypaisa, cash) and recorded by an admin; reminders before, on and after the due
 * date; the UK's 7-day suspension; reactivation on payment. GoCardless is never called (the fake records nothing).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

/** A Pakistan instance (COUNTRY=PK): the profile rebuilt, the fake GoCardless client installed to prove it stays idle. */
function pkBillingInstance(object $test): FakeGoCardlessClient
{
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
    config([
        // The production default and owner rule (the suite pins 14 for older tests): suspended after 7 days unpaid.
        'billing.suspend_after_days' => 7,
        'billing.vat.enabled' => false,
        'billing.manual.pay' => [
            'bank_name' => 'Meezan Bank', 'bank_account_title' => 'Switch & Save', 'bank_iban' => 'PK36MEZN0000000000000001',
            'jazzcash' => '0300 1234567', 'easypaisa' => '0345 7654321',
        ],
    ]);

    return $test->gc = FakeGoCardlessClient::install();
}

/** Set the clock to a Karachi wall-clock time. */
function atKarachi(string $datetime): void
{
    test()->travelTo(CarbonImmutable::parse($datetime, 'Asia/Karachi'));
}

/** billing:run on the morning (06:00 Karachi) of a date. */
function runPkBilling(object $test, string $date): array
{
    atKarachi($date.' 06:00');

    return $test->runBilling();
}

/** What the till is told now. */
function pkTillSees(Licence $licence): LicenceState
{
    $licence = Licence::withoutCompanyScope()->with(['company', 'branch', 'register', 'plan'])->findOrFail($licence->id);

    return LicenceState::for($licence, CarbonImmutable::now());
}

/** A Lahore shop with 2 tills paid to 31 Oct, Rs 2,500 a till a month. */
function pkPayingShop(object $test): Company
{
    $company = $test->payingTenant('Lahore Mart', 2, 'LHR');
    $test->standardPlan()->forceFill(['price_monthly' => '2500.00', 'price_yearly' => '25000.00', 'setup_fee' => '0.00', 'billing_type' => null])->save();

    return $company->refresh();
}

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    pkBillingInstance($this);
    atKarachi('2026-10-20 10:00');
});

it('collects by hand on a Pakistan instance: no Direct Debit onboarding, deadline or GoCardless', function () {
    $company = $this->trialTenant('Patel Store', 1, '2026-10-26 10:00', 'PTL');
    $this->standardPlan()->forceFill(['setup_fee' => '15000.00', 'price_monthly' => '2500.00', 'price_yearly' => '0.00', 'billing_type' => null])->save();
    $account = app(OnboardTenantBilling::class)->handle($company->refresh());

    expect(ManualCollection::active())->toBeTrue()
        ->and($account->billing_mode)->toBe(BillingMode::UpfrontCash)
        ->and($account->mandate_deadline_at)->toBeNull()
        ->and(MandateDeadline::state($company, $account))->toBeNull()
        ->and(BillingStatus::for($company)->state)->toBe(BillingStatus::TRIAL);

    // Nothing recurring is invoiced before the setup fee is paid; the trial runs out and the till locks.
    expect(runPkBilling($this, '2026-10-25')['invoicesCreated'])->toBe(0)
        ->and(runPkBilling($this, '2026-10-30')['invoicesCreated'])->toBe(0);
    $licence = $this->licencesOf($company)[0];
    expect(pkTillSees($licence)->status)->toBe(LicenceStatus::Expired);

    $status = BillingStatus::for($company);
    expect($status->state)->toBe(BillingStatus::SETUP_FEE_DUE);

    // The setup fee by Easypaisa: paid at once, and the first monthly invoice is issued straight away (the trial is
    // over), due in 7 days; the till trades until then.
    atKarachi('2026-10-30 11:00');
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Easypaisa));

    $setup = Invoice::withoutCompanyScope()->where('kind', InvoiceKind::SetupFee->value)->sole();
    $period = Invoice::withoutCompanyScope()->where('kind', InvoiceKind::Subscription->value)->sole();
    expect($setup->status)->toBe(InvoiceStatus::Paid)->and($setup->total)->toBe('15000.00')->and($setup->currency)->toBe('PKR')
        ->and($period->status)->toBe(InvoiceStatus::Issued)->and($period->total)->toBe('2500.00')
        ->and($period->period_start->format('Y-m-d'))->toBe('2026-10-30')
        ->and($period->due_date?->format('Y-m-d'))->toBe('2026-11-06')
        ->and($this->billingAccountOf($company)->upfront_method)->toBe(PaymentMethod::Easypaisa)
        ->and(pkTillSees($licence)->status)->toBe(LicenceStatus::Trial)
        ->and($this->licenceFresh($licence)->trial_ends_at?->toDateTimeString())->toBe(CarbonImmutable::parse('2026-11-06 23:59:59', 'Asia/Karachi')->utc()->toDateTimeString());

    // Paid by JazzCash: the till is paid to the end of the period and the business is active.
    $this->pay($company, '2500.00', method: PaymentMethod::JazzCash);
    expect($this->fresh($period)->status)->toBe(InvoiceStatus::Paid)
        ->and(pkTillSees($licence)->status)->toBe(LicenceStatus::Active)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active)
        ->and($this->gc->calls)->toBe([])->and($this->gc->subscriptions)->toBe([])->and($this->gc->payments)->toBe([]);
})->group('country-pk');

it('issues each period as an invoice, reminds before, on and after the due date, suspends after 7 days and reactivates on payment', function () {
    $company = pkPayingShop($this);
    $licence = $this->licencesOf($company)[0];

    // Not yet: the tills run to 31 Oct, the invoice goes 7 days before.
    expect(runPkBilling($this, '2026-10-24')['invoicesCreated'])->toBe(0);

    $run = runPkBilling($this, '2026-10-25');
    $invoice = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();
    expect($run['invoicesCreated'])->toBe(1)->and($run)->toHaveKey('paymentReminders')
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)->and($invoice->number)->not->toBeNull()
        ->and($invoice->period_start->format('Y-m-d'))->toBe('2026-11-01')->and($invoice->period_end->format('Y-m-d'))->toBe('2026-11-30')
        ->and($invoice->due_date?->format('Y-m-d'))->toBe('2026-11-01')
        ->and($invoice->total)->toBe('5000.00')->and($invoice->currency)->toBe('PKR');

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => str_contains((string) $mail->data->howToPay, 'bank transfer, JazzCash, Easypaisa or cash')
        && in_array('JazzCash 0300 1234567', $mail->data->bankDetails, true) && in_array('IBAN PK36MEZN0000000000000001', $mail->data->bankDetails, true));

    // Idempotent: the same period is never invoiced twice.
    expect(runPkBilling($this, '2026-10-26')['invoicesCreated'])->toBe(0)
        ->and(runPkBilling($this, '2026-10-28')['paymentReminders'])->toBe(0);

    $reminders = fn (string $kind) => Mail::queued(PaymentReminderMail::class, fn (PaymentReminderMail $mail) => $mail->data->kind === $kind);

    // 3 days before, on the day, 3 days after; each once.
    expect(runPkBilling($this, '2026-10-29')['paymentReminders'])->toBe(1)->and($reminders(PaymentReminderData::DUE_SOON))->toHaveCount(1)
        ->and(runPkBilling($this, '2026-10-30')['paymentReminders'])->toBe(0)
        ->and(runPkBilling($this, '2026-11-01')['paymentReminders'])->toBe(1)->and($reminders(PaymentReminderData::DUE_TODAY))->toHaveCount(1);

    $run = runPkBilling($this, '2026-11-02');
    expect($run['invoicesOverdue'])->toBe(1)->and($run['paymentReminders'])->toBe(0)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Overdue);

    expect(runPkBilling($this, '2026-11-04')['paymentReminders'])->toBe(1)
        ->and(runPkBilling($this, '2026-11-05')['paymentReminders'])->toBe(0);
    $overdue = $reminders(PaymentReminderData::OVERDUE);
    expect($overdue)->toHaveCount(1)
        ->and($overdue->first()->data->locksOn?->format('Y-m-d'))->toBe('2026-11-09')
        ->and($overdue->first()->subjectLine())->toBe("Invoice {$invoice->number} is overdue");

    // 7 days unpaid (BILLING_SUSPEND_AFTER_DAYS, as in the UK): suspended on 9 Nov, with how to pay by hand.
    expect(runPkBilling($this, '2026-11-08')['companiesSuspended'])->toBe(0)
        ->and(runPkBilling($this, '2026-11-09')['companiesSuspended'])->toBe(1)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended)
        ->and(pkTillSees($licence)->status)->toBe(LicenceStatus::Suspended);
    Mail::assertQueued(AccountSuspendedMail::class, fn (AccountSuspendedMail $mail) => str_contains((string) $mail->data->howToFix, 'Rs 5,000 by bank transfer, JazzCash, Easypaisa or cash'));

    // Paid (by Easypaisa, recorded by staff): active again at once, tills renewed to the period end.
    atKarachi('2026-11-09 15:00');
    $paid = $this->pay($company, '5000.00', method: PaymentMethod::Easypaisa);
    expect($paid->unsuspended)->toBeTrue()
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active)
        ->and(pkTillSees($licence)->status)->toBe(LicenceStatus::Active)
        ->and($this->licenceFresh($licence)->expires_at?->toDateTimeString())->toBe(CarbonImmutable::parse('2026-11-30 23:59:59', 'Asia/Karachi')->utc()->toDateTimeString());
    Mail::assertQueued(AccountReactivatedMail::class);

    // Paid invoices get no more reminders; the next period is invoiced 7 days before 1 Dec.
    expect(runPkBilling($this, '2026-11-12')['paymentReminders'])->toBe(0)
        ->and(runPkBilling($this, '2026-11-24')['invoicesCreated'])->toBe(1)
        ->and($this->gc->calls)->toBe([]);
})->group('country-pk');

it('sends no reminder for a cancelled business and none on the day an invoice is issued', function () {
    $company = pkPayingShop($this);
    $this->billingAccountOf($company)->forceFill(['payment_terms_days' => 2])->save();

    // An invoice issued by staff on 30 Oct, due in 2 days: no "due soon" the same morning it was emailed.
    atKarachi('2026-10-30 09:00');
    $invoice = $this->issuedFor($company);
    expect($invoice->due_date?->format('Y-m-d'))->toBe('2026-11-01');

    atKarachi('2026-10-30 18:00');
    expect($this->runBilling()['paymentReminders'])->toBe(0)
        ->and(runPkBilling($this, '2026-10-31')['paymentReminders'])->toBe(1);

    $company->forceFill(['status' => CompanyStatus::Cancelled])->save();
    expect(runPkBilling($this, '2026-11-01')['paymentReminders'])->toBe(0);
    Mail::assertQueued(PaymentReminderMail::class, 1);
})->group('country-pk');

it('records JazzCash and Easypaisa payments, refuses UK-only methods, and offers the profile methods', function () {
    $company = pkPayingShop($this);
    $invoice = $this->issuedFor($company);
    $values = fn (array $methods) => array_map(fn (PaymentMethod $method) => $method->value, $methods);

    expect($values(PaymentMethod::manual()))->toBe(['bankTransfer', 'jazzCash', 'easypaisa', 'cash'])
        ->and($values(PaymentMethod::setupFee()))->toBe(['bankTransfer', 'jazzCash', 'easypaisa', 'cash'])
        ->and(PaymentMethod::options())->toBe([
            ['value' => 'bankTransfer', 'label' => 'Bank transfer'],
            ['value' => 'jazzCash', 'label' => 'JazzCash'],
            ['value' => 'easypaisa', 'label' => 'Easypaisa'],
            ['value' => 'cash', 'label' => 'Cash'],
        ])
        ->and(app(Country::class)->toFrontend()['manualMethods'])->toBe(['bankTransfer', 'jazzCash', 'easypaisa', 'cash']);

    $admin = $this->actingAs($this->admin(), 'admin');
    $admin->post(route('admin.billing.tenants.payments.store', $company), [
        'method' => 'card', 'amount' => '1000', 'received_on' => '2026-10-20', 'allocation' => 'auto',
    ])->assertSessionHasErrors(['method' => 'Choose bank transfer, JazzCash, Easypaisa or cash.']);

    $admin->post(route('admin.billing.tenants.payments.store', $company), [
        'method' => 'jazzCash', 'amount' => 'Rs 2,000', 'received_on' => '2026-10-20', 'reference' => 'JC-TXN-88231', 'allocation' => 'auto',
    ])->assertSessionHasNoErrors();
    $admin->post(route('admin.billing.tenants.payments.store', $company), [
        'method' => 'easypaisa', 'amount' => '3000', 'received_on' => '2026-10-20', 'reference' => 'EP-55120', 'allocation' => 'auto',
    ])->assertSessionHasNoErrors();

    $payments = Payment::withoutCompanyScope()->where('company_id', $company->id)->orderBy('sequence')->get();
    expect($payments->pluck('method')->all())->toBe([PaymentMethod::JazzCash, PaymentMethod::Easypaisa])
        ->and($payments->pluck('reference')->all())->toBe(['JC-TXN-88231', 'EP-55120'])
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->gc->calls)->toBe([]);

    // A large rupee setup fee is a valid amount here (the UK limit is 99,999.99).
    $admin->post(route('admin.billing.tenants.payments.store', $company), [
        'method' => 'bankTransfer', 'amount' => '150000', 'received_on' => '2026-10-20', 'allocation' => 'auto',
    ])->assertSessionHasNoErrors();
})->group('country-pk');

it('makes no GoCardless call when tills change, settings change or the reconcile runs', function () {
    $company = pkPayingShop($this);
    $branch = $this->allowTills($this->branchOf($company, 'LHR'));
    app(AddRegister::class)->handle($branch, 'Till 3');

    $this->actingAs($this->admin(), 'admin')->put(route('admin.billing.tenants.direct-debit.settings', $company), [
        'billing_mode' => 'directDebit', 'setup_fee_override' => null, 'setup_fee_instalments' => 1,
    ])->assertSessionHasErrors(['billing_mode' => 'Direct Debit is not available here: every invoice is paid by hand.']);
    expect($this->billingAccountOf($company)->billing_mode)->toBe(BillingMode::UpfrontCash);

    $this->artisan('billing:reconcile-gocardless')->expectsOutputToContain('No Direct Debit on this instance')->assertSuccessful();
    // The billing showcase is the UK Direct Debit flow: refused here, nothing made.
    $this->artisan('demo:billing')->expectsOutputToContain('This instance collects fees by hand')->assertFailed();
    expect(Company::query()->where('is_demo', true)->exists())->toBeFalse();
    $this->artisan('billing:run')->expectsOutputToContain('Payment reminders sent')->doesntExpectOutputToContain('Direct Debit')->assertSuccessful();

    expect($this->gc->calls)->toBe([]);
})->group('country-pk');
