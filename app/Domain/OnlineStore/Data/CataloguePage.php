<?php

namespace App\Domain\OnlineStore\Data;

/**
 * One page of the online store's product list (StorefrontCatalogue::products).
 */
final readonly class CataloguePage
{
    /**
     * @param  list<CatalogueItem>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(fn (CatalogueItem $item) => $item->toArray(), $this->items),
            'total' => $this->total,
            'page' => $this->page,
            'perPage' => $this->perPage,
            'lastPage' => $this->lastPage(),
        ];
    }
}
