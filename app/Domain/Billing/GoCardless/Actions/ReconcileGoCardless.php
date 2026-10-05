<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Enums\EventStatus;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Models\GoCardlessEvent;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\Pence;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily `billing:reconcile-gocardless` (heals missed or failed webhooks): replays failed events, then for every
 * company with a mandate compares GoCardless with our records and fixes the drift through the same actions the
 * webhooks use: mandate status, subscription status/amount/next charge date, the subscription amount against the
 * live tills (SyncSubscription), and every payment of the last `billing.direct_debit.reconcile_days` (unknown,
 * a different status, or collected but not recorded). Dry run: reports without changing anything.
 */
class ReconcileGoCardless
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly ProcessGoCardlessEvent $processEvent,
        private readonly ApplyMandate $applyMandate,
        private readonly ApplySubscription $applySubscription,
        private readonly SyncSubscription $syncSubscription,
        private readonly ApplyGoCardlessPayment $applyPayment,
    ) {}

    /**
     * @return array{checked: int, fixes: list<string>, errors: list<string>}
     */
    public function handle(CarbonImmutable $now, bool $dryRun = false): array
    {
        $report = ['checked' => 0, 'fixes' => [], 'errors' => []];

        if (! $this->client->enabled()) {
            return $report;
        }

        if (! $dryRun) {
            foreach (GoCardlessEvent::query()->where('status', EventStatus::Failed->value)->where('attempts', '<', 20)->orderBy('created_at')->limit(500)->get() as $event) {
                try {
                    $this->processEvent->handle($event);
                    $report['fixes'][] = "Replayed event {$event->gc_event_id} ({$event->type()})";
                } catch (Throwable $exception) {
                    $report['errors'][] = "Event {$event->gc_event_id}: {$exception->getMessage()}";
                }
            }
        }

        $accounts = BillingAccount::withoutCompanyScope()->whereNotNull('gc_mandate_id')->orderBy('company_id')->get();

        foreach ($accounts as $account) {
            $company = Company::query()->find($account->company_id);

            if ($company === null || DemoBusinesses::isDemo($company)) {
                continue; // demo businesses are never sent to GoCardless
            }

            $report['checked']++;

            try {
                $this->company($company, $account, $now, $dryRun, $report['fixes']);
            } catch (GoCardlessException $exception) {
                $report['errors'][] = "{$company->name}: {$exception->getMessage()}";
                Log::warning('GoCardless reconcile failed for a company', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
            }
        }

        return $report;
    }

    /**
     * @param  list<string>  $fixes
     */
    private function company(Company $company, BillingAccount $account, CarbonImmutable $now, bool $dryRun, array &$fixes): void
    {
        $mandate = $this->client->mandate((string) $account->gc_mandate_id);

        if ($mandate->status !== $account->gc_mandate_status) {
            $fixes[] = "{$company->name}: mandate {$account->gc_mandate_status?->value} → {$mandate->status->value}";
            if (! $dryRun) {
                $this->applyMandate->handle($company, $mandate);
            }
        }

        if ($account->gc_subscription_id !== null) {
            $subscription = $this->client->subscription($account->gc_subscription_id);
            $amount = Pence::toPounds($subscription->amountPence);
            $next = $subscription->upcomingChargeDate;

            if ($subscription->status !== $account->gc_subscription_status || $amount !== $account->gc_subscription_amount || $next !== $account->gc_next_charge_date?->format('Y-m-d')) {
                $fixes[] = "{$company->name}: subscription {$subscription->id} updated from GoCardless";
                if (! $dryRun) {
                    $this->applySubscription->handle($company, $subscription);
                }
            }
        }

        $account = $this->accounts->for($company);

        // Amount drift on a live subscription, or one that was never created (a cancelled one stays cancelled).
        if ($account->isDirectDebit() && $account->hasUsableMandate() && ($account->hasLiveSubscription() || $account->gc_subscription_id === null)) {
            $expected = Pence::fromPounds(SubscriptionAmount::for($company, $account, $now)['gross']);
            $current = $account->hasLiveSubscription() && $account->gc_subscription_amount !== null ? Pence::fromPounds($account->gc_subscription_amount) : 0;

            if ($expected !== $current || ($account->hasLiveSubscription() && $account->gc_subscription_cycle !== $account->cycle)) {
                $fixes[] = "{$company->name}: subscription amount ".Pence::toPounds($current).' → '.Pence::toPounds($expected);
                if (! $dryRun) {
                    $this->syncSubscription->handle($company, 'reconcile');
                }
            }
        }

        $since = $now->subDays(max(1, (int) config('billing.direct_debit.reconcile_days', 45)));

        foreach ($this->client->paymentsForMandate((string) $account->gc_mandate_id, $since) as $payment) {
            $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->first();
            $unrecorded = $payment->status->isCollected() && $row?->payment_id === null;

            if ($row === null || $row->status !== $payment->status || $unrecorded) {
                $fixes[] = "{$company->name}: payment {$payment->id} ".($row?->status->value ?? 'unknown')." → {$payment->status->value}";
                if (! $dryRun) {
                    $this->applyPayment->handle($company, $payment);
                }
            }
        }

        if (! $dryRun) {
            BillingAccount::withoutCompanyScope()->whereKey($account->id)->update(['gc_reconciled_at' => $now]);
        }
    }
}
