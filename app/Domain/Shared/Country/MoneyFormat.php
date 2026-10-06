<?php

namespace App\Domain\Shared\Country;

use App\Domain\Shared\Support\Money;

/**
 * Money for people to read, in this instance's currency (Country profile). No floats: the amount is rounded half
 * away from zero with bcmath to the profile's display decimals.
 *
 *     GB: "1234.5" → "£1,234.50", "-3" → "-£3.00", 0 → "£0.00"
 *     PK: "1250" → "Rs 1,250", "-1249.5" → "-Rs 1,250", "12345678.9" → "Rs 1,23,45,679" (lakh grouping)
 *
 * GB output equals MailFormat::money for every amount that is not negative, and BillingFormat::money and the
 * front end's Intl 'en-GB' GBP formatters for every amount (sign before the symbol). Callers move here in phase P2.
 */
final class MoneyFormat
{
    public static function format(string|int|float $amount, ?Country $country = null): string
    {
        $country ??= app(Country::class);
        $normalised = Money::normalise($amount, $country->displayDecimals());
        $negative = str_starts_with($normalised, '-');
        $parts = explode('.', ltrim($normalised, '-'));
        $grouped = $country->groupDigits($parts[0]);
        $fraction = isset($parts[1]) ? '.'.$parts[1] : '';

        return ($negative ? '-' : '').$country->symbol().($country->symbolSpace() ? ' ' : '').$grouped.$fraction;
    }

    /** A plain number grouped the profile's way: GB "125000" → "125,000", PK → "1,25,000". Decimals kept as given. */
    public static function number(string|int $value, ?Country $country = null): string
    {
        $country ??= app(Country::class);
        $value = Money::parse($value);
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-+'));

        return ($negative ? '-' : '').$country->groupDigits($parts[0]).(isset($parts[1]) && $parts[1] !== '' ? '.'.$parts[1] : '');
    }
}
