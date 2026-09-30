<?php

namespace App\Domain\Catalogue\Queries;

use App\Domain\Catalogue\Support\ProductFields;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\ProductUnit;
use App\Domain\TillData\Models\VatRate;

/**
 * Props of the product form (create, edit, read-only view): the product's editable values as the form holds them
 * (strings for numbers, so nothing is rounded in the browser), its barcodes and units with their ids, where the
 * current content came from, and the pick lists.
 */
final class ProductForm
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?Product $product): array
    {
        $values = [];
        $source = $product ?? (new Product)->forceFill([
            ...ProductFields::defaults(),
            'vat_rate_id' => VatRate::query()->where('is_default', true)->value('id'),
        ]);

        foreach (ProductFields::EDITABLE as $column) {
            $value = ProductFields::comparable($source->getAttribute($column));
            $values[$column] = in_array($column, ProductFields::BOOLEANS, true) ? (bool) $value : self::trim($column, $value);
        }

        $values['barcodes'] = $product === null ? [] : ProductBarcode::query()->where('product_id', $product->id)->orderByDesc('is_primary')->orderBy('created_at')->get()
            ->map(fn (ProductBarcode $b) => ['id' => $b->id, 'barcode' => $b->barcode, 'pack_qty' => (string) $b->pack_qty, 'is_primary' => $b->is_primary])->all();
        $values['units'] = $product === null ? [] : ProductUnit::query()->where('product_id', $product->id)->orderBy('position')->get()
            ->map(fn (ProductUnit $u) => [
                'id' => $u->id, 'unit_id' => $u->unit_id, 'conversion_factor' => self::trim('qty', (string) $u->conversion_factor), 'sell_price_inc_vat' => (string) $u->sell_price_inc_vat,
                'cost' => self::trim('cost', (string) $u->cost), 'is_default_sell_unit' => $u->is_default_sell_unit, 'is_purchase_unit' => $u->is_purchase_unit,
                'is_default_purchase_unit' => $u->is_default_purchase_unit,
            ])->all();

        return [
            'product' => $product === null ? null : [
                'id' => $product->id,
                'name' => (string) $product->name,
                'isActive' => $product->is_active,
                'archivedAt' => $product->archived_at?->toIso8601ZuluString(),
                'createdAt' => $product->created_at?->toIso8601ZuluString(),
                'updatedAt' => $product->updated_at?->toIso8601ZuluString(),
                'lastChangedBy' => $product->origin_branch_id === null
                    ? 'Head office'
                    : (Branch::query()->whereKey($product->origin_branch_id)->value('name') ?? 'A shop'),
                'nearestExpiryDate' => $product->nearest_expiry_date?->format('Y-m-d'),
            ],
            'values' => $values,
            'options' => CatalogueOptions::all(),
        ];
    }

    /**
     * Form text for a value: fixed-scale decimals lose trailing zeros ("6.0000" → "6"; a cost keeps 2 places: "0.98").
     */
    private static function trim(string $column, bool|string|null $value): string
    {
        $value = (string) $value;
        $quantity = in_array($column, ['qty', 'min_stock_qty', 'max_stock_qty', 'reorder_qty', 'volume_ml', 'net_mass_kg', 'abv_percent'], true);
        $cost = in_array($column, ['cost', 'cost_price'], true);

        if ((! $quantity && ! $cost) || ! str_contains($value, '.')) {
            return $value;
        }

        [$whole, $fraction] = explode('.', $value, 2);
        $fraction = rtrim($fraction, '0');

        if ($cost) {
            $fraction = str_pad($fraction, 2, '0');
        }

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
