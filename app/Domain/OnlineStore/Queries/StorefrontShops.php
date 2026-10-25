<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\ShopMethods;
use App\Domain\OnlineStore\Support\StoreScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * The shops of a public store: those that take part (OS-12, ShopMethods: collection on, or delivering to an area with
 * a way to pay for it, or both) and are still open (the shop is active and not removed), with their branch, by shop
 * name. A delivery-only shop (collection off) is one of them. Always scoped to the store's own business.
 */
final class StorefrontShops
{
    /**
     * @return Collection<int, OnlineStoreShop>
     */
    public function handle(OnlineStore $store): Collection
    {
        return StoreScope::run($store, fn (): Collection => OnlineStoreShop::query()
            ->with('branch')
            ->where('company_id', $store->company_id)
            ->where('online_store_id', $store->id)
            ->where(fn ($q) => $q->where('enabled', true)->orWhere('delivery_enabled', true))
            ->whereHas('branch', fn ($q) => $q->where('company_id', $store->company_id)->where('is_active', true))
            ->get()
            ->filter(fn (OnlineStoreShop $shop) => ShopMethods::takesPart($store, $shop->setRelation('store', $store)))
            ->sortBy(fn (OnlineStoreShop $shop) => $shop->branch->name)
            ->values());
    }

    /** One of the store's shops by its id, or null when it is not one (or takes no orders: no collection, no delivery). */
    public function find(OnlineStore $store, string $shopId): ?OnlineStoreShop
    {
        return $this->handle($store)->firstWhere('id', $shopId);
    }
}
