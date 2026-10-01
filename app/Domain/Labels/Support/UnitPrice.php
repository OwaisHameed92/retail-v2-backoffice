<?php

namespace App\Domain\Labels\Support;

use App\Domain\TillData\Enums\UnitType;
use App\Domain\TillData\Models\Product;

/**
 * UK unit pricing for a shelf label (Price Marking Order 2004): the price per kg / litre, or per 100 g / 100 ml for
 * small sizes. The size comes from the product's `volume_ml` or `net_mass_kg`, else from its name ("800g",
 * "2L", "6 x 330ml", "75cl"). Liquids: per litre (per 100 ml under 100 ml). Weights: per 100 g under 1 kg, else per kg.
 * A weighed product (sold by the kg) is already priced per kg. Pounds in, pence rounded half up; never floats.
 */
final class UnitPrice
{
    private const SIZE = '/(?:(\d{1,3})\s*[x×]\s*)?(\d+(?:[.,]\d+)?)\s*(kg|kilos?|g|grams?|gm|ml|cl|ltrs?|litres?|liters?|l)(?![a-z])/i';

    /**
     * @return array{amount: string, per: string, text: string}|null null = no size known (or a price of 0)
     */
    public static function for(Product $product, string $price): ?array
    {
        if ($product->is_weighed || $product->unit_type === UnitType::Kg) {
            return null; // The label price itself is per kg.
        }

        $size = self::size($product);

        return $size === null ? null : self::calculate($price, $size['quantity'], $size['unit']);
    }

    /**
     * @param  string  $unit  'ml' or 'g'
     * @return array{amount: string, per: string, text: string}|null
     */
    public static function calculate(string $price, string $quantity, string $unit): ?array
    {
        if (bccomp($quantity, '0', 4) <= 0 || bccomp($price, '0', 2) <= 0) {
            return null;
        }

        [$per, $base] = $unit === 'ml'
            ? (bccomp($quantity, '100', 4) < 0 ? ['100ml', '100'] : ['litre', '1000'])
            : (bccomp($quantity, '1000', 4) < 0 ? ['100g', '100'] : ['kg', '1000']);

        $amount = self::round(bcdiv(bcmul($price, $base, 8), $quantity, 8));

        return ['amount' => $amount, 'per' => $per, 'text' => self::money($amount).' per '.$per];
    }

    /**
     * The pack size in ml or g, or null.
     *
     * @return array{quantity: string, unit: string}|null
     */
    public static function size(Product $product): ?array
    {
        if ($product->volume_ml !== null && bccomp((string) $product->volume_ml, '0', 4) > 0) {
            return ['quantity' => (string) $product->volume_ml, 'unit' => 'ml'];
        }

        if ($product->net_mass_kg !== null && bccomp((string) $product->net_mass_kg, '0', 4) > 0) {
            return ['quantity' => bcmul((string) $product->net_mass_kg, '1000', 4), 'unit' => 'g'];
        }

        return self::parse((string) $product->name);
    }

    /**
     * "Coca-Cola 6 x 330ml" → 1980 ml; "Toastie White 800g" → 800 g; the last size in the name wins.
     *
     * @return array{quantity: string, unit: string}|null
     */
    public static function parse(string $name): ?array
    {
        if (preg_match_all(self::SIZE, $name, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $match = end($matches);
        $count = $match[1] !== '' ? $match[1] : '1';
        $value = str_replace(',', '.', $match[2]);
        $unit = strtolower($match[3]);

        [$factor, $base] = match (true) {
            str_starts_with($unit, 'k') => ['1000', 'g'],
            $unit === 'g' || str_starts_with($unit, 'gr') || $unit === 'gm' => ['1', 'g'],
            $unit === 'ml' => ['1', 'ml'],
            $unit === 'cl' => ['10', 'ml'],
            default => ['1000', 'ml'], // l, ltr, litre
        };

        $quantity = bcmul(bcmul($value, $factor, 4), $count, 4);

        return bccomp($quantity, '0', 4) > 0 ? ['quantity' => $quantity, 'unit' => $base] : null;
    }

    /** "1.5" → "£1.50"; under £1 as pence: "0.17" → "17p". */
    public static function money(string $amount): string
    {
        return bccomp($amount, '1', 2) < 0 ? ((int) bcmul($amount, '100', 0)).'p' : '£'.bcadd($amount, '0', 2);
    }

    /** Half up to pence (positive values only). */
    private static function round(string $value): string
    {
        return bcdiv(bcdiv(bcadd(bcmul($value, '100', 8), '0.5', 8), '1', 0), '100', 2);
    }
}
