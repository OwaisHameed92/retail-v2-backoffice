<?php

namespace App\Domain\OnlineStore\Basket;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;

/**
 * Prices a basket at a shop from the catalogue, every time it is shown (prices in the session are never trusted:
 * none are kept). Lines whose product the shop no longer shows or that are out of stock are removed; quantities are
 * fitted to the product's current rules (step, `max_qty_per_sale`). Each removal or change gives a notice. Without
 * a shop (none open for collections) every line is kept unpriced: nothing is shown and nothing removed. A price
 * that changed since the customer last saw it gives a notice too (audit OSA-24), so the order is confirmed again.
 */
final class PriceBasket
{
    public function __construct(private readonly StorefrontCatalogue $catalogue) {}

    public function handle(OnlineStore $store, ?OnlineStoreShop $shop, Basket $basket): PricedBasket
    {
        if ($shop === null || $basket->isEmpty()) {
            return new PricedBasket($basket, [], '0.00', []);
        }

        $items = $this->catalogue->items($store, $shop, array_map('strval', array_keys($basket->lines)));
        $clean = $basket;
        $lines = [];
        $notices = [];

        foreach ($basket->lines as $id => $stored) {
            $id = (string) $id;
            $item = $items[$id] ?? null;
            $name = $item->name ?? ($stored['name'] !== '' ? $stored['name'] : 'A product');

            if ($item === null || ! $item->orderable) {
                $clean = $clean->without($id);
                $notices[] = $item === null ? "{$name} is no longer available, so we took it out of your basket."
                    : "{$name} has just sold out, so we took it out of your basket.";

                continue;
            }

            $quantity = BasketRules::fit($item, $stored['qty']);

            if (! Money::equals($quantity, $stored['qty'])) {
                $notices[] = "We changed {$item->name} to ".BasketRules::display($quantity).($item->isWeighed ? ' kg' : '').' to fit what one order can have.';
            }

            $shown = $basket->shownPrice($id);

            if ($shown !== null && ! Money::equals($shown, $item->price)) {
                $notices[] = "The price of {$item->name} changed from ".MoneyFormat::format($shown).' to '.MoneyFormat::format($item->price).($item->isWeighed ? ' per kg' : '').'.';
            }

            $clean = $clean->with($id, $quantity, $item->name, $item->price);
            $lines[] = ['item' => $item, 'quantity' => $quantity, 'lineTotal' => Money::mul($item->price, $quantity, 2)];
        }

        return new PricedBasket($clean, $lines, Money::sum(array_column($lines, 'lineTotal'), 2), $notices);
    }
}
