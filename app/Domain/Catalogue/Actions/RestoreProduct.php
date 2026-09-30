<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\Product;

/**
 * Brings an archived product back (module 4.2): `isActive` true, `archivedAt` cleared, sent to every till. Restoring an
 * active product is a no-op.
 */
final class RestoreProduct
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Product $product): Product
    {
        if ($product->is_active && $product->archived_at === null) {
            return $product;
        }

        $product->forceFill(['is_active' => true, 'archived_at' => null, 'row_version' => (int) $product->row_version + 1])->save();

        $this->audit->handle('product.restored', $product, ['is_active' => false], ['is_active' => true], ['name' => $product->name]);

        return $product;
    }
}
