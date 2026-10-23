<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Basket\Basket;
use App\Domain\OnlineStore\Basket\BasketRules;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Shared\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Sets the quantity of a line already in a public store basket (OS-2); 0 removes it (always allowed, even when the
 * store has stopped taking orders). Other quantities follow BasketRules. A product not in the basket is refused.
 */
final class UpdateBasketLine
{
    public function __construct(private readonly BasketRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(OnlineStore $store, ?OnlineStoreShop $shop, Basket $basket, string $productId, string $quantity): Basket
    {
        if (! $basket->has($productId)) {
            throw ValidationException::withMessages(['product' => 'That product is not in your basket.']);
        }

        if (Money::isZero($quantity)) {
            return $basket->without($productId);
        }

        $quantity = Money::normalise($quantity, 4);
        $item = $this->rules->check($store, $shop, $basket, $productId, $quantity);

        return $basket->with($item->id, $quantity, $item->name);
    }
}
