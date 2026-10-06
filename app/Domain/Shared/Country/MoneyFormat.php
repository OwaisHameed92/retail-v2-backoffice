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
 * Every PHP money text goes through here (phase P2). Some UK call sites have always written money another way; they
 * pass a `$ukStyle` so the UK output stays byte-identical (UK safety rules). Any other profile ignores the style and
 * writes the one style above ("-Rs 5"), so Pakistan reads the same everywhere.
 */
final class MoneyFormat
{
    /** GB "£-5.00", "£1,234.50": the sign after the symbol, as MailFormat and `'£'.number_format()` text always wrote. */
    public const SIGN_AFTER_SYMBOL = 'signAfterSymbol';

    /** GB "£1250.5": the symbol and the amount exactly as given (a raw decimal from the database or the request). */
    public const AS_GIVEN = 'asGiven';

    public static function format(mixed $amount, ?Country $country = null, ?string $ukStyle = null): string
    {
        $country ??= app(Country::class);

        if ($ukStyle === self::AS_GIVEN && self::keepsUkStyles($country)) {
            // Exactly what "£{$amount}" wrote.
            return $country->symbol().(is_scalar($amount) ? (string) $amount : '');
        }

        $normalised = Money::normalise($amount, $country->displayDecimals());
        $negative = str_starts_with($normalised, '-');
        $parts = explode('.', ltrim($normalised, '-'));
        $number = $country->groupDigits($parts[0]).(isset($parts[1]) ? '.'.$parts[1] : '');

        if ($ukStyle === self::SIGN_AFTER_SYMBOL && self::keepsUkStyles($country)) {
            return $country->symbol().($negative ? '-' : '').$number;
        }

        return ($negative ? '-' : '').self::prefix($country).$number;
    }

    /**
     * A cost or unit price that may carry up to 4 decimal places: at least the profile's display decimals, trailing
     * zeros dropped. GB "0.4575" → "£0.4575", "1.5" → "£1.50"; PK "12.5" → "Rs 12.5", "1250" → "Rs 1,250".
     */
    public static function cost(mixed $amount, ?Country $country = null, ?string $ukStyle = null): string
    {
        $country ??= app(Country::class);

        if ($ukStyle !== null && self::keepsUkStyles($country)) {
            return self::format($amount, $country, $ukStyle);
        }

        $normalised = Money::normalise($amount, Money::QUANTITY_SCALE);
        $negative = str_starts_with($normalised, '-');
        [$whole, $fraction] = explode('.', ltrim($normalised, '-'));
        $fraction = substr($fraction, 0, max($country->displayDecimals(), strlen(rtrim($fraction, '0'))));

        return ($negative ? '-' : '').self::prefix($country).$country->groupDigits($whole).($fraction === '' ? '' : '.'.$fraction);
    }

    /** Whole units, for round figures in text: GB "£0", "£1,200"; PK "Rs 1,200". */
    public static function whole(mixed $amount, ?Country $country = null): string
    {
        $country ??= app(Country::class);
        $normalised = Money::normalise($amount, 0);
        $negative = str_starts_with($normalised, '-');

        return ($negative ? '-' : '').self::prefix($country).$country->groupDigits(ltrim($normalised, '-'));
    }

    /** What goes before an amount: "£" (GB), "Rs " (PK). */
    public static function prefix(?Country $country = null): string
    {
        $country ??= app(Country::class);

        return $country->symbol().($country->symbolSpace() ? ' ' : '');
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

    /**
     * A count or measure from a float, rounded with PHP's number_format as the UK text always was, then grouped the
     * profile's way: GB 1234.0 → "1,234", PK 125000.0 → "1,25,000".
     */
    public static function decimal(float $value, int $decimals = 0, ?Country $country = null): string
    {
        if (! is_finite($value)) {
            return number_format($value, $decimals, '.', ',');
        }

        return self::number(number_format($value, $decimals, '.', ''), $country);
    }

    /** True on the default profile (GB), whose call sites keep their own UK money styles. */
    public static function keepsUkStyles(?Country $country = null): bool
    {
        return ($country ?? app(Country::class))->is(Country::DEFAULT);
    }
}
