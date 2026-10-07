<?php

namespace Tests\Feature\Billing;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the "Change plan" tests (with TenantTestHelpers, LicensingTestHelpers, BillingTestHelpers).
 */
trait ChangePlanHelpers
{
    /**
     * A plan to move to.
     *
     * @param  list<Feature>  $features
     */
    public function cpPlan(string $name, PlanBillingType $type, string $setupFee, string $monthly, array $features = [Feature::Loyalty, Feature::Promotions], SetupFeeMode $mode = SetupFeeMode::PerBusiness): Plan
    {
        return Plan::factory()->create([
            'name' => $name,
            'code' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'billing_type' => $type,
            'setup_fee' => $setupFee,
            'setup_fee_mode' => $mode,
            'price_monthly' => $monthly,
            'price_yearly' => bcmul($monthly, '10', 2),
            'features' => $features,
            'grace_days' => 7,
            'trial_grace_days' => 3,
        ]);
    }

    /** The standard plan as given (the plan the test business is on). */
    public function cpStandard(PlanBillingType $type, string $setupFee, string $monthly): Plan
    {
        $plan = $this->standardPlan();
        $plan->forceFill([
            'billing_type' => $type, 'setup_fee' => $setupFee, 'price_monthly' => $monthly, 'price_yearly' => bcmul($monthly, '10', 2),
            'setup_fee_mode' => SetupFeeMode::PerBusiness, 'features' => [Feature::Loyalty],
        ])->save();

        return $plan->refresh();
    }

    /**
     * A business onboarded today on the standard plan as given, its setup fee paid by card (tills on trial ending
     * 26 Oct; a setup-only plan gives them the 10-year licence).
     */
    public function cpOnboarded(PlanBillingType $type, string $setupFee = '1200.00', string $monthly = '0.00', int $tills = 2, PaymentMethod $method = PaymentMethod::Card): Company
    {
        $company = $this->trialTenant('Patel News', $tills, '2026-10-26 10:00');
        $this->cpStandard($type, $setupFee, $monthly);
        app(OnboardTenantBilling::class)->handle($company, new UpfrontPayment(null, $method));

        return $company->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function changePlan(Company $company, Plan $to, array $data = [], AdminRole $role = AdminRole::Accounts): TestResponse
    {
        return $this->actingAs($this->admin($role), 'admin')
            ->post(route('admin.tenants.plan-change.store', $company), ['plan_id' => $to->id, ...$data]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function previewPlan(Company $company, Plan $to, array $data = [], AdminRole $role = AdminRole::Accounts): TestResponse
    {
        return $this->actingAs($this->admin($role), 'admin')
            ->getJson(route('admin.tenants.plan-change.preview', ['company' => $company->id, 'plan_id' => $to->id, ...$data]));
    }

    /**
     * @return list<Invoice>
     */
    public function invoicesOfKind(Company $company, InvoiceKind $kind): array
    {
        return Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', $kind->value)->orderBy('sequence')->get()->all();
    }
}
