<?php

namespace App\Domain\MasterCatalogue\Support;

/**
 * Retail barcodes the master catalogue keeps: GTIN-8 (EAN-8), GTIN-12 (UPC-A), GTIN-13 (EAN-13) and GTIN-14, digits
 * only with a valid check digit. In-store numbers (EAN-13 prefixes 02, 04 and 2x; EAN-8 starting 0 or 2; UPC-A
 * starting 2 or 4) are a shop's own codes (weighed goods, internal labels) and never enter the shared catalogue.
 */
final class Gtin
{
    /** Digits of a valid GTIN, or null. Spaces and dashes are ignored. */
    public static function normalise(?string $value): ?string
    {
        $code = preg_replace('/[\s-]+/', '', (string) $value) ?? '';

        if (preg_match('/^\d{8}$|^\d{12,14}$/', $code) !== 1 || ! self::checkDigitOk($code)) {
            return null;
        }

        return $code;
    }

    public static function checkDigitOk(string $code): bool
    {
        $digits = array_map('intval', str_split($code));
        $check = array_pop($digits);
        $sum = 0;

        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /** A shop's own number (weighed goods, internal codes): never shared. */
    public static function isInStore(string $code): bool
    {
        return match (strlen($code)) {
            13 => preg_match('/^(02|04|2)/', $code) === 1,
            12 => preg_match('/^[24]/', $code) === 1,
            8 => preg_match('/^[02]/', $code) === 1,
            default => false,
        };
    }

    /**
     * The forms one product's barcode may be scanned or stored as: a UPC-A is also the EAN-13 with a leading zero.
     *
     * @return list<string>
     */
    public static function variants(string $code): array
    {
        return array_values(array_unique(array_filter([
            $code,
            strlen($code) === 12 ? '0'.$code : null,
            strlen($code) === 13 && str_starts_with($code, '0') ? substr($code, 1) : null,
        ])));
    }
}
