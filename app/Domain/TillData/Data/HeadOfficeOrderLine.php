<?php

namespace App\Domain\TillData\Data;

use App\Domain\Shared\Support\Money;

/**
 * One line of a head-office purchase order (contract v1.4 §10.6, samples/entities/PurchaseOrderLine.json):
 * `orderedUnits` = cases × case size + loose units; the cost is ex VAT (4 dp), line net and VAT are rounded to
 * pennies half away from zero, as the till does. Loose units may be a decimal (4 dp) for weighed (kg) products: the
 * contract's `orderedUnits` is a decimal and till orders carry `orderedCases` 0 with the whole quantity there.
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
        public string $looseUnits = '0',
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
            Money::normalise($line['looseUnits'] ?? 0, 4),
        );
    }

    /**
     * The loose units of a stored line (ordered units − cases × case size, 4 dp, never below 0): decimals kept, so a
     * weighed line (2.5 kg) is re-sent unchanged when the order is sent or cancelled.
     */
    public static function looseFrom(mixed $orderedUnits, int $orderedCases, int $caseQty): string
    {
        $loose = Money::sub($orderedUnits ?? 0, (string) ($orderedCases * max(1, $caseQty)), 4);

        return Money::isNegative($loose) ? '0.0000' : $loose;
    }

    /** Base units ordered, 4 dp. */
    public function orderedUnits(): string
    {
        return Money::add((string) ($this->orderedCases * $this->caseQty), $this->looseUnits, 4);
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
