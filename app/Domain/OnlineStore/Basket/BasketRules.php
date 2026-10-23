<?php

namespace App\Domain\OnlineStore\Basket;

use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Support\OrderLineRules;
use App\Domain\Shared\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * The basket's quantity rules, the same as an order's (OrderLineRules): weighed products in 0.25 kg steps up to
 * MAX_WEIGHED_KG, others whole numbers up to MAX_UNITS, never above `max_qty_per_sale`, only products shown and
 * orderable at the shop, at most MAX_LINES lines, and only while the store takes orders.
 */
final class BasketRules
{
    public const WEIGHED_STEP = '0.25';

    public function __construct(private readonly StorefrontCatalogue $catalogue) {}

    /**
     * The product as the shop shows it, once the new quantity of its line is allowed.
     *
     * @throws ValidationException under `product` or `quantity`
     */
    public function check(OnlineStore $store, ?OnlineStoreShop $shop, Basket $basket, string $productId, string $quantity): CatalogueItem
    {
        if (! $store->taking_orders || $shop === null) {
            throw ValidationException::withMessages(['product' => 'This shop is not taking online orders right now.']);
        }

        if (! $basket->has($productId) && count($basket->lines) >= OrderLineRules::MAX_LINES) {
            throw ValidationException::withMessages(['product' => 'Your basket is full (at most '.OrderLineRules::MAX_LINES.' products).']);
        }

        $items = $this->catalogue->items($store, $shop, [$productId]);

        if (! isset($items[$productId])) {
            throw ValidationException::withMessages(['product' => 'That product is not available.']);
        }

        try {
            OrderLineRules::price(OrderLineRules::requested([['productId' => $productId, 'quantity' => $quantity]]), $items);
        } catch (ValidationException $e) {
            $key = array_key_first($e->errors());
            $field = is_string($key) && str_ends_with($key, 'quantity') ? 'quantity' : 'product';

            throw ValidationException::withMessages([$field => $e->errors()[$key][0] ?? 'That product is not available.']);
        }

        return $items[$productId];
    }

    /** The most of a product one order may have: "99", "50" (kg) or its `max_qty_per_sale`. */
    public static function limit(CatalogueItem $item): string
    {
        $max = $item->isWeighed ? OrderLineRules::MAX_WEIGHED_KG : (string) OrderLineRules::MAX_UNITS;

        return $item->maxQtyPerSale !== null && Money::compare($item->maxQtyPerSale, $max) < 0 ? (string) $item->maxQtyPerSale : $max;
    }

    /** The smallest step: 0.25 kg for a weighed product, else 1. */
    public static function step(CatalogueItem $item): string
    {
        return $item->isWeighed ? self::WEIGHED_STEP : '1';
    }

    /**
     * A stored quantity made valid for the product as it is now: down to its step (at least one step), at most its
     * limit. "1.2500" style, 4 dp.
     */
    public static function fit(CatalogueItem $item, string $quantity): string
    {
        $step = self::step($item);
        $steps = bcdiv(Money::normalise($quantity, 4), $step, 0);
        $qty = Money::mul(max(1, (int) $steps), $step, 4);
        $limit = Money::normalise(self::limit($item), 4);

        return Money::compare($qty, $limit) > 0 ? $limit : $qty;
    }

    /** A quantity for people: "3", "0.5", "1.25". */
    public static function display(string $quantity): string
    {
        $trimmed = rtrim(rtrim(Money::normalise($quantity, 4), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
