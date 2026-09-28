<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\GoCardless\Data\GcSubscription;
use App\Domain\Billing\GoCardless\Support\Pence;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Copies GoCardless' view of the company's current subscription (status, amount, cycle, next charge date) onto
 * the billing account, from a webhook or the daily reconcile. Events about an older, replaced subscription are
 * ignored. A status change is audited. Returns whether anything changed.
 */
class ApplySubscription
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Company $company, GcSubscription $subscription): bool
    {
        return DB::transaction(function () use ($company, $subscription) {
            $account = $this->accounts->lock($company);

            if ($account->gc_subscription_id !== $subscription->id) {
                return false;
            }

            $before = $account->gc_subscription_status?->value;
            self::fill($account, $subscription);

            if (! $account->isDirty()) {
                return false;
            }

            $account->save();

            if ($before !== $subscription->status->value) {
                $this->audit->handle('billing.dd_subscription_status', $account, ['status' => $before], ['status' => $subscription->status->value], ['subscription' => $subscription->id], companyId: $company->id);
            }

            return true;
        });
    }

    public static function fill(BillingAccount $account, GcSubscription $subscription): void
    {
        $account->gc_subscription_id = $subscription->id;
        $account->gc_subscription_status = $subscription->status;
        $account->gc_subscription_amount = Pence::toPounds($subscription->amountPence);
        $account->gc_subscription_cycle = BillingCycle::from($subscription->intervalUnit);
        $account->gc_next_charge_date = $subscription->status->isLive() && $subscription->upcomingChargeDate !== null
            ? BillingDates::date($subscription->upcomingChargeDate)
            : null;
    }
}
