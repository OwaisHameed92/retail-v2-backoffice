<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\UpdateCompanyPricing;
use App\Domain\Billing\Data\PricingOverride;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\DeactivateBranch;
use App\Domain\Tenancy\Actions\ReactivateBranch;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

/** A second shop (Bradford) with its tills, activated and paid like the first. */
function addPaidBranch(object $test, Company $company, int $tills = 1, string $code = 'BRD'): void
{
    app(AddBranch::class)->handle($company, new BranchDetails(code: $code, name: 'Bradford', nation: Nation::England), $tills);

    foreach ($test->licencesOf($company) as $licence) {
        if ($licence->expires_at === null) {
            $test->activate($licence, now()->subDays(40)->toImmutable());
            $licence->forceFill(['expires_at' => $test->licencesOf($company)[0]->expires_at])->save();
        }
    }
}

function perBranchPlan(object $test): void
{
    $test->standardPlan()->forceFill(['pricing_mode' => PricingMode::PerBranch, 'price_monthly' => '40.00', 'price_yearly' => '400.00'])->save();
}

test('per till: one line per live till at the plan price', function () {
    $company = $this->payingTenant(tills: 2);
    addPaidBranch($this, $company, 1);

    $invoice = $this->draftFor($company);
    $lines = $invoice->lines;

    expect($lines)->toHaveCount(3)
        ->and($lines->pluck('unit_price')->unique()->all())->toBe(['25.00'])
        ->and($lines->whereNotNull('licence_id'))->toHaveCount(3)
        ->and($lines->whereNotNull('branch_id'))->toHaveCount(0)
        ->and($invoice->total)->toBe('90.00');
});

test('per branch: one line per active branch whatever its tills, and paying it renews every till of the branch', function () {
    $company = $this->payingTenant(tills: 2);
    perBranchPlan($this);
    addPaidBranch($this, $company, 1);

    $invoice = $this->issuedFor($company);
    $lines = $invoice->lines->sortBy('position')->values();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('description')->all())->toBe([
            'Standard plan · Bradford (1 till) · 1 Nov – 30 Nov 2026',
            'Standard plan · Leeds (2 tills) · 1 Nov – 30 Nov 2026',
        ])
        ->and($lines->pluck('unit_price')->all())->toBe(['40.00', '40.00'])
        ->and($lines->whereNull('licence_id'))->toHaveCount(2)
        ->and($lines->whereNotNull('branch_id'))->toHaveCount(2)
        ->and($invoice->total)->toBe('96.00');

    $this->pay($company, '96.00');

    expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid);
    foreach ($this->licencesOf($company) as $licence) {
        expect($this->licenceFresh($licence)->expires_at->utc()->toDateTimeString())->toBe($this->londonEnd('2026-11-30'));
    }
});

test('the company override wins over the plan: mode and price, audited, and the Direct Debit follows', function () {
    $company = $this->directDebitTenant(tills: 2);
    addPaidBranch($this, $company, 1);
    $this->setUpMandate($company);
    $subscription = $this->gc->lastSubscription();
    expect($subscription->amountPence)->toBe(9000); // 3 tills × £25 + VAT

    app(UpdateCompanyPricing::class)->handle($company, new PricingOverride(PricingMode::PerBranch, '35.00', null));

    // 2 branches × £35 + VAT = £84.
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(8400)
        ->and($this->billingAccountOf($company)->gc_subscription_amount)->toBe('84.00')
        ->and(AuditLog::query()->where('action', 'billing.pricing_updated')->sole()->after)->toMatchArray(['pricing_mode_override' => 'perBranch', 'price_monthly_override' => '35.00'])
        ->and(AuditLog::query()->where('action', 'billing.dd_subscription_amount_changed')->pluck('meta')->pluck('reason')->all())->toContain('pricing');

    $lines = $this->draftFor($company)->lines;
    expect($lines)->toHaveCount(2)->and($lines->pluck('unit_price')->unique()->all())->toBe(['35.00']);

    // A per-till price override on its own keeps the plan's mode (per till).
    app(UpdateCompanyPricing::class)->handle($company, new PricingOverride(null, '20.00', null));
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(7200); // 3 × £20 + VAT
});

test('per branch: adding, closing and reopening a branch changes the Direct Debit amount', function () {
    $company = $this->directDebitTenant(tills: 2);
    perBranchPlan($this);
    $this->setUpMandate($company);
    $subscription = $this->gc->lastSubscription();
    expect($subscription->amountPence)->toBe(4800); // 1 branch × £40 + VAT

    addPaidBranch($this, $company, 2);
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(9600);

    // Closing a branch fires no till event: the branch event updates the amount.
    app(DeactivateBranch::class)->handle($this->branchOf($company, 'BRD'));
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(4800)
        ->and(AuditLog::query()->where('action', 'billing.dd_subscription_amount_changed')->get()->pluck('meta.reason')->all())->toContain('branches');

    app(ReactivateBranch::class)->handle($this->branchOf($company, 'BRD'));
    expect($this->gc->subscriptions[$subscription->id]->amountPence)->toBe(9600);
});

test('a free plan recurs at £0: no subscription is created', function () {
    $company = $this->directDebitTenant(tills: 2);
    $this->standardPlan()->forceFill(['price_monthly' => '0.00', 'price_yearly' => '0.00'])->save();

    $this->setUpMandate($company);

    expect($this->gc->subscriptions)->toHaveCount(0)
        ->and($this->billingAccountOf($company)->hasLiveSubscription())->toBeFalse();
});

test('plans save their pricing mode, and billing admins set a company override over HTTP', function () {
    $company = $this->payingTenant();
    $admin = $this->admin(AdminRole::Accounts);

    $this->actingAs($admin, 'admin')->put(route('admin.billing.tenants.pricing', $company), ['pricing_mode' => 'perBranch', 'price_monthly' => '£45.50', 'price_yearly' => ''])
        ->assertRedirect()->assertSessionHasNoErrors();

    $account = $this->billingAccountOf($company);
    expect($account->pricing_mode_override)->toBe(PricingMode::PerBranch)
        ->and($account->price_monthly_override)->toBe('45.50')
        ->and($account->price_yearly_override)->toBeNull();

    $this->actingAs($admin, 'admin')->put(route('admin.billing.tenants.pricing', $company), ['pricing_mode' => 'perShop'])
        ->assertSessionHasErrors('pricing_mode');

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->put(route('admin.billing.tenants.pricing', $company), ['pricing_mode' => 'perTill'])
        ->assertForbidden();

    expect(InvoiceLine::withoutCompanyScope()->count())->toBe(0);
});
