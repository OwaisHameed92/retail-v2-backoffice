<?php

namespace App\Domain\Catalogue\Support;

use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\ProductUnit;

/**
 * Writes a product's barcodes and units (both hub-owned child rows) from the form or an import row.
 *
 * A row keeps its id: it is matched by id (only among this product's own rows), barcodes also by value, and only
 * changed rows are saved. Rows no longer listed are soft deleted, so every till receives a `D`. New rows get a fresh
 * ULID (HubOwnedRow). Every save stamps a pull version after the transaction commits.
 */
final class ProductChildren
{
    private const UNIT_COLUMNS = ['unit_id', 'conversion_factor', 'sell_price_inc_vat', 'cost', 'is_default_sell_unit', 'is_purchase_unit', 'is_default_purchase_unit', 'position'];

    /**
     * @param  list<array{id?: string|null, barcode: string, pack_qty?: int|string|null, is_primary?: bool|null}>  $rows
     */
    public function syncBarcodes(Product $product, array $rows): bool
    {
        $existing = ProductBarcode::query()->where('product_id', $product->id)->get()->keyBy('id');
        $byValue = $existing->keyBy('barcode');
        $primary = 0;

        foreach ($rows as $i => $row) {
            if (($row['is_primary'] ?? false) === true) {
                $primary = $i;
                break;
            }
        }

        $kept = [];
        $changed = false;

        foreach ($rows as $i => $row) {
            $model = $existing->get((string) ($row['id'] ?? '')) ?? $byValue->get($row['barcode']);

            if ($model === null || isset($kept[$model->id])) {
                $model = new ProductBarcode;
                $model->forceFill(['product_id' => $product->id, 'source' => 'internal']);
            }

            $model->forceFill([
                'barcode' => $row['barcode'],
                'pack_qty' => max(1, (int) ($row['pack_qty'] ?? 1)),
                'is_primary' => $i === $primary,
            ]);

            if (! $model->exists || $model->isDirty()) {
                $model->save();
                $changed = true;
            }

            $kept[$model->id] = true;
        }

        return $this->deleteMissing($existing->all(), $kept) || $changed;
    }

    /**
     * @param  list<array{id?: string|null, unit_id: string, conversion_factor: string, sell_price_inc_vat: string, cost: string, is_default_sell_unit?: bool|null, is_purchase_unit?: bool|null, is_default_purchase_unit?: bool|null}>  $rows
     */
    public function syncUnits(Product $product, array $rows): bool
    {
        $existing = ProductUnit::query()->where('product_id', $product->id)->get()->keyBy('id');
        $kept = [];
        $changed = false;

        foreach ($rows as $i => $row) {
            $model = $existing->get((string) ($row['id'] ?? ''));

            if ($model === null || isset($kept[$model->id])) {
                $model = new ProductUnit;
                $model->forceFill(['product_id' => $product->id]);
            }

            $before = ProductFields::snapshot($model, self::UNIT_COLUMNS);
            $model->forceFill([
                'unit_id' => $row['unit_id'],
                'conversion_factor' => $row['conversion_factor'],
                'sell_price_inc_vat' => $row['sell_price_inc_vat'],
                'cost' => $row['cost'],
                'is_default_sell_unit' => (bool) ($row['is_default_sell_unit'] ?? false),
                'is_purchase_unit' => (bool) ($row['is_purchase_unit'] ?? false),
                'is_default_purchase_unit' => (bool) ($row['is_default_purchase_unit'] ?? false),
                'position' => $i,
            ]);

            if (! $model->exists || ProductFields::changed($before, ProductFields::snapshot($model, self::UNIT_COLUMNS)) !== []) {
                $model->save();
                $changed = true;
            }

            $kept[$model->id] = true;
        }

        return $this->deleteMissing($existing->all(), $kept) || $changed;
    }

    /**
     * @param  array<string, ProductBarcode|ProductUnit>  $existing
     * @param  array<string, true>  $kept
     */
    private function deleteMissing(array $existing, array $kept): bool
    {
        $deleted = false;

        foreach ($existing as $id => $model) {
            if (! isset($kept[$id])) {
                $model->delete();
                $deleted = true;
            }
        }

        return $deleted;
    }
}
