<?php

namespace App\Domain\Reporting\Data;

/**
 * One VAT rate of the VAT summary (DASHBOARD.md §2.3, §5.5), net of refunds.
 */
final readonly class VatRateTotals
{
    public function __construct(
        public string $vatRateId,
        public string $code,
        public string $percentage,
        public string $net,
        public string $vat,
        public string $gross,
    ) {}
}
