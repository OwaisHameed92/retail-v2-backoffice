<?php

namespace App\Domain\TillData\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BranchPrice (contract v1.4 §10.5, SHOP-OR-EVERY-SHOP.md): a price a shop's till set (`origin_branch_id` = that
 * shop) is never edited on the portal — the portal adds a newer row instead (SetBranchPrice), so the till never
 * holds a clash. Pull stamping (only `hub_version`) is allowed.
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

            if ($model->getOriginal('origin_branch_id') !== null && $changed !== []) {
                throw new LogicException('A price set at the shop is never edited on the portal. Add a newer price instead (SetBranchPrice).');
            }
        });
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
