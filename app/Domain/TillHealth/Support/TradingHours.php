<?php

namespace App\Domain\TillHealth\Support;

use App\Domain\Calendar\Support\WeeklyHours;
use Carbon\CarbonImmutable;

/**
 * Trading hours in the shops' time zone (module 2.7): is it trading time now, and how many trading hours a silence
 * covered. A shop's own opening hours and the till's special days (module 5.9, WeeklyHours) when given, else the
 * configured default every day (08:00–20:00). Closed days have no trading hours; hours past midnight count. Works
 * across BST/GMT changes.
 */
final readonly class TradingHours
{
    /** Longer silences are certainly past any threshold: stop counting after this many days. */
    private const MAX_DAYS = 14;

    private WeeklyHours $hours;

    public function __construct(private HealthThresholds $thresholds, ?WeeklyHours $hours = null)
    {
        $this->hours = $hours ?? self::defaultHours($thresholds);
    }

    public static function defaultHours(HealthThresholds $thresholds): WeeklyHours
    {
        return WeeklyHours::everyDay($thresholds->tradingStart, $thresholds->tradingEnd);
    }

    public function isOpen(CarbonImmutable $at): bool
    {
        $today = $at->setTimezone($this->thresholds->timezone)->startOfDay();

        foreach ([$today->subDay(), $today] as $day) {
            $window = $this->hours->window($day, $this->thresholds->timezone);

            if ($window !== null && $at->greaterThanOrEqualTo($window[0]) && $at->lessThan($window[1])) {
                return true;
            }
        }

        return false;
    }

    /** Trading hours (fractional) between two instants. */
    public function hoursBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0.0;
        }

        $tz = $this->thresholds->timezone;
        $day = $from->setTimezone($tz)->startOfDay()->subDay(); // yesterday's hours may run past midnight
        $last = $to->setTimezone($tz)->startOfDay();
        $seconds = 0;

        for ($i = 0; $i <= self::MAX_DAYS + 1 && $day->lessThanOrEqualTo($last); $i++, $day = $day->addDay()) {
            $window = $this->hours->window($day, $tz);

            if ($window === null) {
                continue;
            }

            $overlapStart = $window[0]->max($from);
            $overlapEnd = $window[1]->min($to);

            if ($overlapEnd->greaterThan($overlapStart)) {
                $seconds += $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
            }
        }

        return $seconds / 3600;
    }
}
