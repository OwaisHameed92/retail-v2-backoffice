<?php

namespace App\Domain\TillData\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BranchPrice (contract v1.4 §10.5, SHOP-OR-EVERY-SHOP.md): a price a shop's till set (`origin_branch_id` = that
 * shop) is never edited on the portal — the portal adds a newer row instead (SetBranchPrice), so the till never
 * holds a clash. Pull stamping (only `hub_version`) is allowed, and so is **ending** it (only `valid_to_utc` set,
 * earlier than before): SHOP-OR-EVERY-SHOP.md "end them on the portal and send the rows down" (module 4.3).
 *
 *     BranchPrice::query()->forProduct($productId, $unitId)->liveAt($now)->forBranch($branch)->first();
 *
 * @mixin Model
 */
trait ShopPriceRows
{
    public static function bootShopPriceRows(): void
    {
        static::updating(function (Model $model): void {
            $changed = array_diff(array_keys($model->getDirty()), ['hub_version']);

            if ($model->getOriginal('origin_branch_id') !== null && $changed !== [] && ! self::isEnding($model, $changed)) {
                throw new LogicException('A price set at the shop is never edited on the portal. Add a newer price instead (SetBranchPrice).');
            }
        });
    }

    /**
     * Only the end moved, and earlier (the save's own bookkeeping columns aside): the price stops, nothing else changes.
     *
     * @param  array<int, string>  $changed
     */
    private static function isEnding(Model $model, array $changed): bool
    {
        $bookkeeping = ['valid_to_utc', 'row_version', 'updated_at', 'hub_edited_at', 'origin_branch_id', 'hub_hash'];
        $old = $model->getOriginal('valid_to_utc');
        $new = $model->getAttribute('valid_to_utc');

        return in_array('valid_to_utc', $changed, true) && array_diff($changed, $bookkeeping) === []
            && $new !== null && ($old === null || $new < $old);
    }

    /**
     * @param  Builder<Model>  $query
     */
    public function scopeForProduct(Builder $query, string $productId, ?string $productUnitId = null): void
    {
        $query->where($this->qualifyColumn('product_id'), $productId);
        $productUnitId === null
            ? $query->whereNull($this->qualifyColumn('product_unit_id'))
            : $query->where($this->qualifyColumn('product_unit_id'), $productUnitId);
    }

    /**
     * Live at `$at` (UTC 'Y-m-d H:i:s'): started, not ended (validTo exclusive). The till's winner is the latest
     * validFromUtc, then the higher id.
     *
     * @param  Builder<Model>  $query
     */
    public function scopeLiveAt(Builder $query, string $at): void
    {
        $query->where($this->qualifyColumn('valid_from_utc'), '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('valid_to_utc'))->orWhere($this->qualifyColumn('valid_to_utc'), '>', $at))
            ->orderByDesc($this->qualifyColumn('valid_from_utc'))
            ->orderByDesc($this->qualifyColumn('id'));
    }
}
