<?php

namespace App\Domain\Reporting\Data;

/**
 * Sales of one shop, till or business (DASHBOARD.md §2.3 "Sales by shop / by till"; the admin's per business).
 */
final readonly class GroupSales
{
    public function __construct(
        public string $id,
        public string $label,
        public string $gross,
        public string $net,
        public string $vat,
        public int $transactions,
        public int $refundCount,
        public string $refundGross,
        public string $takings,
        public ?string $averageBasketExVat,
    ) {}
}
