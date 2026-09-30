<?php

namespace App\Domain\Catalogue\Data;

use App\Domain\TillData\Models\Product;

/** What SaveProduct did: the product, whether it is new, and which members changed (empty = nothing was written). */
final readonly class SavedProduct
{
    /**
     * @param  list<string>  $changed  product columns, plus `barcodes` / `units` when those rows changed
     */
    public function __construct(public Product $product, public bool $created, public array $changed) {}

    public function wroteSomething(): bool
    {
        return $this->created || $this->changed !== [];
    }
}
