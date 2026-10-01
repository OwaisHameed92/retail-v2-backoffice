<?php

namespace App\Domain\Promotions\Support;

/**
 * `PromotionRule.priceTiers` of a `quantityPrice` offer (ANSWERS-2026-10-01 §2a, the till's `PromotionPriceTiers.cs`):
 * a string, not JSON — `quantity=total price` incl. VAT, `;` between tiers, e.g. "2=5.00;3=7.00". Quantities rise,
 * each ≥ 2 and used once; each price > 0, in pence (`.` decimal). The till picks the blocks that save the customer
 * the most; pieces left over sell at the shelf price.
 */
final class PriceTiers
{
    public const MAX_TIERS = 20;

    /** Why the tiers are not valid, or null when they are. */
    public static function error(?string $tiers): ?string
    {
        $parts = self::parts($tiers);

        if ($parts === []) {
            return 'Add at least one tier, e.g. 2 for £5.00.';
        }

        if (count($parts) > self::MAX_TIERS) {
            return 'Use at most '.self::MAX_TIERS.' tiers.';
        }

        $last = 1;

        foreach ($parts as $part) {
            if (preg_match('/^(\d{1,3})=(\d{1,6}(?:\.\d{1,2})?)$/', $part, $m) !== 1) {
                return 'Write each tier as a quantity and a price in pounds, e.g. 2 for 5.00.';
            }

            if ((int) $m[1] < 2) {
                return 'Each tier is for 2 or more items.';
            }

            if ((int) $m[1] <= $last) {
                return 'List the quantities from smallest to largest, each once.';
            }

            if (bccomp($m[2], '0', 2) <= 0) {
                return 'Each tier needs a price above £0.00.';
            }

            $last = (int) $m[1];
        }

        return null;
    }

    /** The till's string with every price at 2 dp ("2=5;3=7.5" → "2=5.00;3=7.50"). Call after error() is null. */
    public static function normalise(string $tiers): string
    {
        return implode(';', array_map(function (string $part): string {
            [$quantity, $price] = explode('=', $part);

            return (int) $quantity.'='.bcadd($price, '0', 2);
        }, self::parts($tiers)));
    }

    /**
     * The tiers as rows for the form.
     *
     * @return list<array{quantity: string, price: string}>
     */
    public static function rows(?string $tiers): array
    {
        return array_map(function (string $part): array {
            [$quantity, $price] = array_pad(explode('=', $part, 2), 2, '');

            return ['quantity' => $quantity, 'price' => $price];
        }, self::parts($tiers));
    }

    /** "2 for £5.00, 3 for £7.00". */
    public static function describe(?string $tiers): string
    {
        return implode(', ', array_map(fn (array $t) => $t['quantity'].' for £'.number_format((float) $t['price'], 2), self::rows($tiers)));
    }

    /** @return list<string> */
    private static function parts(?string $tiers): array
    {
        return preg_split('/\s*;\s*/', str_replace(' ', '', trim((string) $tiers)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
