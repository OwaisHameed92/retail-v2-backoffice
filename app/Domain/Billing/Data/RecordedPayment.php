<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;

/**
 * Result of RecordPayment: the payment, the invoices it paid in full, how many licences were renewed, the credit
 * left on the payment and whether a billing suspension was lifted.
 */
final readonly class RecordedPayment
{
    /**
     * @param  list<Invoice>  $paidInvoices
     */
    public function __construct(
        public Payment $payment,
        public array $paidInvoices,
        public int $licencesRenewed,
        public string $credit,
        public bool $unsuspended,
        public bool $duplicate = false,
    ) {}
}
