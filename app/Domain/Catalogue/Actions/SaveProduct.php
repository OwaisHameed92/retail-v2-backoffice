<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Data\SavedProduct;
use App\Domain\Catalogue\Support\CatalogueRules;
use App\Domain\Catalogue\Support\ProductChildren;
use App\Domain\Catalogue\Support\ProductFields;
use App\Domain\Labels\Actions\QueueChangedLabels;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a product with its barcodes and units on the portal (module 4.2). Products are hub-owned: the save
 * goes through the model (HubOwnedRow), so every till receives it in its next pull, and a product keeps its ULID.
 *
 * Only `ProductFields::EDITABLE` columns are written; members the input leaves out keep their value (an import row
 * may carry only a price). `barcodes` / `units` null = leave them as they are. Nothing is written when nothing
 * changed (no new pull version, no audit). An edit raises `row_version` by one, as the till does.
 *
 * Checks what the form cannot: department, category (of that department), sub-category (of that category), VAT rate
 * and units exist in this business; no barcode is on another product; a new or changed SKU is not on another product.
 */
final class SaveProduct
{
    public function __construct(
        private readonly ProductChildren $children,
        private readonly CatalogueRules $rules,
        private readonly RecordAudit $audit,
        private readonly QueueChangedLabels $labels,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  product columns (a subset of ProductFields::EDITABLE)
     * @param  list<array{id?: string|null, barcode: string, pack_qty?: int|string|null, is_primary?: bool|null}>|null  $barcodes
     * @param  list<array{id?: string|null, unit_id: string, conversion_factor: string, sell_price_inc_vat: string, cost: string, is_default_sell_unit?: bool|null, is_purchase_unit?: bool|null, is_default_purchase_unit?: bool|null}>|null  $units
     */
    public function handle(?Product $product, array $attributes, ?array $barcodes = null, ?array $units = null, bool $audit = true): SavedProduct
    {
        $created = $product === null;
        $product ??= (new Product)->forceFill(ProductFields::defaults());
        $attributes = Arr::only($attributes, ProductFields::EDITABLE);

        if (array_key_exists('short_name', $attributes) || $created) {
            $name = (string) ($attributes['name'] ?? $product->name);
            $attributes['short_name'] = trim((string) ($attributes['short_name'] ?? '')) !== '' ? $attributes['short_name'] : mb_substr($name, 0, 40);
        }

        $this->rules->check($product, $attributes, $barcodes, $units);

        return DB::transaction(function () use ($product, $attributes, $barcodes, $units, $created, $audit) {
            $before = ProductFields::snapshot($product, ProductFields::EDITABLE);
            $product->forceFill($attributes);
            $product->is_age_restricted = $product->age_rule !== null && $product->age_rule->value !== 'none';

            if (! $product->is_active && $product->archived_at === null) {
                $product->archived_at = now('UTC')->toImmutable();
            } elseif ($product->is_active) {
                $product->archived_at = null;
            }

            $after = ProductFields::snapshot($product, ProductFields::EDITABLE);
            $changed = $created ? array_keys(array_filter($after, fn ($v) => $v !== null)) : ProductFields::changed($before, $after);

            if ($created || $changed !== [] || $product->isDirty('is_age_restricted', 'archived_at')) {
                if (! $created) {
                    $product->row_version = (int) $product->row_version + 1;
                }

                $product->save();
            }

            if ($barcodes !== null && $this->children->syncBarcodes($product, $barcodes)) {
                $changed[] = 'barcodes';
            }

            if ($units !== null && $this->children->syncUnits($product, $units)) {
                $changed[] = 'units';
            }

            if (! $created && in_array('sell_price', $changed, true)) {
                $this->labels->businessPrice($product, (string) $before['sell_price'], (string) $product->sell_price); // Shelf labels (gap #6).
            }

            if ($audit && ($created || $changed !== [])) {
                $this->audit->handle(
                    $created ? 'product.created' : 'product.updated',
                    $product,
                    $created ? null : Arr::only($before, $changed),
                    Arr::only($after, $created ? ['name', 'sku', 'sell_price', 'cost_price'] : $changed),
                    ['name' => $product->name],
                );
            }

            return new SavedProduct($product, $created, $changed);
        });
    }

    /**
     * Throws the error of one field, keyed as the form names it.
     */
    public static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
