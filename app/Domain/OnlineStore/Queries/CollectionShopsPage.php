<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\Calendar\Models\ShopOpeningHour;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Collection;

/**
 * The portal's collection shops page (OS-3, `app/online-store/shops`): every open shop of the business with its
 * click & collect settings (SaveStoreShop). A shop not set up yet starts from its opening hours on the Calendar page
 * (module 5.9), else Monday to Saturday 09:00–17:00. A one-shop user sees their own shop only.
 */
final class CollectionShopsPage
{
    public const DEFAULTS = ['prepMinutes' => 30, 'slotMinutes' => 30, 'ordersPerSlot' => 4];

    /** Slot lengths offered (SaveStoreShop takes 10 to 240 in steps of 5). */
    public const SLOT_OPTIONS = [10, 15, 20, 30, 45, 60, 90, 120];

    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, ?string $onlyBranchId): array
    {
        $store = OnlineStore::query()->where('company_id', $company->id)->first();
        $branches = Branch::query()->where('company_id', $company->id)->where('is_active', true)
            ->when($onlyBranchId !== null, fn ($q) => $q->whereKey($onlyBranchId))
            ->orderBy('name')->get();
        $rows = $store === null ? collect() : OnlineStoreShop::query()->where('company_id', $company->id)->where('online_store_id', $store->id)->get()->keyBy('branch_id');
        $calendar = ShopOpeningHour::query()->where('company_id', $company->id)->whereIn('branch_id', $branches->modelKeys())->get()->groupBy('branch_id');

        return [
            'hasStore' => $store !== null,
            'shops' => $branches->map(fn (Branch $branch) => self::shop($branch, $rows->get($branch->id), $calendar->get($branch->id)))->values()->all(),
            'slotOptions' => self::SLOT_OPTIONS,
        ];
    }

    /**
     * @param  Collection<int, ShopOpeningHour>|null  $calendar
     * @return array<string, mixed>
     */
    private static function shop(Branch $branch, ?OnlineStoreShop $row, ?Collection $calendar): array
    {
        [$hours, $source] = match (true) {
            $row !== null => [array_map(fn (string $day) => $row->hoursOn($day), OnlineStoreShop::WEEKDAYS), 'saved'],
            $calendar !== null && $calendar->isNotEmpty() => [self::fromCalendar($calendar), 'calendar'],
            default => [array_map(fn (string $day) => $day === 'sunday' ? null : ['open' => '09:00', 'close' => '17:00'], OnlineStoreShop::WEEKDAYS), 'default'],
        };

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
            'town' => $branch->town,
            'saved' => $row !== null,
            'enabled' => (bool) ($row->enabled ?? false),
            'hoursSource' => $source,
            'days' => array_map(fn (string $day, ?array $h) => ['day' => $day, 'open' => $h['open'] ?? null, 'close' => $h['close'] ?? null],
                OnlineStoreShop::WEEKDAYS, $hours),
            'prepMinutes' => $row->prep_minutes ?? self::DEFAULTS['prepMinutes'],
            'slotMinutes' => $row->slot_minutes ?? self::DEFAULTS['slotMinutes'],
            'ordersPerSlot' => $row->orders_per_slot ?? self::DEFAULTS['ordersPerSlot'],
        ];
    }

    /**
     * The Calendar week as collection hours (a shop open past midnight hands out orders until 23:59).
     *
     * @param  Collection<int, ShopOpeningHour>  $calendar
     * @return list<array{open: string, close: string}|null>
     */
    private static function fromCalendar(Collection $calendar): array
    {
        $byDay = $calendar->keyBy('weekday');

        return array_map(function (int $weekday) use ($byDay): ?array {
            $day = $byDay->get($weekday);

            if ($day === null || $day->is_closed || $day->opens_at === null || $day->closes_at === null) {
                return null;
            }

            $open = substr($day->opens_at, 0, 5);
            $close = substr($day->closes_at, 0, 5);

            return ['open' => $open, 'close' => strcmp($close, $open) > 0 ? $close : '23:59'];
        }, range(1, 7));
    }
}
