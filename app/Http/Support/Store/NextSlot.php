<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Data\CollectionSlot;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Queries\CollectionDays;
use App\Domain\OnlineStore\Support\ShopMethods;

/**
 * OS-12: the first slot with room at the visitor's shop, over the methods it offers (collection, delivery or both;
 * the earlier one wins), for the home page's hero chip and the product page ("Next delivery Today 14:00–15:00").
 * Null while the store takes no orders or nothing has room.
 */
final class NextSlot
{
    public function __construct(private readonly CollectionDays $days) {}

    /**
     * @return array{collection: string|null, next: array{method: string, when: string}|null}
     */
    public function for(StoreContext $context): array
    {
        $shop = $context->shop();

        if ($shop === null || ! $context->takingOrders()) {
            return ['collection' => null, 'next' => null];
        }

        /** @var array<string, CollectionSlot> $found */
        $found = [];

        foreach (ShopMethods::of($context->store, $shop) as $method) {
            $slot = $this->days->next($shop, null, $method);

            if ($slot !== null) {
                $found[$method->value] = $slot;
            }
        }

        uasort($found, fn (CollectionSlot $a, CollectionSlot $b) => $a->from <=> $b->from);
        $method = array_key_first($found);
        $collection = $found[FulfilmentMethod::Collection->value] ?? null;

        return [
            'collection' => $collection === null ? null : CollectionDays::describe($collection->from, $collection->to),
            'next' => $method === null ? null : ['method' => $method, 'when' => CollectionDays::describe($found[$method]->from, $found[$method]->to)],
        ];
    }
}
