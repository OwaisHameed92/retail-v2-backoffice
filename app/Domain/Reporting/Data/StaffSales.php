<?php

namespace App\Domain\Reporting\Data;

/**
 * One cashier's sales (DASHBOARD.md §2.3 "Staff sales"); name from the till user (fallback "Unknown user").
 */
final readonly class StaffSales
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $gross,
        public string $net,
        public int $transactions,
        public int $refundCount,
        public string $refundGross,
        public int $voidCount,
        public ?string $averageBasketExVat,
    ) {}
}
