<?php

namespace App\Domain\StaffTime\Support;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use App\Domain\StaffTime\Data\WorkedShift;
use Carbon\CarbonImmutable;

/**
 * Hours arithmetic of module 5.6. Minutes are whole numbers; hours leave as 2 dp decimal strings.
 *
 * - Rounding: each complete shift's worked minutes to the nearest N minutes (0 = exact); halves round up;
 * - Overtime: per person per London week (Monday to Sunday), paid minutes beyond the weekly threshold, given to the
 *   shifts that cross it in time order (so a per-shop split adds up);
 * - Rota: a planned shift's London wall-clock start and end (end at or before start = the next day) to real minutes,
 *   less its break. Converting through Europe/London makes clock-change nights 1 hour shorter or longer.
 */
final class HoursMath
{
    public const ROUNDINGS = [0, 5, 10, 15];

    public static function roundMinutes(int $minutes, int $step): int
    {
        return $step <= 0 ? $minutes : (int) (floor(($minutes + $step / 2) / $step) * $step);
    }

    /**
     * Set each shift's paid minutes (worked, rounded).
     *
     * @param  list<WorkedShift>  $shifts
     */
    public static function round(array $shifts, int $step): void
    {
        foreach ($shifts as $shift) {
            $shift->paidMinutes = self::roundMinutes($shift->workedMinutes(), $step);
        }
    }

    /**
     * Set each shift's overtime minutes: paid minutes beyond $thresholdMinutes in the person's week (null = none).
     *
     * @param  list<WorkedShift>  $shifts  paid minutes already set
     */
    public static function overtime(array $shifts, ?int $thresholdMinutes): void
    {
        $sorted = $shifts;
        usort($sorted, fn (WorkedShift $a, WorkedShift $b) => $a->startsAt()->getTimestamp() <=> $b->startsAt()->getTimestamp());
        $running = [];

        foreach ($sorted as $shift) {
            $shift->overtimeMinutes = 0;

            if ($thresholdMinutes === null || ! $shift->isComplete()) {
                continue;
            }

            $key = $shift->userId.'|'.$shift->weekStart();
            $before = $running[$key] ?? 0;
            $after = $before + $shift->paidMinutes;
            $running[$key] = $after;
            $shift->overtimeMinutes = max(0, $after - $thresholdMinutes) - max(0, $before - $thresholdMinutes);
        }
    }

    /**
     * Planned minutes of a rota shift; null when a time cannot be read.
     */
    public static function plannedMinutes(string $shiftDate, ?string $start, ?string $end, int $breakMinutes): ?int
    {
        $from = self::localTime($shiftDate, $start);
        $to = self::localTime($shiftDate, $end);

        if ($from === null || $to === null) {
            return null;
        }

        if ($to->lessThanOrEqualTo($from)) {
            $to = self::localTime(CarbonImmutable::parse($shiftDate, 'UTC')->addDay()->toDateString(), $end);
        }

        $minutes = (int) round(($to?->getTimestamp() - $from->getTimestamp()) / 60);

        return max(0, $minutes - max(0, $breakMinutes));
    }

    /** "HH:mm" of a rota time as given ("09:00", "9:00:00", "09:00:00.0000000"), or null. */
    public static function clock(?string $time): ?string
    {
        if ($time === null || preg_match('/^\s*(\d{1,2}):(\d{2})/', $time, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    public static function hours(int $minutes): string
    {
        return Money::round(bcdiv((string) $minutes, '60', 6), 2);
    }

    /** Estimated pay: minutes × hourly rate, 2 dp; null without a rate. */
    public static function wage(int $minutes, ?string $rate): ?string
    {
        if ($rate === null || Money::compare($rate, '0') <= 0) {
            return null;
        }

        return Money::round(bcdiv(bcmul((string) $minutes, Money::normalise($rate, 4), 6), '60', 6), 2);
    }

    private static function localTime(string $day, ?string $time): ?CarbonImmutable
    {
        $clock = self::clock($time);

        return $clock === null ? null : CarbonImmutable::parse($day.' '.$clock, TradingDay::timezone())->utc();
    }
}
