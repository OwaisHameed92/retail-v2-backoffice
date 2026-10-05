<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Actions\ApplySetupFeeTerms;
use App\Domain\Billing\Actions\ReleaseBillingHolds;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Data\GcMandate;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Support\DirectDebitMailer;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a mandate's status to the company (webhook, return page or reconcile; idempotent).
 *
 * - Usable (pending submission, submitted, active) and new to us: store it, create the subscription (or move it
 *   onto this mandate) and lift a billing hold (the "no Direct Debit" suspension, an overdue flag). The setup fee
 *   is never collected here: it is always paid by hand (owner rule 2026-10-05).
 * - Lost (cancelled, failed, expired…): treated like "no mandate" (owner rule 2026-10-05): a new deadline of
 *   `billing.direct_debit.mandate_grace_days` starts, the subscription is paused, owners and staff are emailed
 *   once, and billing:run suspends the business when the deadline passes without a new mandate.
 */
class ApplyMandate
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly SyncSubscription $syncSubscription,
        private readonly ApplySubscription $applySubscription,
        private readonly GoCardlessClient $client,
        private readonly ReleaseBillingHolds $releaseHolds,
        private readonly ApplySetupFeeTerms $setupFeeTerms,
        private readonly DirectDebitMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return 'finalised'|'updated'|'lost'|'ignored'
     */
    public function handle(Company $company, GcMandate $mandate): string
    {
        $now = CarbonImmutable::now();

        $outcome = DB::transaction(function () use ($company, $mandate, $now) {
            $account = $this->accounts->lock($company);
            $current = $account->gc_mandate_id === $mandate->id;

            if (! $current && $mandate->status->isLost()) {
                return 'ignored'; // an old mandate we already replaced
            }

            if ($mandate->status->isUsable()) {
                $new = ! $current || ! ($account->gc_mandate_status?->isUsable() ?? false);
                $before = ['mandate' => $account->gc_mandate_id, 'status' => $account->gc_mandate_status?->value];

                $account->forceFill([
                    'gc_mandate_id' => $mandate->id,
                    'gc_customer_id' => $mandate->customerId ?? $account->gc_customer_id,
                    'gc_mandate_status' => $mandate->status,
                    'gc_mandate_active_at' => $new ? $now : $account->gc_mandate_active_at,
                    'gc_mandate_lost_at' => null,
                    'mandate_overdue_at' => null,
                ])->save();

                if ($new) {
                    $this->audit->handle('billing.dd_mandate_active', $account, $before, ['mandate' => $mandate->id, 'status' => $mandate->status->value], companyId: $company->id);
                }

                return $new ? 'finalised' : 'updated';
            }

            if (! $current) {
                return 'ignored';
            }

            $lostNow = $mandate->status->isLost() && $account->gc_mandate_lost_at === null;
            $before = $account->gc_mandate_status?->value;
            $account->gc_mandate_status = $mandate->status;

            if ($lostNow) {
                $account->gc_mandate_lost_at = $now;
                // Same as never having one: a new deadline, then suspension (EnforceDirectDebit).
                $account->mandate_deadline_at = $now->addDays(self::graceDays());
            }

            $account->save();

            if ($lostNow) {
                $this->audit->handle('billing.dd_mandate_lost', $account, ['status' => $before], ['status' => $mandate->status->value], ['mandate' => $mandate->id], companyId: $company->id);
            }

            return $lostNow ? 'lost' : 'updated';
        });

        if ($outcome === 'finalised') {
            $this->finalise($company);
        }

        if ($outcome === 'lost') {
            $this->pauseSubscription($company);
            $account = $this->accounts->for($company);
            $this->mailer->mandateLost($company, $account, $account->mandate_deadline_at ?? $now->addDays(self::graceDays()));
        }

        return $outcome;
    }

    public static function graceDays(): int
    {
        return max(0, (int) config('billing.direct_debit.mandate_grace_days', 3));
    }

    /** GoCardless cancels the subscriptions of a cancelled mandate itself; anything still live is paused. */
    private function pauseSubscription(Company $company): void
    {
        $account = $this->accounts->for($company);

        if ($account->gc_subscription_id === null || $account->gc_subscription_status !== SubscriptionStatus::Active || DemoBusinesses::isDemo($company)) {
            return;
        }

        try {
            $this->applySubscription->handle($company, $this->client->pauseSubscription($account->gc_subscription_id));
        } catch (GoCardlessException $exception) {
            Log::info('Direct Debit subscription not paused after the mandate stopped', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
        }
    }

    private function finalise(Company $company): void
    {
        $account = $this->accounts->for($company);

        if (! $account->isDirectDebit()) {
            return;
        }

        try {
            $this->syncSubscription->handle($company, 'mandate');
        } catch (GoCardlessException $exception) {
            // The daily reconcile creates it; staff can also press "Update subscription".
            Log::warning('Direct Debit subscription could not be created', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
        }

        // A mandate set up after (or just before) the trial end: the tills keep trading until the first collection.
        if ($this->accounts->for($company)->hasLiveSubscription()) {
            $this->setupFeeTerms->bridgeTrial($company);
        }

        $this->releaseHolds->handle($company);
    }
}
