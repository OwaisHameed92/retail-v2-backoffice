<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreScope;

/**
 * The public order page's order (OS-2, `store.order`, a signed link to the order's id: StoreLinks): this store's
 * order with that id, its lines, collection shop and branch. Null for another store's or business's order.
 */
final class StoreOrderById
{
    public function handle(OnlineStore $store, string $orderId): ?OnlineOrder
    {
        if (preg_match('/^[0-9A-Za-z]{26}$/', $orderId) !== 1) {
            return null;
        }

        return StoreScope::run($store, fn (): ?OnlineOrder => OnlineOrder::query()
            ->with(['lines', 'shop.branch', 'branch'])
            ->where('company_id', $store->company_id)
            ->where('online_store_id', $store->id)
            ->whereKey($orderId)
            ->first());
    }
}
