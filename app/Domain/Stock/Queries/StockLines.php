<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Reporting\Queries\OperationsReport;
use App\Domain\Stock\Data\StockFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The stock lines of the current business (module 5.1): the tills' `branch_products` rows (branch-owned, read only)
 * joined to their product, active and stock-tracked (`BranchProduct.isStockTracked`, else `Product.trackStock`), the
 * way the dashboard tile and the stock report (4.8) read them. The low-stock point is the till's rule: the line's
 * reorder point, else its min, else the product's min, else the shop's then the business's
 * `stock.low_stock_threshold` (default 5), as `OperationsReport::thresholdSql`.
 *
 * Always filtered by `company_id` (these are raw queries; the models' global scope does not apply).
 */
final class StockLines
{
    public function __construct(private readonly OperationsReport $operations) {}

    /**
     * Lines matching the filters (shop, search, department, supplier; not status).
     */
    public function base(string $companyId, StockFilters $f): Builder
    {
        return DB::table('branch_products as bp')
            ->join('products as p', fn (JoinClause $j) => $j->on('p.id', '=', 'bp.product_id')->on('p.company_id', '=', 'bp.company_id'))
            ->where('bp.company_id', $companyId)
            ->whereNull('bp.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('bp.is_active', true)
            ->whereRaw('COALESCE(bp.is_stock_tracked, p.track_stock) = ?', [true])
            ->when($f->shop !== null, fn (Builder $q) => $q->where('bp.branch_id', $f->shop))
            ->when($f->product !== null, fn (Builder $q) => $q->where('bp.product_id', $f->product))
            ->when($f->department !== null, fn (Builder $q) => $q->where('p.department_id', $f->department))
            ->when($f->supplier !== null, fn (Builder $q) => $q->whereExists(fn (Builder $e) => $e->from('product_suppliers as ps')
                ->whereColumn('ps.product_id', 'p.id')->whereColumn('ps.company_id', 'p.company_id')
                ->where('ps.supplier_id', $f->supplier)->whereNull('ps.deleted_at')))
            ->when($f->search !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('p.name', 'like', '%'.$f->search.'%')
                ->orWhere('p.sku', 'like', $f->search.'%')
                ->orWhereIn('p.id', fn (Builder $b) => $b->from('product_barcodes')->select('product_id')
                    ->where('company_id', $companyId)->where('barcode', $f->search)->whereNull('deleted_at'))));
    }

    /**
     * The line's low-stock point as SQL and its bindings.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function threshold(string $companyId): array
    {
        [$case, $bindings] = $this->operations->thresholdSql($companyId);

        return ["COALESCE(bp.reorder_point, bp.min_qty, p.min_stock_qty, {$case})", $bindings];
    }

    /**
     * SQL conditions of a status, given the threshold SQL.
     */
    public static function condition(string $status, string $threshold): string
    {
        return match ($status) {
            'negative' => 'COALESCE(bp.qty_on_hand, 0) < 0',
            'out' => 'COALESCE(bp.qty_on_hand, 0) <= 0',
            default => "COALESCE(bp.qty_on_hand, 0) <= {$threshold}",
        };
    }
}
