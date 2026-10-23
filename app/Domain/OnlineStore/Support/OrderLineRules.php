<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\Shared\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * The basket rules of a click & collect order (PlaceCollectOrder). The customer sends only product ids and
 * quantities; names and prices come from the catalogue (StorefrontCatalogue::items) on the server.
 *
 * - 1 to MAX_LINES products; the same product twice is one line with the quantities added.
 * - Weighed products: kg in 0.25 steps, more than 0, at most MAX_WEIGHED_KG. Others: whole numbers, 1 to MAX_UNITS.
 * - Never more than the product's `max_qty_per_sale`.
 * - Every product must be shown and orderable at the shop (in stock when stock-tracked).
 * - Line total = shelf price × quantity, rounded to the penny (an estimate for weighed products).
 */
final class OrderLineRules
{
    public const MAX_LINES = 100;

    public const MAX_UNITS = 99;

    public const MAX_WEIGHED_KG = '50';

    /**
     * The requested quantities per product id, in the order first given.
     *
     * @param  list<array{productId: string, quantity: string}>  $lines
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function requested(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Your basket is empty.']);
        }

        if (count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => 'Too many products in one order (at most '.self::MAX_LINES.').']);
        }

        $quantities = [];

        foreach ($lines as $i => $line) {
            $id = trim($line['productId']);
            $qty = trim($line['quantity']);

            if (preg_match('/^[0-9A-Za-z]{26}$/', $id) !== 1) {
                throw ValidationException::withMessages(["lines.{$i}.productId" => 'That product is not available.']);
            }

            if (preg_match('/^\d{1,6}(\.\d{1,4})?$/', $qty) !== 1 || Money::compare($qty, '0') <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => 'Enter a quantity above zero.']);
            }

            $quantities[$id] = Money::add($quantities[$id] ?? '0', $qty, 4);
        }

        return $quantities;
    }

    /**
     * The order lines, priced on the server.
     *
     * @param  array<string, string>  $quantities  product id => quantity (from requested())
     * @param  array<string, CatalogueItem>  $items  the catalogue's items at the shop
     * @return list<array{product_id: string, name: string, unit_price: string, quantity: string, is_weighed: bool, line_total: string}>
     *
     * @throws ValidationException
     */
    public static function price(array $quantities, array $items): array
    {
        $lines = [];
        $i = 0;

        foreach ($quantities as $productId => $qty) {
            $item = $items[$productId] ?? null;
            $key = "lines.{$i}";

            if ($item === null) {
                throw ValidationException::withMessages(["{$key}.productId" => 'One of the products is no longer available. Remove it from your basket.']);
            }

            if (! $item->orderable) {
                throw ValidationException::withMessages(["{$key}.productId" => "{$item->name} is out of stock."]);
            }

            self::checkQuantity($item, $qty, $key);

            $lines[] = [
                'product_id' => $item->id,
                'name' => $item->name,
                'unit_price' => $item->price,
                'quantity' => Money::normalise($qty, 4),
                'is_weighed' => $item->isWeighed,
                'line_total' => Money::mul($item->price, $qty, 2),
            ];
            $i++;
        }

        return $lines;
    }

    /**
     * @throws ValidationException
     */
    private static function checkQuantity(CatalogueItem $item, string $qty, string $key): void
    {
        $max = $item->isWeighed ? self::MAX_WEIGHED_KG : (string) self::MAX_UNITS;

        if ($item->maxQtyPerSale !== null && Money::compare($item->maxQtyPerSale, $max) < 0) {
            $max = (string) $item->maxQtyPerSale;
        }

        if ($item->isWeighed && ! Money::isZero(bcmod(Money::mul($qty, '4', 4), '1', 4))) {
            throw ValidationException::withMessages(["{$key}.quantity" => "Choose {$item->name} in steps of 0.25 kg."]);
        }

        if (! $item->isWeighed && ! Money::isZero(bcmod(Money::normalise($qty, 4), '1', 4))) {
            throw ValidationException::withMessages(["{$key}.quantity" => "Choose a whole number of {$item->name}."]);
        }

        if (Money::compare($qty, $max) > 0) {
            $unit = $item->isWeighed ? ' kg' : '';

            throw ValidationException::withMessages(["{$key}.quantity" => "You can order at most {$max}{$unit} of {$item->name}."]);
        }
    }
}
