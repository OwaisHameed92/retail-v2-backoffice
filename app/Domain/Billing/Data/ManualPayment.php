<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Shared\Support\Money;

/**
 * Pakistan plan P5 (manual collection): the "How to pay" card of the portal Billing page: what is owed now, the next
 * invoice to pay, and where to send the money (bank account in Pakistani style, JazzCash, Easypaisa; `BILLING_PAY_*`,
 * empty values hidden). Invoices are read under the tenant scope (the `company` middleware set it).
 */
final class ManualPayment
{
    /**
     * @return array<string, mixed>
     */
    public static function for(): array
    {
        $open = Invoice::query()->open()->orderBy('due_date')->orderBy('sequence')->get();
        $next = $open->first();
        $details = ManualCollection::payDetails();
        $due = Money::sum($open->pluck('balance'));

        return [
            'methods' => array_map(fn (PaymentMethod $method) => $method->label(), ManualCollection::methods()),
            'methodsText' => ManualCollection::methodsText(),
            'amountDue' => BillingFormat::money($due),
            'hasAmountDue' => ! Money::isZero($due),
            'overdue' => $open->contains(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Overdue),
            'next' => $next === null ? null : [
                'id' => $next->id,
                'number' => $next->number,
                'dueDate' => $next->due_date?->format('Y-m-d'),
                'balance' => BillingFormat::money($next->balance),
                'overdue' => $next->status === InvoiceStatus::Overdue,
            ],
            'reference' => $next?->number,
            'bank' => $details['bank'],
            'jazzCash' => $details['jazzCash'],
            'easypaisa' => $details['easypaisa'],
            'cash' => in_array(PaymentMethod::Cash, ManualCollection::methods(), true),
        ];
    }
}
