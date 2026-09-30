<?php

namespace App\Domain\Admin\Queries\Trading;

use App\Domain\Admin\Data\TradingFilters;
use App\Domain\Reporting\Queries\SalesReport;

/**
 * The chart series of the admin trading dashboard (DASHBOARD.md §2.3 "Sales by day", "Sales by hour"), each point
 * beside the same point of the compare window: day n against the compare window's day n, hour against hour.
 * Money stays a decimal string; the page turns it into a number only to draw.
 */
final class TradingSeries
{
    public function __construct(private readonly SalesReport $sales) {}

    /**
     * Every day of the range (null for a single day: the hourly chart is the trend then).
     *
     * @return list<array<string, mixed>>|null
     */
    public function daily(TradingFilters $filters): ?array
    {
        if ($filters->singleDay()) {
            return null;
        }

        $compare = $filters->compareScope();
        $current = $this->sales->byDay($filters->scope());
        $previous = $compare === null ? [] : $this->sales->byDay($compare);
        $points = [];

        foreach ($current as $i => $day) {
            $before = $previous[$i] ?? null;
            $points[] = [
                'day' => $day->day,
                'net' => $day->net,
                'gross' => $day->gross,
                'transactions' => $day->transactions,
                'compareDay' => $before?->day,
                'compareNet' => $before?->net,
                'compareGross' => $before?->gross,
                'compareTransactions' => $before?->transactions,
            ];
        }

        return $points;
    }

    /**
     * Hours 0–23 summed over the range (the trading pattern), against the compare window. Today stops at the
     * current hour; its compare day is shown whole for context.
     *
     * @return list<array<string, mixed>>
     */
    public function hourly(TradingFilters $filters): array
    {
        $compare = $filters->compareScope();
        $current = $this->sales->byHour($filters->scope(), $filters->isToday() ? $filters->hour : null);
        $previous = $compare === null ? [] : $this->sales->byHour($compare);
        $byHour = [];

        foreach ($previous as $hour) {
            $byHour[$hour->hour] = $hour;
        }

        $points = [];

        for ($hour = 0; $hour <= 23; $hour++) {
            $now = $current[$hour] ?? null;
            $before = $byHour[$hour] ?? null;
            $points[] = [
                'hour' => $hour,
                'net' => $now?->net,
                'gross' => $now?->gross,
                'transactions' => $now?->transactions,
                'compareNet' => $before?->net,
                'compareTransactions' => $before?->transactions,
            ];
        }

        return $points;
    }

    /**
     * Sparkline values (oldest first): per day, or per hour for a single day.
     *
     * @param  list<array<string, mixed>>|null  $daily
     * @param  list<array<string, mixed>>  $hourly
     * @return array{net: list<float>, gross: list<float>, transactions: list<int>}
     */
    public static function sparklines(?array $daily, array $hourly): array
    {
        $points = $daily;

        if ($points === null) {
            // One day: its hours so far, from the first hour with a sale (no flat line through the night).
            $points = array_values(array_filter($hourly, fn (array $p) => $p['net'] !== null));

            while ($points !== [] && (int) ($points[0]['transactions'] ?? 0) === 0 && (float) $points[0]['net'] === 0.0) {
                array_shift($points);
            }
        }

        return [
            'net' => array_map(fn (array $p) => (float) ($p['net'] ?? 0), $points),
            'gross' => array_map(fn (array $p) => (float) ($p['gross'] ?? 0), $points),
            'transactions' => array_map(fn (array $p) => (int) ($p['transactions'] ?? 0), $points),
        ];
    }
}
