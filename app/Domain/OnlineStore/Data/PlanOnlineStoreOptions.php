<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\PlanOnlineStore;
use App\Domain\Plans\Models\Plan;

/**
 * OS-7: what the admin screens need to offer plans with the online store: the active plans that include it (with the
 * add-on's monthly price before VAT), the templates and whether online stores are switched on on this server. Null
 * where the country has no online stores (Pakistan): the screens show nothing about it.
 */
final class PlanOnlineStoreOptions
{
    /**
     * @return array{plans: array<string, array{price: string, priceLabel: string, onlineOnly?: bool}>, templates: list<array{value: string, label: string}>, serverOn: bool, defaultPrice: string}|null
     */
    public static function get(): ?array
    {
        if (! PlanOnlineStore::offered()) {
            return null;
        }

        $plans = [];

        foreach (Plan::query()->where('is_active', true)->where('includes_online_store', true)->ordered()->get() as $plan) {
            $summary = PlanOnlineStore::summary($plan);

            if ($summary !== null) {
                // OS-13: an online store only plan is marked (no tills: the form asks for none).
                $plans[$plan->id] = $summary + ($plan->isOnlineStoreOnly() ? ['onlineOnly' => true] : []);
            }
        }

        return [
            'plans' => $plans,
            'templates' => OnlineStoreAddOnInput::templateOptions(),
            'serverOn' => OnlineStoreSwitch::enabled(),
            'defaultPrice' => PlanOnlineStore::price(null),
        ];
    }
}
