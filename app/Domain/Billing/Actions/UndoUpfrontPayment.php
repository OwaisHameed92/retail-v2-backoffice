<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\UpfrontUndo;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Undo upfront payment (recorded by mistake)" (owner 2026-11-05): the setup fee was recorded as paid upfront but no
 * money came in. In one transaction under the billing lock (rules in UpfrontUndo):
 *
 * - the upfront payment is reversed (ReversePayment; the row stays, marked reversed with the reason);
 * - every setup fee invoice that is not void is voided (VoidInvoice; numbers kept, reason on each);
 * - the account is back to "setup fee not invoiced, unpaid": `upfront_amount`, `upfront_method`,
 *   `upfront_recorded_at` and `setup_fee_invoiced_at` are cleared. The setup fee override, the instalments, the plan
 *   and the pricing stay as they are.
 *
 * A £0 "nothing to pay" record has no payment or invoice: only the stamp is cleared, so staff can set the fee.
 * Licences (a date is never shortened), GoCardless, the mandate deadline and the monthly price are not touched, and
 * no email is sent. Staff then set the instalments (Payment settings), invoice the setup fee and record each payment.
 * Audited `billing.upfront_undone`.
 */
class UndoUpfrontPayment
{
    public const PREFIX = 'Recorded by mistake: ';

    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly ReversePayment $reversePayment,
        private readonly VoidInvoice $voidInvoice,
        private readonly RecordAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(Company $company, string $reason): BillingAccount
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter why the upfront payment is undone.']);
        }

        return DB::transaction(function () use ($company, $reason) {
            $account = $this->accounts->lock($company);
            $undo = UpfrontUndo::check($company, $account);

            if (! $undo->allowed()) {
                throw ValidationException::withMessages(['upfront' => (string) $undo->refusal]);
            }

            $note = self::PREFIX.$reason;

            if ($undo->payment !== null) {
                $this->reversePayment->handle($undo->payment, $note);
            }

            foreach ($undo->invoices as $invoice) {
                $this->voidInvoice->handle($invoice, $note, releaseHolds: false);
            }

            $account = $this->accounts->lock($company);
            $before = [
                'upfront_amount' => $account->upfront_amount,
                'upfront_method' => $account->upfront_method?->value,
                'upfront_recorded_at' => $account->upfront_recorded_at?->toIso8601String(),
                'setup_fee_invoiced_at' => $account->setup_fee_invoiced_at?->toIso8601String(),
            ];

            $account->forceFill([
                'upfront_amount' => null,
                'upfront_method' => null,
                'upfront_recorded_at' => null,
                'setup_fee_invoiced_at' => null,
            ])->save();

            $this->audit->handle('billing.upfront_undone', $account, $before, array_fill_keys(array_keys($before), null), [
                'amount' => $before['upfront_amount'],
                'amount_label' => BillingFormat::money($before['upfront_amount'] ?? '0.00'),
                'payment' => $undo->payment?->number,
                'invoices' => array_map(fn (Invoice $invoice) => $invoice->number, $undo->invoices),
                'reason' => $reason,
            ], companyId: $company->id);

            return $account;
        });
    }
}
