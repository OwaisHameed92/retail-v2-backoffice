<?php

use App\Domain\Billing\Actions\ApplySetupFeeTerms;
use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\ReleaseBillingHolds;
use App\Domain\Billing\Actions\UpdateBillingSettings;
use App\Domain\Billing\Actions\UpdateCompanyPricing;
use App\Domain\Billing\Data\BillingSettingsInput;
use App\Domain\Billing\Data\PricingOverride;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\DirectDebitSetupMail;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Billing\SetupOnlyTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit BILL-1 (docs/audit/2026-10-11-billing.md): a setup-only UK business keeps its onboarding Direct Debit deadline.
 * Once anything recurs it must get a fresh deadline (and a reminder) instead of being suspended the next morning, and
 * a till added to it must stay on its plan. Deploying the fix changes nothing for anybody (no suspension made or lifted).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class, SetupOnlyTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->setVat(true);
    config(['billing.suspend_after_days' => 7]);
});

function bfxRestarts(Company $company): int
{
    return AuditLog::query()->where('company_id', $company->id)->where('action', 'billing.dd_mandate_deadline_restarted')->count();
}

/** A Pakistan instance (manual collection, no Direct Debit). */
function bfxPakistan(): void
{
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
}

test('BILL-1: a monthly price on a setup-only business starts a fresh deadline: reminded, suspended only after it', function () {
    $company = $this->istanbulLike()['company'];

    app(UpdateCompanyPricing::class)->handle($company, new PricingOverride(priceMonthly: '10.00'));
    $account = $this->billingAccountOf($company);

    // 9 Oct (onboarding) → 14 Oct 10:00 (now + 3 days), reminder due again; nothing missed.
    expect($account->mandate_deadline_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/London')->utc()->toIso8601String())
        ->and($account->mandate_reminder_for)->toBeNull()
        ->and(MandateDeadline::missed($company, $account))->toBeFalse()
        ->and(MandateDeadline::state($company, $account)['daysLeft'])->toBe(3)
        ->and(bfxRestarts($company))->toBe(1);

    $first = $this->runBillingOn('2026-10-12');
    expect($first['noMandateSuspended'] + $first['mandateReminders'])->toBe(0)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active)
        ->and($this->tillSeesNow($this->licencesOf($company)[0])->status)->toBe(LicenceStatus::Active);

    expect($this->runBillingOn('2026-10-13')['mandateReminders'])->toBe(1);
    Mail::assertQueued(DirectDebitSetupMail::class, fn (DirectDebitSetupMail $mail) => $mail->data->reminder);
    expect($this->runBillingOn('2026-10-14')['noMandateSuspended'])->toBe(0);

    expect($this->runBillingOn('2026-10-15')['noMandateSuspended'])->toBe(1)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended)
        ->and($this->companyFresh($company)->suspension_reason)->toBe('No Direct Debit set up');
    Mail::assertQueued(AccountSuspendedMail::class);
});

test('BILL-1: a cycle change that makes a business recur starts a fresh deadline too', function () {
    $istanbul = $this->istanbulLike();
    $company = $istanbul['company'];
    $istanbul['plan']->forceFill(['price_yearly' => '100.00'])->save(); // £0 a month, £100 a year

    app(UpdateBillingSettings::class)->handle($company, new BillingSettingsInput(
        billingName: null, billingAddress: null, emails: [], cycle: BillingCycle::Yearly, paymentTermsDays: 7, vatApplies: true,
    ));

    expect($this->billingAccountOf($company)->mandate_deadline_at->greaterThan(CarbonImmutable::now()->addDays(2)))->toBeTrue()
        ->and(bfxRestarts($company))->toBe(1)
        ->and($this->runBillingOn('2026-10-12')['noMandateSuspended'])->toBe(0)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active);
});

test('BILL-1: a deadline that passed while nothing recurred never suspends, whatever made the business recur (fresh deadline, setup email)', function () {
    $istanbul = $this->istanbulLike();
    $company = $istanbul['company'];
    $this->runBillingOn('2026-10-12');

    // A path with no hook of its own (here the plan's price edited): billing:run catches it.
    $istanbul['plan']->forceFill(['price_monthly' => '10.00', 'billing_type' => PlanBillingType::SetupAndRecurring])->save();
    expect(MandateDeadline::unwarned($this->billingAccountOf($company)))->toBeTrue()
        ->and(MandateDeadline::missed($company, $this->billingAccountOf($company)))->toBeFalse();

    $run = $this->runBillingOn('2026-10-13');
    $account = $this->billingAccountOf($company);

    expect($run['noMandateSuspended'])->toBe(0)
        ->and($run['mandateReminders'])->toBe(1)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active)
        ->and($account->mandate_deadline_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-16 06:00', 'Europe/London')->utc()->toIso8601String())
        ->and($account->mandate_reminder_for?->equalTo($account->mandate_deadline_at))->toBeTrue()
        ->and(AuditLog::query()->where('company_id', $company->id)->where('action', 'billing.dd_mandate_deadline_restarted')->sole()->meta['reason'] ?? null)->toBe('unwarned');
    Mail::assertQueued(DirectDebitSetupMail::class, fn (DirectDebitSetupMail $mail) => ! $mail->data->reminder && $mail->data->deadline?->equalTo($account->mandate_deadline_at));

    expect($this->runBillingOn('2026-10-14')['mandateReminders'] + $this->runBillingOn('2026-10-15')['noMandateSuspended'])->toBe(0);
    expect($this->runBillingOn('2026-10-16')['noMandateSuspended'])->toBe(1)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);
});

test('BILL-1b: with its plan made inactive, a new till stays on the business’s plan: nothing recurs, the added-till fee follows that plan', function () {
    $istanbul = $this->istanbulLike();
    $company = $istanbul['company'];
    $istanbul['plan']->forceFill(['is_active' => false])->save();
    $this->setupAndMonthlyPlan(); // now the portal default: first active plan, £12 a month, £999 per business

    expect(DefaultPlan::for($company)?->name)->toBe('Setup + monthly')
        ->and(DefaultPlan::forNewTill($company)?->id)->toBe($istanbul['plan']->id)
        ->and(SetupFeeTills::perTill($company))->toBeTrue()
        ->and(SetupFee::planFee($company))->toBe('2400.00'); // the business's plan: £1,200 × 2 tills

    $branch = $this->allowTills($this->branchOf($company, 'IST'));
    $this->actingAs($this->admin(), 'admin')->postJson(route('admin.tenants.registers.store', [$company, $branch->id]), [])->assertOk();
    $new = $this->newestLicence($company);
    $charge = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::TillSetupFee->value)->sole();

    expect($new->plan_id)->toBe($istanbul['plan']->id)
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe('0.00')
        ->and(ApplySetupFeeTerms::planType($company))->toBe(PlanBillingType::SetupOnly)
        ->and($charge->total)->toBe('900.00') // £750 + VAT, the business's own per-till fee
        ->and(SetupFeeTills::isHeld($new))->toBeTrue();

    $this->runBillingOn('2026-10-12');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Active)
        ->and($this->tillSeesNow($this->licencesOf($company)[0])->status)->toBe(LicenceStatus::Active);

    // Without its own per-till fee, the added-till charge is the business's plan fee, not the portal default's.
    $account = $this->billingAccountOf($company);
    $account->till_setup_fee_override = null;
    $account->save();
    expect(SetupFee::perTillFee($company, $account))->toBe('1200.00');
});

test('BILL-1b: a business with no live tills still gets today’s plan for a new till (its own active plan, else the default)', function () {
    $company = $this->trialTenant('Patel News', 1, '2026-10-26 10:00', 'PTL');
    $plan = $this->standardPlan();

    foreach ($this->licencesOf($company) as $licence) {
        $licence->register->forceFill(['is_active' => false])->save();
    }

    expect(DefaultPlan::forNewTill($company)?->id)->toBe(DefaultPlan::for($company)?->id)
        ->and(DefaultPlan::forNewTill($company)?->id)->toBe($plan->id);
});

test('BILL-1b: a plan no longer offered is still refused for a business that is not on it', function () {
    $istanbul = $this->istanbulLike();
    $istanbul['plan']->forceFill(['is_active' => false])->save();
    $other = $this->trialTenant('Patel News', 1, '2026-10-26 10:00', 'PTL');
    $other->forceFill(['plan_id' => null])->save();
    $this->setupAndMonthlyPlan();

    expect(DefaultPlan::isKeptFor($istanbul['plan'], $istanbul['company']))->toBeTrue()
        ->and(DefaultPlan::isKeptFor($istanbul['plan'], $other->refresh()))->toBeFalse();
});

test('deploy: a setup-only business with a passed deadline and nothing recurring gets no reminder, no suspension, no new deadline', function () {
    $company = $this->istanbulLike()['company'];
    $before = $this->billingAccountOf($company);

    foreach (['2026-10-12', '2026-10-13', '2026-10-20', '2026-11-15', '2027-03-01'] as $day) {
        $run = $this->runBillingOn($day);
        expect($run['noMandateSuspended'] + $run['mandateReminders'] + $run['companiesSuspended'])->toBe(0);
    }

    $after = $this->billingAccountOf($company);
    expect($after->mandate_deadline_at->toIso8601String())->toBe($before->mandate_deadline_at->toIso8601String())
        ->and($after->mandate_deadline_at->format('Y-m-d'))->toBe('2026-10-09')
        ->and($after->mandate_reminder_for)->toBeNull()
        ->and(bfxRestarts($company))->toBe(0)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active);
    Mail::assertNotQueued(DirectDebitSetupMail::class);
    Mail::assertNotQueued(AccountSuspendedMail::class);
});

test('deploy: a business already suspended for its deadline stays suspended (never reminded, as before the fix); a hand payment does not lift it', function () {
    $this->atLondon('2026-10-12 06:00');
    $company = $this->directDebitTenant('Khan Mini Mart', 1, 'KMM'); // £25 a till a month, tills paid to 31 Oct
    $account = $this->billingAccountOf($company);
    $account->mandate_deadline_at = CarbonImmutable::parse('2026-10-09 10:00', 'Europe/London');
    $account->save();

    // As billing:run left it before the fix: suspended for that deadline with no reminder stamped.
    $suspended = app(SuspendCompany::class)->handle($company, 'No Direct Debit set up');
    $account = $this->billingAccountOf($company);
    $account->billing_suspended_at = CarbonImmutable::instance($suspended->suspended_at);
    $account->mandate_grace_suspended_for = $account->mandate_deadline_at;
    $account->save();

    foreach (['2026-10-13', '2026-10-20', '2026-11-02'] as $day) {
        $run = $this->runBillingOn($day);
        expect($run['noMandateSuspended'] + $run['mandateReminders'])->toBe(0);
    }

    $account = $this->billingAccountOf($company);
    expect(MandateDeadline::unwarned($account))->toBeFalse()
        ->and(MandateDeadline::missed($company, $account))->toBeTrue()
        ->and(app(ReleaseBillingHolds::class)->handle($company))->toBeFalse()
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended)
        ->and($account->mandate_deadline_at->format('Y-m-d'))->toBe('2026-10-09')
        ->and(bfxRestarts($company))->toBe(0);
    Mail::assertNotQueued(DirectDebitSetupMail::class);
});

test('deploy: a business waiting for its mandate (deadline ahead) is reminded and suspended exactly as before', function () {
    $this->atLondon('2026-10-24 10:00');
    $company = $this->directDebitTenant('Khan Mini Mart', 1, 'KMM');
    $account = $this->billingAccountOf($company);
    $account->mandate_deadline_at = MandateDeadline::fromNow();
    $account->save();

    expect($this->runBillingOn('2026-10-25')['mandateReminders'])->toBe(0)
        ->and($this->runBillingOn('2026-10-26')['mandateReminders'])->toBe(1)
        ->and($this->runBillingOn('2026-10-27')['noMandateSuspended'])->toBe(0)
        ->and($this->runBillingOn('2026-10-28')['noMandateSuspended'])->toBe(1)
        ->and(bfxRestarts($company))->toBe(0)
        ->and($this->billingAccountOf($company)->mandate_deadline_at->equalTo($account->mandate_deadline_at))->toBeTrue();
});

test('PK: a setup-only business has no deadline: a price or a new till on its inactive plan starts nothing and never suspends', function () {
    bfxPakistan();
    $this->atLondon('2026-10-06 11:00');
    $company = $this->trialTenant('Lahore Mart', 2, '2026-10-13 11:00', 'IST');
    $plan = $this->standardPlan();
    $plan->forceFill([
        'name' => 'Setup only', 'setup_fee' => '60000.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
        'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => SetupFeeMode::PerTill,
    ])->save();
    app(OnboardTenantBilling::class)->handle($company, new UpfrontPayment(null, PaymentMethod::JazzCash));
    $this->atLondon('2026-10-11 10:00');
    $company->refresh();
    $istanbul = ['company' => $company, 'plan' => $plan->refresh()];
    expect($this->billingAccountOf($company)->mandate_deadline_at)->toBeNull()
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PAID);

    $istanbul['plan']->forceFill(['is_active' => false])->save();
    $this->setupAndMonthlyPlan();
    $branch = $this->allowTills($this->branchOf($company, 'IST'));
    $this->actingAs($this->admin(), 'admin')->postJson(route('admin.tenants.registers.store', [$company, $branch->id]), [])->assertOk();

    expect($this->newestLicence($company)->plan_id)->toBe($istanbul['plan']->id)
        ->and(SetupFeeTills::perTill($company))->toBeTrue()
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe('0.00');

    app(UpdateCompanyPricing::class)->handle($company, new PricingOverride(priceMonthly: '2500.00'));

    expect($this->billingAccountOf($company)->mandate_deadline_at)->toBeNull()
        ->and(bfxRestarts($company))->toBe(0)
        ->and($this->runBillingOn('2026-10-12')['noMandateSuspended'])->toBe(0)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Active);
    Mail::assertNotQueued(DirectDebitSetupMail::class);
})->group('country-pk');
