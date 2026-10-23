<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Basket\Basket;
use App\Domain\OnlineStore\Basket\BasketRules;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Shared\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Adds a quantity of a product to a public store basket (OS-2): the product's line grows by it, within the basket
 * rules (BasketRules: shown and orderable at the shop, steps, limits, store taking orders). Only the id and the
 * quantity come from the customer; nothing is priced here (PriceBasket prices on every view).
 */
final class AddToBasket
{
    public function __construct(private readonly BasketRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(OnlineStore $store, ?OnlineStoreShop $shop, Basket $basket, string $productId, string $quantity): Basket
    {
        $total = Money::add($basket->quantity($productId), $quantity, 4);
        $item = $this->rules->check($store, $shop, $basket, $productId, $total);

        return $basket->with($item->id, $total, $item->name);
    }
}
