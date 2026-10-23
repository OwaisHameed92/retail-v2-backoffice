<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\Shared\Country\Country;

/**
 * Whether this instance runs online stores (docs/online-store.md): the `ONLINE_STORE` switch (config
 * `online_store.enabled`, default off) and the country flag `onlineStore` (GB on, PK off). Off: no store is found by
 * its address (FindStoreBySlug), none can go live or take an order, and the role matrix hides `store.manage`.
 */
final class OnlineStoreSwitch
{
    public const FEATURE = 'onlineStore';

    public static function enabled(): bool
    {
        return (bool) config('online_store.enabled', false) && app(Country::class)->feature(self::FEATURE);
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
