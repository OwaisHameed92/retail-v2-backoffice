<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Data\DirectDebitSettingsInput;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Billing\Support\SetupFeeDeposit;
use App\Domain\Billing\Support\Vat;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves how a company pays: upfront cash or Direct Debit, its setup fee override, in how many instalments and the
 * optional deposit (first instalment, owner 2026-11-11; audited with the other settings) (the
 * setup fee is paid by hand: owner rule 2026-10-05). Moving to upfront cancels a live GoCardless subscription; moving to Direct Debit with a
 * working mandate creates it. The setup fee cannot change once invoiced. Audited.
 *
 * Owner 2026-11-05: "Setup fee: By Direct Debit" (`setup_fee_by_direct_debit`, a per-business exception, default off)
 * may be switched on only for a Direct Debit business on a UK instance with GoCardless; it can be switched at any time
 * (on after invoicing: the unpaid instalments are collected; off: nothing new is collected). Audited on its own.
 */
class UpdateDirectDebitSettings
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly SyncSubscription $syncSubscription,
        private readonly ApplySubscription $applySubscription,
        private readonly RecordAudit $audit,
        private readonly CollectSetupFeeByDirectDebit $collectSetupFee,
    ) {}

    /** @throws ValidationException */
    public function handle(Company $company, DirectDebitSettingsInput $input): BillingAccount
    {
        // Pakistan plan P5: fees are paid by hand on this instance; there is no Direct Debit to switch to.
        if (ManualCollection::active() && $input->mode === BillingMode::DirectDebit) {
            throw ValidationException::withMessages(['billing_mode' => 'Direct Debit is not available here: every invoice is paid by hand.']);
        }

        $this->checkSetupFeeByDirectDebit($input);
        $switchedOn = false;

        $account = DB::transaction(function () use ($company, $input, &$switchedOn) {
            $account = $this->accounts->lock($company);
            $wasByDirectDebit = $account->setup_fee_by_direct_debit;
            // Owner 2026-11-05: only staff set it, here; it means nothing unless the business pays by Direct Debit.
            $byDirectDebit = $input->mode === BillingMode::DirectDebit && ($input->setupFeeByDirectDebit ?? $wasByDirectDebit);
            $override = $input->setupFeeOverride === null ? null : Money::normalise($input->setupFeeOverride);
            // Owner 2026-11-11: the deposit (first instalment); callers that do not send it keep the business's.
            $deposit = $input->depositGiven ? ($input->setupFeeDeposit === null ? null : Money::normalise($input->setupFeeDeposit)) : $account->setup_fee_deposit;
            $instalments = max(1, min((int) config('billing.direct_debit.max_instalments', 12), $input->instalments));
            $feeChanged = $override !== $account->setup_fee_override || $input->instalments !== $account->setup_fee_instalments
                || $deposit !== $account->setup_fee_deposit;

            if ($account->setup_fee_invoiced_at !== null && $feeChanged) {
                throw ValidationException::withMessages(['setup_fee_override' => 'The setup fee is already invoiced, so it cannot change. Void or credit those invoices instead.']);
            }

            $this->checkDeposit($company, $account, $override, $deposit, $instalments);

            $account->fill([
                'billing_mode' => $input->mode,
                'setup_fee_override' => $override,
                'setup_fee_method' => SetupFeeMethod::Manual,
                'setup_fee_instalments' => $instalments,
                'setup_fee_deposit' => $deposit,
            ]);

            if ($input->tillFeeGiven) {
                $account->till_setup_fee_override = $input->tillSetupFeeOverride === null ? null : Money::normalise($input->tillSetupFeeOverride);
            }

            // Module 1.13: switching to Direct Debit starts the setup deadline (unless it already runs or is met).
            if ($input->mode === BillingMode::DirectDebit && $account->mandate_deadline_at === null && ! $account->hasUsableMandate()) {
                $account->mandate_deadline_at = MandateDeadline::fromNow();
            }

            if ($byDirectDebit !== $wasByDirectDebit) {
                $account->setup_fee_by_direct_debit = $byDirectDebit;
            }

            [$before, $after] = AuditChanges::of($account);

            if ($after !== []) {
                $account->save();
                $this->audit->handle('billing.dd_settings_updated', $account, $before, $after, companyId: $company->id);
            }

            if ($byDirectDebit !== $wasByDirectDebit) {
                $this->audit->handle($byDirectDebit ? 'billing.setup_fee_direct_debit_on' : 'billing.setup_fee_direct_debit_off', $account,
                    ['setup_fee_by_direct_debit' => $wasByDirectDebit], ['setup_fee_by_direct_debit' => $byDirectDebit], companyId: $company->id);
                $switchedOn = $byDirectDebit;
            }

            return $account;
        });

        if (DemoBusinesses::isDemo($company)) {
            return $account->refresh(); // settings saved; nothing is sent to GoCardless for a demo business
        }

        // Switched on after the setup fee was invoiced: the unpaid instalments coming due go on the mandate now and the
        // customer gets each open instalment again, saying when it is collected (switched off: nothing new is made;
        // payments already with GoCardless are left alone).
        if ($switchedOn) {
            $this->collectSetupFee->handle($company, email: $account->hasUsableMandate());
        }

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

    /**
     * Owner 2026-11-11: a deposit must be above £0, below the setup fee (net) and needs 2 or more instalments.
     * Checked only while the fee can change (not invoiced yet).
     *
     * @throws ValidationException
     */
    private function checkDeposit(Company $company, BillingAccount $account, ?string $override, ?string $deposit, int $instalments): void
    {
        if ($deposit === null || $account->setup_fee_invoiced_at !== null) {
            return;
        }

        $problem = SetupFeeDeposit::problem($override ?? SetupFee::planFee($company), $deposit, $instalments, Vat::rateFor($account));

        if ($problem !== null) {
            throw ValidationException::withMessages(['setup_fee_deposit' => $problem]);
        }
    }

    /**
     * Owner 2026-11-05: "Setup fee: By Direct Debit" only on a UK instance with GoCardless configured (never on
     * manual collection) and for a business that pays by Direct Debit.
     *
     * @throws ValidationException
     */
    private function checkSetupFeeByDirectDebit(DirectDebitSettingsInput $input): void
    {
        if ($input->setupFeeByDirectDebit !== true) {
            return;
        }

        $problem = match (true) {
            ManualCollection::active() => 'The setup fee cannot be collected by Direct Debit here: every invoice is paid by hand.',
            ! $this->client->enabled() => 'GoCardless is not connected, so the setup fee cannot be collected by Direct Debit.',
            $input->mode !== BillingMode::DirectDebit => 'Only a business that pays by Direct Debit can have its setup fee collected by Direct Debit.',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['setup_fee_by_direct_debit' => $problem]);
        }
    }
}
