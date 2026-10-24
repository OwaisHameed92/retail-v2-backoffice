<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\CollectionSlot;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * A collection shop's slots on one day (shop time, Europe/London on GB): from its opening time to its closing time,
 * `slot_minutes` long (the last one ends by closing), the first one starting at least `prep_minutes` from now.
 * Days before today, after `online_store.order_days_ahead`, or without hours have none. Each slot carries how many of
 * its orders are not cancelled (`taken`) against `orders_per_slot`. Slot times are built on the shop's wall clock
 * and returned in UTC (as stored), so they stay right across the clock changes.
 */
final class CollectionSlots
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @param  string  $day  the shop's date, Y-m-d
     * @return list<CollectionSlot>
     */
    public function handle(OnlineStoreShop $shop, string $day, ?CarbonImmutable $now = null): array
    {
        $tz = OnlineStoreSwitch::timezone();
        $now = ($now ?? CarbonImmutable::now())->utc();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $day, $tz) : null;

        if ($date === null || $date->format('Y-m-d') !== $day || ! $this->bookable($date, $now, $tz)) {
            return [];
        }

        $hours = $shop->hoursOn(strtolower($date->englishDayOfWeek));
        $length = max(1, $shop->slot_minutes);

        if ($hours === null) {
            return [];
        }

        $earliest = $now->addMinutes(max(0, $shop->prep_minutes));
        $starts = [];

        for ($minute = self::minutes($hours['open']); $minute + $length <= self::minutes($hours['close']); $minute += $length) {
            $from = self::at($day, $minute, $tz);

            if ($from->greaterThanOrEqualTo($earliest)) {
                $starts[$minute] = $from;
            }
        }

        if ($starts === []) {
            return [];
        }

        $taken = $this->taken($shop, array_values($starts));
        $slots = [];

        foreach ($starts as $minute => $from) {
            $to = self::at($day, $minute + $length, $tz);
            $label = $from->setTimezone($tz)->format('H:i').'–'.$to->setTimezone($tz)->format('H:i');
            $slots[] = new CollectionSlot($from, $to, $label, $taken[$from->format('Y-m-d H:i:s')] ?? 0, max(0, $shop->orders_per_slot));
        }

        return $slots;
    }

    /** The slot of this shop that starts at `$from` (any timezone), or null when there is none at that time. */
    public function find(OnlineStoreShop $shop, CarbonImmutable $from, ?CarbonImmutable $now = null): ?CollectionSlot
    {
        $day = $from->setTimezone(OnlineStoreSwitch::timezone())->format('Y-m-d');

        foreach ($this->handle($shop, $day, $now) as $slot) {
            if ($slot->from->equalTo($from)) {
                return $slot;
            }
        }

        return null;
    }

    /** Today (shop time) up to `order_days_ahead` days, today included. */
    private function bookable(CarbonImmutable $date, CarbonImmutable $now, string $tz): bool
    {
        $today = $now->setTimezone($tz)->startOfDay();
        $days = max(1, (int) config('online_store.order_days_ahead', 7));

        return $date->greaterThanOrEqualTo($today) && $date->lessThan($today->addDays($days));
    }

    /** @var array<string, Company> */
    private array $companies = [];

    /**
     * Orders that are not cancelled per slot start (UTC "Y-m-d H:i:s").
     *
     * @param  list<CarbonImmutable>  $starts
     * @return array<string, int>
     */
    private function taken(OnlineStoreShop $shop, array $starts): array
    {
        // Audit OSA-28: the business is read once per request, not once per day asked.
        $company = $this->companies[$shop->company_id] ??= Company::query()->findOrFail($shop->company_id);

        return $this->tenancy->runAs($company, fn (): array => OnlineOrder::query()
            ->where('company_id', $shop->company_id)
            ->where('online_store_shop_id', $shop->id)
            ->where('status', '!=', OnlineOrderStatus::Cancelled->value)
            ->whereIn('collect_from', array_map(fn (CarbonImmutable $s) => $s->format('Y-m-d H:i:s'), $starts))
            ->selectRaw('collect_from, COUNT(*) as aggregate')->groupBy('collect_from')
            ->toBase()->get()
            ->mapWithKeys(fn (object $row) => [CarbonImmutable::parse((string) $row->collect_from, 'UTC')->format('Y-m-d H:i:s') => (int) $row->aggregate])
            ->all());
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }

    private static function at(string $day, int $minute, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse(sprintf('%s %02d:%02d:00', $day, intdiv($minute, 60), $minute % 60), $tz)->utc();
    }
}
