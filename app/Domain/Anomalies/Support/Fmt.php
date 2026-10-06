<?php

namespace App\Domain\Anomalies\Support;

use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\MoneyFormat;

/**
 * Display text of anomaly facts (module 6.6): money "£1,234.50" (MailFormat), counts "3 voids", rates "7.5", grouped
 * the country profile's way.
 */
final class Fmt
{
    public static function money(string $pounds): string
    {
        return MailFormat::money($pounds);
    }

    public static function count(int $n, string $singular, ?string $plural = null): string
    {
        return MailFormat::count($n, $singular, $plural);
    }

    /** One decimal place: "7.5", "0.0". */
    public static function rate(float $value): string
    {
        return MoneyFormat::decimal($value, 1);
    }

    /** Pounds from a float statistic (a median of pound amounts), two places. */
    public static function pounds(float $value): string
    {
        return self::money(number_format($value, 2, '.', ''));
    }

    /** Whole number with thousands separators. */
    public static function number(float $value): string
    {
        return MoneyFormat::decimal($value);
    }
}
