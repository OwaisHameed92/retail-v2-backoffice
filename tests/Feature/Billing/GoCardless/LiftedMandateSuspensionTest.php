<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\BillingStatusData;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Tenancy\Actions\UnsuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Owner 2026-11-11 (Asia Halal Food Stores): billing suspended a business for no Direct Debit, staff lifted it by
 * hand. billing:run never suspends again for that deadline, so the admin and portal must not say "the tills are
 * locked" or "locks on <date>"; they still ask for the Direct Debit.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    $this->standardPlan()->forceFill(['price_monthly' => '25.00', 'price_yearly' => '250.00', 'setup_fee' => '199.00'])->save();
});

/** Onboarded through the wizard without an upfront payment (Direct Debit due in 3 days), then suspended for it. */
function lmsSuspendedTenant(object $test): Company
{
    $test->actingAs($test->admin(AdminRole::Sales), 'admin')->post('/admin/tenants', [
        'name' => 'Patel News', 'email' => 'hello@patelnews.test', 'status' => 'trial',
        'branch_code' => 'LDS', 'branch_name' => 'Leeds', 'branch_nation' => 'england',
        'tills' => 2, 'owner_name' => 'Raj Patel', 'owner_email' => 'raj@patelnews.test',
    ])->assertSessionHasNoErrors();

    $company = Company::query()->where('name', 'Patel News')->sole();
    // The reminder run first: a deadline nobody was warned of never suspends (BILL-1).
    expect($test->runBillingOn('2026-10-27')['noMandateSuspended'])->toBe(0)
        ->and($test->runBillingOn('2026-10-28')['noMandateSuspended'])->toBe(1);

    return $company;
}

test('while billing\'s own suspension stands the admin sees it and the status names no lock day after it', function () {
    $company = lmsSuspendedTenant($this);

    $this->actingAs($this->admin(), 'admin')->get("/admin/tenants/{$company->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('billing.summary.suspendedForBilling', true)
            ->where('billing.directDebit.deadline.locked', true)
            ->where('billing.directDebit.deadline.lifted', false));
});

test('staff lifting a no-mandate suspension: no "suspended" banner, no lock day, and billing:run never locks again for that deadline', function () {
    $company = lmsSuspendedTenant($this);

    app(UnsuspendCompany::class)->handle($this->companyFresh($company));
    $fresh = $this->companyFresh($company);
    $account = $this->billingAccountOf($company);

    // The billing stamp stays behind (ReleaseBillingHolds only clears it when billing lifts its own suspension).
    expect($fresh->status)->not->toBe(CompanyStatus::Suspended)
        ->and($account->billing_suspended_at)->not->toBeNull();

    $this->actingAs($this->admin(), 'admin')->get("/admin/tenants/{$company->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('billing.summary.suspendedForBilling', false)
            ->where('billing.directDebit.deadline.passed', true)
            ->where('billing.directDebit.deadline.locked', false)
            ->where('billing.directDebit.deadline.lifted', true)
            ->where('billing.status.headline', 'Waiting for Direct Debit')
            ->where('billing.status.next.date', null));

    expect(MandateDeadline::state($fresh, $account))->toMatchArray(['lifted' => true, 'locked' => false, 'lockOn' => null]);

    $status = BillingStatus::for($fresh);
    expect($status->state)->toBe(BillingStatus::WAITING_FOR_DD)
        ->and($status->locksOn)->toBeNull()
        ->and(BillingStatusData::for($status, portal: true)['next']['text'])->toBe('Please set up your Direct Debit: nothing can be collected until it is set up.');

    // The portal asks for the Direct Debit without threatening a lock.
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('directDebit.deadline.lifted', true)->where('directDebit.deadline.lockOn', null));

    // And the daily run keeps its word: no second suspension for the same deadline.
    expect($this->runBillingOn('2026-10-29')['noMandateSuspended'])->toBe(0)
        ->and($this->runBillingOn('2026-10-30')['noMandateSuspended'])->toBe(0)
        ->and($this->companyFresh($company)->status)->not->toBe(CompanyStatus::Suspended);
});

test('a deadline that has not passed yet still names the day the tills lock', function () {
    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->post('/admin/tenants', [
        'name' => 'Patel News', 'email' => 'hello@patelnews.test', 'status' => 'trial',
        'branch_code' => 'LDS', 'branch_name' => 'Leeds', 'branch_nation' => 'england',
        'tills' => 2, 'owner_name' => 'Raj Patel', 'owner_email' => 'raj@patelnews.test',
    ])->assertSessionHasNoErrors();
    $company = Company::query()->where('name', 'Patel News')->sole();

    $status = BillingStatus::for($company);
    expect($status->state)->toBe(BillingStatus::WAITING_FOR_DD)
        ->and($status->locksOn)->not->toBeNull()
        ->and(BillingStatusData::for($status)['headline'])->toStartWith('Waiting for Direct Debit — locks on ');
});
