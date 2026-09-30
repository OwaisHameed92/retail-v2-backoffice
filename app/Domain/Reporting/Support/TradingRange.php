<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\Enums\TradingPeriod;
use Carbon\CarbonImmutable;

/**
 * The trading days of a dashboard preset or custom range (admin 3.2, business 3.3). A custom range is clamped:
 * never after today, at most MAX_DAYS long, swapped when it ends before it starts; an unreadable one becomes the
 * last 7 days.
 */
final class TradingRange
{
    public const MAX_DAYS = 366;

    /**
     * @return array{0: TradingPeriod, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    public static function resolve(TradingPeriod $period, ?string $from, ?string $to, CarbonImmutable $today): array
    {
        $days = $period->days($today) ?? self::custom($from, $to, $today);

        if ($days === null) {
            $period = TradingPeriod::Last7Days;
            $days = $period->days($today);
        }

        /** @var array{0: CarbonImmutable, 1: CarbonImmutable} $days */
        return [$period, $days[0], $days[1]];
    }

    /** Today's trading day as a UTC-midnight date (how ReportScope holds days). */
    public static function today(CarbonImmutable $now): CarbonImmutable
    {
        return CarbonImmutable::parse(TradingDay::today($now)->format('Y-m-d'), 'UTC');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function custom(?string $from, ?string $to, CarbonImmutable $today): ?array
    {
        $parse = fn (?string $d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))
            ? CarbonImmutable::parse($d, 'UTC')->startOfDay()
            : null;
        [$start, $end] = [$parse($from), $parse($to)];

        if ($start === null || $end === null) {
            return null;
        }

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $end = $end->greaterThan($today) ? $today : $end;
        $start = $start->greaterThan($end) ? $end : $start;

        if ((int) $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return [$start, $end];
    }
}
