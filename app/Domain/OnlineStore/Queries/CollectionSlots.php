<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\CollectionSlot;
use App\Domain\OnlineStore\Data\SlotPlan;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
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
 *
 * OS-9: delivery slots work the same from the shop's delivery hours, slot length, orders per slot and lead time
 * (SlotPlan); each method counts only its own orders. An order taken counts unless it is cancelled or its card
 * payment failed; an unpaid card order counts only while its hold lasts (`payment_expires_at` after now).
 */
final class CollectionSlots
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @param  string  $day  the shop's date, Y-m-d
     * @return list<CollectionSlot>
     */
    public function handle(OnlineStoreShop $shop, string $day, ?CarbonImmutable $now = null, FulfilmentMethod $method = FulfilmentMethod::Collection): array
    {
        $plan = SlotPlan::for($shop, $method);
        $tz = OnlineStoreSwitch::timezone();
        $now = ($now ?? CarbonImmutable::now())->utc();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $day, $tz) : null;

        if ($date === null || $date->format('Y-m-d') !== $day || ! $this->bookable($date, $now, $tz)) {
            return [];
        }

        $hours = $plan->hoursOnDate($day);
        $length = $plan->slotMinutes;

        if ($hours === null) {
            return [];
        }

        $earliest = $now->addMinutes($plan->leadMinutes);
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

        $taken = $this->taken($shop, $method, array_values($starts), $now);
        $slots = [];

        foreach ($starts as $minute => $from) {
            $to = self::at($day, $minute + $length, $tz);
            $label = $from->setTimezone($tz)->format('H:i').'–'.$to->setTimezone($tz)->format('H:i');
            $slots[] = new CollectionSlot($from, $to, $label, $taken[$from->format('Y-m-d H:i:s')] ?? 0, $plan->capacity);
        }

        return $slots;
    }

    /** The slot of this shop that starts at `$from` (any timezone), or null when there is none at that time. */
    public function find(OnlineStoreShop $shop, CarbonImmutable $from, ?CarbonImmutable $now = null, FulfilmentMethod $method = FulfilmentMethod::Collection): ?CollectionSlot
    {
        $day = $from->setTimezone(OnlineStoreSwitch::timezone())->format('Y-m-d');

        foreach ($this->handle($shop, $day, $now, $method) as $slot) {
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

    /**
     * The method's orders that hold a place per slot start (UTC "Y-m-d H:i:s").
     *
     * @param  list<CarbonImmutable>  $starts
     * @return array<string, int>
     */
    private function taken(OnlineStoreShop $shop, FulfilmentMethod $method, array $starts, CarbonImmutable $now): array
    {
        $company = Company::query()->findOrFail($shop->company_id);

        return $this->tenancy->runAs($company, fn (): array => OnlineOrder::query()
            ->where('company_id', $shop->company_id)
            ->where('online_store_shop_id', $shop->id)
            ->where('fulfilment', $method->value)
            ->whereNotIn('status', [OnlineOrderStatus::Cancelled->value, OnlineOrderStatus::PaymentFailed->value])
            ->where(fn ($q) => $q->where('status', '!=', OnlineOrderStatus::AwaitingPayment->value)
                ->orWhere('payment_expires_at', '>', $now->format('Y-m-d H:i:s')))
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
