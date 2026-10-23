<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\StoreScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * The shops a customer can collect from in a store: turned on for collections and still open (the shop is active
 * and not removed), with their branch, by shop name. Always scoped to the store's own business.
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
            ->where('enabled', true)
            ->whereHas('branch', fn ($q) => $q->where('company_id', $store->company_id)->where('is_active', true))
            ->get()
            ->sortBy(fn (OnlineStoreShop $shop) => $shop->branch->name)
            ->values());
    }

    /** One of the store's collection shops by its id, or null when it is not one (or not open for collections). */
    public function find(OnlineStore $store, string $shopId): ?OnlineStoreShop
    {
        return $this->handle($store)->firstWhere('id', $shopId);
    }
}
