<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\MasterCatalogue\Models\CatalogueContribution;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merges a duplicate master product into the one to keep (the same product under two barcodes, e.g. a UPC and its
 * EAN, or a supplier file that named it twice). The kept row gains any detail it lacks from the duplicate; the
 * duplicate stays as an alias (`merged_into_id`), so a scan of its barcode still finds the kept product and it is no
 * longer listed or offered. Rows already merged into the duplicate follow it.
 */
final class MergeMasterProducts
{
    private const FILLABLE = ['brand', 'size_value', 'size_unit', 'pack_qty', 'department', 'category', 'vat_rate', 'rrp', 'image_url'];

    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(MasterProduct $duplicate, MasterProduct $keep): MasterProduct
    {
        if ($duplicate->is($keep)) {
            throw ValidationException::withMessages(['keep' => 'Choose a different product to keep.']);
        }

        if ($keep->merged_into_id !== null || $duplicate->merged_into_id !== null) {
            throw ValidationException::withMessages(['keep' => 'One of these products is already merged into another.']);
        }

        return DB::transaction(function () use ($duplicate, $keep) {
            foreach (self::FILLABLE as $field) {
                if ($keep->getAttribute($field) === null && $duplicate->getAttribute($field) !== null) {
                    $keep->setAttribute($field, $duplicate->getAttribute($field));
                }
            }

            if ($keep->age_rule === 'none' && $duplicate->age_rule !== 'none') {
                $keep->age_rule = $duplicate->age_rule;
            }

            $keep->save();
            $duplicate->forceFill(['merged_into_id' => $keep->id])->save();
            MasterProduct::query()->where('merged_into_id', $duplicate->id)->update(['merged_into_id' => $keep->id]);
            CatalogueContribution::query()->where('master_product_id', $duplicate->id)->update(['master_product_id' => $keep->id]);

            $this->audit->handle('master_product.merged', $keep, null, null, [
                'kept' => $keep->barcode, 'merged' => $duplicate->barcode, 'name' => $keep->name,
            ]);

            return $keep;
        });
    }
}
