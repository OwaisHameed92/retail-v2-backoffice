<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;

/**
 * Can the upfront (setup fee) payment of a business be undone as "recorded by mistake" (owner 2026-11-05,
 * UndoUpfrontPayment), and what would it touch. Reads only; the action calls it again under the billing lock.
 *
 * Allowed when the account carries the upfront stamp and either:
 * - the upfront payment stands: a payment recorded by hand (cash, card, bank transfer; never Direct Debit or a
 *   gateway), not reversed, no credit left on it, paying only setup fee invoices of this business; every setup fee
 *   invoice that is not void is either paid by it alone or unpaid, has no credit note and no Direct Debit payment,
 *   and there is no setup fee draft. Those invoices are the ones to void; or
 * - a £0 "nothing to pay" was recorded and no setup fee invoice or payment exists: only the stamp is cleared.
 */
final readonly class UpfrontUndo
{
    /**
     * @param  list<Invoice>  $invoices  the setup fee invoices to void (empty for a £0 record)
     */
    private function __construct(
        public ?string $refusal,
        public ?Payment $payment = null,
        public array $invoices = [],
    ) {}

    public static function check(Company $company, BillingAccount $account): self
    {
        if ($account->upfront_recorded_at === null) {
            return self::refuse("No upfront payment is recorded for {$company->name}, so there is nothing to undo.");
        }

        $invoices = Invoice::withoutCompanyScope()->where('company_id', $company->id)
            ->where('kind', InvoiceKind::SetupFee->value)->where('status', '!=', InvoiceStatus::Void->value)
            ->orderBy('due_date')->orderBy('sequence')->get();

        if ($invoices->contains(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Draft)) {
            return self::refuse("{$company->name} has a draft setup fee invoice. Delete the draft first.");
        }

        $payment = self::upfrontPayment($company);

        if ($payment === null) {
            return Money::isZero($account->upfront_amount ?? '0.00') && $invoices->isEmpty()
                ? new self(null) // £0 "nothing to pay": only the stamp is cleared
                : self::refuse("No setup fee payment was found for {$company->name}, so it cannot be undone here.");
        }

        return self::paymentRefusal($payment) ?? self::invoicesRefusal($payment, $invoices->all()) ?? new self(null, $payment, $invoices->all());
    }

    public function allowed(): bool
    {
        return $this->refusal === null;
    }

    /** The upfront payment: the first payment that stands on a setup fee invoice, else the first reversed one. */
    private static function upfrontPayment(Company $company): ?Payment
    {
        $query = Payment::withoutCompanyScope()->where('company_id', $company->id)
            ->whereIn('id', PaymentAllocation::withoutCompanyScope()->select('payment_id')->where('company_id', $company->id)
                ->whereIn('invoice_id', Invoice::withoutCompanyScope()->select('id')->where('company_id', $company->id)
                    ->where('kind', InvoiceKind::SetupFee->value)))
            ->orderBy('received_at')->orderBy('sequence');

        return $query->clone()->whereNull('reversed_at')->first() ?? $query->clone()->first();
    }

    private static function paymentRefusal(Payment $payment): ?self
    {
        if ($payment->gateway !== null || ! in_array($payment->method, PaymentMethod::setupFee(), true)) {
            return self::refuse("{$payment->number} was paid by {$payment->method->inSentence()}. Only a setup fee payment recorded by hand can be undone here.");
        }

        if ($payment->reversed_at !== null) {
            return self::refuse("{$payment->number} is already reversed.");
        }

        if (! Money::isZero($payment->unallocated)) {
            return self::refuse('Part of '.$payment->number.' ('.BillingFormat::money($payment->unallocated).') is credit on the account, so it cannot be undone here.');
        }

        return null;
    }

    /**
     * @param  list<Invoice>  $invoices  the setup fee invoices that are not void
     */
    private static function invoicesRefusal(Payment $payment, array $invoices): ?self
    {
        $ids = array_map(fn (Invoice $invoice) => $invoice->id, $invoices);
        $allocations = PaymentAllocation::withoutCompanyScope()->where('payment_id', $payment->id)->whereNull('released_at')->get();

        if ($allocations->isEmpty()) {
            return self::refuse("{$payment->number} no longer pays a setup fee invoice, so it cannot be undone here.");
        }

        foreach ($allocations as $allocation) {
            if (! in_array($allocation->invoice_id, $ids, true)) {
                $number = Invoice::withoutCompanyScope()->whereKey($allocation->invoice_id)->value('number') ?? 'another invoice';

                return self::refuse("{$payment->number} also pays {$number}, which is not a setup fee invoice. Undo is only for a setup fee payment.");
            }
        }

        foreach ($invoices as $invoice) {
            $otherPayment = PaymentAllocation::withoutCompanyScope()->where('invoice_id', $invoice->id)->whereNull('released_at')
                ->where('payment_id', '!=', $payment->id)->exists();

            if ($otherPayment) {
                return self::refuse("{$invoice->number} has another payment on it, so the upfront payment cannot be undone here.");
            }

            if (CreditNote::withoutCompanyScope()->where('invoice_id', $invoice->id)->exists()) {
                return self::refuse("{$invoice->number} has a credit note, so the upfront payment cannot be undone here.");
            }

            if (GoCardlessPayment::withoutCompanyScope()->where('invoice_id', $invoice->id)->exists()) {
                return self::refuse("{$invoice->number} has a Direct Debit payment, so the upfront payment cannot be undone here.");
            }
        }

        return null;
    }

    private static function refuse(string $message): self
    {
        return new self($message);
    }
}
