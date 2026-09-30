<?php

namespace App\Domain\Stock\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets a product's stock levels from the stock screens (module 5.1): `Product.minStockQty` (the low-stock point when
 * a shop's line has no reorder point or min of its own), `maxStockQty` and `reorderQty`. Product is hub-owned
 * (ownership.json), so the save goes through the model (HubOwnedRow): every till receives it in its next pull.
 * The shops' own `BranchProduct` reorder point / min / max are branch-owned and stay read only.
 *
 * Nothing is written when nothing changed (no new pull version, no audit). An edit raises `row_version` by one, as
 * the till does, and is audited as `product.updated`.
 */
final class SetProductStockLevels
{
    public const FIELDS = ['min_stock_qty', 'max_stock_qty', 'reorder_qty'];

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array{min_stock_qty?: string|null, max_stock_qty?: string|null, reorder_qty?: string|null}  $levels
     * @return list<string> the fields that changed
     */
    public function handle(Product $product, array $levels): array
    {
        $new = [];

        foreach (self::FIELDS as $field) {
            $value = $levels[$field] ?? null;
            $new[$field] = $value === null || $value === '' ? null : Money::normalise($value, 4);
        }

        if ($new['min_stock_qty'] !== null && $new['max_stock_qty'] !== null && Money::compare($new['max_stock_qty'], $new['min_stock_qty']) < 0) {
            throw ValidationException::withMessages(['max_stock_qty' => 'The most to hold cannot be below the minimum.']);
        }

        $before = [];
        $changed = [];

        foreach (self::FIELDS as $field) {
            $old = $product->getAttribute($field);
            $before[$field] = $old !== null ? Money::normalise($old, 4) : null;

            if ($before[$field] !== $new[$field]) {
                $changed[] = $field;
            }
        }

        if ($changed === []) {
            return [];
        }

        DB::transaction(function () use ($product, $new, $before, $changed) {
            $product->forceFill($new);
            $product->row_version = (int) $product->row_version + 1;
            $product->save();

            $this->audit->handle(
                'product.updated',
                $product,
                array_intersect_key($before, array_flip($changed)),
                array_intersect_key($new, array_flip($changed)),
                ['name' => $product->name, 'via' => 'stock'],
            );
        });

        return $changed;
    }
}
