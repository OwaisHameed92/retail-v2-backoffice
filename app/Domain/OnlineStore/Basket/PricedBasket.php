<?php

namespace App\Domain\OnlineStore\Basket;

use App\Domain\OnlineStore\Data\CatalogueItem;

/**
 * A basket priced at one shop just now (PriceBasket): its lines with the catalogue's current prices, the total (an
 * estimate when a weighed line is in it), and the notices for lines that were removed or changed on the way. The
 * cleaned `basket` is what the session should keep.
 */
final readonly class PricedBasket
{
    /**
     * @param  list<array{item: CatalogueItem, quantity: string, lineTotal: string}>  $lines
     * @param  list<string>  $notices
     */
    public function __construct(
        public Basket $basket,
        public array $lines,
        public string $total,
        public array $notices,
    ) {}

    /** Lines were removed or changed while pricing it. */
    public function changed(): bool
    {
        return $this->notices !== [];
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** What the basket badge counts: units of each product, one per weighed line. */
    public function count(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            $count += $line['item']->isWeighed ? 1 : (int) $line['quantity'];
        }

        return $count;
    }

    /** A weighed line makes the total an estimate (the till weighs at collection). */
    public function estimated(): bool
    {
        foreach ($this->lines as $line) {
            if ($line['item']->isWeighed) {
                return true;
            }
        }

        return false;
    }
}
