<?php

namespace App\Domain\Leads\Support;

/**
 * Phone numbers reduced to digits for duplicate checks: "+44 (0)7700 900-123", "0044 7700 900123" and
 * "07700 900123" all become "07700900123". Too few digits to be a phone number → null.
 */
final class PhoneDigits
{
    public static function from(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', str_replace('(0)', '', $phone)) ?? '';

        if (str_starts_with($digits, '0044')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '44') && strlen($digits) >= 12) {
            $digits = '0'.substr($digits, 2);
        }

        return strlen($digits) < 7 ? null : substr($digits, 0, 20);
    }
}
