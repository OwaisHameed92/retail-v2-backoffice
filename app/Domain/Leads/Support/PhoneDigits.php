<?php

namespace App\Domain\Leads\Support;

use App\Domain\Shared\Country\ContactRules;

/**
 * Phone numbers reduced to digits for duplicate checks: "+44 (0)7700 900-123", "0044 7700 900123" and
 * "07700 900123" all become "07700900123". The calling code is the profile's (Pakistan plan P4): on PK
 * "+92 300 1234567", "0092 300 1234567" and "0300 1234567" all become "03001234567". Too few digits to be a phone
 * number → null.
 */
final class PhoneDigits
{
    public static function from(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', str_replace('(0)', '', $phone)) ?? '';
        $code = ContactRules::dialCode();

        if (str_starts_with($digits, '00'.$code)) {
            $digits = '0'.substr($digits, 2 + strlen($code));
        } elseif (str_starts_with($digits, $code) && strlen($digits) >= strlen($code) + 10) {
            $digits = '0'.substr($digits, strlen($code));
        }

        return strlen($digits) < 7 ? null : substr($digits, 0, 20);
    }
}
