<?php

namespace App\Domain\Workshop\Support;

/**
 * UK phone numbers compared by their digits (customer de-duplication, WORKSHOP-BIKEBOOK.md §3.5): "+44 7700 900123",
 * "0044 7700-900123" and "07700 900123" are the same number, "07700900123".
 */
final class UkPhone
{
    public static function normalise(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '0044')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '44') && strlen($digits) >= 12) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /** Long enough to compare (a full UK number has 10 or 11 digits). */
    public static function comparable(string $normalised): bool
    {
        return strlen($normalised) >= 9;
    }
}
