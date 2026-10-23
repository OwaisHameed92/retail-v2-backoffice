<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreScope;

/**
 * The order page opened by its secret link (`/{slug}/order/{token}`): the store's order whose token hashes to the
 * stored `token_hash`, with its lines, shop and branch. Null for an unknown token or another store's order. The
 * plain token is never stored or logged.
 */
final class OnlineOrderByToken
{
    /** Tokens PlaceCollectOrder makes are 64 hex characters; anything else is not looked up. */
    public const PATTERN = '/^[a-f0-9]{64}$/';

    public function handle(OnlineStore $store, string $token): ?OnlineOrder
    {
        if (preg_match(self::PATTERN, $token) !== 1) {
            return null;
        }

        return StoreScope::run($store, fn (): ?OnlineOrder => OnlineOrder::query()
            ->with(['lines', 'shop', 'branch'])
            ->where('company_id', $store->company_id)
            ->where('online_store_id', $store->id)
            ->where('token_hash', OnlineOrder::hashToken($token))
            ->first());
    }
}
