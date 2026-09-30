<?php

namespace App\Domain\Reporting\Data;

/**
 * Takings of one payment type (DASHBOARD.md §2.3, §5.2): amount − cashback − change, net of refunds; `refunds` shown positive.
 */
final readonly class TenderTotals
{
    public function __construct(
        public string $paymentTypeId,
        public string $name,
        public string $amount,
        public int $payments,
        public string $refunds,
    ) {}
}
