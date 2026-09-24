<?php

namespace App\Domain\Billing\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendar dates for billing. Invoice dates and periods are Europe/London calendar days held as
 * CarbonImmutable at midnight UTC (see CalendarDateCast); instants (paid_at, licence expiry) are UTC.
 */
final class BillingDates
{
    public const TIMEZONE = 'Europe/London';

    /** Today's date in London. */
    public static function today(?CarbonInterface $now = null): CarbonImmutable
    {
        return self::londonDate($now ?? CarbonImmutable::now());
    }

    /** The London calendar day an instant falls on. */
    public static function londonDate(CarbonInterface $instant): CarbonImmutable
    {
        return self::date($instant->copy()->setTimezone(self::TIMEZONE)->format('Y-m-d'));
    }

    /** "2026-10-01" → calendar date. */
    public static function date(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), 'UTC') ?: throw new InvalidArgumentException("Not a date: {$ymd}");
    }

    /** 23:59:59 London on that calendar day, as UTC (same as licence expiries). */
    public static function endOfDay(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d').' 23:59:59', self::TIMEZONE)->utc();
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
