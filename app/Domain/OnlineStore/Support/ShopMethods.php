<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;

/**
 * OS-12: how a shop of the public store gets orders to customers. Collection when it is switched on for collections
 * (`enabled`); delivery when it delivers to an area (OS-9) and the store has a way to pay for a delivery
 * (PaymentOptions). A shop takes part in the store when it offers at least one of them, so a shop can be
 * delivery-only. The store's mode (collection, delivery or both) is what its shops offer together; the public pages'
 * words follow it (StoreWording).
 *
 *     ShopMethods::of($store, $shop);              // [FulfilmentMethod::Delivery]
 *     ShopMethods::mode($store, $context->shops);  // 'delivery'
 */
final class ShopMethods
{
    public const COLLECTION = 'collection';

    public const DELIVERY = 'delivery';

    public const BOTH = 'both';

    /**
     * What the shop offers now, collection first.
     *
     * @return list<FulfilmentMethod>
     */
    public static function of(OnlineStore $store, OnlineStoreShop $shop): array
    {
        $methods = [];

        if ($shop->collects()) {
            $methods[] = FulfilmentMethod::Collection;
        }

        if ($shop->delivers() && PaymentOptions::for($store, FulfilmentMethod::Delivery) !== []) {
            $methods[] = FulfilmentMethod::Delivery;
        }

        return $methods;
    }

    public static function offers(OnlineStore $store, OnlineStoreShop $shop, FulfilmentMethod $method): bool
    {
        return in_array($method, self::of($store, $shop), true);
    }

    /**
     * What the shop offers, with the store it was found for (StorefrontShops sets it); without one, delivery counts
     * when it delivers to an area.
     *
     * @return list<FulfilmentMethod>
     */
    public static function ofShop(OnlineStoreShop $shop): array
    {
        $store = $shop->relationLoaded('store') ? $shop->getRelation('store') : null;

        if ($store instanceof OnlineStore) {
            return self::of($store, $shop);
        }

        return array_values(array_filter([$shop->collects() ? FulfilmentMethod::Collection : null, $shop->delivers() ? FulfilmentMethod::Delivery : null]));
    }

    /** The shop takes part in the public store: it collects, delivers, or both. */
    public static function takesPart(OnlineStore $store, OnlineStoreShop $shop): bool
    {
        return self::of($store, $shop) !== [];
    }

    /**
     * What the store's shops offer together. No shop: collection (the store's first and default way).
     *
     * @param  iterable<OnlineStoreShop>  $shops
     * @return array{collection: bool, delivery: bool, mode: string}
     */
    public static function forStore(OnlineStore $store, iterable $shops): array
    {
        $collection = false;
        $delivery = false;

        foreach ($shops as $shop) {
            $methods = self::of($store, $shop);
            $collection = $collection || in_array(FulfilmentMethod::Collection, $methods, true);
            $delivery = $delivery || in_array(FulfilmentMethod::Delivery, $methods, true);
        }

        $mode = match (true) {
            $collection && $delivery => self::BOTH,
            $delivery => self::DELIVERY,
            default => self::COLLECTION,
        };

        return ['collection' => $collection || ! $delivery, 'delivery' => $delivery, 'mode' => $mode];
    }

    /**
     * @param  iterable<OnlineStoreShop>  $shops
     */
    public static function mode(OnlineStore $store, iterable $shops): string
    {
        return self::forStore($store, $shops)['mode'];
    }
}
