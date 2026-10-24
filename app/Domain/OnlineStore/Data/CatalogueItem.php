<?php

namespace App\Domain\OnlineStore\Data;

/**
 * One product as the online store shows it at one shop (StorefrontCatalogue). Money and quantities are decimal
 * strings. `price` is the shop's live price, else the business price (a weighed product's price is per kg and only
 * an estimate: the till weighs it). Stock-tracked products with no stock are shown but not `orderable`. OS-8: the
 * category (offers), the online "Ingredients & allergens" text and the pack size from the till (`net_mass_kg`,
 * `volume_ml`) for the size and unit price. `stockLimit` (audit OSA-12): for a stock-tracked product, the most one
 * order may have: the shop's stock on hand less what open online orders already hold (StorefrontCatalogue::items),
 * whole units or 0.25 kg steps; null = not limited.
 */
final readonly class CatalogueItem
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public ?string $photoPath,
        public ?string $departmentId,
        public string $price,
        public bool $isWeighed,
        public string $unitCode,
        public ?int $maxQtyPerSale,
        public bool $stockTracked,
        public bool $orderable,
        public ?string $categoryId = null,
        public ?string $allergenInfo = null,
        public ?string $netMassKg = null,
        public ?string $volumeMl = null,
        public ?string $stockLimit = null,
    ) {}

    /** The same item with the stock left for online orders (OSA-12); nothing left = not orderable. */
    public function withStockLimit(?string $limit): self
    {
        $orderable = $this->orderable && ($limit === null || bccomp($limit, '0', 4) > 0);

        return new self($this->id, $this->name, $this->description, $this->photoPath, $this->departmentId, $this->price, $this->isWeighed,
            $this->unitCode, $this->maxQtyPerSale, $this->stockTracked, $orderable, $this->categoryId, $this->allergenInfo, $this->netMassKg,
            $this->volumeMl, $limit);
    }

    /**
     * @return array{id: string, name: string, description: string|null, photoPath: string|null, departmentId: string|null, price: string, isWeighed: bool, unitCode: string, maxQtyPerSale: int|null, stockTracked: bool, inStock: bool, orderable: bool, stockLimit: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'photoPath' => $this->photoPath,
            'departmentId' => $this->departmentId,
            'price' => $this->price,
            'isWeighed' => $this->isWeighed,
            'unitCode' => $this->unitCode,
            'maxQtyPerSale' => $this->maxQtyPerSale,
            'stockTracked' => $this->stockTracked,
            'inStock' => $this->orderable,
            'orderable' => $this->orderable,
            'stockLimit' => $this->stockLimit,
        ];
    }
}
