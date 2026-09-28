<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff pause, resume or cancel a company's Direct Debit subscription (billing.manage). Pausing stops new
 * collections until resumed; cancelling ends it (Sync subscription creates a new one). Audited.
 */
class ChangeSubscription
{
    public const ACTIONS = ['pause', 'resume', 'cancel'];

    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  'pause'|'resume'|'cancel'  $action
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $action): SubscriptionStatus
    {
        $account = $this->accounts->for($company);
        $id = $account->gc_subscription_id;

        if ($id === null || ! $account->hasLiveSubscription()) {
            throw ValidationException::withMessages(['subscription' => "{$company->name} has no Direct Debit subscription to {$action}."]);
        }

        if ($action === 'resume' && $account->gc_subscription_status !== SubscriptionStatus::Paused) {
            throw ValidationException::withMessages(['subscription' => 'Only a paused subscription can be resumed.']);
        }

        try {
            $subscription = match ($action) {
                'pause' => $this->client->pauseSubscription($id),
                'resume' => $this->client->resumeSubscription($id),
                'cancel' => $this->client->cancelSubscription($id),
            };
        } catch (GoCardlessException $exception) {
            throw ValidationException::withMessages(['subscription' => $exception->getMessage()]);
        }

        DB::transaction(function () use ($company, $subscription, $account, $action) {
            $locked = $this->accounts->lock($company);
            ApplySubscription::fill($locked, $subscription);
            $locked->save();

            $this->audit->handle('billing.dd_subscription_'.($action === 'cancel' ? 'cancelled' : ($action === 'pause' ? 'paused' : 'resumed')), $locked,
                ['status' => $account->gc_subscription_status?->value], ['status' => $subscription->status->value], ['subscription' => $subscription->id, 'reason' => 'staff'], companyId: $company->id);
        });

        return $subscription->status;
    }
}
