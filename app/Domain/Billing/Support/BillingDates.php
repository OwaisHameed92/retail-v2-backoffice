<?php

namespace App\Domain\Billing\Support;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendar dates for billing. Invoice dates and periods are calendar days in the shops' time zone (Country::zone()),
 * held as CarbonImmutable at midnight UTC (see CalendarDateCast); instants (paid_at, licence expiry) are UTC.
 */
final class BillingDates
{
    /**
     * The GB zone, kept for tests written before phase P1. Code reads Country::zone().
     *
     * @deprecated use Country::zone()
     */
    public const TIMEZONE = 'Europe/London';

    /** Today's date in the shops' time zone. */
    public static function today(?CarbonInterface $now = null): CarbonImmutable
    {
        return self::localDate($now ?? CarbonImmutable::now());
    }

    /** The calendar day an instant falls on in the shops' time zone. */
    public static function localDate(CarbonInterface $instant): CarbonImmutable
    {
        return self::date($instant->copy()->setTimezone(Country::zone())->format('Y-m-d'));
    }

    /** "2026-10-01" → calendar date. */
    public static function date(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), 'UTC') ?: throw new InvalidArgumentException("Not a date: {$ymd}");
    }

    /** 23:59:59 London on that calendar day, as UTC (same as licence expiries). */
    public static function endOfDay(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d').' 23:59:59', Country::zone())->utc();
    }

    /** Number of calendar days from $from to $to, inclusive of both (1 Oct – 31 Oct = 31). */
    public static function daysInclusive(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $from->diffInDays($to) + 1;
    }

    /** "24 Sept 2026" style used in the admin UI and emails: "24 September 2026". */
    public static function long(CarbonInterface $date): string
    {
        return $date->format('j F Y');
    }

    /**
     * "1 Oct – 31 Oct 2026", or "1 Dec 2026 – 30 Nov 2027" across years.
     */
    public static function range(CarbonInterface $start, CarbonInterface $end): string
    {
        return $start->year === $end->year
            ? $start->format('j M').' – '.$end->format('j M Y')
            : $start->format('j M Y').' – '.$end->format('j M Y');
    }
}
