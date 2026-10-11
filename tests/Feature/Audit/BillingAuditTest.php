<?php

use App\Domain\Billing\Actions\ApplySetupFeeTerms;
use App\Domain\Billing\Actions\ChangeBusinessPlan;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Actions\ReversePayment;
use App\Domain\Billing\Actions\UpdateCompanyPricing;
use App\Domain\Billing\Data\PortalBilling;
use App\Domain\Billing\Data\PricingOverride;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Actions\ChargeSetupFee;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Queries\BillingStats;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\PlanChangePlanner;
use App\Domain\Billing\Support\SetupFeeInvoices;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\DirectDebitSetupMail;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Billing audit 2026-10-11 (docs/audit/2026-10-11-billing.md). Each "BILL-n" test REPRODUCES a problem: it asserts
 * what the code does today, so the suite stays green; the comment above each assertion says what it should do. When a
 * finding is fixed, flip those expectations. The "fine" tests pin behaviour that was checked and is correct, using an
 * Istanbul-like fixture (the first paying UK customer after its manual corrections).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->setVat(true);
    // The live value (owner 2026-10-05); the suite pins 14 for older tests.
    config(['billing.suspend_after_days' => 7]);
});

/**
 * Istanbul Market as it stands on the live UK server (memory 2026-10-08): plan "Setup only" (per till since P11,
 * £1,200 a till), 2 tills, onboarded on Direct Debit mode (the wizard always does) with a mandate deadline long gone,
 * a first setup fee invoice paid by bank transfer, then corrected by hand: that payment reversed and its invoice
 * voided, a second wrong invoice paid, reversed and voided, and finally one setup fee invoice of 2 × £750 + VAT =
 * £1,800 paid in cash. `till_setup_fee_override` 750, 2 tills covered, and the shop keeps custom features with
 * cloud_sync. Clock left at 11 Oct 2026 10:00 London.
 *
 * @return array{company: Company, plan: Plan, paid: Invoice, voided: list<Invoice>, reversed: list<Payment>, kept: Payment}
 */
function auditIstanbul(object $test): array
{
    $test->atLondon('2026-10-06 11:00');
    $company = $test->trialTenant('Istanbul Market', 2, '2026-10-13 11:00', 'IST');
    $plan = $test->standardPlan();
    $plan->forceFill([
        'name' => 'Setup only', 'setup_fee' => '1200.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
        'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => SetupFeeMode::PerBusiness,
    ])->save();

    // 6 Oct: onboarded (Direct Debit mode, deadline 9 Oct) with the setup fee paid by bank transfer.
    app(OnboardTenantBilling::class)->handle($company, new UpfrontPayment(null, PaymentMethod::BankTransfer));
    $first = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)->sole();
    $firstPayment = Payment::withoutCompanyScope()->where('company_id', $company->id)->sole();

    // 7 Oct: P11 switches the plan to per till; the backfill marks the 2 tills covered.
    $test->atLondon('2026-10-07 12:00');
    $plan->forceFill(['setup_fee_mode' => SetupFeeMode::PerTill])->save();
    $test->billingAccountOf($company)->forceFill(['setup_fee_covered_tills' => 2])->save();

    // 8 Oct: £750 + VAT a till agreed. The first payment is reversed and its invoice voided...
    $test->atLondon('2026-10-08 10:00');
    app(ReversePayment::class)->handle($firstPayment, 'Wrong amount: £750 + VAT per till agreed');
    $test->voidIt($test->fresh($first), 'Replaced: £750 + VAT per till');

    // ... a second attempt is issued, paid, reversed and voided too ...
    $account = app(BillingAccounts::class)->for($company);
    $second = app(IssueInvoice::class)->handle(SetupFeeInvoices::businessDraft($company, $account, '1200.00', 'Setup fee · Istanbul Market'));
    $secondPayment = $test->pay($company, $second->total, [$second->id => $second->total], PaymentMethod::BankTransfer)->payment;
    app(ReversePayment::class)->handle($secondPayment, 'Entered against the wrong invoice');
    $test->voidIt($test->fresh($second), 'Wrong amount');

    // ... and the right one, 2 × £750 + VAT = £1,800, is paid in cash.
    $account = app(BillingAccounts::class)->for($company);
    $account->till_setup_fee_override = '750.00';
    $account->save();
    $paid = app(IssueInvoice::class)->handle(SetupFeeInvoices::businessDraft($company, $account, '1500.00', 'Setup fee · 2 tills × £750 · Istanbul Market'));
    $kept = $test->pay($company, '1800.00', [$paid->id => '1800.00'], PaymentMethod::Cash)->payment;

    // The shop's own features include cloud sync (owner: keep them).
    $branch = $test->branchOf($company, 'IST');
    $custom = [Feature::Loyalty->value, Feature::Promotions->value, Feature::CloudSync->value];
    $branch->forceFill(['licence_features' => $custom])->save();
    foreach ($test->licencesOf($company) as $licence) {
        $licence->forceFill(['features' => $custom])->save();
    }

    $test->atLondon('2026-10-11 10:00');

    return [
        'company' => $company->refresh(),
        'plan' => $plan->refresh(),
        'paid' => $test->fresh($paid),
        'voided' => [$test->fresh($first), $test->fresh($second)],
        'reversed' => [$firstPayment->refresh(), $secondPayment->refresh()],
        'kept' => $kept->refresh(),
    ];
}

/** What a till is told now. */
function auditTillSees(Licence $licence): LicenceState
{
    $licence = Licence::withoutCompanyScope()->with(['company', 'branch', 'register', 'plan'])->findOrFail($licence->id);

    return LicenceState::for($licence, CarbonImmutable::now());
}

// ---------------------------------------------------------------------------------------------------------------
// Checked and fine: the Istanbul-like fixture
// ---------------------------------------------------------------------------------------------------------------

test('fine: Istanbul-like data reads as paid; 10-year licences; billing:run for a year never invoices, suspends or shortens', function () {
    $istanbul = auditIstanbul($this);
    $company = $istanbul['company'];
    $account = $this->billingAccountOf($company);
    $state = SetupFeeState::for($company, $account);

    expect($istanbul['paid']->status)->toBe(InvoiceStatus::Paid)
        ->and($istanbul['paid']->total)->toBe('1800.00')
        ->and(array_map(fn (Invoice $i) => $i->status, $istanbul['voided']))->toBe([InvoiceStatus::Void, InvoiceStatus::Void])
        ->and(array_map(fn (Payment $p) => [$p->reversed_at !== null, $p->unallocated], $istanbul['reversed']))->toBe([[true, '0.00'], [true, '0.00']])
        ->and($state->status)->toBe(SetupFeeState::PAID)
        ->and($state->total)->toBe('1800.00')
        ->and(ApplySetupFeeTerms::planType($company))->toBe(PlanBillingType::SetupOnly)
        ->and(SubscriptionAmount::for($company, $account)['gross'])->toBe('0.00')
        ->and(MandateDeadline::missed($company, $account))->toBeFalse()
        ->and($company->status)->toBe(CompanyStatus::Active);

    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at->greaterThan(CarbonImmutable::parse('2036-10-01')))->toBeTrue()
            ->and(auditTillSees($licence)->status)->toBe(LicenceStatus::Active)
            ->and($licence->features->map(fn (Feature $f) => $f->value)->all())->toContain('cloud_sync');
    }

    // A year of daily runs (sampled): nothing invoiced, nothing overdue, never suspended, topped up below 9 years.
    foreach (['2026-10-12', '2026-11-01', '2027-01-15', '2027-06-01', '2027-10-07', '2027-10-08'] as $day) {
        $run = $this->runBillingOn($day);
        expect($run['invoicesCreated'] + $run['invoicesOverdue'] + $run['companiesSuspended'] + $run['noMandateSuspended'] + $run['mandateReminders'])->toBe(0);
    }

    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Active);
    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at->greaterThan(CarbonImmutable::parse('2037-10-01')))->toBeTrue();
    }
});

test('fine: Istanbul-like third till is charged £750 + VAT (one line), held on trial, then gets the 10-year licence when paid', function () {
    $company = auditIstanbul($this)['company'];
    $branch = $this->allowTills($this->branchOf($company, 'IST'));

    $this->actingAs($this->admin(), 'admin')->postJson(route('admin.tenants.registers.store', [$company, $branch->id]), [])->assertOk();

    $invoice = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::TillSetupFee->value)->sole();
    $new = Licence::withoutCompanyScope()->where('company_id', $company->id)->live()->latest('created_at')->latest('id')->firstOrFail();

    expect($invoice->subtotal)->toBe('750.00')
        ->and($invoice->vat_total)->toBe('150.00')
        ->and($invoice->total)->toBe('900.00')
        ->and($invoice->lines()->count())->toBe(1)
        ->and($this->billingAccountOf($company)->setup_fee_covered_tills)->toBe(3)
        ->and(SetupFeeTills::isHeld($new))->toBeTrue();

    $this->runBillingOn('2026-10-12');
    expect($this->licenceFresh($new)->expires_at)->toBeNull();

    $this->pay($company, '900.00', [$invoice->id => '900.00']);
    expect($this->licenceFresh($new)->expires_at?->greaterThan(CarbonImmutable::parse('2036-10-01')))->toBeTrue()
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active);
});

test('fine: Istanbul-like admin and portal billing pages and its invoice PDFs render (void, reversed, paid)', function () {
    $istanbul = auditIstanbul($this);
    $company = $istanbul['company'];

    $this->actingAs($this->admin(), 'admin')->get(route('admin.tenants.show', $company))->assertOk();
    $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.index'))->assertOk();

    foreach ([$istanbul['paid'], ...$istanbul['voided']] as $invoice) {
        $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.invoices.show', $invoice->id))->assertOk();
        $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.invoices.pdf', $invoice->id))->assertOk();
    }

    foreach ([...$istanbul['reversed'], $istanbul['kept']] as $payment) {
        $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.payments.show', $payment->id))->assertOk();
    }

    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk();
});

test('fine: Istanbul-like change plan: no setup fee charged again, a new mandate deadline for a recurring plan, own features kept', function () {
    $company = auditIstanbul($this)['company'];
    $monthly = Plan::factory()->create([
        'name' => 'Setup + monthly', 'code' => 'setup-monthly', 'setup_fee' => '1200.00', 'setup_fee_mode' => SetupFeeMode::PerTill,
        'price_monthly' => '12.00', 'price_yearly' => '120.00', 'billing_type' => PlanBillingType::SetupAndRecurring, 'sort_order' => 30,
    ]);
    $pro = Plan::factory()->create([
        'name' => 'Setup only Pro', 'code' => 'setup-pro', 'setup_fee' => '1500.00', 'setup_fee_mode' => SetupFeeMode::PerTill,
        'price_monthly' => '0.00', 'price_yearly' => '0.00', 'billing_type' => PlanBillingType::SetupOnly, 'sort_order' => 40,
    ]);

    $preview = app(PlanChangePlanner::class)->plan($company, $monthly);
    expect($preview->setupKind)->toBeNull()
        ->and($preview->coveredTills)->toBe(2)
        ->and($preview->startsMandateSetup)->toBeTrue()
        ->and($preview->mandateDeadlineAt?->greaterThan(CarbonImmutable::now()))->toBeTrue()
        ->and(array_column($preview->customShops, 'name'))->toHaveCount(1);

    $result = app(ChangeBusinessPlan::class)->handle($company, $pro);
    expect($result['setupInvoice'])->toBeNull()->and($result['firstInvoice'])->toBeNull();

    $this->runBillingOn('2026-10-12');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Active);
    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->plan_id)->toBe($pro->id)
            ->and($licence->expires_at->greaterThan(CarbonImmutable::parse('2036-10-01')))->toBeTrue()
            ->and($licence->features->map(fn (Feature $f) => $f->value)->all())->toContain('cloud_sync');
    }
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-1 (High): a stale Direct Debit deadline suspends a setup-only business the moment anything recurs
// ---------------------------------------------------------------------------------------------------------------

test('BILL-1: Istanbul given a monthly price (e.g. a cloud sync add-on) is suspended at the next billing:run with no warning', function () {
    $company = auditIstanbul($this)['company'];

    // Billing tab → Change pricing: £10 a till a month (no plan change, so no new mandate deadline is started).
    app(UpdateCompanyPricing::class)->handle($company, new PricingOverride(priceMonthly: '10.00'));
    $account = $this->billingAccountOf($company);

    // The deadline set at onboarding (9 Oct) counts at once: should start a new deadline from today instead.
    expect($account->mandate_deadline_at->format('Y-m-d'))->toBe('2026-10-09')
        ->and(MandateDeadline::missed($company, $account))->toBeTrue();

    $run = $this->runBillingOn('2026-10-12');

    // Should be: a reminder and a new 3-day deadline. Is: suspended straight away, no reminder ever sent.
    expect($run['noMandateSuspended'])->toBe(1)
        ->and($run['mandateReminders'])->toBe(0)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended)
        ->and($this->companyFresh($company)->suspension_reason)->toBe('No Direct Debit set up')
        ->and(auditTillSees($this->licencesOf($company)[0])->status)->toBe(LicenceStatus::Suspended);
    Mail::assertNotQueued(DirectDebitSetupMail::class);
    Mail::assertQueued(AccountSuspendedMail::class);
});

test('BILL-1b: Istanbul’s plan made inactive: a third till lands on another plan, recurs, and the business is suspended', function () {
    $istanbul = auditIstanbul($this);
    $company = $istanbul['company'];
    // The owner stops offering "Setup only" (inactive, as the archive message suggests) and offers a new plan.
    $istanbul['plan']->forceFill(['is_active' => false])->save();
    Plan::factory()->create([
        'name' => 'Setup + monthly', 'code' => 'setup-monthly', 'setup_fee' => '1200.00', 'setup_fee_mode' => SetupFeeMode::PerTill,
        'price_monthly' => '12.00', 'price_yearly' => '120.00', 'billing_type' => PlanBillingType::SetupAndRecurring,
        'features' => [Feature::Loyalty], 'sort_order' => 1,
    ]);

    $branch = $this->allowTills($this->branchOf($company, 'IST'));
    $this->actingAs($this->admin(), 'admin')->postJson(route('admin.tenants.registers.store', [$company, $branch->id]), [])->assertOk();
    $new = Licence::withoutCompanyScope()->where('company_id', $company->id)->live()->latest('created_at')->latest('id')->firstOrFail();

    // The new till is licensed on the default plan, not the business's, so something now recurs.
    expect($new->plan->name)->toBe('Setup + monthly')
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe('14.40');

    $this->runBillingOn('2026-10-12');

    // Should be: no lock (or a fresh deadline). Is: suspended for "No Direct Debit set up", all three tills locked.
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended)
        ->and(auditTillSees($this->licencesOf($company)[0])->status)->toBe(LicenceStatus::Suspended);
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-2 (Medium): voiding an unpaid setup fee invoice to re-issue it gives a setup-only business 10 free years
// ---------------------------------------------------------------------------------------------------------------

test('BILL-2: void-and-re-issue of an unpaid setup fee: billing:run grants the 10-year licence before anything is paid', function () {
    $this->atLondon('2026-10-20 10:00');
    $company = $this->trialTenant('Patel News', 2, '2026-10-26 10:00', 'PTL');
    $this->standardPlan()->forceFill(['setup_fee' => '600.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => SetupFeeMode::PerTill])->save();
    app(OnboardTenantBilling::class)->handle($company);
    [$unpaid] = app(ChargeSetupFee::class)->handle($company);

    // The amount was wrong: Void with "create a corrected draft".
    [, $draft] = $this->voidIt($unpaid, 'Wrong amount', redraft: true);

    // Nothing is paid, but with every setup fee invoice void (the draft does not count) the state reads "none".
    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::NONE);

    $this->runBillingOn('2026-10-21');

    // Should be: still on trial. Is: a 10-year licence.
    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at?->greaterThan(CarbonImmutable::parse('2036-10-01')))->toBeTrue();
    }

    // Issuing the corrected invoice makes it "unpaid" again, but the 10 years stay (a paid date is never shortened).
    app(IssueInvoice::class)->handle($draft);
    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::UNPAID);
    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at->greaterThan(CarbonImmutable::parse('2036-10-01')))->toBeTrue();
    }
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-3 (Medium, UK Direct Debit): a late-failed payment that GoCardless retries and collects is never recorded
// ---------------------------------------------------------------------------------------------------------------

test('BILL-3: confirmed → failed (late failure) → retried → confirmed leaves the invoice owed and suspends a paying customer', function () {
    $this->atLondon('2026-10-24 10:00');
    $this->fakeGoCardless();
    $company = $this->directDebitTenant(tills: 2);
    $this->setUpMandate($company);
    $subscription = $this->gc->lastSubscription();

    $payment = $this->gc->collect($subscription->id, '2026-11-01');
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();
    $this->atLondon('2026-11-04 09:00');
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Failed, 'late_failure_settled')->assertOk();
    // GoCardless retries the payment and collects it.
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'resubmission_requested')->assertOk();
    $this->atLondon('2026-11-10 09:00');
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();

    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole();

    // Should be: a new payment recorded and the invoice paid. Is: still linked to the reversed payment, invoice owed.
    expect(Payment::withoutCompanyScope()->where('company_id', $company->id)->whereNull('reversed_at')->count())->toBe(0)
        ->and(Payment::withoutCompanyScope()->findOrFail($row->payment_id)->reversed_at)->not->toBeNull()
        ->and($this->fresh($row->invoice)->status)->toBe(InvoiceStatus::Issued)
        ->and($this->fresh($row->invoice)->balance)->toBe('60.00');

    $this->runBillingOn('2026-11-11');
    $this->runBillingOn('2026-11-19');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-4 (Low): demo businesses' money is counted in the live billing figures
// ---------------------------------------------------------------------------------------------------------------

test('BILL-4: BillingStats (overview and dashboard) counts demo businesses’ invoices, payments and credit', function () {
    $this->atLondon('2026-10-01 10:00');
    $demo = $this->payingTenant('DEMO – Setup + monthly', 1, 'DEM');
    $demo->forceFill(['is_demo' => true])->save();
    $invoice = $this->issuedFor($demo); // £30.00 with VAT
    $this->pay($demo, '10.00');

    $stats = BillingStats::for(CarbonImmutable::now());

    // Should be: 0 everywhere (no real business here). Is: the demo money.
    expect($stats['cashDue'])->toBe(['count' => 1, 'amount' => '20.00'])
        ->and($stats['collectedThisMonth'])->toBe(['count' => 1, 'amount' => '10.00'])
        ->and($invoice->number)->toStartWith('DEMO-INV-');
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-5 (Low): the "paid by … on …" line comes from the first upfront payment, even when it was reversed
// ---------------------------------------------------------------------------------------------------------------

test('BILL-5: Istanbul’s portal and Payment settings say the setup fee was paid by bank transfer on 6 Oct (that payment was reversed)', function () {
    $istanbul = auditIstanbul($this);
    $portal = app(CurrentCompany::class)->runAs($istanbul['company'], fn () => PortalBilling::for($istanbul['company']));

    expect($istanbul['kept']->method)->toBe(PaymentMethod::Cash)
        ->and($portal['upfront']['status'])->toBe(SetupFeeState::PAID)
        ->and($portal['upfront']['total'])->toBe('£1,800.00')
        // Should be: "Cash", 8 Oct (the payment that stands). Is: the reversed first payment's stamp.
        ->and($portal['upfront']['method'])->toBe('Bank transfer')
        ->and(BillingDates::localDate(CarbonImmutable::parse($portal['upfront']['recordedAt']))->format('Y-m-d'))->toBe('2026-10-06')
        ->and($portal['upfront']['amount'])->toBe('£1,440.00');
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-6 (Low): a setup fee settled by a credit note or by credit does not unlock anything until the next billing:run
// ---------------------------------------------------------------------------------------------------------------

test('BILL-6: a setup-only fee finished with a credit note leaves the tills on trial until the next billing:run', function () {
    $this->atLondon('2026-10-20 10:00');
    $company = $this->trialTenant('Patel News', 1, '2026-10-21 10:00', 'PTL');
    $this->standardPlan()->forceFill(['setup_fee' => '1200.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => SetupFeeMode::PerBusiness])->save();
    app(OnboardTenantBilling::class)->handle($company);
    [$invoice] = app(ChargeSetupFee::class)->handle($company);

    $this->pay($company, '1000.00', [$invoice->id => '1000.00']);
    $this->creditIt($this->fresh($invoice), '440.00', 'Agreed discount');

    expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PAID);

    // Should be: the 10-year licence now (as Record payment does). Is: nothing until billing:run, trial ends at 10:00.
    expect($this->licencesOf($company)[0]->expires_at)->toBeNull();
    $this->atLondon('2026-10-21 12:00');
    expect(auditTillSees($this->licencesOf($company)[0])->status)->not->toBe(LicenceStatus::Active);

    $this->runBillingOn('2026-10-22');
    expect($this->licencesOf($company)[0]->expires_at?->greaterThan(CarbonImmutable::parse('2036-10-01')))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-7 (Medium, PK manual billing; UK cash mode alike): paid after the period ended → nothing renewed, never re-invoiced
// ---------------------------------------------------------------------------------------------------------------

test('BILL-7: PK invoice paid after its period ended renews nothing and billing:run never issues another invoice', function () {
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Karachi'));
    $company = $this->payingTenant('Lahore Mart', 1, 'LHR'); // paid to 31 Oct
    $this->standardPlan()->forceFill(['price_monthly' => '2500.00', 'price_yearly' => '25000.00', 'setup_fee' => '0.00', 'billing_type' => null])->save();
    $this->travelTo(CarbonImmutable::parse('2026-10-25 06:00', 'Asia/Karachi'));
    expect($this->runBilling()['invoicesCreated'])->toBe(1);
    $november = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();
    expect($november->period_start->format('Y-m-d'))->toBe('2026-11-01');

    foreach (['2026-11-02', '2026-11-09'] as $day) {
        $this->travelTo(CarbonImmutable::parse($day.' 06:00', 'Asia/Karachi'));
        $this->runBilling();
    }
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);

    // The customer pays the November invoice on 2 December.
    $this->travelTo(CarbonImmutable::parse('2026-12-02 12:00', 'Asia/Karachi'));
    $this->pay($company, $november->total, [$november->id => $november->total], PaymentMethod::JazzCash);
    expect($this->fresh($november)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active);

    $created = 0;
    foreach (['2026-12-03', '2026-12-10', '2027-01-05'] as $day) {
        $this->travelTo(CarbonImmutable::parse($day.' 06:00', 'Asia/Karachi'));
        $created += $this->runBilling()['invoicesCreated'];
    }

    // Should be: a December invoice (or the paid period moved). Is: tills still paid to 31 Oct, no invoice ever again.
    expect($created)->toBe(0)
        ->and($this->licencesOf($company)[0]->expires_at->setTimezone('Asia/Karachi')->format('Y-m-d'))->toBe('2026-10-31')
        ->and(auditTillSees($this->licencesOf($company)[0])->status)->toBe(LicenceStatus::Expired);
})->group('country-pk');

// ---------------------------------------------------------------------------------------------------------------
// BILL-8 (Low, operational): a paid invoice cannot be corrected from the admin screens
// ---------------------------------------------------------------------------------------------------------------

test('BILL-8: a paid invoice cannot be voided and no route reverses or refunds a recorded payment', function () {
    $istanbul = auditIstanbul($this);

    expect(fn () => $this->voidIt($istanbul['paid'], 'Wrong amount'))->toThrow(ValidationException::class);

    $reversal = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.billing.'))
        ->filter(fn ($route) => preg_match('/revers|refund/i', $route->uri().' '.$route->getName()) === 1);

    // Should be: an audited "Reverse payment" (as Istanbul's corrections needed). Is: none; done by hand on the server.
    expect($reversal)->toBeEmpty();
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-9 (Low): no idempotency on "Record setup fee payment": a double submit pays the next instalment too
// ---------------------------------------------------------------------------------------------------------------

test('BILL-9: the same setup fee payment submitted twice pays two instalments', function () {
    $this->atLondon('2026-10-20 10:00');
    $company = $this->trialTenant('Patel News', 1, '2026-10-26 10:00', 'PTL');
    $this->standardPlan()->forceFill(['setup_fee' => '1200.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => SetupFeeMode::PerBusiness])->save();
    $account = $this->billingAccountOf($company);
    $account->setup_fee_instalments = 3;
    $account->save();

    $input = new UpfrontPayment(null, PaymentMethod::Cash, 'Receipt 0042');
    app(RecordUpfrontPayment::class)->handle($company, $input);
    app(RecordUpfrontPayment::class)->handle($company, $input); // the double click / resubmitted form

    expect(Payment::withoutCompanyScope()->where('company_id', $company->id)->where('reference', 'Receipt 0042')->count())->toBe(2)
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->paidParts)->toBe(2);
});
