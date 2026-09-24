<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\TillData\Queries\TillSum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-model helpers for sale lines (top products, margin).
 *
 *     SaleLine::query()->forProduct($productId)->whereIn('sale_id', $saleIds);
 *     SaleLine::totals($query);   // qty (4 dp), line total, VAT and cost at sale, exact
 *
 * @mixin Model
 */
trait SaleLineQueries
{
    /**
     * @param  Builder<Model>  $query
     */
    public function scopeForProduct(Builder $query, string $productId): void
    {
        $query->where($this->qualifyColumn('product_id'), $productId);
    }

    /**
     * Cost of goods is qty × costAtSale per line, so it is summed at 4 dp in the database.
     *
     * @param  Builder<Model>  $query
     * @return array{qty: string, line_total: string, vat_amount: string, cost: string}
     */
    public static function totals(Builder $query): array
    {
        $sums = TillSum::many($query, ['qty' => 4, 'line_total' => 2, 'vat_amount' => 2]);
        $model = $query->getModel();
        $cost = $query->clone()->toBase()->reorder()->selectRaw(
            'SUM(ROUND('.$model->qualifyColumn('qty').' * '.$model->qualifyColumn('cost_at_sale').' * 10000)) as units'
        )->value('units');

        return [...$sums, 'cost' => TillSum::fromUnits($cost, 4)];
    }
}
