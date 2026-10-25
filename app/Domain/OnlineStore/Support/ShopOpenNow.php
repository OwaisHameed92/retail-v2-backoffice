<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStoreShop;
use Carbon\CarbonImmutable;

/**
 * "Open now · until 20:00" for a collection shop (OS-8, audit OSA-19), from its collection hours in shop time:
 * open now, opening later today, or the next day it opens this week. OS-12: a delivery-only shop (collection off)
 * says the same from its delivery hours ("Delivering now · until 20:00", "Delivers today from 10:00").
 *
 *     ShopOpenNow::describe($shop); // ['open' => true, 'label' => 'Open now · until 20:00']
 */
final class ShopOpenNow
{
    /**
     * @return array{open: bool, label: string}
     */
    public static function describe(OnlineStoreShop $shop, ?CarbonImmutable $now = null): array
    {
        $delivery = ! $shop->collects() && $shop->delivers();
        $hoursOn = fn (string $day): ?array => $delivery ? $shop->deliveryHoursOn($day) : $shop->hoursOn($day);
        $now = ($now ?? CarbonImmutable::now())->setTimezone(OnlineStoreSwitch::timezone());
        $time = $now->format('H:i');
        $today = $hoursOn(strtolower($now->format('l')));

        if ($today !== null && $time >= $today['open'] && $time < $today['close']) {
            return ['open' => true, 'label' => ($delivery ? 'Delivering now' : 'Open now')." · until {$today['close']}"];
        }

        if ($today !== null && $time < $today['open']) {
            return ['open' => false, 'label' => $delivery ? "Delivers today from {$today['open']}" : "Opens today at {$today['open']}"];
        }

        for ($i = 1; $i <= 7; $i++) {
            $day = $now->addDays($i);
            $hours = $hoursOn(strtolower($day->format('l')));

            if ($hours !== null) {
                $when = $i === 1 ? 'tomorrow' : $day->format('l');

                return ['open' => false, 'label' => $delivery ? "No deliveries now · next {$when} from {$hours['open']}" : "Closed now · opens {$when} at {$hours['open']}"];
            }
        }

        return ['open' => false, 'label' => $delivery ? 'No deliveries this week' : 'Closed for collections'];
    }
}
