<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Support\Money;

/**
 * Payment rows and detail for the admin screens.
 */
final class PaymentData
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Payment $payment): array
    {
        $payment->loadMissing(['company', 'receivedBy', 'allocations.invoice']);

        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'company' => ['id' => $payment->company_id, 'name' => $payment->company->name ?? 'Deleted business'],
            'method' => $payment->method->value,
            'methodLabel' => $payment->method->label(),
            'amount' => BillingFormat::money($payment->amount),
            'unallocated' => BillingFormat::money($payment->unallocated),
            'hasCredit' => ! Money::isZero($payment->unallocated),
            'receivedAt' => $payment->received_at->toIso8601String(),
            'reference' => $payment->reference,
            'receivedBy' => $payment->receivedBy->name ?? ($payment->gateway !== null ? ucfirst($payment->gateway) : null),
            'invoices' => $payment->allocations->map(fn (PaymentAllocation $allocation) => [
                'id' => $allocation->invoice_id,
                'number' => $allocation->invoice?->number,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Payment $payment): array
    {
        $payment->loadMissing(['allAllocations.invoice']);

        return self::row($payment) + [
            'notes' => $payment->notes,
            'gateway' => $payment->gateway,
            'gatewayReference' => $payment->gateway_reference,
            'allocations' => $payment->allAllocations->map(fn (PaymentAllocation $allocation) => [
                'id' => $allocation->id,
                'invoiceId' => $allocation->invoice_id,
                'invoiceNumber' => $allocation->invoice?->displayNumber(),
                'invoiceStatus' => $allocation->invoice?->status->value,
                'amount' => BillingFormat::money($allocation->amount),
                'released' => $allocation->released_at !== null,
                'releasedAt' => $allocation->released_at?->toIso8601String(),
                'createdAt' => $allocation->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
