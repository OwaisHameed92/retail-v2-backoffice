<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Models\OnlineStoreClosure;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use Carbon\CarbonImmutable;

/**
 * The slot settings of one shop for one method (CollectionSlots): collection uses the shop's collection hours, slot
 * length, orders per slot and minutes to get ready; delivery (OS-9) its own delivery hours, slot length, orders per
 * slot and lead time. Each method's slots fill up separately. The shop's closed days and special hours (audit OSA-13,
 * OnlineStoreClosure) apply to both methods.
 */
final readonly class SlotPlan
{
    public function __construct(
        public OnlineStoreShop $shop,
        public FulfilmentMethod $method,
        public int $slotMinutes,
        public int $leadMinutes,
        public int $capacity,
        /** @var array<string, OnlineStoreClosure> closures from yesterday on, by date */
        public array $closures = [],
    ) {}

    public static function for(OnlineStoreShop $shop, FulfilmentMethod $method = FulfilmentMethod::Collection): self
    {
        $closures = OnlineStoreClosure::withoutCompanyScope()->where('company_id', $shop->company_id)->where('online_store_shop_id', $shop->id)
            ->where('date', '>=', CarbonImmutable::now(OnlineStoreSwitch::timezone())->subDay()->format('Y-m-d'))
            ->get()->keyBy(fn (OnlineStoreClosure $c) => substr((string) $c->date, 0, 10))->all();

        return $method === FulfilmentMethod::Delivery
            ? new self($shop, $method, max(1, $shop->delivery_slot_minutes), max(0, $shop->delivery_lead_minutes), max(0, $shop->delivery_orders_per_slot), $closures)
            : new self($shop, $method, max(1, $shop->slot_minutes), max(0, $shop->prep_minutes), max(0, $shop->orders_per_slot), $closures);
    }

    /**
     * The hours of one date (shop date, Y-m-d) for this method: the weekday's, unless the shop is closed that day
     * (null) or has special hours then (those, on a day this method has slots).
     *
     * @return array{open: string, close: string}|null
     */
    public function hoursOnDate(string $date): ?array
    {
        $weekday = strtolower(CarbonImmutable::createFromFormat('!Y-m-d', $date)->englishDayOfWeek);
        $usual = $this->hoursOn($weekday);
        $closure = $this->closures[$date] ?? null;

        return $closure === null || $usual === null ? $usual : $closure->hours();
    }

    /** Why the shop is closed that date ("Christmas Day"), or null when it is not closed then. */
    public function closedReason(string $date): ?string
    {
        $closure = $this->closures[$date] ?? null;

        return $closure !== null && $closure->hours() === null ? ($closure->reason ?: 'Closed') : null;
    }

    /**
     * The hours of one weekday ("monday"…) for this method, or null when there are no slots that day.
     *
     * @return array{open: string, close: string}|null
     */
    public function hoursOn(string $weekday): ?array
    {
        if ($this->method === FulfilmentMethod::Delivery) {
            return $this->shop->delivers() ? $this->shop->deliveryHoursOn($weekday) : null;
        }

        return $this->shop->hoursOn($weekday);
    }
}
