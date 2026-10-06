<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Trading days and hours (contract v1.4.1 DASHBOARD.md §1.3): the calendar date and local hour (0–23) of a UTC
 * instant in the shops' time zone: `reporting.timezone`, which defaults to the country profile's zone (GB London, PK
 * Karachi). Worked out in PHP at ingest, never in SQL, so MySQL needs no time-zone tables and BST/GMT changes are
 * handled by PHP's tz database: 23:30Z on a summer day is the next day, hour 0; both 01:00 hours of the last Sunday
 * of October are hour 1.
 */
final class TradingDay
{
    public static function timezone(): DateTimeZone
    {
        $name = config('reporting.timezone');

        return new DateTimeZone(is_string($name) && $name !== '' ? $name : Country::zone());
    }

    /**
     * @param  string|DateTimeInterface  $utc  a UTC instant ("Y-m-d H:i:s" as stored, or ISO-8601)
     * @return array{0: string, 1: int} [trading day "Y-m-d", hour 0–23]
     */
    public static function of(string|DateTimeInterface $utc): array
    {
        $at = $utc instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($utc)
            : new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $local = $at->setTimezone(self::timezone());

        return [$local->format('Y-m-d'), (int) $local->format('G')];
    }

    /**
     * The UTC window [start, end) of a local trading day (23, 24 or 25 hours long).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(string $day): array
    {
        $start = CarbonImmutable::parse($day, self::timezone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /** Today's trading day in the shops' time zone. */
    public static function today(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(self::timezone())->startOfDay();
    }

    /** The local hour (0–23) now: "compare Today up to the same time of day" (DASHBOARD.md §2.9). */
    public static function currentHour(?CarbonImmutable $now = null): int
    {
        return (int) ($now ?? CarbonImmutable::now())->setTimezone(self::timezone())->format('G');
    }

    /**
     * Every day from $from to $to inclusive, as "Y-m-d".
     *
     * @return list<string>
     */
    public static function range(string $from, string $to): array
    {
        $days = [];

        for ($day = CarbonImmutable::parse($from, 'UTC'); $day->toDateString() <= $to; $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
