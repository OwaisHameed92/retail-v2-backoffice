<?php

namespace App\Domain\OnlineStore\Data;

/**
 * Optional filters and order for the public store's product lists (StorefrontCatalogue::products, OS-2): sort by
 * name (default) or by the shop's price, only products that can be ordered now, and a price range in pounds
 * (decimal strings, inclusive). Without filters the list is as OS-1 built it: everything shown, by name.
 */
final readonly class CatalogueFilters
{
    public const SORT_NAME = 'name';

    public const SORT_PRICE_ASC = 'priceAsc';

    public const SORT_PRICE_DESC = 'priceDesc';

    /** OS-8: the products most recently added on the tills first. */
    public const SORT_NEWEST = 'newest';

    public const SORTS = [self::SORT_NAME, self::SORT_PRICE_ASC, self::SORT_PRICE_DESC, self::SORT_NEWEST];

    public function __construct(
        public string $sort = self::SORT_NAME,
        public bool $inStockOnly = false,
        public ?string $minPrice = null,
        public ?string $maxPrice = null,
    ) {}

    /** Any filter or order other than the default. */
    public function active(): bool
    {
        return $this->sort !== self::SORT_NAME || $this->inStockOnly || $this->minPrice !== null || $this->maxPrice !== null;
    }
}
