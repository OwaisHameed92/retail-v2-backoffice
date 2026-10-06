<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Actions\ActivateCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * What the setup fee (the one-time "upfront" amount, always paid by hand) unlocks, owner rules 2026-10-05. Called when
 * a setup fee payment is recorded (`$justPaid`) and by billing:run every day (new tills, top-ups):
 *
 * - Setup-only plan: paid in full → every live till gets a full licence `billing.setup_only.years` (10) ahead, topped
 *   up whenever less than `renew_below_years` (9) are left; part paid (instalments) → paid up to the next unpaid
 *   instalment's due date; unpaid → nothing (the trial runs out and the till locks as expired). Never shortens a
 *   date. The company becomes active once paid.
 * - Recurring plans on Direct Debit: the subscription starts once the setup fee (or its first instalment) is paid
 *   (SyncSubscription waits for it). When that happens after the trial ended, trial tills get their trial moved to
 *   the first collection (or the Direct Debit deadline) so the customer is not locked out while it is collected
 *   (bridgeTrial, also used by ApplyMandate for a mandate set up late).
 * - Recurring plans on a manual-collection instance (Pakistan plan P5, no Direct Debit): once the setup fee (or its
 *   first instalment) is paid, StartManualBilling issues the first period invoice when it is due and moves an ended
 *   trial to that invoice's due date.
 *
 * Returns how many licences got a new date.
 */
class ApplySetupFeeTerms
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly RenewLicence $renewLicence,
        private readonly SyncSubscription $syncSubscription,
        private readonly ActivateCompany $activateCompany,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Company $company, bool $justPaid = false, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        if ($company->isCancelled() || $company->trashed()) {
            return 0;
        }

        $account = $this->accounts->for($company);
        $state = SetupFeeState::for($company, $account);

        if (self::planType($company) === PlanBillingType::SetupOnly) {
            return $this->setupOnly($company, $state, $now);
        }

        if (ManualCollection::active()) {
            return $justPaid && $state->isStarted() ? app(StartManualBilling::class)->handle($company, $now) : 0;
        }

        if (! $state->isStarted() || ! $account->isDirectDebit()) {
            return 0;
        }

        if ($account->hasUsableMandate() && ! $account->hasLiveSubscription()) {
            try {
                $this->syncSubscription->handle($company, 'setupFee');
            } catch (GoCardlessException $exception) {
                Log::warning('Direct Debit subscription not started after the setup fee', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
            }
        }

        return $justPaid ? $this->bridgeTrial($company, $now) : 0;
    }

    /** The plan type the company is billed on: its live tills' plan, else the plan new tills get. */
    public static function planType(Company $company): ?PlanBillingType
    {
        $account = app(BillingAccounts::class)->for($company);

        return CompanyPricing::for($company, $account, RenewCompanyLicences::renewable($company)->get())->plan?->billingType();
    }

    private function setupOnly(Company $company, SetupFeeState $state, CarbonImmutable $now): int
    {
        $target = match (true) {
            $state->isSettled() => $now->addYears(max(1, (int) config('billing.setup_only.years', 10))),
            $state->status === SetupFeeState::PART_PAID && $state->nextDue !== null => $state->nextDue,
            default => null,
        };

        if ($target === null) {
            return 0;
        }

        $expiresAt = RenewalTerm::endOfLocalDay($target->setTimezone(Country::zone())->format('Y-m-d'));
        // A full term is only topped up once it gets below the threshold, so the date does not move every day.
        $below = $state->isSettled()
            ? $now->addYears(max(0, (int) config('billing.setup_only.renew_below_years', 9)))
            : $expiresAt;

        $renewed = DB::transaction(function () use ($company, $expiresAt, $below, $now) {
            $this->accounts->lock($company);
            $renewed = 0;

            foreach (RenewCompanyLicences::renewable($company)->get() as $licence) {
                if ($licence->isRevoked() || ($licence->expires_at !== null && $licence->expires_at->greaterThanOrEqualTo($below))) {
                    continue;
                }

                $locked = LicenceGuard::lock($licence);

                if ($locked->expires_at !== null && $locked->expires_at->greaterThanOrEqualTo($expiresAt)) {
                    continue; // never shorten a date the customer already has
                }

                try {
                    $this->renewLicence->apply($locked->load(['plan']), RenewalTerm::until($expiresAt), $now, $expiresAt);
                    $renewed++;
                } catch (ValidationException) {
                    // Revoked in between.
                }
            }

            if ($renewed > 0) {
                $this->audit->handle('billing.setup_only_term', $company, null, ['expires_at' => $expiresAt->toIso8601String()], ['licences' => $renewed], companyId: $company->id);
            }

            return $renewed;
        });

        if ($state->isSettled() && $company->refresh()->status === CompanyStatus::Trial) {
            try {
                $this->activateCompany->handle($company);
            } catch (ValidationException) {
                // Status changed in between.
            }
        }

        return $renewed;
    }

    /**
     * The setup fee or the mandate arrived late (after the trial, or too close to its end for the first collection):
     * trial tills (never paid) get their trial moved to the first Direct Debit collection (else to a Direct Debit
     * deadline from today), so they trade now and the usual rules take over. Only once the setup fee is paid (or
     * nothing is due) and something recurs. Never shortens a date. Returns how many licences moved.
     */
    public function bridgeTrial(Company $company, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $account = $this->accounts->for($company);

        if (! SetupFeeState::for($company, $account)->isStarted() || Money::isZero(SubscriptionAmount::for($company, $account, $now)['gross'])) {
            return 0;
        }

        $until = $account->gc_next_charge_date !== null && $account->hasLiveSubscription()
            ? BillingDates::endOfDay($account->gc_next_charge_date)
            : RenewalTerm::endOfLocalDay($now->addDays(max(1, MandateDeadline::days()))->setTimezone(Country::zone())->format('Y-m-d'));

        return DB::transaction(function () use ($company, $until, $now) {
            $this->accounts->lock($company);
            $moved = 0;

            foreach (RenewCompanyLicences::renewable($company)->get() as $licence) {
                /** @var Licence $licence */
                if ($licence->expires_at !== null || $licence->activated_at === null || ($licence->trial_ends_at !== null && $licence->trial_ends_at->greaterThanOrEqualTo($until))) {
                    continue;
                }

                $locked = LicenceGuard::lock($licence);
                $before = ['trial_ends_at' => $locked->trial_ends_at?->toIso8601String(), 'status' => $locked->status->value];
                $locked->trial_ends_at = $until;
                $locked->status = LicenceTerms::storedStatusAfterDateChange($locked, $now);
                $locked->save();
                $moved++;

                $this->audit->handle('licence.trial_extended', $locked, $before, ['trial_ends_at' => $until->toIso8601String(), 'status' => $locked->status->value], ['reason' => 'Setup fee paid after the trial'], companyId: $company->id);
            }

            return $moved;
        });
    }
}
