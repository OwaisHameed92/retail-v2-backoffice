<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Support\Money;

/**
 * Recomputes what is paid, credited and still owed on an issued invoice from its allocations and credit notes,
 * and its open status: overdue once billing:run marked it (until paid), partly paid when anything came in,
 * else issued. Turning an invoice "paid" is SettleInvoice's job (it renews the licences).
 */
final class InvoiceBalance
{
    /** Refresh and save. Returns true when nothing is owed any more. */
    public static function refresh(Invoice $invoice): bool
    {
        $paid = Money::sum(PaymentAllocation::withoutCompanyScope()->where('invoice_id', $invoice->id)->whereNull('released_at')->pluck('amount'));
        $credited = Money::sum(CreditNote::withoutCompanyScope()->where('invoice_id', $invoice->id)->pluck('total'));
        $balance = Money::sub(Money::sub($invoice->total, $paid), $credited);

        $invoice->amount_paid = $paid;
        $invoice->amount_credited = $credited;
        $invoice->balance = Money::isNegative($balance) ? '0.00' : $balance;

        if ($invoice->isOpen()) {
            $invoice->status = match (true) {
                $invoice->overdue_at !== null => InvoiceStatus::Overdue,
                ! Money::isZero($paid) || ! Money::isZero($credited) => InvoiceStatus::PartiallyPaid,
                default => InvoiceStatus::Issued,
            };
        }

        $invoice->save();

        return Money::isZero($invoice->balance);
    }
}
