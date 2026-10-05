<?php

namespace App\Domain\Demo\Billing;

use App\Domain\Billing\Actions\MarkOverdueInvoices;
use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Actions\EnforceDirectDebit;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\AddCompanyUser;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Makes one demo business for a billing case (DemoBillingScenario) the way a real one gets there: the real tenancy
 * and billing actions run with the clock set back (onboarding, setup fee payments, the mandate, Direct Debit
 * collections and failures), so invoices, payments, GoCardless rows and licence dates are consistent. One shop, one
 * till; its licence is issued, never activated. Marked `is_demo`: never sent to GoCardless, never emailed.
 */
final class BuildDemoBillingBusiness
{
    public function __construct(
        private readonly DemoBillingPlans $plans,
        private readonly AddBranch $addBranch,
        private readonly AddCompanyUser $addUser,
        private readonly OnboardTenantBilling $onboard,
        private readonly BillingAccounts $accounts,
        private readonly DemoGoCardless $goCardless,
        private readonly MarkOverdueInvoices $markOverdue,
        private readonly EnforceDirectDebit $enforceDirectDebit,
    ) {}

    public function handle(DemoBillingScenario $scenario, ?CarbonImmutable $now = null): Company
    {
        $now ??= CarbonImmutable::now();
        $plan = $this->plans->ensure($scenario->plan());

        return match ($scenario) {
            DemoBillingScenario::SetupMonthly => $this->setupMonthly($scenario, $plan, $now),
            DemoBillingScenario::SetupOnlyPaid => $this->at($now->subDays(20), fn () => $this->business($scenario, $plan, PaymentMethod::Card)),
            DemoBillingScenario::SetupOnlyUnpaid => $this->at($now->subDays(3), fn () => $this->business($scenario, $plan, null)),
            DemoBillingScenario::WaitingForDirectDebit => $this->waitingForDirectDebit($scenario, $plan, $now),
            DemoBillingScenario::PaymentFailed => $this->paymentFailed($scenario, $plan, $now),
            DemoBillingScenario::Instalments => $this->instalments($scenario, $plan, $now),
        };
    }

    /** Setup fee by bank transfer 35 days ago, mandate the next day, first month collected 26 days ago. */
    private function setupMonthly(DemoBillingScenario $scenario, Plan $plan, CarbonImmutable $now): Company
    {
        $company = $this->at($now->subDays(35), fn () => $this->business($scenario, $plan, PaymentMethod::BankTransfer));
        $this->at($now->subDays(34), function () use ($company, $now) {
            $this->goCardless->mandate($company);
            $this->goCardless->subscription($company, $now->subDays(26)->startOfDay());
        });
        $this->collect($company, $now->subDays(26)->startOfDay(), PaymentStatus::Confirmed);

        return $company->refresh();
    }

    /** Setup fee in cash 50 hours ago, no mandate: the 3-day deadline is tomorrow and the reminder has gone. */
    private function waitingForDirectDebit(DemoBillingScenario $scenario, Plan $plan, CarbonImmutable $now): Company
    {
        $company = $this->at($now->subHours(50), fn () => $this->business($scenario, $plan, PaymentMethod::Cash));
        $this->enforceDirectDebit->handle($now, $company->id);

        return $company->refresh();
    }

    /** Monthly only: month 1 collected, month 2 charged 5 days ago and failed 4 days ago; billing run marks it overdue. */
    private function paymentFailed(DemoBillingScenario $scenario, Plan $plan, CarbonImmutable $now): Company
    {
        $first = $now->subDays(5)->startOfDay()->subMonthNoOverflow();
        $company = $this->at($first->subDays(5), fn () => $this->business($scenario, $plan, null));
        $this->at($first->subDays(4), function () use ($company, $first) {
            $this->goCardless->mandate($company);
            $this->goCardless->subscription($company, $first);
        });
        $this->collect($company, $first, PaymentStatus::Confirmed);
        $this->collect($company, $now->subDays(5)->startOfDay(), PaymentStatus::Failed, failAt: $now->subDays(4));
        $this->markOverdue->handle($now, $company->id);

        return $company->refresh();
    }

    /** Setup fee in 2 instalments: the first paid by bank transfer 20 days ago, the second due next month. */
    private function instalments(DemoBillingScenario $scenario, Plan $plan, CarbonImmutable $now): Company
    {
        $company = $this->at($now->subDays(20), fn () => $this->business($scenario, $plan, PaymentMethod::BankTransfer, instalments: 2));
        $this->at($now->subDays(19), function () use ($company, $now) {
            $this->goCardless->mandate($company);
            $this->goCardless->subscription($company, $now->addDays(5)->startOfDay());
        });

        return $company->refresh();
    }

    /**
     * GoCardless collects one subscription payment: created on the charge date, then confirmed (3 days on) or failed.
     */
    private function collect(Company $company, CarbonImmutable $chargeDate, PaymentStatus $outcome, ?CarbonImmutable $failAt = null): void
    {
        $row = $this->at($chargeDate->setTime(7, 0), function () use ($company, $chargeDate) {
            $row = $this->goCardless->payment($company, $chargeDate);
            $this->goCardless->nextCharge($company, $chargeDate->addMonthNoOverflow());

            return $row;
        });

        $this->at($failAt ?? $chargeDate->addDays(3)->setTime(9, 0), fn () => $this->goCardless->status($company, $row, $outcome, $outcome === PaymentStatus::Failed ? 'Insufficient funds (demo)' : null));
    }

    /** The business, its shop and till (licence issued), its owner, and its onboarding with the setup fee if paid. */
    private function business(DemoBillingScenario $scenario, Plan $plan, ?PaymentMethod $paid, int $instalments = 1): Company
    {
        $slug = $scenario->value;
        $company = DB::transaction(function () use ($scenario, $plan, $slug) {
            $company = new Company([
                'name' => $scenario->businessName(),
                'legal_name' => $scenario->businessName().' Ltd',
                'email' => "accounts@{$slug}.".DemoBusinesses::EMAIL_DOMAIN,
                'town' => 'Leeds',
                'postcode' => 'LS1 6BY',
                'address' => '1 Demo Street, Leeds LS1 6BY',
                'notes' => 'Demo business made by demo:billing ('.$scenario->story().') Never sent to GoCardless, never emailed. Remove with demo:billing --fresh.',
            ]);
            $company->forceFill([
                'status' => CompanyStatus::Trial,
                'plan_id' => $plan->id,
                'is_demo' => true,
                // Only the unpaid setup-only case shows a trial (its till was never activated, so the date is given).
                'trial_ends_at' => $scenario === DemoBillingScenario::SetupOnlyUnpaid ? CarbonImmutable::now()->addDays(max(1, $plan->trial_days)) : null,
            ])->save();

            $this->addBranch->handle($company, new BranchDetails('DEMO', 'Demo shop', address: '1 Demo Street, Leeds LS1 6BY', town: 'Leeds', postcode: 'LS1 6BY'), 1);
            $this->addUser->handle($company, 'Demo Owner', "owner@{$slug}.".DemoBusinesses::EMAIL_DOMAIN, CompanyRole::Owner);

            return $company;
        });

        if ($instalments > 1) {
            DB::transaction(function () use ($company, $instalments) {
                $account = $this->accounts->lock($company);
                $account->setup_fee_instalments = $instalments;
                $account->save();
            });
        }

        $this->onboard->handle($company, $paid === null ? null : new UpfrontPayment(null, $paid, 'Demo '.mb_strtolower($paid->label()), CarbonImmutable::now()));

        return $company->refresh();
    }

    /**
     * Runs `$work` with the clock at `$at` (the real actions stamp their dates from it), then puts the clock back.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function at(CarbonImmutable $at, Closure $work): mixed
    {
        $before = [Carbon::getTestNow(), CarbonImmutable::getTestNow()];
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);

        try {
            return $work();
        } finally {
            Carbon::setTestNow($before[0]);
            CarbonImmutable::setTestNow($before[1]);
        }
    }
}
