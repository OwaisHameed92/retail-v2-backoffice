<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;

/**
 * London calendar buckets for dashboard series, oldest first. Each bucket is `[start, end)` in UTC; the last one
 * is the current (partial) week or month. Stocks (tills, trials, overdue) are measured at `at()`: the bucket's
 * end, or now for the current bucket.
 *
 * @phpstan-type Bucket array{start: CarbonImmutable, end: CarbonImmutable, label: string}
 */
final class Buckets
{
    /**
     * Weeks start on Monday 00:00 London.
     *
     * @return list<Bucket>
     */
    public static function weeks(CarbonImmutable $now, int $count = 12, int $offset = 0): array
    {
        $current = $now->setTimezone(Country::zone())->startOfWeek(CarbonImmutable::MONDAY)->startOfDay()->subWeeks($offset);
        $buckets = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $start = $current->subWeeks($i);
            $buckets[] = ['start' => $start->utc(), 'end' => $start->addWeek()->utc(), 'label' => $start->format('j M')];
        }

        return $buckets;
    }

    /**
     * Calendar months in London.
     *
     * @return list<Bucket>
     */
    public static function months(CarbonImmutable $now, int $count, int $offset = 0): array
    {
        $current = $now->setTimezone(Country::zone())->startOfMonth()->startOfDay()->subMonthsNoOverflow($offset);
        $buckets = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $start = $current->subMonthsNoOverflow($i);
            $buckets[] = ['start' => $start->utc(), 'end' => $start->addMonthNoOverflow()->utc(), 'label' => $start->format($count > 6 ? 'M y' : 'M')];
        }

        return $buckets;
    }

    /**
     * When to measure a stock for this bucket: its end, or now while it is still running.
     *
     * @param  Bucket  $bucket
     */
    public static function at(array $bucket, CarbonImmutable $now): CarbonImmutable
    {
        return $bucket['end']->lessThan($now) ? $bucket['end'] : $now;
    }

    public static function monthStart(CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone(Country::zone())->startOfMonth()->startOfDay()->utc();
    }
}
