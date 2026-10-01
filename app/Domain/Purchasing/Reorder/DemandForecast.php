<?php

namespace App\Domain\Purchasing\Reorder;

use Carbon\CarbonImmutable;

/**
 * Average daily sales of one product at one shop (module 6.4), from its daily units sold (`rpt_product_daily`, net
 * of refunds) over the last full weeks ending yesterday. Exact decimals (bcmath), never floats.
 *
 * - Weighted weeks: week 1 is the latest. Weeks before the first week with a sale are left out (a new line), and
 *   the remaining W weeks weigh W, W−1, … 1, so last week counts most: rate = Σ wₖ·unitsₖ / (7·Σ wₖ).
 * - Weekday pattern: each weekday's share of the units × 7 (1 = an average day; a shop shut on Sundays gets 0). With
 *   few sales it is blended towards flat: index = 1 + min(1, units / `weekday_pattern_units`) × (raw − 1).
 * - forecast(day) = rate × weekday index × the day's seasonal factor (1 outside events).
 */
final readonly class DemandForecast
{
    private const S = 6;

    private const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @param  array<int, string>  $weekday  ISO weekday (1 = Monday) → index, averaging 1.
     */
    public function __construct(
        public string $rate,
        public array $weekday,
        public int $weeks,
        public string $units,
        public string $lastWeekUnits,
    ) {}

    public static function none(): self
    {
        return new self('0', array_fill(1, 7, '1'), 0, '0', '0');
    }

    /**
     * @param  array<string, string>  $daily  "Y-m-d" → units sold that day (missing days sold none).
     */
    public static function fromHistory(array $daily, CarbonImmutable $today, int $weeks, int $patternUnits): self
    {
        $totals = [];
        $byWeekday = [];

        for ($k = 1; $k <= $weeks; $k++) {
            $total = '0';
            $days = [];

            for ($i = 1; $i <= 7; $i++) {
                [$date, $weekday] = self::calendar($today, -(7 * ($k - 1) + $i));
                $units = self::positive($daily[$date] ?? '0');
                $total = bcadd($total, $units, self::S);
                $days[$weekday] = $units;
            }

            $totals[$k] = $total;
            $byWeekday[$k] = $days;
        }

        // Leave out the oldest weeks before the product first sold (a new line is not averaged with empty weeks).
        $used = $weeks;
        while ($used > 0 && bccomp($totals[$used], '0', self::S) === 0) {
            $used--;
        }

        if ($used === 0) {
            return self::none();
        }

        [$weighted, $weights, $units] = ['0', 0, '0'];
        $weekday = array_fill(1, 7, '0');

        for ($k = 1; $k <= $used; $k++) {
            $weight = $used - $k + 1;
            $weighted = bcadd($weighted, bcmul((string) $weight, $totals[$k], self::S), self::S);
            $weights += $weight;
            $units = bcadd($units, $totals[$k], self::S);

            foreach ($byWeekday[$k] as $d => $u) {
                $weekday[$d] = bcadd($weekday[$d], $u, self::S);
            }
        }

        $rate = bcdiv($weighted, (string) (7 * $weights), self::S);
        $blend = bccomp($units, (string) max(1, $patternUnits), self::S) >= 0 ? '1' : bcdiv($units, (string) max(1, $patternUnits), self::S);
        $index = [];

        foreach ($weekday as $d => $u) {
            $raw = bcdiv(bcmul($u, '7', self::S), $units, self::S);
            $index[$d] = bcadd('1', bcmul($blend, bcsub($raw, '1', self::S), self::S), self::S);
        }

        return new self($rate, $index, $used, $units, $totals[1]);
    }

    /**
     * Units expected to sell over `$days` days from `$from` (day 0 = `$from`).
     *
     * @param  array<string, string>  $factors  "Y-m-d" → seasonal multiplier.
     */
    public function forecast(CarbonImmutable $from, int $days, array $factors = []): string
    {
        $sum = '0';

        for ($i = 0; $i < $days; $i++) {
            [$date, $weekday] = self::calendar($from, $i);
            $sum = bcadd($sum, bcmul(bcmul($this->rate, $this->weekday[$weekday], self::S), $factors[$date] ?? '1', self::S), self::S);
        }

        return $sum;
    }

    /**
     * @param  array<string, string>  $factors
     */
    public function day(CarbonImmutable $day, array $factors = []): string
    {
        return $this->forecast($day, 1, $factors);
    }

    /**
     * The date ("Y-m-d") and ISO weekday `$offset` days from `$from`, remembered (thousands of products share the
     * same few dates, and Carbon arithmetic is the slow part of a large business's suggestions).
     *
     * @return array{0: string, 1: int}
     */
    private static function calendar(CarbonImmutable $from, int $offset): array
    {
        static $cache = [];
        $key = $from->toDateString().'|'.$offset;

        if (! isset($cache[$key])) {
            if (count($cache) > 5000) {
                $cache = [];
            }

            $day = $from->addDays($offset);
            $cache[$key] = [$day->toDateString(), $day->isoWeekday()];
        }

        return $cache[$key];
    }

    /** The busiest weekday's name when the pattern is clear (index ≥ 1.2), else null. */
    public function busiestDay(): ?string
    {
        $best = array_keys($this->weekday, max($this->weekday), true)[0] ?? null;

        return $best !== null && bccomp($this->weekday[$best], '1.2', self::S) >= 0
            ? self::DAYS[$best]
            : null;
    }

    /**
     * Days with an index of about 0 (the shop sells none then, usually closed), as names.
     *
     * @return list<string>
     */
    public function quietDays(): array
    {
        $names = [];

        foreach ($this->weekday as $d => $index) {
            if ($this->weeks > 0 && bccomp($index, '0.05', self::S) < 0) {
                $names[] = self::DAYS[$d];
            }
        }

        return $names;
    }

    private static function positive(string $units): string
    {
        return bccomp($units, '0', self::S) > 0 ? bcadd($units, '0', self::S) : '0';
    }
}
