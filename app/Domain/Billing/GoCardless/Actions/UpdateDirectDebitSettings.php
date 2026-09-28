<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Data\DirectDebitSettingsInput;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves how a company pays: upfront cash or Direct Debit, its setup fee override, how the setup fee is paid and
 * in how many instalments. Moving to upfront cancels a live GoCardless subscription; moving to Direct Debit with a
 * working mandate creates it. The setup fee cannot change once invoiced. Audited.
 */
class UpdateDirectDebitSettings
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly SyncSubscription $syncSubscription,
        private readonly ApplySubscription $applySubscription,
        private readonly RecordAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(Company $company, DirectDebitSettingsInput $input): BillingAccount
    {
        $account = DB::transaction(function () use ($company, $input) {
            $account = $this->accounts->lock($company);
            $override = $input->setupFeeOverride === null ? null : Money::normalise($input->setupFeeOverride);
            $feeChanged = $override !== $account->setup_fee_override || $input->instalments !== $account->setup_fee_instalments || $input->setupFeeMethod !== $account->setup_fee_method;

            if ($account->setup_fee_invoiced_at !== null && $feeChanged) {
                throw ValidationException::withMessages(['setup_fee_override' => 'The setup fee is already invoiced, so it cannot change. Void or credit those invoices instead.']);
            }

            $account->fill([
                'billing_mode' => $input->mode,
                'setup_fee_override' => $override,
                'setup_fee_method' => $input->setupFeeMethod,
                'setup_fee_instalments' => max(1, min((int) config('billing.direct_debit.max_instalments', 12), $input->instalments)),
            ]);

            [$before, $after] = AuditChanges::of($account);

            if ($after !== []) {
                $account->save();
                $this->audit->handle('billing.dd_settings_updated', $account, $before, $after, companyId: $company->id);
            }

            return $account;
        });

        try {
            if ($input->mode === BillingMode::UpfrontCash && $account->hasLiveSubscription() && $account->gc_subscription_id !== null) {
                $subscription = $this->client->cancelSubscription($account->gc_subscription_id);
                $this->applySubscription->handle($company, $subscription);
                $this->audit->handle('billing.dd_subscription_cancelled', $account, null, ['status' => $subscription->status->value], ['reason' => 'upfront'], companyId: $company->id);
            } elseif ($input->mode === BillingMode::DirectDebit) {
                $this->syncSubscription->handle($company, 'settings');
            }
        } catch (GoCardlessException $exception) {
            throw ValidationException::withMessages(['billing_mode' => 'Saved, but GoCardless did not accept the change: '.$exception->getMessage()]);
        }

        return $account->refresh();
    }
}
