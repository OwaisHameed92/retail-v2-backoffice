<?php

namespace App\Domain\Calendar\Queries;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\Reporting\Data\DaySales;
use App\Domain\Reporting\Data\LineGroupSales;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Queries\ProductReport;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\SeasonalEvent;
use App\Domain\TillData\Models\SeasonalEventUplift;
use Carbon\CarbonImmutable;

/**
 * "Compare with last year's event" (module 5.9), from the `rpt_*` tables only. Last year's window is the same shop's
 * event of the same name that started within 60 days of a year earlier (Easter and Ramadan move), else the same dates
 * a year earlier. An event on now is compared day for day so far (both windows cut to the same length); an upcoming
 * one shows last year's figures only. The event's shop, or every shop when asked (not for a one-shop user). Department
 * sales sit beside the till's own uplift for that department (SeasonalEventUplift).
 */
final class EventComparison
{
    private const MOVE_DAYS = 60;

    /**
     * @return array<string, mixed>
     */
    public static function for(SeasonalEvent $event, bool $everyShop, string $today): array
    {
        $starts = $event->starts_on;
        $length = max(0, (int) $starts->diffInDays($event->ends_on));
        [$lastStarts, $basis, $lastEvent] = self::lastYear($event);
        $status = SeasonalEventList::row($event, $today)['status'];
        $soFar = $status === 'onNow' ? (int) $starts->diffInDays(CarbonImmutable::parse($today)) : $length;
        $branchIds = $everyShop ? null : [(string) $event->branch_id];

        $current = ReportScope::tenant($starts, $starts->addDays($soFar), $branchIds);
        $previous = ReportScope::tenant($lastStarts, $lastStarts->addDays($soFar), $branchIds);
        $sales = app(SalesReport::class);
        $comparison = $sales->compare($current, $previous);
        $days = self::days($status === 'upcoming' ? [] : $sales->byDay($current), $sales->byDay($previous), $starts, $lastStarts, $soFar);
        $shops = CashLookup::shops([$event->branch_id]);

        return [
            'event' => [...SeasonalEventList::row($event, $today), 'shop' => CashLookup::name($shops, $event->branch_id)],
            'everyShop' => $everyShop,
            'basis' => $basis,
            'lastYear' => [
                'name' => $lastEvent?->name,
                'from' => $previous->from->format('Y-m-d'),
                'to' => $previous->to->format('Y-m-d'),
                'eventTo' => $lastStarts->addDays($lastEvent === null ? $length : (int) $lastEvent->starts_on->diffInDays($lastEvent->ends_on))->format('Y-m-d'),
            ],
            'thisYear' => ['from' => $current->from->format('Y-m-d'), 'to' => $current->to->format('Y-m-d')],
            'daysCompared' => $soFar + 1,
            'totals' => [
                'gross' => self::pair($comparison->current->gross, $comparison->previous->gross, $comparison->changePercent('gross')),
                'net' => self::pair($comparison->current->net, $comparison->previous->net, $comparison->changePercent('net')),
                'transactions' => self::pair($comparison->current->transactions, $comparison->previous->transactions, $comparison->changePercent('transactions')),
                'basket' => self::pair($comparison->current->averageBasketIncVat(), $comparison->previous->averageBasketIncVat(), null),
            ],
            'days' => $days,
            'departments' => self::departments($event, $current, $previous, $status === 'upcoming'),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: 'lastYearEvent'|'sameDates', 2: SeasonalEvent|null}
     */
    private static function lastYear(SeasonalEvent $event): array
    {
        $yearAgo = $event->starts_on->subYearNoOverflow();
        $match = SeasonalEvent::query()
            ->where('branch_id', $event->branch_id)
            ->whereKeyNot($event->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $event->name))])
            ->whereBetween('starts_on', [$yearAgo->subDays(self::MOVE_DAYS)->format('Y-m-d'), $yearAgo->addDays(self::MOVE_DAYS)->format('Y-m-d')])
            ->get()
            ->sortBy(fn (SeasonalEvent $e) => abs($e->starts_on->diffInDays($yearAgo)))
            ->first();

        return $match === null ? [$yearAgo, 'sameDates', null] : [$match->starts_on, 'lastYearEvent', $match];
    }

    /**
     * Day 1, 2, … of each window side by side.
     *
     * @param  list<DaySales>  $current
     * @param  list<DaySales>  $previous
     * @return list<array{day: int, date: string, lastYearDate: string, gross: string|null, lastYearGross: string}>
     */
    private static function days(array $current, array $previous, CarbonImmutable $starts, CarbonImmutable $lastStarts, int $soFar): array
    {
        $out = [];

        for ($i = 0; $i <= $soFar; $i++) {
            $out[] = [
                'day' => $i + 1,
                'date' => $starts->addDays($i)->format('Y-m-d'),
                'lastYearDate' => $lastStarts->addDays($i)->format('Y-m-d'),
                'gross' => isset($current[$i]) ? $current[$i]->gross : null,
                'lastYearGross' => isset($previous[$i]) ? $previous[$i]->gross : '0.00',
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string|null, name: string, net: string, lastYearNet: string, change: string|null, tillUplift: string|null, learned: bool}>
     */
    private static function departments(SeasonalEvent $event, ReportScope $current, ReportScope $previous, bool $upcoming): array
    {
        $products = app(ProductReport::class);
        $now = $upcoming ? [] : collect($products->byDepartment($current))->keyBy(fn (LineGroupSales $g) => (string) $g->id)->all();
        $before = collect($products->byDepartment($previous))->keyBy(fn (LineGroupSales $g) => (string) $g->id)->all();
        $uplifts = SeasonalEventUplift::query()->where('seasonal_event_id', $event->id)->get()->keyBy('department_id');
        $rows = [];

        foreach (array_unique([...array_keys($now), ...array_keys($before)]) as $id) {
            $a = $now[$id] ?? null;
            $b = $before[$id] ?? null;
            $uplift = $uplifts->get($id);
            $rows[] = [
                'id' => $id === '' ? null : (string) $id,
                'name' => $a->name ?? $b->name ?? 'Unassigned',
                'net' => $a->net ?? '0.00',
                'lastYearNet' => $b->net ?? '0.00',
                'change' => $upcoming ? null : self::change($a->net ?? '0', $b->net ?? '0'),
                'tillUplift' => $uplift === null ? null : Money::round(Money::parse($uplift->uplift_percent), 1),
                'learned' => (bool) $uplift?->is_learned,
            ];
        }

        usort($rows, fn (array $x, array $y) => Money::compare(Money::add($y['net'], $y['lastYearNet']), Money::add($x['net'], $x['lastYearNet'])));

        return array_slice($rows, 0, 12);
    }

    /**
     * @return array{current: string|int|null, previous: string|int|null, change: string|null}
     */
    private static function pair(string|int|null $current, string|int|null $previous, ?string $change): array
    {
        if ($change === null && $current !== null && $previous !== null && ! is_int($current)) {
            $change = self::change($current, (string) $previous);
        }

        return ['current' => $current, 'previous' => $previous, 'change' => $change];
    }

    private static function change(string $now, string $before): ?string
    {
        if (Money::isZero($before)) {
            return null;
        }

        return Money::round(bcdiv(bcmul(bcsub(Money::parse($now), Money::parse($before), 12), '100', 12), Money::parse($before), 12), 1);
    }
}
