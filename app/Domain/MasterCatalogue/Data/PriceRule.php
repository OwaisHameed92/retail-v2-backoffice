<?php

namespace App\Domain\MasterCatalogue\Data;

use App\Domain\Shared\Country\Country;

/**
 * How a business prices products it adds from the master catalogue: the recommended retail price, or its cost plus a
 * margin (margin on the price before VAT, as the product form shows it), optionally rounded up to end in 9p. A price
 * typed for one product always wins; with the margin rule, a product without a cost falls back to its RRP.
 *
 * "End in 9" off GB (Pakistan plan P10, owner 2026-10-07): prices are whole rupees, so the price goes up to the
 * smallest whole amount ending in 9 that is not below it: 123.40 → 129, 129 → 129, 129.01 → 139, 1,201 → 1,209.
 * GB keeps "end in 9p" (1.23 → 1.29).
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

            if ($this->endIn9 && ! app(Country::class)->is(Country::DEFAULT)) {
                return number_format(self::wholeEndingIn9($pence), 2, '.', '');
            }

            if ($this->endIn9 && $pence % 10 !== 9) {
                $pence = intdiv($pence, 10) * 10 + 9;
            }

            return number_format($pence / 100, 2, '.', '');
        }

        return $rrp !== null && (float) $rrp > 0 ? number_format((float) $rrp, 2, '.', '') : null;
    }

    /** The smallest whole amount ending in 9 that is not below `$minor` hundredths (12340 → 129, 120100 → 1209). */
    public static function wholeEndingIn9(int $minor): int
    {
        $whole = intdiv($minor + 99, 100);

        return $whole % 10 === 9 ? $whole : intdiv($whole, 10) * 10 + 9;
    }
}
