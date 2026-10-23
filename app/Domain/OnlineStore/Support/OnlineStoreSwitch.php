<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Models\Company;

/**
 * Whether this instance runs online stores (docs/online-store.md): the `ONLINE_STORE` switch (config
 * `online_store.enabled`, default off) and the country flag `onlineStore` (GB on, PK off). Off: no store is found by
 * its address (FindStoreBySlug), none can go live or take an order, and the role matrix hides `store.manage`.
 *
 * OS-6: a business's store is usable only with `availableFor()`: the switch above, the business's add-on switched on
 * by an admin (OnlineStoreAddOn), cloud sync on one of its tills and a monthly plan. Otherwise its portal pages and
 * public store 404.
 */
final class OnlineStoreSwitch
{
    public const FEATURE = 'onlineStore';

    public static function enabled(): bool
    {
        return (bool) config('online_store.enabled', false) && app(Country::class)->feature(self::FEATURE);
    }

    /**
     * This business may use its online store now (portal pages, menu items, public store): the switch is on, its
     * add-on is on, it has cloud sync and it is on a monthly (recurring) plan, so the add-on is billed. `$store` saves a
     * read when the caller already has the row.
     */
    public static function availableFor(Company $company, ?OnlineStore $store = null): bool
    {
        return self::enabled() && OnlineStoreAddOn::isOn($company, $store) && OnlineStoreAddOn::hasCloudSync($company)
            && OnlineStoreAddOn::onRecurringPlan($company);
    }

    /** The add-on's monthly price before VAT: the business's own, else the default. */
    public static function monthlyPrice(?string $override): string
    {
        return $override ?? (string) config('online_store.default_monthly_price', '20.00');
    }

    /** The timezone shops' hours and collection slots are in (Europe/London on GB). */
    public static function timezone(): string
    {
        return app(Country::class)->timezone();
    }
}
