<?php

namespace App\Domain\Anomalies\Support;

use App\Domain\Anomalies\Enums\AnomalySeverity;

/**
 * The statistics behind every detector (module 6.6): median and MAD (median absolute deviation), which one odd day in
 * the baseline cannot drag about the way a mean and standard deviation can. The robust score of a value is how many
 * "spreads" it sits above the median, where spread = 1.4826 × MAD (the standard deviation of normal data) but never
 * less than a floor, so a very steady baseline (MAD 0) does not turn a small wobble into an alarm.
 *
 * Floats are used for scores only; money shown to people is always worked out with bcmath strings.
 */
final class RobustStats
{
    /** Scores at or above this are unusual. */
    public const UNUSUAL = 3.5;

    /** And the value must be at least this many times the median. */
    public const RATIO = 2.0;

    /**
     * @param  list<float>  $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * @param  list<float>  $values
     */
    public static function mad(array $values): float
    {
        $median = self::median($values);

        return self::median(array_map(fn (float $v) => abs($v - $median), $values));
    }

    /**
     * How many spreads `$value` sits above the baseline's median (negative = below).
     *
     * @param  list<float>  $baseline
     * @param  float  $floor  smallest spread allowed (in the measure's own units)
     * @param  float  $relativeFloor  and at least this share of the median
     */
    public static function score(float $value, array $baseline, float $floor, float $relativeFloor = 0.25): float
    {
        $median = self::median($baseline);
        $spread = max(1.4826 * self::mad($baseline), $floor, abs($median) * $relativeFloor);

        return ($value - $median) / $spread;
    }

    /**
     * Far above the baseline: the score reaches {@see self::UNUSUAL} and the value is at least {@see self::RATIO}
     * times the median (or, with a zero median, at least `$floor` × RATIO).
     *
     * @param  list<float>  $baseline
     */
    public static function farAbove(float $value, array $baseline, float $floor): bool
    {
        $median = self::median($baseline);

        return self::score($value, $baseline, $floor) >= self::UNUSUAL
            && $value >= max($median * self::RATIO, $floor * self::RATIO);
    }

    /**
     * Severity from the score and the times-normal ratio: high at 8 spreads and 4×, medium at 5 and 3×, else low.
     */
    public static function severity(float $score, float $ratio): AnomalySeverity
    {
        return match (true) {
            $score >= 8 && $ratio >= 4 => AnomalySeverity::High,
            $score >= 5 && $ratio >= 3 => AnomalySeverity::Medium,
            default => AnomalySeverity::Low,
        };
    }

    /** Value ÷ median, with a zero median read as the floor. */
    public static function ratio(float $value, float $median, float $floor): float
    {
        return $value / max($median, $floor);
    }
}
