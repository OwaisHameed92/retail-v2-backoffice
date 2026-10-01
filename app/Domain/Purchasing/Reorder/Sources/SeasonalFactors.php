<?php

namespace App\Domain\Purchasing\Reorder\Sources;

use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Seasonal events of one shop that overlap the days an order has to cover (module 6.4), with a multiplier per product:
 *
 * 1. last year's uplift for the product itself: its units a day during last year's event ÷ its units a day over the
 *    `event_baseline_days` before it (at least `event_min_baseline_units` in the baseline). Last year's event is the
 *    shop's event of the same name starting within 60 days of a year earlier, else the same dates (as 5.9);
 * 2. else the till's own uplift for the product's department on this event (`SeasonalEventUplift.upliftPercent`);
 * 3. else none (the product is not affected).
 *
 * Multipliers are kept between `event_factor_min` and `event_factor_max`.
 *
 * @phpstan-type Event array{name: string, from: string, to: string, factor: string, basis: 'lastYear'|'department'}
 */
final class SeasonalFactors
{
    private const MOVE_DAYS = 60;

    /**
     * @param  array<string, string|null>  $departments  product id → department id
     * @return array<string, list<Event>> product id → events
     */
    public static function forShop(string $companyId, string $shopId, array $departments, CarbonImmutable $today, int $horizon): array
    {
        $last = $today->addDays(max(1, $horizon) - 1)->toDateString();
        $events = DB::table('seasonal_events')->where('company_id', $companyId)->where('branch_id', $shopId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->where('starts_on', '<=', $last)->where('ends_on', '>=', $today->toDateString())
            ->orderBy('starts_on')->limit(10)->get(['id', 'name', 'starts_on', 'ends_on']);

        $out = [];

        foreach ($events as $event) {
            $starts = CarbonImmutable::parse(substr((string) $event->starts_on, 0, 10));
            $ends = CarbonImmutable::parse(substr((string) $event->ends_on, 0, 10));
            $name = trim((string) $event->name) !== '' ? trim((string) $event->name) : 'Seasonal event';
            $from = $starts->max($today)->toDateString();
            $to = $ends->toDateString();
            $productFactors = self::lastYear($companyId, $shopId, (string) $event->id, $name, $starts, $ends, array_keys($departments));
            $uplifts = DB::table('seasonal_event_uplifts')->where('company_id', $companyId)->where('seasonal_event_id', $event->id)
                ->whereNull('deleted_at')->whereNotNull('department_id')->pluck('uplift_percent', 'department_id');

            foreach ($departments as $product => $department) {
                if (isset($productFactors[$product])) {
                    $out[$product][] = ['name' => $name, 'from' => $from, 'to' => $to, 'factor' => $productFactors[$product], 'basis' => 'lastYear'];
                } elseif ($department !== null && isset($uplifts[$department])) {
                    $factor = self::clamp(bcadd('1', bcdiv(Money::parse($uplifts[$department]), '100', 6), 6));
                    $out[$product][] = ['name' => $name, 'from' => $from, 'to' => $to, 'factor' => $factor, 'basis' => 'department'];
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, string> product id → multiplier from last year's sales
     */
    private static function lastYear(string $companyId, string $shopId, string $eventId, string $name, CarbonImmutable $starts, CarbonImmutable $ends, array $productIds): array
    {
        $yearAgo = $starts->subYearNoOverflow();
        $match = DB::table('seasonal_events')->where('company_id', $companyId)->where('branch_id', $shopId)->whereNull('deleted_at')
            ->where('id', '!=', $eventId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->whereBetween('starts_on', [$yearAgo->subDays(self::MOVE_DAYS)->toDateString(), $yearAgo->addDays(self::MOVE_DAYS)->toDateString()])
            ->get(['starts_on', 'ends_on'])
            ->sortBy(fn (object $e) => abs(CarbonImmutable::parse(substr((string) $e->starts_on, 0, 10))->diffInDays($yearAgo)))->first();

        $lastStarts = $match !== null ? CarbonImmutable::parse(substr((string) $match->starts_on, 0, 10)) : $yearAgo;
        $lastEnds = $match !== null ? CarbonImmutable::parse(substr((string) $match->ends_on, 0, 10)) : $ends->subYearNoOverflow();
        $eventDays = max(1, (int) $lastStarts->diffInDays($lastEnds) + 1);
        $baselineDays = max(7, (int) config('reorder.event_baseline_days', 28));
        $baselineFrom = $lastStarts->subDays($baselineDays);
        $minimum = (string) config('reorder.event_min_baseline_units', 4);
        [$event, $baseline] = [[], []];

        foreach (array_chunk($productIds, 500) as $chunk) {
            $rows = DB::table('rpt_product_daily')->where('company_id', $companyId)->where('branch_id', $shopId)->whereIn('product_id', $chunk)
                ->whereBetween('trading_day', [$baselineFrom->toDateString(), $lastEnds->toDateString()])
                ->get(['product_id', 'trading_day', 'qty', 'refund_qty']);

            foreach ($rows as $r) {
                $units = Money::sub($r->qty ?? 0, $r->refund_qty ?? 0, 4);
                $product = (string) $r->product_id;

                if (substr((string) $r->trading_day, 0, 10) < $lastStarts->toDateString()) {
                    $baseline[$product] = bcadd($baseline[$product] ?? '0', $units, 6);
                } else {
                    $event[$product] = bcadd($event[$product] ?? '0', $units, 6);
                }
            }
        }

        $factors = [];

        foreach ($baseline as $product => $units) {
            if (bccomp($units, $minimum, 6) < 0) {
                continue;
            }

            $perDayEvent = bcdiv($event[$product] ?? '0', (string) $eventDays, 6);
            $perDayBase = bcdiv($units, (string) $baselineDays, 6);
            $factors[$product] = self::clamp(bcdiv($perDayEvent, $perDayBase, 6));
        }

        return $factors;
    }

    private static function clamp(string $factor): string
    {
        $min = (string) config('reorder.event_factor_min', '0.5');
        $max = (string) config('reorder.event_factor_max', '4');

        return bcadd(match (true) {
            bccomp($factor, $min, 6) < 0 => $min,
            bccomp($factor, $max, 6) > 0 => $max,
            default => $factor,
        }, '0', 4);
    }
}
