<?php

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\ChangePlanHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class, ChangePlanHelpers::class);

/*
 * Change plan (owner 2026-10-07), UK: an admin moves an existing business to another plan. Setup fees already paid are
 * never charged again; a recurring fee starts on the change date; no till locks at the change; the Direct Debit
 * follows (a mandate deadline when there is none); every licence moves (a shop's own features kept).
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->fakeGoCardless();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
});

function cpTillSees(Licence $licence): LicenceState
{
    $licence = Licence::withoutCompanyScope()->with(['company', 'branch', 'register', 'plan'])->findOrFail($licence->id);

    return LicenceState::for($licence, CarbonImmutable::now());
}

test('setup only → setup + monthly with a mandate: the first month starts today, tills stay valid, the Direct Debit collects it', function () {
    $company = $this->cpOnboarded(PlanBillingType::SetupOnly);
    $this->setUpMandate($company);
    [$first] = $this->licencesOf($company);
    expect($this->licenceFresh($first)->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-24'));
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '1200.00', '12.00');

    $this->changePlan($company, $to)->assertRedirect()->assertSessionHas('success');

    // The setup fee already paid covers both tills: nothing charged again.
    expect($this->invoicesOfKind($company, InvoiceKind::SetupFee))->toHaveCount(1)
        ->and($this->invoicesOfKind($company, InvoiceKind::TillSetupFee))->toBe([]);

    $period = $this->invoicesOfKind($company, InvoiceKind::Subscription);
    expect($period)->toHaveCount(1)
        ->and($period[0]->period_start->format('Y-m-d'))->toBe('2026-10-24')
        ->and($period[0]->period_end->format('Y-m-d'))->toBe('2026-11-23')
        ->and($period[0]->total)->toBe('28.80')
        ->and($period[0]->status)->toBe(InvoiceStatus::Issued)
        ->and($period[0]->due_date->format('Y-m-d'))->toBe('2026-10-28'); // the mandate's first possible day

    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->plan_id)->toBe($to->id)
            ->and($licence->features->all())->toBe([Feature::Loyalty, Feature::Promotions])
            ->and($licence->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-23'))
            ->and(cpTillSees($licence)->status)->toBe(LicenceStatus::Active); // never locked at the change
    }

    $subscription = $this->gc->lastSubscription();
    $account = $this->billingAccountOf($company);
    expect($subscription->amountPence)->toBe(2880)
        ->and($subscription->upcomingChargeDate)->toBe('2026-10-28')
        ->and($account->recurring_starts_on)->toBeNull()
        ->and($company->refresh()->plan_id)->toBe($to->id);

    $audit = AuditLog::query()->where('action', 'billing.plan_changed')->sole();
    expect($audit->before['plan'])->toBe($this->standardPlan()->code)
        ->and($audit->after['plan'])->toBe($to->code)
        ->and($audit->after['first_invoice'])->toBe($period[0]->number);

    // GoCardless creates the first payment: it collects the first period invoice.
    $payment = $this->gc->collect($subscription->id);
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();
    expect(GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole()->invoice_id)->toBe($period[0]->id);
});

test('setup only → setup + monthly without a mandate: a new mandate deadline, no suspension before it, the subscription collects the first period', function () {
    $company = $this->cpOnboarded(PlanBillingType::SetupOnly);
    $this->atLondon('2026-11-02 10:00'); // the onboarding deadline is long gone
    $to = $this->cpPlan('Monthly only', PlanBillingType::RecurringOnly, '0.00', '30.00');

    $this->changePlan($company, $to)->assertRedirect();

    $account = $this->billingAccountOf($company);
    $period = $this->invoicesOfKind($company, InvoiceKind::Subscription)[0];
    expect($account->mandate_deadline_at->toDateTimeString())->toBe(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/London')->utc()->toDateTimeString())
        ->and($account->recurring_starts_on->format('Y-m-d'))->toBe('2026-11-02')
        ->and($period->total)->toBe('72.00')
        ->and($period->due_date->format('Y-m-d'))->toBe('2026-11-05')
        ->and($this->gc->subscriptions)->toBe([]);

    $this->runBillingOn('2026-11-04');
    expect($company->refresh()->status)->not->toBe(CompanyStatus::Suspended)
        ->and(cpTillSees($this->licencesOf($company)[0])->status)->toBe(LicenceStatus::Active);

    $this->atLondon('2026-11-04 12:00');
    $this->setUpMandate($company);
    $subscription = $this->gc->lastSubscription();
    expect($subscription->upcomingChargeDate)->toBe('2026-11-09') // from 2 Nov, on the mandate's first possible day
        ->and($subscription->amountPence)->toBe(7200)
        ->and($this->billingAccountOf($company)->recurring_starts_on)->toBeNull();

    $payment = $this->gc->collect($subscription->id);
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();
    expect(GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole()->invoice_id)->toBe($period->id);
});

test('without a mandate by the new deadline the business is suspended, as for a new Direct Debit business', function () {
    $company = $this->cpOnboarded(PlanBillingType::SetupOnly);
    $this->changePlan($company, $this->cpPlan('Monthly only', PlanBillingType::RecurringOnly, '0.00', '30.00'))->assertRedirect();

    $this->runBillingOn('2026-10-28');

    expect($company->refresh()->status)->toBe(CompanyStatus::Suspended);
});

test('monthly only → setup + monthly: the setup fee is charged (editable), paid dates stay, the Direct Debit amount changes', function () {
    $company = $this->directDebitTenant(); // £25 a till a month, paid to 31 Oct, no setup fee
    $this->setUpMandate($company);
    expect($this->gc->lastSubscription()->amountPence)->toBe(6000);
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '1200.00', '12.00');

    $this->previewPlan($company, $to)->assertOk()->assertJsonPath('setupFee.suggested', '1200.00')->assertJsonPath('setupFee.kind', 'setupFee');
    $this->changePlan($company, $to, ['setup_fee' => '500'])->assertRedirect();

    $setup = $this->invoicesOfKind($company, InvoiceKind::SetupFee);
    expect($setup)->toHaveCount(1)
        ->and($setup[0]->total)->toBe('600.00')
        ->and($setup[0]->status)->toBe(InvoiceStatus::Issued)
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::UNPAID)
        ->and($this->gc->lastSubscription()->amountPence)->toBe(2880)
        ->and($this->invoicesOfKind($company, InvoiceKind::Subscription))->toBe([]);

    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'));
    }
});

test('setup + monthly → monthly only: nothing charged, the new price from the next collection, dates unchanged', function () {
    $company = $this->cpOnboarded(PlanBillingType::SetupAndRecurring, '1200.00', '25.00');
    $this->setUpMandate($company);
    expect($this->gc->lastSubscription()->amountPence)->toBe(6000);
    $trialEnds = $this->licencesOf($company)[0]->trial_ends_at->toDateTimeString();

    $this->changePlan($company, $this->cpPlan('Monthly only', PlanBillingType::RecurringOnly, '0.00', '30.00'))->assertRedirect();

    expect($this->gc->lastSubscription()->amountPence)->toBe(7200)
        ->and($this->gc->subscriptions)->toHaveCount(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1) // the paid setup fee only
        ->and($this->licencesOf($company)[0]->trial_ends_at->toDateTimeString())->toBe($trialEnds);
});

test('the admin can waive the new plan’s setup fee with 0', function () {
    $company = $this->directDebitTenant();
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '1200.00', '12.00');

    $this->changePlan($company, $to, ['setup_fee' => '0'])->assertRedirect();

    expect($this->invoicesOfKind($company, InvoiceKind::SetupFee))->toBe([])
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::NONE)
        ->and(AuditLog::query()->where('action', 'billing.setup_fee_waived')->count())->toBe(1);
});

test('setup + monthly → setup only, setup fee paid: the 10-year licence at once and the Direct Debit is cancelled', function () {
    $company = $this->cpOnboarded(PlanBillingType::SetupAndRecurring, '1200.00', '25.00');
    $this->setUpMandate($company);
    $subscription = $this->gc->lastSubscription();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);

    $this->changePlan($company, $this->cpPlan('Setup only', PlanBillingType::SetupOnly, '900.00', '0.00'))->assertRedirect();

    expect($this->gc->subscriptions[$subscription->id]->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->invoicesOfKind($company, InvoiceKind::SetupFee))->toHaveCount(1);

    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-24'));
    }
});

test('setup + monthly → setup only per till: covered tills are never charged; the uncovered till keeps its date until its fee is paid', function () {
    $company = $this->payingTenant();
    $this->cpStandard(PlanBillingType::SetupAndRecurring, '300.00', '25.00');
    $account = $this->billingAccountOf($company);
    $account->forceFill(['setup_fee_invoiced_at' => now(), 'upfront_recorded_at' => now(), 'upfront_amount' => '0.00', 'setup_fee_covered_tills' => 1])->save();
    $to = $this->cpPlan('Setup only', PlanBillingType::SetupOnly, '400.00', '0.00', mode: SetupFeeMode::PerTill);
    [$covered, $uncovered] = $this->licencesOf($company);

    $this->previewPlan($company, $to)->assertOk()->assertJsonPath('setupFee.covered', 1)->assertJsonPath('setupFee.uncovered', 1)
        ->assertJsonPath('setupFee.suggested', '400.00');
    $this->changePlan($company, $to)->assertRedirect();

    $fees = $this->invoicesOfKind($company, InvoiceKind::TillSetupFee);
    expect($fees)->toHaveCount(1)
        ->and($fees[0]->total)->toBe('480.00')
        ->and($fees[0]->lines()->sole()->licence_id)->toBe($uncovered->id)
        ->and($this->licenceFresh($covered)->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-24'))
        ->and($this->licenceFresh($uncovered)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'))
        ->and($this->billingAccountOf($company)->setup_fee_covered_tills)->toBe(2);

    $this->pay($company, '480.00');

    expect($this->licenceFresh($uncovered)->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-24'));
});

test('recurring → setup only with nothing paid before: the setup fee is due and the tills keep their paid dates', function () {
    $company = $this->payingTenant(); // monthly only, no setup fee ever
    $to = $this->cpPlan('Setup only', PlanBillingType::SetupOnly, '1200.00', '0.00');

    $this->changePlan($company, $to)->assertRedirect();

    expect($this->invoicesOfKind($company, InvoiceKind::SetupFee)[0]->total)->toBe('1440.00');
    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'));
    }

    $this->pay($company, '1440.00');
    expect($this->licenceFresh($this->licencesOf($company)[0])->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-24'));
});

test('a plan change is refused for the same plan, an inactive plan or a cancelled business', function () {
    $company = $this->payingTenant();

    $this->changePlan($company, $this->standardPlan())->assertSessionHasErrors('plan_id');

    $inactive = $this->cpPlan('Retired', PlanBillingType::RecurringOnly, '0.00', '9.00');
    $inactive->forceFill(['is_active' => false])->save();
    $this->changePlan($company, $inactive)->assertSessionHasErrors('plan_id');

    $company->forceFill(['status' => CompanyStatus::Cancelled])->save();
    $this->changePlan($company, $this->cpPlan('Other', PlanBillingType::RecurringOnly, '0.00', '9.00'))->assertSessionHasErrors('plan_id');

    expect(AuditLog::query()->where('action', 'billing.plan_changed')->count())->toBe(0);
});
