<?php

namespace App\Domain\Mail\Support;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonInterface;

/**
 * Formatting used inside email templates: dates in the country profile's time zone, pounds, first names.
 */
final class MailFormat
{
    /**
     * The GB zone, kept for tests written before phase P1. Code reads Country::zone().
     *
     * @deprecated use Country::zone()
     */
    public const TIMEZONE = 'Europe/London';

    /** "24 September 2026" */
    public static function date(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(Country::zone())->format('j F Y');
    }

    /** "24 September 2026 at 09:41" */
    public static function dateTime(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(Country::zone())->format('j F Y \a\t H:i');
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
    /**
     * Text a visitor typed (the public trial form), shown as plain text in a Markdown mail: every Markdown character
     * is backslash-escaped, so `[click](https://…)`, `**`, headings or lists render literally and no link is made
     * (security review L3). Use inside `{{ }}`, which still escapes HTML.
     */
    public static function plain(?string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~:])/', '\\\\$1', (string) $text);
    }

    public static function count(int $count, string $singular, ?string $plural = null): string
    {
        return $count.' '.($count === 1 ? $singular : ($plural ?? $singular.'s'));
    }
}
