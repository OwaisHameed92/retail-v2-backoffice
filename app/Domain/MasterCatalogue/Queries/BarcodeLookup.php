<?php

namespace App\Domain\MasterCatalogue\Queries;

use App\Domain\Catalogue\Import\ImportLookups;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;

/**
 * Barcode lookup for the product form: a scanned or typed barcode found in the master catalogue (any UPC/EAN form, a
 * merged barcode resolves to the product it was merged into) gives the details to pre-fill, matched to the current
 * business's own departments, categories and VAT rates by name and percentage (null when it has none of that name:
 * the owner picks). Also says when the business already has a product with that barcode.
 */
final class BarcodeLookup
{
    /**
     * @return array{found: bool, existing: array{id: string, name: string}|null, product: array<string, mixed>|null}
     */
    public static function find(string $barcode): array
    {
        $code = Gtin::normalise($barcode);

        if ($code === null) {
            return ['found' => false, 'existing' => null, 'product' => null];
        }

        $variants = Gtin::variants($code);
        $existingId = ProductBarcode::query()->whereIn('barcode', $variants)->value('product_id');
        $existing = $existingId === null ? null : Product::query()->find($existingId, ['id', 'name']);
        $master = MasterProduct::query()->whereIn('barcode', $variants)->orderByRaw('merged_into_id is null desc')->first();

        if ($master?->merged_into_id !== null) {
            $master = MasterProduct::query()->find($master->merged_into_id) ?? $master;
        }

        return [
            'found' => $master !== null,
            'existing' => $existing === null ? null : ['id' => $existing->id, 'name' => (string) $existing->name],
            'product' => $master === null ? null : self::prefill($master),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function prefill(MasterProduct $master): array
    {
        $lookups = ImportLookups::load();
        $departmentId = $lookups->departmentId($master->department);
        $categoryId = $lookups->categoryId($departmentId, $master->category);
        $size = $master->size();

        return [
            ...MasterRow::of($master),
            'departmentId' => $departmentId,
            'categoryId' => $categoryId,
            'vatRateId' => ($master->vat_rate !== null ? $lookups->vatId((string) $master->vat_rate) : null) ?? $lookups->defaultVat($departmentId, $categoryId),
            'volumeMl' => $size->volumeMl(),
            'netMassKg' => $size->massKg(),
        ];
    }
}
