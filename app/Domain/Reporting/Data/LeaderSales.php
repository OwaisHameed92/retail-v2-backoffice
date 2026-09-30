<?php

namespace App\Domain\Reporting\Data;

/**
 * A leading business or shop of the admin trading dashboard (module 3.2). For a shop, `parentId` / `parentLabel`
 * are its business; `children` = shops (of a business) or tills (of a shop) with figures in the range.
 */
final readonly class LeaderSales
{
    public function __construct(
        public string $id,
        public string $label,
        public string $parentId,
        public string $parentLabel,
        public int $children,
        public string $gross,
        public string $net,
        public int $transactions,
        public int $refundCount,
        public string $refundGross,
        public ?string $averageBasketExVat,
    ) {}
}
