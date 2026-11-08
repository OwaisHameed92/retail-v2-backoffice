<?php

namespace App\Domain\Salon\Availability;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * UK shop time for the booking page (W3a, salon pack README §4.1 "timeZone", §10 "UTC"): every date and `HH:mm` the
 * till publishes is Europe/London. `toUtc()` turns a shop date and start into the UTC instant a request carries, and
 * refuses a start in the clock-change hour (01:00–01:59 on the last Sunday of March, which does not exist, and of
 * October, which happens twice): such a time is never offered.
 */
final class LondonClock
{
    public const ZONE = 'Europe/London';

    /** The UTC instant of `$date` at `$minutes` after midnight shop time; null inside the clock-change hour. */
    public static function toUtc(string $date, int $minutes): ?CarbonImmutable
    {
        if ($minutes < 0 || $minutes >= 24 * 60 || self::inChangeHour($date, $minutes)) {
            return null;
        }

        return CarbonImmutable::parse(sprintf('%s %02d:%02d:00', $date, intdiv($minutes, 60), $minutes % 60), self::ZONE)->utc();
    }

    /** Today's date in the shop. */
    public static function today(CarbonImmutable $now): string
    {
        return $now->setTimezone(self::ZONE)->format('Y-m-d');
    }

    /** "Mon 9 Nov" and "14:00" for a UTC instant, in the shop. */
    public static function label(CarbonImmutable $utc, string $format = 'D j M, H:i'): string
    {
        return $utc->setTimezone(self::ZONE)->format($format);
    }

    /**
     * Whether a shop wall-clock time falls between the two wall-clock readings of a clock change that day.
     */
    private static function inChangeHour(string $date, int $minutes): bool
    {
        $zone = new DateTimeZone(self::ZONE);
        $midnight = new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
        $from = $midnight->getTimestamp() - 14 * 3600;
        $transitions = $zone->getTransitions($from, $from + 40 * 3600);

        if ($transitions === false || count($transitions) < 2) {
            return false;
        }

        // The wall clock of this time as if it were UTC: comparable with the transitions' wall-clock readings.
        $wall = $midnight->getTimestamp() + $minutes * 60;

        for ($i = 1, $n = count($transitions); $i < $n; $i++) {
            $at = (int) $transitions[$i]['ts'];
            $before = $at + (int) $transitions[$i - 1]['offset'];
            $after = $at + (int) $transitions[$i]['offset'];

            if ($wall >= min($before, $after) && $wall < max($before, $after)) {
                return true;
            }
        }

        return false;
    }
}
