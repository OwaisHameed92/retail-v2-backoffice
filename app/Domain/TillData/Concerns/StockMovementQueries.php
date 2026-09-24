<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\TillData\Queries\TillSum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-model helpers for the stock ledger.
 *
 *     StockMovement::query()->forBranch($branch)->forProduct($id)->between($from, $to)->orderBy('at');
 *     StockMovement::netQty($query);   // "-3.0000"
 *
 * @mixin Model
 */
trait StockMovementQueries
{
    /**
     * @param  Builder<Model>  $query
     */
    public function scopeForProduct(Builder $query, string $productId): void
    {
        $query->where($this->qualifyColumn('product_id'), $productId);
    }

    /**
     * Movements in [from, to), UTC.
     *
     * @param  Builder<Model>  $query
     */
    public function scopeBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->where($this->qualifyColumn('at'), '>=', gmdate('Y-m-d H:i:s', $from->getTimestamp()))
            ->where($this->qualifyColumn('at'), '<', gmdate('Y-m-d H:i:s', $to->getTimestamp()));
    }

    /**
     * @param  Builder<Model>  $query
     */
    public static function netQty(Builder $query): string
    {
        return TillSum::of($query, 'qty_delta', 4);
    }
}
