<?php

namespace App\Domain\TillHealth\Support;

use Carbon\CarbonImmutable;

/**
 * Daily trading hours in the shops' time zone (module 2.7, until each shop's opening hours arrive in 5.9): is it
 * trading time now, and how many trading hours a silence covered. Works across BST/GMT changes.
 */
final readonly class TradingHours
{
    /** Longer silences are certainly past any threshold: stop counting after this many days. */
    private const MAX_DAYS = 14;

    public function __construct(private HealthThresholds $thresholds) {}

    public function isOpen(CarbonImmutable $at): bool
    {
        [$start, $end] = $this->window($at->setTimezone($this->thresholds->timezone));

        return $at->greaterThanOrEqualTo($start) && $at->lessThan($end);
    }

    /** Trading hours (fractional) between two instants. */
    public function hoursBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0.0;
        }

        $tz = $this->thresholds->timezone;
        $day = $from->setTimezone($tz)->startOfDay();
        $last = $to->setTimezone($tz)->startOfDay();
        $seconds = 0;

        for ($i = 0; $i <= self::MAX_DAYS && $day->lessThanOrEqualTo($last); $i++, $day = $day->addDay()) {
            [$start, $end] = $this->window($day);
            $overlapStart = $start->max($from);
            $overlapEnd = $end->min($to);

            if ($overlapEnd->greaterThan($overlapStart)) {
                $seconds += $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
            }
        }

        return $seconds / 3600;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(CarbonImmutable $localDay): array
    {
        $date = $localDay->format('Y-m-d');
        $tz = $this->thresholds->timezone;
        $start = CarbonImmutable::parse("{$date} {$this->thresholds->tradingStart}", $tz);
        $end = CarbonImmutable::parse("{$date} {$this->thresholds->tradingEnd}", $tz);

        return [$start, $end->greaterThan($start) ? $end : $start->addDay()->setTimeFrom($end)];
    }
}
