<?php

use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Mailables\DirectDebitSetupMail;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/*
 * Owner billing rules (2026-10-05), end to end: the setup fee (upfront) is always paid by hand, the recurring fee
 * always by Direct Debit, and every status change reaches the till through the licence status it is told.
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

/** What the till is told now (licence/validate uses LicenceState). */
function tillSees(Licence $licence): LicenceState
{
    $licence = Licence::withoutCompanyScope()->with(['company', 'branch', 'register', 'plan'])->findOrFail($licence->id);

    return LicenceState::for($licence, CarbonImmutable::now());
}

/** A business onboarded today on the Standard plan as given, trial ending 26 Oct 10:00, Direct Debit due by 27 Oct. */
function onboarded(object $test, string $setupFee, string $monthly, ?UpfrontPayment $upfront = null): Company
{
    $company = $test->trialTenant('Patel News', 1, '2026-10-26 10:00');
    $test->standardPlan()->forceFill(['setup_fee' => $setupFee, 'price_monthly' => $monthly, 'price_yearly' => '0.00', 'billing_type' => null])->save();
    app(OnboardTenantBilling::class)->handle($company, $upfront);

    return $company->refresh();
}

test('setup only: trial, then locked as expired until the fee is paid; paid = a full 10-year licence, topped up, never by Direct Debit', function () {
    $company = onboarded($this, '300.00', '0.00');
    $licence = $this->licencesOf($company)[0];

    expect($this->standardPlan()->billingType())->toBe(PlanBillingType::SetupOnly)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Trial);

    // Nothing recurs: no Direct Debit deadline, no invoices; the trial (and its 3 grace days) runs out.
    $run = $this->runBillingOn('2026-10-30');
    expect($run['noMandateSuspended'])->toBe(0)->and($run['invoicesCreated'])->toBe(0)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Expired)
        ->and(tillSees($licence)->reasonCode)->toBe(LicenceState::REASON_EXPIRED)
        ->and($this->companyFresh($company)->status)->not->toBe(CompanyStatus::Suspended);

    // Paid by card on our machine: £360 receipt, full licence to 30 Oct 2036, business active.
    $this->atLondon('2026-10-30 11:00');
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Card));

    $invoice = Invoice::withoutCompanyScope()->where('kind', InvoiceKind::SetupFee->value)->sole();
    expect($invoice->total)->toBe('360.00')->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-30'))
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Active)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active)
        ->and($this->gc->payments)->toBe([])
        ->and($this->gc->subscriptions)->toBe([]);

    // A till added later gets the full licence at the next run; the term is topped up once below 9 years left.
    $branch = $this->allowTills($this->branchOf($company, 'TRL'));
    app(AddRegister::class)->handle($branch, 'Till 2');
    expect($this->runBillingOn('2027-06-01')['setupOnlyLicences'])->toBe(1);
    $second = $this->licencesOf($company)[1];
    expect($this->licenceFresh($second)->expires_at)->not->toBeNull()
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-30'));

    // The first till (to Oct 2036) drops below 9 years left; the second (to Jun 2037) not yet.
    expect($this->runBillingOn('2028-01-01')['setupOnlyLicences'])->toBe(1)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2038-01-01'))
        ->and($this->runBillingOn('2028-01-02')['setupOnlyLicences'])->toBe(0);
});

test('setup only in instalments: each paid part keeps the tills paid to the next due date, the last one gives the full term', function () {
    $company = onboarded($this, '300.00', '0.00');
    $this->billingAccountOf($company)->forceFill(['setup_fee_instalments' => 3])->save();
    $licence = $this->licencesOf($company)[0];

    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Cash));
    $state = SetupFeeState::for($company, $this->billingAccountOf($company));
    expect($state->status)->toBe(SetupFeeState::PART_PAID)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-24'));

    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::BankTransfer));
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Card));

    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PAID)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-24'));
});

test('setup + monthly: no mandate → reminder → suspended (till locked); a hand payment does not lift it; the mandate does', function () {
    $company = onboarded($this, '199.00', '25.00', new UpfrontPayment(null, PaymentMethod::Card));
    $licence = $this->licencesOf($company)[0];
    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PAID);

    $run = $this->runBillingOn('2026-10-26');
    expect($run['mandateReminders'])->toBe(1)->and($run['noMandateSuspended'])->toBe(0);
    Mail::assertQueued(DirectDebitSetupMail::class, fn (DirectDebitSetupMail $mail) => $mail->data->reminder && $mail->data->deadline !== null);
    expect($this->runBillingOn('2026-10-26')['mandateReminders'])->toBe(0); // once per deadline

    expect($this->runBillingOn('2026-10-28')['noMandateSuspended'])->toBe(1)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Suspended)
        ->and(tillSees($licence)->reasonCode)->toBe(LicenceState::REASON_COMPANY_SUSPENDED);

    // Money taken by hand does not replace the Direct Debit.
    $this->pay($company, '30.00');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);

    // The owner sets it up: lifted at once, the subscription starts and the trial runs to the first collection.
    $this->setUpMandate($company);
    $account = $this->billingAccountOf($company);
    expect($this->companyFresh($company)->status)->not->toBe(CompanyStatus::Suspended)
        ->and($account->hasLiveSubscription())->toBeTrue()
        ->and($this->gc->lastSubscription()->amountPence)->toBe(3000)
        ->and($this->gc->oneOffPayments())->toBe([])
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Trial)
        ->and($this->licenceFresh($licence)->trial_ends_at->toDateTimeString())->toBe($this->londonEnd($account->gc_next_charge_date->format('Y-m-d')));
});

test('setup + monthly with the setup fee unpaid: no Direct Debit, the trial ends and the till locks (expired) until it is recorded', function () {
    $company = onboarded($this, '199.00', '25.00');
    $licence = $this->licencesOf($company)[0];
    $this->setUpMandate($company);

    expect($this->gc->subscriptions)->toBe([]);
    $this->runBillingOn('2026-10-30');
    expect(tillSees($licence)->status)->toBe(LicenceStatus::Expired)
        ->and($this->companyFresh($company)->status)->not->toBe(CompanyStatus::Suspended);

    $this->atLondon('2026-10-30 12:00');
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::BankTransfer, 'BACS 99'));

    expect($this->gc->lastSubscription()->amountPence)->toBe(3000)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Trial);
});

/** A paying Direct Debit tenant (tills paid to 31 Oct, £60 a month) with its 1 Nov collection created. */
function collecting(object $test): GcPayment
{
    $test->company = $test->directDebitTenant(tills: 2);
    $test->setUpMandate($test->company);
    $payment = $test->gc->collect($test->gc->lastSubscription()->id, '2026-11-01');
    $test->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();

    return $payment;
}

test('a collection in progress is not overdue; failed → grace → suspended → paid later → active again', function () {
    $payment = collecting($this);
    $licence = $this->licencesOf($this->company)[0];
    $this->paymentEvent($payment, PaymentStatus::Submitted, 'submitted')->assertOk();

    expect($this->runBillingOn('2026-11-03')['invoicesOverdue'])->toBe(0);

    $this->atLondon('2026-11-04 09:00');
    $this->paymentEvent($payment, PaymentStatus::Failed, 'failed')->assertOk();
    expect($this->runBillingOn('2026-11-05')['invoicesOverdue'])->toBe(1)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Grace);

    expect($this->runBillingOn('2026-11-16')['companiesSuspended'])->toBe(1)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Suspended);

    // GoCardless retries the payment and it is collected: paid, renewed to 30 Nov, unlocked.
    $this->atLondon('2026-11-16 10:00');
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'resubmission_requested')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    expect($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Active)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Active)
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'));
});

test('a late chargeback reopens the invoice without shortening the paid date, with the full grace from the chargeback', function () {
    $payment = collecting($this);
    $licence = $this->licencesOf($this->company)[0];
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    expect($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'));

    $this->atLondon('2026-11-25 10:00');
    $this->paymentEvent($payment, PaymentStatus::ChargedBack, 'charged_back')->assertOk();
    $invoice = Invoice::withoutCompanyScope()->findOrFail(GoCardlessPayment::withoutCompanyScope()->sole()->invoice_id);
    expect($invoice->status)->toBe(InvoiceStatus::Issued)->and($invoice->reopened_at)->not->toBeNull()
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'));

    // Due 1 Nov, but reopened on 25 Nov: overdue now, suspended only 14 days after the chargeback.
    $run = $this->runBillingOn('2026-11-26');
    expect($run['invoicesOverdue'])->toBe(1)->and($run['companiesSuspended'])->toBe(0)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Active);
    expect($this->runBillingOn('2026-12-09')['companiesSuspended'])->toBe(0);
    expect($this->runBillingOn('2026-12-10')['companiesSuspended'])->toBe(1)
        ->and(tillSees($licence)->status)->toBe(LicenceStatus::Suspended);

    // Paid by bank transfer (an exception, recorded by hand): unlocked straight away.
    $this->pay($this->company, '60.00', [$invoice->id => '60.00'], PaymentMethod::BankTransfer);
    expect(tillSees($licence)->status)->not->toBe(LicenceStatus::Suspended)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Active);
});
