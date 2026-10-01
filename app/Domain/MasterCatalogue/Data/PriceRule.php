<?php

namespace App\Domain\MasterCatalogue\Data;

/**
 * How a business prices products it adds from the master catalogue: the recommended retail price, or its cost plus a
 * margin (margin on the price before VAT, as the product form shows it), optionally rounded up to end in 9p. A price
 * typed for one product always wins; with the margin rule, a product without a cost falls back to its RRP.
 */
final readonly class PriceRule
{
    public function __construct(public string $mode = 'rrp', public float $margin = 30.0, public bool $endIn9 = true) {}

    /**
     * Sell price including VAT in pounds ("1.29"), or null when there is nothing to price it from.
     */
    public function sellPrice(?string $typed, ?string $cost, ?string $rrp, float $vatPercent): ?string
    {
        if ($typed !== null && is_numeric($typed) && (float) $typed > 0) {
            return number_format((float) $typed, 2, '.', '');
        }

        if ($this->mode === 'margin' && $cost !== null && is_numeric($cost) && (float) $cost > 0 && $this->margin < 100) {
            $net = (float) $cost / (1 - $this->margin / 100);
            $pence = (int) ceil(round($net * (1 + $vatPercent / 100) * 100, 4));

            if ($this->endIn9 && $pence % 10 !== 9) {
                $pence = intdiv($pence, 10) * 10 + 9;
            }

            return number_format($pence / 100, 2, '.', '');
        }

        return $rrp !== null && (float) $rrp > 0 ? number_format((float) $rrp, 2, '.', '') : null;
    }
}
