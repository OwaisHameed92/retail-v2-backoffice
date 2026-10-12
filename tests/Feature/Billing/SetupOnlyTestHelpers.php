<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\ReversePayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\SetupFeeInvoices;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Setup-only fixtures for the billing audit fixes (docs/audit/2026-10-11-billing.md, BILL-1/2/5/6). Use with
 * TenantTestHelpers, LicensingTestHelpers and BillingTestHelpers.
 */
trait SetupOnlyTestHelpers
{
    /**
     * Istanbul Market as it stands on the live UK server (audit 2026-10-11): plan "Setup only" (per till, £1,200 a
     * till), 2 tills, onboarded on Direct Debit mode with its 3-day mandate deadline long gone (9 Oct), a bank transfer
     * setup fee payment reversed and its invoice voided, a second wrong invoice paid, reversed and voided, then one setup
     * fee invoice of 2 × £750 + VAT = £1,800 paid in cash on 8 Oct. `till_setup_fee_override` 750, 2 tills covered,
     * the shop's own features with cloud_sync. Clock left at 11 Oct 2026 10:00 London.
     *
     * @return array{company: Company, plan: Plan, paid: Invoice, voided: list<Invoice>, reversed: list<Payment>, kept: Payment}
     */
    public function istanbulLike(): array
    {
        $this->atLondon('2026-10-06 11:00');
        $company = $this->trialTenant('Istanbul Market', 2, '2026-10-13 11:00', 'IST');
        $plan = $this->standardPlan();
        $plan->forceFill([
            'name' => 'Setup only', 'setup_fee' => '1200.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
            'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => SetupFeeMode::PerBusiness,
        ])->save();

        app(OnboardTenantBilling::class)->handle($company, new UpfrontPayment(null, PaymentMethod::BankTransfer));
        $first = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)->sole();
        $firstPayment = Payment::withoutCompanyScope()->where('company_id', $company->id)->sole();

        $this->atLondon('2026-10-07 12:00');
        $plan->forceFill(['setup_fee_mode' => SetupFeeMode::PerTill])->save();
        $this->billingAccountOf($company)->forceFill(['setup_fee_covered_tills' => 2])->save();

        $this->atLondon('2026-10-08 10:00');
        app(ReversePayment::class)->handle($firstPayment, 'Wrong amount: £750 + VAT per till agreed');
        $this->voidIt($this->fresh($first), 'Replaced: £750 + VAT per till');

        $account = app(BillingAccounts::class)->for($company);
        $second = app(IssueInvoice::class)->handle(SetupFeeInvoices::businessDraft($company, $account, '1200.00', 'Setup fee · Istanbul Market'));
        $secondPayment = $this->pay($company, $second->total, [$second->id => $second->total], PaymentMethod::BankTransfer)->payment;
        app(ReversePayment::class)->handle($secondPayment, 'Entered against the wrong invoice');
        $this->voidIt($this->fresh($second), 'Wrong amount');

        $account = app(BillingAccounts::class)->for($company);
        $account->till_setup_fee_override = '750.00';
        $account->save();
        $paid = app(IssueInvoice::class)->handle(SetupFeeInvoices::businessDraft($company, $account, '1500.00', 'Setup fee · 2 tills × £750 · Istanbul Market'));
        $kept = $this->pay($company, '1800.00', [$paid->id => '1800.00'], PaymentMethod::Cash)->payment;

        $branch = $this->branchOf($company, 'IST');
        $custom = [Feature::Loyalty->value, Feature::Promotions->value, Feature::CloudSync->value];
        $branch->forceFill(['licence_features' => $custom])->save();
        foreach ($this->licencesOf($company) as $licence) {
            $licence->forceFill(['features' => $custom])->save();
        }

        $this->atLondon('2026-10-11 10:00');

        return [
            'company' => $company->refresh(),
            'plan' => $plan->refresh(),
            'paid' => $this->fresh($paid),
            'voided' => [$this->fresh($first), $this->fresh($second)],
            'reversed' => [$firstPayment->refresh(), $secondPayment->refresh()],
            'kept' => $kept->refresh(),
        ];
    }

    /** A plan the business is not on, with a recurring price, made the first in the list (the portal default). */
    public function setupAndMonthlyPlan(int $sortOrder = 1): Plan
    {
        return Plan::factory()->create([
            'name' => 'Setup + monthly', 'code' => 'setup-monthly', 'setup_fee' => '999.00', 'setup_fee_mode' => SetupFeeMode::PerBusiness,
            'price_monthly' => '12.00', 'price_yearly' => '120.00', 'billing_type' => PlanBillingType::SetupAndRecurring,
            'features' => [Feature::Loyalty], 'sort_order' => $sortOrder,
        ]);
    }

    /** What a till is told now. */
    public function tillSeesNow(Licence $licence): LicenceState
    {
        $licence = Licence::withoutCompanyScope()->with(['company', 'branch', 'register', 'plan'])->findOrFail($licence->id);

        return LicenceState::for($licence, CarbonImmutable::now());
    }

    /** The newest live licence of a business (the till just added). */
    public function newestLicence(Company $company): Licence
    {
        return Licence::withoutCompanyScope()->where('company_id', $company->id)->live()->latest('created_at')->latest('id')->firstOrFail();
    }
}
