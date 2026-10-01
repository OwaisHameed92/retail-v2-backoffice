<?php

namespace App\Domain\Calendar\Support;

use Carbon\CarbonImmutable;

/**
 * A shop's opening hours (module 5.9): one entry per ISO weekday (1 = Monday) and the till's special days
 * (`BranchHoursOverride`) by local date. `null` = closed. Times are local "HH:MM"; a closing time at or before the
 * opening time runs past midnight ("00:00"–"00:00" is open 24 hours). Used by Till health (2.7) for "trading hours"
 * and to write the `shop.trading_hours` setting text.
 *
 *     $hours->window(CarbonImmutable::parse('2026-12-25', 'Europe/London'), 'Europe/London'); // null: closed
 */
final readonly class WeeklyHours
{
    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param  array<int, array{opens: string, closes: string}|null>  $days  weekday => hours or null (closed)
     * @param  array<string, array{opens: string, closes: string}|null>  $specialDays  "Y-m-d" => hours or null (closed)
     */
    public function __construct(public array $days, public array $specialDays = []) {}

    /** The same hours every day (Till health's default until a shop's hours are set). */
    public static function everyDay(string $opens, string $closes): self
    {
        return new self(array_fill_keys(array_keys(self::DAY_NAMES), ['opens' => $opens, 'closes' => $closes]));
    }

    /**
     * @param  array<string, array{opens: string, closes: string}|null>  $specialDays
     */
    public function withSpecialDays(array $specialDays): self
    {
        return new self($this->days, $specialDays);
    }

    /**
     * The hours of one local date: a special day wins over the weekday.
     *
     * @return array{opens: string, closes: string}|null
     */
    public function forDate(CarbonImmutable $localDay): ?array
    {
        $date = $localDay->format('Y-m-d');

        return array_key_exists($date, $this->specialDays) ? $this->specialDays[$date] : ($this->days[$localDay->dayOfWeekIso] ?? null);
    }

    /**
     * The open window [start, end) of a local date in `$timezone`, or null when closed that day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function window(CarbonImmutable $localDay, string $timezone): ?array
    {
        $hours = $this->forDate($localDay);

        if ($hours === null) {
            return null;
        }

        $date = $localDay->format('Y-m-d');
        $start = CarbonImmutable::parse("{$date} {$hours['opens']}", $timezone);
        $end = CarbonImmutable::parse("{$date} {$hours['closes']}", $timezone);

        return [$start, $end->greaterThan($start) ? $end : CarbonImmutable::parse($localDay->addDay()->format('Y-m-d').' '.$hours['closes'], $timezone)];
    }

    /**
     * The week as the `shop.trading_hours` setting text (ANSWERS-2026-10-01 §3: free text, not parsed by the till, at
     * most 200 characters): one line, "Mon 07:00-22:00, Tue 07:00-22:00, … Sun Closed" (seven full days ≈ 117).
     */
    public function text(): string
    {
        $lines = [];

        foreach (self::DAY_NAMES as $weekday => $name) {
            $day = $this->days[$weekday] ?? null;
            $lines[] = $name.' '.($day === null ? 'Closed' : $day['opens'].'-'.$day['closes']);
        }

        return implode(', ', $lines);
    }
}
