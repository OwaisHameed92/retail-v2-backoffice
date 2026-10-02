<?php

namespace App\Domain\Anomalies\Support;

use App\Domain\Mail\Support\MailFormat;

/**
 * Display text of anomaly facts (module 6.6): pounds "£1,234.50", counts "3 voids", rates "7.5".
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
        return number_format($value, 1, '.', ',');
    }

    /** Pounds from a float statistic (a median of pound amounts), two places. */
    public static function pounds(float $value): string
    {
        return self::money(number_format($value, 2, '.', ''));
    }

    /** Whole number with thousands separators. */
    public static function number(float $value): string
    {
        return number_format($value, 0, '.', ',');
    }
}
