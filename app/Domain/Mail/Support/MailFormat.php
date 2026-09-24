<?php

namespace App\Domain\Mail\Support;

use Carbon\CarbonInterface;

/**
 * Formatting used inside email templates: Europe/London dates, pounds, first names.
 */
final class MailFormat
{
    public const TIMEZONE = 'Europe/London';

    /** "24 September 2026" */
    public static function date(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(self::TIMEZONE)->format('j F Y');
    }

    /** "24 September 2026 at 09:41" */
    public static function dateTime(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(self::TIMEZONE)->format('j F Y \a\t H:i');
    }

    /** "1234.5" → "£1,234.50" */
    public static function money(string $pounds): string
    {
        return '£'.number_format((float) $pounds, 2, '.', ',');
    }

    /** "Aisha Khan" → "Aisha" */
    public static function firstName(string $name): string
    {
        $first = strtok(trim($name), ' ');

        return $first === false ? 'there' : $first;
    }

    /** 1 → "1 till", 3 → "3 tills" */
    public static function count(int $count, string $singular, ?string $plural = null): string
    {
        return $count.' '.($count === 1 ? $singular : ($plural ?? $singular.'s'));
    }
}
