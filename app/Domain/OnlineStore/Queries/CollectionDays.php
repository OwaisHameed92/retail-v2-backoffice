<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\CollectionSlot;
use App\Domain\OnlineStore\Data\SlotPlan;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use Carbon\CarbonImmutable;

/**
 * The days a customer can pick at checkout (OS-2), from today (shop time) for `online_store.order_days_ahead`
 * days, each with whether the shop hands out orders that day, and the first slot with room (CollectionSlots).
 * OS-9: for delivery, the days it delivers and the first delivery slot with room.
 */
final class CollectionDays
{
    public function __construct(private readonly CollectionSlots $slots) {}

    /**
     * @return list<array{date: string, label: string, short: string, open: bool, closedReason: string|null}>
     */
    public function days(OnlineStoreShop $shop, ?CarbonImmutable $now = null, FulfilmentMethod $method = FulfilmentMethod::Collection): array
    {
        $plan = SlotPlan::for($shop, $method);
        $tz = OnlineStoreSwitch::timezone();
        $today = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();
        $days = [];

        for ($i = 0; $i < max(1, (int) config('online_store.order_days_ahead', 7)); $i++) {
            $date = $today->addDays($i);
            $days[] = [
                'date' => $date->format('Y-m-d'),
                'label' => match ($i) {
                    0 => 'Today',
                    1 => 'Tomorrow',
                    default => $date->format('l'),
                },
                'short' => $date->format('j M'),
                'open' => $plan->hoursOnDate($date->format('Y-m-d')) !== null,
                'closedReason' => $plan->closedReason($date->format('Y-m-d')),
            ];
        }

        return $days;
    }

    /** The first slot with room in the bookable days, or null. */
    public function next(OnlineStoreShop $shop, ?CarbonImmutable $now = null, FulfilmentMethod $method = FulfilmentMethod::Collection): ?CollectionSlot
    {
        foreach ($this->days($shop, $now, $method) as $day) {
            if (! $day['open']) {
                continue;
            }

            foreach ($this->slots->handle($shop, $day['date'], $now, $method) as $slot) {
                if ($slot->available()) {
                    return $slot;
                }
            }
        }

        return null;
    }

    /** "Today 10:00–10:30", "Tomorrow 09:00–09:30", "Friday 12:00–12:30"; further away or past: "Friday 14 Nov 12:00–12:30". */
    public static function describe(CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $now = null): string
    {
        $tz = OnlineStoreSwitch::timezone();
        $from = $from->setTimezone($tz);
        $today = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();
        $days = (int) round($today->diffInDays($from->startOfDay(), false));

        $day = match (true) {
            $days === 0 => 'Today',
            $days === 1 => 'Tomorrow',
            $days > 1 && $days < 7 => $from->format('l'),
            default => $from->format('l j M'),
        };

        return $day.' '.$from->format('H:i').'–'.$to->setTimezone($tz)->format('H:i');
    }
}
