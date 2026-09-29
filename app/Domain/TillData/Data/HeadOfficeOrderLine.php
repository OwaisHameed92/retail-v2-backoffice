<?php

namespace App\Domain\TillData\Data;

use App\Domain\Shared\Support\Money;

/**
 * One line of a head-office purchase order (contract v1.4 §10.6, samples/entities/PurchaseOrderLine.json):
 * `orderedUnits` = cases × case size + loose units; the cost is ex VAT (4 dp), line net and VAT are rounded to
 * pennies half away from zero, as the till does.
 */
final readonly class HeadOfficeOrderLine
{
    public function __construct(
        public string $productId,
        public int $orderedCases,
        public int $caseQty,
        public string $unitCost,
        public string $vatRateId,
        public string $vatPercentage,
        public int $looseUnits = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $line  productId, orderedCases, caseQty, unitCost, vatRateId, vatPercentage, looseUnits
     */
    public static function fromArray(array $line): self
    {
        return new self(
            (string) ($line['productId'] ?? ''),
            (int) ($line['orderedCases'] ?? 0),
            (int) ($line['caseQty'] ?? 1),
            Money::normalise($line['unitCost'] ?? 0, 4),
            (string) ($line['vatRateId'] ?? ''),
            Money::normalise($line['vatPercentage'] ?? 0, 4),
            (int) ($line['looseUnits'] ?? 0),
        );
    }

    public function orderedUnits(): int
    {
        return $this->orderedCases * $this->caseQty + $this->looseUnits;
    }

    public function net(): string
    {
        return Money::mul($this->orderedUnits(), $this->unitCost);
    }

    public function vat(): string
    {
        return Money::mul($this->net(), bcdiv($this->vatPercentage, '100', 8));
    }
}
