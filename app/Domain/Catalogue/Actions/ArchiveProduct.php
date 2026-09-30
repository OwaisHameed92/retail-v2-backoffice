<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonImmutable;

/**
 * Archives a product (module 4.2): `isActive` false and `archivedAt` now, sent to every till as an update. The row is
 * never deleted: past sales, stock and orders still point at it, and it can be restored. Archiving twice is a no-op.
 */
final class ArchiveProduct
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Product $product): Product
    {
        if (! $product->is_active && $product->archived_at !== null) {
            return $product;
        }

        $product->forceFill([
            'is_active' => false,
            'archived_at' => CarbonImmutable::now('UTC'),
            'row_version' => (int) $product->row_version + 1,
        ])->save();

        $this->audit->handle('product.archived', $product, ['is_active' => true], ['is_active' => false], ['name' => $product->name]);

        return $product;
    }
}
