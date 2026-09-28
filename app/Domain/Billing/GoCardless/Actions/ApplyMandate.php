<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Actions\ReleaseBillingHolds;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Data\GcMandate;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Support\DirectDebitMailer;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Applies a mandate's status to the company (webhook, return page or reconcile; idempotent).
 *
 * - Usable (pending submission, submitted, active) and new to us: store it, charge the setup fee (when it is
 *   collected by Direct Debit and not invoiced yet), create the subscription and lift a billing hold (the "no
 *   Direct Debit after the trial" suspension, an overdue flag) once nothing is overdue.
 * - Lost (cancelled, failed, expired…): remember when, email the owners and staff once; billing:run marks the
 *   company overdue after `billing.direct_debit.mandate_grace_days`.
 */
class ApplyMandate
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly ChargeSetupFee $chargeSetupFee,
        private readonly SyncSubscription $syncSubscription,
        private readonly ReleaseBillingHolds $releaseHolds,
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
            $account = $this->accounts->for($company);
            $this->mailer->mandateLost($company, $account, $now->addDays(max(0, (int) config('billing.direct_debit.mandate_grace_days', 3))));
        }

        return $outcome;
    }

    private function finalise(Company $company): void
    {
        $account = $this->accounts->for($company);

        if (! $account->isDirectDebit()) {
            return;
        }

        try {
            if ($account->setup_fee_invoiced_at === null && $account->setup_fee_method === SetupFeeMethod::DirectDebit) {
                $this->chargeSetupFee->handle($company);
            }
        } catch (ValidationException) {
            // No setup fee to charge.
        }

        try {
            $this->syncSubscription->handle($company, 'mandate');
        } catch (GoCardlessException $exception) {
            // The daily reconcile creates it; staff can also press "Update subscription".
            Log::warning('Direct Debit subscription could not be created', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
        }

        $this->releaseHolds->handle($company);
    }
}
