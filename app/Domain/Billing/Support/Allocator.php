<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Support\Money;
use LogicException;

/**
 * Puts part of a payment towards an invoice. The caller holds the company's billing lock. Never allocates more
 * than the payment has left or the invoice still owes.
 */
final class Allocator
{
    /**
     * Allocate up to `$amount` (default: as much as possible). Returns the allocation, or null when nothing
     * could be allocated. The invoice's balance and status are refreshed; SettleInvoice is the caller's job.
     */
    public function allocate(Payment $payment, Invoice $invoice, ?string $amount = null): ?PaymentAllocation
    {
        if ($payment->company_id !== $invoice->company_id) {
            throw new LogicException('A payment can only pay invoices of its own company.');
        }

        if (! $invoice->isOpen()) {
            return null;
        }

        $amount = InvoiceMaths::min($amount ?? $payment->unallocated, InvoiceMaths::min($payment->unallocated, $invoice->balance));

        if (Money::compare($amount, '0') <= 0) {
            return null;
        }

        $allocation = new PaymentAllocation(['amount' => $amount]);
        $allocation->company_id = $invoice->company_id;
        $allocation->payment_id = $payment->id;
        $allocation->invoice_id = $invoice->id;
        $allocation->save();

        $payment->unallocated = Money::sub($payment->unallocated, $amount);
        $payment->save();

        InvoiceBalance::refresh($invoice);

        return $allocation;
    }
}
