<?php

namespace App\Domain\Salon\Availability;

use Carbon\CarbonImmutable;

/**
 * The start times the booking page offers (W3a, salon pack README §10, model "a"). Only from the ranges the till
 * published; the portal never works out availability itself.
 *
 * - A range "10:00-11:30" = every `intervalMin` from 10:00 to 11:30 inclusive, shop time.
 * - One stylist: start `t` is offered when it is a start of the basket's first item for that stylist, and for each
 *   next item `t` + (the minutes of the items before it, for that stylist) is a start of that item for the same
 *   stylist. One option is the plain case of this.
 * - "Any stylist" (or a group of them): the union of each stylist's starts for the whole basket.
 * - A start earlier than now + `minNoticeMin` is hidden; a start in the clock-change hour is never offered.
 * - A stylist's start that overlaps a time held for an earlier pending request of theirs is hidden (README §1.3).
 */
final class FreeTimes
{
    /** @var array<string, array<string, array<string, array<int, true>>>> date => productId => userId => minute set */
    private array $grid = [];

    /** @var array<string, array{date: string, utcOffsetMinutes: int}> */
    private array $days = [];

    public function __construct(private readonly PageMenu $menu)
    {
        $interval = max(1, (int) ($menu->page['availability']['intervalMin'] ?? 15));

        foreach ($menu->page['availability']['days'] ?? [] as $day) {
            $this->days[$day['date']] = $day;

            foreach ($day['items'] as $item) {
                foreach ($item['stylists'] as $stylist) {
                    $this->grid[$day['date']][$item['productId']][$stylist['userId']] = self::expand($stylist['ranges'], $interval);
                }
            }
        }

        ksort($this->days);
    }

    /**
     * Every date with at least one start, in order.
     *
     * @param  list<array{grid: string, minutes: array<string, int>|int}>  $basket
     * @param  list<string>  $stylists  the stylists to consider (one = a named stylist)
     * @param  list<array{userId: string, from: CarbonImmutable, to: CarbonImmutable}>  $holds
     * @return list<array{date: string, times: list<string>}>
     */
    public function days(array $basket, array $stylists, CarbonImmutable $now, array $holds = []): array
    {
        $out = [];

        foreach (array_keys($this->days) as $date) {
            $times = $this->on((string) $date, $basket, $stylists, $now, $holds);

            if ($times !== []) {
                $out[] = ['date' => (string) $date, 'times' => $times];
            }
        }

        return $out;
    }

    /**
     * The starts of one date as `HH:mm`, in order.
     *
     * @param  list<array{grid: string, minutes: array<string, int>|int}>  $basket
     * @param  list<string>  $stylists
     * @param  list<array{userId: string, from: CarbonImmutable, to: CarbonImmutable}>  $holds
     * @return list<string>
     */
    public function on(string $date, array $basket, array $stylists, CarbonImmutable $now, array $holds = []): array
    {
        $starts = [];

        foreach ($this->stylistStarts($date, $basket, $stylists, $now, $holds) as $minutes) {
            $starts += $minutes;
        }

        ksort($starts);

        return array_map(fn (int $m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60), array_keys($starts));
    }

    /**
     * The stylists who can start this basket at this time (empty when nobody can).
     *
     * @param  list<array{grid: string, minutes: array<string, int>|int}>  $basket
     * @param  list<string>  $stylists
     * @param  list<array{userId: string, from: CarbonImmutable, to: CarbonImmutable}>  $holds
     * @return list<string>
     */
    public function whoCan(string $date, string $time, array $basket, array $stylists, CarbonImmutable $now, array $holds = []): array
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            return [];
        }

        $minute = (int) $m[1] * 60 + (int) $m[2];

        return array_keys(array_filter($this->stylistStarts($date, $basket, $stylists, $now, $holds), fn (array $set) => isset($set[$minute])));
    }

    /**
     * @param  list<array{grid: string, minutes: array<string, int>|int}>  $basket
     * @param  list<string>  $stylists
     * @param  list<array{userId: string, from: CarbonImmutable, to: CarbonImmutable}>  $holds
     * @return array<string, array<int, true>> userId => starts
     */
    private function stylistStarts(string $date, array $basket, array $stylists, CarbonImmutable $now, array $holds): array
    {
        if ($basket === [] || ! isset($this->grid[$date])) {
            return [];
        }

        $notBefore = $now->addMinutes(max(0, (int) $this->menu->rule('minNoticeMin')));
        $out = [];

        foreach ($stylists as $userId) {
            $total = PageMenu::totalMinutes($basket, $userId);

            if ($total === null) {
                continue;
            }

            foreach (array_keys($this->grid[$date][$basket[0]['grid']][$userId] ?? []) as $start) {
                if (! $this->fits($date, $start, $basket, $userId)) {
                    continue;
                }

                $utc = LondonClock::toUtc($date, $start);

                if ($utc === null || $utc->lessThan($notBefore) || self::held($holds, $userId, $utc, $utc->addMinutes($total))) {
                    continue;
                }

                $out[$userId][$start] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{grid: string, minutes: array<string, int>|int}>  $basket
     */
    private function fits(string $date, int $start, array $basket, string $userId): bool
    {
        $offset = 0;

        foreach ($basket as $item) {
            if (! isset($this->grid[$date][$item['grid']][$userId][$start + $offset])) {
                return false;
            }

            $offset += (int) PageMenu::minutes($item, $userId);
        }

        return true;
    }

    /**
     * @param  list<array{userId: string, from: CarbonImmutable, to: CarbonImmutable}>  $holds
     */
    private static function held(array $holds, string $userId, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        foreach ($holds as $hold) {
            if ($hold['userId'] === $userId && $from->lessThan($hold['to']) && $hold['from']->lessThan($to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $ranges
     * @return array<int, true>
     */
    private static function expand(array $ranges, int $interval): array
    {
        $set = [];

        foreach ($ranges as $range) {
            if (preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $range, $m) !== 1) {
                continue;
            }

            for ($t = (int) $m[1] * 60 + (int) $m[2], $end = (int) $m[3] * 60 + (int) $m[4]; $t <= $end; $t += $interval) {
                $set[$t] = true;
            }
        }

        return $set;
    }
}
