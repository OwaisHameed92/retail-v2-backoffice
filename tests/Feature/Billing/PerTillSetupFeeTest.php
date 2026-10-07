<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\ChargeAddedTills;
use App\Domain\Billing\Actions\GenerateInvoice;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

/*
 * P11 (owner 2026-10-07): a plan's setup fee charged for each till. At onboarding it is fee × tills (editable by the
 * admin); a till added later gets its own setup fee invoice and stays on its trial until that is paid; tills already
 * covered by a paid setup fee are never charged again (grandfathered, and the backfill command).
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
});

function setupPlan(object $test, string $fee, SetupFeeMode $mode, string $monthly = '0.00'): Plan
{
    $plan = $test->standardPlan();
    $plan->forceFill(['setup_fee' => $fee, 'price_monthly' => $monthly, 'price_yearly' => '0.00', 'billing_type' => null, 'setup_fee_mode' => $mode])->save();

    return $plan->refresh();
}

/** A business on trial with its setup fee paid by card today (setup-only plan as given). */
function paidSetupBusiness(object $test, int $tills, string $fee = '300.00', SetupFeeMode $mode = SetupFeeMode::PerTill, PaymentMethod $method = PaymentMethod::Card): Company
{
    $company = $test->trialTenant('Patel News', $tills, '2026-10-26 10:00');
    setupPlan($test, $fee, $mode);
    app(OnboardTenantBilling::class)->handle($company, new UpfrontPayment(null, $method));

    return $company->refresh();
}

function tillSetupInvoices(Company $company): array
{
    return Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::TillSetupFee->value)->orderBy('sequence')->get()->all();
}

/** Adds a till through the admin dialog (JSON), as `$role`, with an optional setup fee typed in. */
function addTillAsAdmin(object $test, Company $company, AdminRole $role = AdminRole::Owner, ?string $fee = null): array
{
    $branch = $test->allowTills($test->branchOf($company, 'TRL'));

    return $test->actingAs($test->admin($role), 'admin')
        ->postJson(route('admin.tenants.registers.store', [$company, $branch->id]), array_filter(['till_setup_fee' => $fee], fn ($v) => $v !== null))
        ->assertOk()->json();
}

function newestLicence(Company $company): Licence
{
    return Licence::withoutCompanyScope()->where('company_id', $company->id)->live()->latest('created_at')->latest('id')->firstOrFail();
}

test('per till at onboarding: the setup fee is the plan fee times the tills; covered tills are recorded', function () {
    $company = paidSetupBusiness($this, 2);

    $invoice = Invoice::withoutCompanyScope()->where('kind', InvoiceKind::SetupFee->value)->sole();
    expect($invoice->subtotal)->toBe('600.00')
        ->and($invoice->total)->toBe('720.00')
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($this->billingAccountOf($company)->setup_fee_covered_tills)->toBe(2)
        // Kept as the business's amount: tills added later never change what its setup fee shows.
        ->and($this->billingAccountOf($company)->setup_fee_override)->toBe('600.00');
});

test('per till at onboarding: the admin can charge less or nothing', function () {
    $less = $this->trialTenant('Less Stores', 2, '2026-10-26 10:00', 'LSS');
    setupPlan($this, '300.00', SetupFeeMode::PerTill);
    app(OnboardTenantBilling::class)->handle($less, new UpfrontPayment('500.00', PaymentMethod::Cash));

    expect(Invoice::withoutCompanyScope()->where('company_id', $less->id)->sole()->total)->toBe('600.00');

    $free = $this->trialTenant('Free Stores', 3, '2026-10-26 10:00', 'FRE');
    app(OnboardTenantBilling::class)->handle($free, new UpfrontPayment('0', PaymentMethod::Cash));

    expect(Invoice::withoutCompanyScope()->where('company_id', $free->id)->count())->toBe(0)
        ->and(SetupFeeState::for($free, $this->billingAccountOf($free))->status)->toBe(SetupFeeState::NONE)
        ->and($this->billingAccountOf($free)->setup_fee_covered_tills)->toBe(3);
});

test('per till: an unpaid, not yet invoiced setup fee counts every till, and the wizard knows the plan is per till', function () {
    $company = $this->trialTenant('Patel News', 3, '2026-10-26 10:00');
    $plan = setupPlan($this, '250.00', SetupFeeMode::PerTill);

    $state = SetupFeeState::for($company, $this->billingAccountOf($company));
    expect($state->status)->toBe(SetupFeeState::UNPAID)->and($state->total)->toBe('900.00');

    $this->actingAs($this->admin(), 'admin')->get(route('admin.tenants.create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where("billing.setupFeePerTill.{$plan->id}", true)->where("billing.setupFees.{$plan->id}", '250.00'));
});

test('per business plans keep charging once: no invoice for an added till', function () {
    $company = paidSetupBusiness($this, 1, '1200.00', SetupFeeMode::PerBusiness);

    $reply = addTillAsAdmin($this, $company);

    expect(tillSetupInvoices($company))->toBe([])
        ->and($reply['message'])->toBe('Till 2 added.')
        ->and($this->billingAccountOf($company)->setup_fee_covered_tills)->toBe(2)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole()->total)->toBe('1440.00');
});

test('a till added later is invoiced its setup fee and stays on its trial until it is paid; paying unlocks it', function () {
    $company = paidSetupBusiness($this, 1);
    $first = $this->licencesOf($company)[0];
    expect($this->licenceFresh($first)->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-24'));

    $reply = addTillAsAdmin($this, $company);
    $invoices = tillSetupInvoices($company);
    $added = newestLicence($company);

    expect($invoices)->toHaveCount(1)
        ->and($reply['message'])->toContain("Setup fee invoice {$invoices[0]->number} raised")
        ->and($invoices[0]->total)->toBe('360.00')
        ->and($invoices[0]->status)->toBe(InvoiceStatus::Issued)
        ->and($invoices[0]->lines()->sole()->licence_id)->toBe($added->id)
        ->and(SetupFeeTills::heldLicenceIds($company->id))->toBe([$added->id])
        ->and($this->billingAccountOf($company)->setup_fee_covered_tills)->toBe(2)
        // The business's own setup fee stays paid: only the new till waits.
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PAID);

    // Billing does not give the held till the full licence; its trial runs as normal.
    $this->activate($added, CarbonImmutable::now());
    $this->runBillingOn('2026-10-25');
    expect($this->licenceFresh($added)->expires_at)->toBeNull();

    // Paid by hand like the first setup fee: the till gets the full licence at once.
    $this->atLondon('2026-10-25 11:00');
    $this->pay($company, '360.00', [$invoices[0]->id => '360.00'], PaymentMethod::BankTransfer);

    expect($this->fresh($invoices[0])->status)->toBe(InvoiceStatus::Paid)
        ->and(SetupFeeTills::heldLicenceIds($company->id))->toBe([])
        ->and($this->licenceFresh($added)->expires_at->toDateTimeString())->toBe($this->londonEnd('2036-10-25'))
        ->and(AuditLog::query()->where('action', 'billing.till_setup_fee_invoiced')->count())->toBe(1);
});

test('the admin can change the added till fee or waive it; only billing admins may', function () {
    $company = paidSetupBusiness($this, 1);

    addTillAsAdmin($this, $company, AdminRole::Owner, '150');
    expect(tillSetupInvoices($company)[0]->total)->toBe('180.00');

    addTillAsAdmin($this, $company, AdminRole::Owner, '0');
    $waived = newestLicence($company);
    expect(tillSetupInvoices($company))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'billing.till_setup_fee_waived')->count())->toBe(1)
        ->and(SetupFeeTills::isHeld($waived))->toBeFalse();

    // Support may add tills but not change money: the usual fee is invoiced.
    addTillAsAdmin($this, $company, AdminRole::Support, '0');
    expect(tillSetupInvoices($company))->toHaveCount(2)
        ->and(tillSetupInvoices($company)[1]->total)->toBe('360.00')
        ->and($this->billingAccountOf($company)->setup_fee_covered_tills)->toBe(4);
});

test('a waived or voided added till fee lets the till get its licence at the next run', function () {
    $company = paidSetupBusiness($this, 1);
    addTillAsAdmin($this, $company);
    $added = newestLicence($company);
    $this->activate($added, CarbonImmutable::now());

    $this->voidIt(tillSetupInvoices($company)[0], 'Waived by the owner');
    $this->runBillingOn('2026-10-25');

    expect($this->licenceFresh($added)->expires_at)->not->toBeNull();
});

test('grandfathering: moving to a per-till plan never charges tills already covered', function () {
    $company = paidSetupBusiness($this, 2, '1200.00', SetupFeeMode::PerBusiness);
    // A till added while the plan was per business is covered by that fee.
    addTillAsAdmin($this, $company);
    expect($this->billingAccountOf($company)->setup_fee_covered_tills)->toBe(3);

    setupPlan($this, '400.00', SetupFeeMode::PerTill);
    $branch = $this->allowTills($this->branchOf($company, 'TRL'));
    app(AddRegister::class)->handle($branch, 'Till 4');
    $fourth = newestLicence($company);
    app(AddRegister::class)->handle($branch, 'Till 5');
    $invoice = app(ChargeAddedTills::class)->handle($company->refresh(), [$fourth, newestLicence($company)]);

    expect($invoice?->subtotal)->toBe('800.00')
        ->and($invoice?->lines()->count())->toBe(2)
        ->and($this->billingAccountOf($company)->setup_fee_covered_tills)->toBe(5);
});

test('grandfathering: untracked coverage (before P11) counts every till the business already had', function () {
    $company = paidSetupBusiness($this, 2, '1200.00', SetupFeeMode::PerBusiness);
    $this->billingAccountOf($company)->forceFill(['setup_fee_covered_tills' => null])->save();
    setupPlan($this, '1200.00', SetupFeeMode::PerTill);

    addTillAsAdmin($this, $company);

    expect(tillSetupInvoices($company))->toHaveCount(1)
        ->and(tillSetupInvoices($company)[0]->lines()->count())->toBe(1)
        ->and(tillSetupInvoices($company)[0]->total)->toBe('1440.00');
});

test('backfill: a business like Istanbul Market (Setup only, 2 tills, £1,200 + VAT paid once) covers its 2 tills; idempotent', function () {
    $istanbul = $this->trialTenant('Istanbul Market', 2, '2026-10-26 10:00', 'IST');
    $plan = setupPlan($this, '1200.00', SetupFeeMode::PerBusiness);
    $plan->forceFill(['name' => 'Setup only'])->save();
    app(OnboardTenantBilling::class)->handle($istanbul, new UpfrontPayment(null, PaymentMethod::BankTransfer));
    $unpaid = $this->trialTenant('Unpaid Stores', 3, '2026-10-26 10:00', 'UNP');
    app(OnboardTenantBilling::class)->handle($unpaid);
    // The live data before P11: nothing tracked.
    $this->billingAccountOf($istanbul)->forceFill(['setup_fee_covered_tills' => null])->save();

    $this->artisan('billing:backfill-setup-fee-coverage', ['--dry-run' => true])->expectsOutputToContain('Would update 1 business')->assertSuccessful();
    expect($this->billingAccountOf($istanbul)->setup_fee_covered_tills)->toBeNull();

    $this->artisan('billing:backfill-setup-fee-coverage')->expectsOutputToContain('Updated 1 business')->assertSuccessful();
    $this->artisan('billing:backfill-setup-fee-coverage')->expectsOutputToContain('Nothing to change')->assertSuccessful();

    expect($this->billingAccountOf($istanbul)->setup_fee_covered_tills)->toBe(2)
        ->and($this->billingAccountOf($unpaid)->setup_fee_covered_tills)->toBeNull()
        ->and(AuditLog::query()->where('action', 'billing.setup_fee_coverage_backfilled')->count())->toBe(1);

    // The owner switches the plan to per till: a third till pays one setup fee, the first two never again.
    setupPlan($this, '1200.00', SetupFeeMode::PerTill);
    $branch = $this->allowTills($this->branchOf($istanbul, 'IST'));
    $this->actingAs($this->admin(), 'admin')->postJson(route('admin.tenants.registers.store', [$istanbul, $branch->id]), [])->assertOk();

    expect(tillSetupInvoices($istanbul))->toHaveCount(1)
        ->and(tillSetupInvoices($istanbul)[0]->total)->toBe('1440.00')
        ->and(tillSetupInvoices($istanbul)[0]->lines()->count())->toBe(1);
});

test('recurring plan: a held till is not renewed by a paid period; paying its setup fee moves its trial to the others’ paid date', function () {
    $company = $this->payingTenant('Monthly Mart', 1, 'TRL');
    setupPlan($this, '100.00', SetupFeeMode::PerTill, '25.00');
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Cash));

    addTillAsAdmin($this, $company);
    $added = newestLicence($company);
    $this->activate($added, CarbonImmutable::now());
    $fee = tillSetupInvoices($company)[0];
    expect($fee->total)->toBe('120.00');

    // November is invoiced and paid: the first till renews, the held one does not.
    $period = app(IssueInvoice::class)->handle(app(GenerateInvoice::class)->handle($company, new NewInvoice(periodStart: BillingDates::date('2026-11-01'))));
    $this->pay($company, $period->balance, [$period->id => $period->balance]);
    expect($this->licenceFresh($this->licencesOf($company)[0])->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'))
        ->and($this->licenceFresh($added)->expires_at)->toBeNull();

    $this->pay($company, '120.00', [$fee->id => '120.00']);

    expect($this->licenceFresh($added)->trial_ends_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'))
        ->and(AuditLog::query()->where('action', 'licence.trial_extended')->where('subject_id', $added->id)->count())->toBe(1);
});

test('company isolation: an added till fee only ever bills its own business', function () {
    $a = paidSetupBusiness($this, 1);
    $b = $this->trialTenant('Other Shop', 1, '2026-10-26 10:00', 'OTH');
    app(OnboardTenantBilling::class)->handle($b, new UpfrontPayment(null, PaymentMethod::Cash));

    $branch = $this->allowTills($this->branchOf($a, 'TRL'));
    app(AddRegister::class)->handle($branch, 'Till 2');
    $licence = newestLicence($a);

    expect(app(ChargeAddedTills::class)->handle($b, [$licence]))->toBeNull()
        ->and(tillSetupInvoices($a))->toBe([])
        ->and(tillSetupInvoices($b))->toBe([])
        ->and(Register::withoutCompanyScope()->where('company_id', $b->id)->count())->toBe(1);
});

test('Pakistan: an added till is invoiced and unlocked when paid by JazzCash', function () {
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    $company = paidSetupBusiness($this, 1, '25000.00', SetupFeeMode::PerTill, PaymentMethod::JazzCash);

    addTillAsAdmin($this, $company);
    $invoice = tillSetupInvoices($company)[0];
    $added = newestLicence($company);
    expect($invoice->total)->toBe('25000.00')->and(SetupFeeTills::isHeld($added))->toBeTrue();

    $this->pay($company, '25000.00', [$invoice->id => '25000.00'], PaymentMethod::JazzCash);

    expect(SetupFeeTills::isHeld($added))->toBeFalse()
        ->and($this->licenceFresh($added)->expires_at)->not->toBeNull();
})->group('country-pk');

test('the tenant page tells the add-till dialog what an added till costs', function () {
    $company = paidSetupBusiness($this, 2);

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get(route('admin.tenants.show', $company))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tillSetupFee.applies', true)
            ->where('tillSetupFee.perTillFee', '300.00')
            ->where('tillSetupFee.tills', 2)
            ->where('tillSetupFee.covered', 2)
            ->where('tillSetupFee.canEdit', true));

    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get(route('admin.tenants.show', $company))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tillSetupFee.canEdit', false)->where('emails', null));
});

test('payment settings keep the per-business override and take a per-till fee', function () {
    $company = $this->trialTenant('Patel News', 2, '2026-10-26 10:00');
    setupPlan($this, '300.00', SetupFeeMode::PerTill);

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')
        ->put(route('admin.billing.tenants.direct-debit.settings', $company), [
            'billing_mode' => 'upfrontCash', 'setup_fee_override' => '450', 'setup_fee_instalments' => 1, 'till_setup_fee_override' => '200',
        ])->assertSessionHasNoErrors();

    $account = $this->billingAccountOf($company);
    expect($account->setup_fee_override)->toBe('450.00')
        ->and($account->till_setup_fee_override)->toBe('200.00')
        ->and(SetupFeeState::for($company, $account)->total)->toBe('540.00');

    // An older client without the field keeps the per-till fee.
    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')
        ->put(route('admin.billing.tenants.direct-debit.settings', $company), ['billing_mode' => 'upfrontCash', 'setup_fee_override' => '', 'setup_fee_instalments' => 1])
        ->assertSessionHasNoErrors();
    expect($this->billingAccountOf($company)->till_setup_fee_override)->toBe('200.00');

    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Cash));
    addTillAsAdmin($this, $company);
    expect(tillSetupInvoices($company)[0]->subtotal)->toBe('200.00');
});

test('plans save how the setup fee is charged, and older forms keep it', function () {
    $plan = setupPlan($this, '300.00', SetupFeeMode::PerBusiness);
    $admin = $this->admin(AdminRole::Accounts);
    $payload = [
        'name' => 'Standard', 'code' => 'standard', 'pricing_mode' => 'perTill', 'billing_type' => 'setupOnly',
        'price_monthly' => '0', 'price_yearly' => '0', 'setup_fee' => '300.00', 'trial_days' => 7, 'trial_grace_days' => 3,
        'grace_days' => 7, 'features' => [], 'is_active' => true, 'is_public' => true, 'sort_order' => 0,
    ];

    $this->actingAs($admin, 'admin')->put(route('admin.plans.update', $plan), $payload + ['setup_fee_mode' => 'perTill'])->assertSessionHasNoErrors();
    expect($plan->refresh()->setup_fee_mode)->toBe(SetupFeeMode::PerTill)
        ->and(AuditLog::query()->where('action', 'plan.updated')->latest('id')->first()->after)->toMatchArray(['setup_fee_mode' => 'perTill']);

    $this->actingAs($admin, 'admin')->put(route('admin.plans.update', $plan), $payload)->assertSessionHasNoErrors();
    expect($plan->refresh()->setup_fee_mode)->toBe(SetupFeeMode::PerTill);

    $this->actingAs($admin, 'admin')->put(route('admin.plans.update', $plan), $payload + ['setup_fee_mode' => 'perShop'])->assertSessionHasErrors('setup_fee_mode');
    expect(Plan::factory()->create()->setup_fee_mode)->toBe(SetupFeeMode::PerBusiness);
});
