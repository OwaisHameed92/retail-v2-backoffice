<?php

namespace App\Domain\Calendar\Queries;

use App\Domain\Calendar\Data\CalendarFilters;
use App\Domain\Calendar\Models\ShopOpeningHour;
use App\Domain\Calendar\Support\BusinessHoursLine;
use App\Domain\Calendar\Support\WeeklyHours;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchHoursOverride;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\TillHealth\Support\HealthThresholds;
use Illuminate\Support\Collection;

/**
 * The opening hours screen (module 5.9): each shop's week (portal-kept), the one `shop.trading_hours` line the tills
 * show for the business (company scope, the first shop's week: BusinessHoursLine) and whether the tills still have
 * it, and the next special days the till holds. A one-shop user sees their shop only.
 */
final class OpeningHoursPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(CalendarFilters $filters): array
    {
        $shops = Branch::query()->when($filters->shopLocked, fn ($q) => $q->whereKey($filters->shop))->orderBy('name')->get(['id', 'name', 'code']);
        $ids = $shops->pluck('id')->all();
        $weeks = ShopOpeningHour::query()->whereIn('branch_id', $ids)->get()->groupBy('branch_id');
        $texts = TillSetting::query()->where('setting_key', 'shop.trading_hours')->where('scope', 'branch')->whereIn('scope_id', $ids)->pluck('value', 'scope_id');
        $everyShop = TillSetting::query()->where('setting_key', 'shop.trading_hours')->where('scope', 'company')->value('value');
        $line = BusinessHoursLine::current();
        $special = BranchHoursOverride::query()->whereIn('branch_id', $ids)->where('date', '>=', $filters->today)
            ->orderBy('date')->get()->groupBy('branch_id');
        $thresholds = HealthThresholds::fromConfig();

        return [
            'shops' => $shops->map(function (Branch $shop) use ($weeks, $texts, $everyShop, $special, $line) {
                /** @var Collection<int, ShopOpeningHour>|null $rows */
                $rows = $weeks->get($shop->id);
                $week = $rows === null ? null : self::week($rows);
                $tillText = $texts->get($shop->id) ?? $everyShop;

                return [
                    'id' => (string) $shop->id,
                    'name' => (string) $shop->name,
                    'days' => $week === null ? null : self::days($week),
                    'tillText' => $tillText,
                    'tillTextMatches' => $week === null || $line['text'] === null || $tillText === $line['text'],
                    'specialDays' => ($special->get($shop->id) ?? collect())->take(4)->map(fn (BranchHoursOverride $o) => SpecialDays::row($o, null))->values()->all(),
                ];
            })->values()->all(),
            'defaults' => ['opens' => $thresholds->tradingStart, 'closes' => $thresholds->tradingEnd],
            'businessLine' => [...$line, 'max' => BusinessHoursLine::MAX],
            'filters' => $filters->toArray(),
        ];
    }

    /**
     * @param  Collection<int, ShopOpeningHour>  $rows
     */
    public static function week(Collection $rows): WeeklyHours
    {
        $days = [];

        foreach ($rows as $row) {
            $days[$row->weekday] = $row->is_closed ? null : ['opens' => (string) $row->opens_at, 'closes' => (string) $row->closes_at];
        }

        return new WeeklyHours($days);
    }

    /**
     * @return list<array{weekday: int, name: string, closed: bool, opens: string|null, closes: string|null}>
     */
    public static function days(WeeklyHours $week): array
    {
        $out = [];

        foreach (WeeklyHours::DAY_NAMES as $weekday => $name) {
            $day = $week->days[$weekday] ?? null;
            $out[] = ['weekday' => $weekday, 'name' => $name, 'closed' => $day === null, 'opens' => $day['opens'] ?? null, 'closes' => $day['closes'] ?? null];
        }

        return $out;
    }
}
