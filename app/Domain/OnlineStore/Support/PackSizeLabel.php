<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Data\CatalogueItem;

/**
 * A product's pack size and unit price for the public store (OS-8, audit OSA-19), from the till's `net_mass_kg` and
 * `volume_ml`: "500 g" and £x per kg, "750 ml" and £x per litre. Display only (the till prices the sale). Weighed
 * products are priced per kg already, so they have neither; a 1 kg or 1 litre pack has no separate unit price.
 */
final class PackSizeLabel
{
    /** "500 g", "1.5 kg", "330 ml", "2 L", or null. */
    public static function size(CatalogueItem $item): ?string
    {
        if ($item->isWeighed) {
            return null;
        }

        if ($item->netMassKg !== null) {
            $kg = (float) $item->netMassKg;

            return $kg < 1 ? self::number($kg * 1000).' g' : self::number($kg).' kg';
        }

        if ($item->volumeMl !== null) {
            $ml = (float) $item->volumeMl;

            return $ml < 1000 ? self::number($ml).' ml' : self::number($ml / 1000).' L';
        }

        return null;
    }

    /**
     * The price per kg or per litre, rounded to the penny, or null.
     *
     * @return array{amount: string, unit: string}|null
     */
    public static function unitPrice(CatalogueItem $item): ?array
    {
        if ($item->isWeighed) {
            return null;
        }

        [$quantity, $unit] = match (true) {
            $item->netMassKg !== null => [(float) $item->netMassKg, 'kg'],
            $item->volumeMl !== null => [(float) $item->volumeMl / 1000, 'litre'],
            default => [0.0, ''],
        };

        if ($quantity <= 0 || abs($quantity - 1) < 0.0001) {
            return null;
        }

        return ['amount' => number_format((float) $item->price / $quantity, 2, '.', ''), 'unit' => $unit];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
