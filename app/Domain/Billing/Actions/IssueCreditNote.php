<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\Actor;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\DocumentNumbers;
use App\Domain\Billing\Support\InvoiceBalance;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A simple credit note (CN-000001) that takes an amount (VAT included) off what is owed on one open invoice,
 * e.g. goodwill for downtime. It is split into net and VAT at the invoice's rate. When the invoice has nothing
 * left to pay it is settled (licences renewed). To cancel a whole unpaid invoice, void it instead.
 */
class IssueCreditNote
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly SettleInvoice $settleInvoice,
        private readonly ReleaseBillingHolds $releaseHolds,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Invoice $invoice, string $amount, string $reason): CreditNote
    {
        $reason = trim($reason);
        $amount = Money::normalise($amount);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter the reason for the credit.']);
        }

        if (Money::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above £0.00.']);
        }

        /** @var Company $company */
        $company = $invoice->company()->firstOrFail();

        $note = DB::transaction(function () use ($invoice, $company, $amount, $reason) {
            $now = CarbonImmutable::now();
            $this->accounts->lock($company);
            $invoice = Invoice::withoutCompanyScope()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isOpen()) {
                throw ValidationException::withMessages(['status' => 'Credit notes can only go on an issued invoice that is not paid or void.']);
            }

            if (Money::compare($amount, $invoice->balance) > 0) {
                throw ValidationException::withMessages(['amount' => 'The credit cannot be more than the '.BillingFormat::money($invoice->balance).' still owed.']);
            }

            if (Money::equals($amount, $invoice->total) && Money::isZero($invoice->amount_paid)) {
                throw ValidationException::withMessages(['amount' => 'This would credit the whole invoice. Void it instead.']);
            }

            $split = InvoiceMaths::splitGross($amount, $invoice->vat_rate);
            [$sequence, $number] = $this->numbers->next(DocumentNumbers::CREDIT_NOTE);

            $note = new CreditNote([
                'number' => $number,
                'sequence' => $sequence,
                'reason' => mb_substr($reason, 0, 500),
                'net' => $split['net'],
                'vat' => $split['vat'],
                'total' => $amount,
                'issued_at' => $now,
                'issued_by_admin_id' => Actor::adminId(),
            ]);
            $note->company_id = $invoice->company_id;
            $note->invoice_id = $invoice->id;
            $note->save();

            $this->audit->handle('credit_note.issued', $invoice, ['balance' => $invoice->balance], null, [
                'number' => $number,
                'invoice' => $invoice->number,
                'amount' => $amount,
                'reason' => $note->reason,
            ]);

            if (InvoiceBalance::refresh($invoice)) {
                $this->settleInvoice->handle($invoice, $now);
            }

            return $note;
        });

        $this->releaseHolds->handle($company);

        return $note;
    }
}
