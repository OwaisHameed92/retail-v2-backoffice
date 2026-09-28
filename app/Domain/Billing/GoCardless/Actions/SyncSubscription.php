<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Data\GcSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Support\Pence;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the company's GoCardless subscription equal to what it owes each cycle (SubscriptionAmount: live tills ×
 * plan price, VAT included). Creates it once the mandate works (first charge on the next period's start, or the
 * mandate's first possible day), changes the amount from the next payment GoCardless has not created yet, and
 * replaces it (cancel + create on the same next charge date) when the cycle changes or GoCardless refuses the
 * amount change. Nothing to collect cancels it. Every change is audited. Returns what happened.
 *
 * @throws GoCardlessException
 */
class SyncSubscription
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return 'noMandate'|'nothingToCollect'|'unchanged'|'created'|'updated'|'replaced'|'cancelled'
     */
    public function handle(Company $company, string $reason = 'manual'): string
    {
        $account = $this->accounts->for($company);

        if (! $account->isDirectDebit() || ! $account->hasUsableMandate() || $company->isCancelled()) {
            return 'noMandate';
        }

        $amount = SubscriptionAmount::for($company, $account);
        $pence = Pence::fromPounds($amount['gross']);
        $live = $account->hasLiveSubscription() ? $account->gc_subscription_id : null;

        if ($pence <= 0) {
            if ($live === null) {
                return 'nothingToCollect';
            }

            $this->store($company, $this->client->cancelSubscription($live), 'billing.dd_subscription_cancelled', $account, $reason);

            return 'cancelled';
        }

        if ($live === null) {
            $this->store($company, $this->create($company, $account, $pence, $amount['start'], null), 'billing.dd_subscription_created', $account, $reason);

            return 'created';
        }

        if ($account->gc_subscription_cycle !== $amount['cycle']) {
            $this->replace($company, $account, $live, $pence, $amount['start'], $reason);

            return 'replaced';
        }

        if ($account->gc_subscription_amount !== null && Pence::fromPounds($account->gc_subscription_amount) === $pence) {
            return 'unchanged';
        }

        try {
            $this->store($company, $this->client->updateSubscriptionAmount($live, $pence), 'billing.dd_subscription_amount_changed', $account, $reason);

            return 'updated';
        } catch (GoCardlessException) {
            // GoCardless limits amount changes: continue on the same billing day with a new subscription.
            $this->replace($company, $account, $live, $pence, null, $reason);

            return 'replaced';
        }
    }

    private function replace(Company $company, BillingAccount $account, string $live, int $pence, ?CarbonImmutable $periodStart, string $reason): void
    {
        $old = $this->client->subscription($live);
        $start = $periodStart ?? ($old->upcomingChargeDate !== null ? CarbonImmutable::parse($old->upcomingChargeDate) : null);
        $this->client->cancelSubscription($live);

        $this->store($company, $this->create($company, $account, $pence, $start, $live), 'billing.dd_subscription_replaced', $account, $reason, ['replaced' => $live]);
    }

    private function create(Company $company, BillingAccount $account, int $pence, ?CarbonImmutable $start, ?string $previous): GcSubscription
    {
        $mandateId = (string) $account->gc_mandate_id;
        $earliest = $this->client->mandate($mandateId)->nextPossibleChargeDate;
        $startDate = $start?->format('Y-m-d');

        if ($startDate !== null && $earliest !== null && $startDate < $earliest) {
            $startDate = $earliest;
        }

        return $this->client->createSubscription(
            mandateId: $mandateId,
            amountPence: $pence,
            intervalUnit: $account->cycle->value,
            startDate: $startDate,
            name: 'Switch & Save EPOS, '.$company->name,
            metadata: ['company_id' => $company->id],
            idempotencyKey: 'subscription:'.$company->id.':'.$mandateId.':'.($previous ?? 'first').':'.$account->cycle->value,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function store(Company $company, GcSubscription $subscription, string $action, BillingAccount $before, string $reason, array $meta = []): void
    {
        DB::transaction(function () use ($company, $subscription, $action, $before, $reason, $meta) {
            $account = $this->accounts->lock($company);
            ApplySubscription::fill($account, $subscription);
            $account->save();

            $this->audit->handle($action, $account, [
                'subscription' => $before->gc_subscription_id,
                'amount' => $before->gc_subscription_amount,
                'cycle' => $before->gc_subscription_cycle?->value,
            ], [
                'subscription' => $subscription->id,
                'amount' => $account->gc_subscription_amount,
                'cycle' => $account->gc_subscription_cycle?->value,
                'status' => $subscription->status->value,
            ], ['reason' => $reason, 'amount_label' => BillingFormat::money((string) $account->gc_subscription_amount), ...$meta], companyId: $company->id);
        });
    }
}
