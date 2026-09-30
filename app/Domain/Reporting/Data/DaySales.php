<?php

namespace App\Domain\Reporting\Data;

/**
 * One trading day of "Sales by day" (DASHBOARD.md §2.3). Days without sales are included with zeros.
 */
final readonly class DaySales
{
    public function __construct(
        public string $day,
        public string $gross,
        public string $net,
        public string $vat,
        public int $transactions,
        public int $refundCount,
        public string $refundGross,
        public string $takings,
    ) {}
}
