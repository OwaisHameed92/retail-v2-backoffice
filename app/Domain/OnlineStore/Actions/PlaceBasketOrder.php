<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Basket\PricedBasket;
use App\Domain\OnlineStore\Data\CollectOrderInput;
use App\Domain\OnlineStore\Data\PlacedOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use Illuminate\Validation\ValidationException;

/**
 * Places the public store's checkout (OS-2): the session basket, priced at the chosen shop just now, becomes a click
 * & collect order through PlaceCollectOrder (which prices and checks everything again on the server). Refused when
 * the basket is empty or pricing it just removed or changed a line: the customer sees the notices and confirms the
 * basket again instead of getting an order they did not see.
 *
 * @see PlaceCollectOrder
 */
final class PlaceBasketOrder
{
    public function __construct(private readonly PlaceCollectOrder $place) {}

    /**
     * @param  array{collectFrom: string, customerName: string, customerMobile: string, customerEmail: string}  $customer
     *
     * @throws ValidationException
     */
    public function handle(OnlineStore $store, OnlineStoreShop $shop, PricedBasket $basket, array $customer): PlacedOrder
    {
        if ($basket->changed()) {
            throw ValidationException::withMessages(['lines' => 'Your basket changed: '.implode(' ', $basket->notices).' Check it and place the order again.']);
        }

        if ($basket->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Your basket is empty.']);
        }

        return $this->place->handle($store, new CollectOrderInput(
            shopId: $shop->id,
            collectFrom: $customer['collectFrom'],
            customerName: $customer['customerName'],
            customerMobile: $customer['customerMobile'],
            customerEmail: $customer['customerEmail'],
            lines: $basket->basket->orderLines(),
        ));
    }
}
