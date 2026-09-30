<?php

namespace App\Domain\Reporting\Reports\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Queries\OperationsReport;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stock now (DASHBOARD.md §2.6) from the tills' `branch_products` rows: active, stock-tracked lines of the shops in
 * scope, with the till's low-stock rule (`OperationsReport::thresholdSql`, the same as the dashboard tile) and value
 * at cost (`qtyOnHand × Product.costPrice`). Indexed on `(company_id, branch_id)`; the date range does not apply.
 */
final class StockLevels
{
    public function __construct(private OperationsReport $operations) {}

    /** Whether any shop of the business has sent stock rows at all. */
    public function hasAny(string $companyId): bool
    {
        return DB::table('branch_products')->where('company_id', $companyId)->whereNull('deleted_at')->exists();
    }

    /**
     * @return array{lines: int, units: string, value: string, costed: int, low: int, out: int}
     */
    public function summary(ReportScope $scope): array
    {
        [$threshold, $bindings] = $this->threshold($scope);
        $row = $this->base($scope)->selectRaw(
            'COUNT(*) as lines_count, '
            .'SUM(ROUND(COALESCE(bp.qty_on_hand, 0) * 10000)) as units, '
            .'SUM(ROUND(COALESCE(bp.qty_on_hand, 0) * COALESCE(p.cost_price, 0) * 10000)) as value_units, '
            .'SUM(CASE WHEN COALESCE(p.cost_price, 0) > 0 THEN 1 ELSE 0 END) as costed, '
            ."SUM(CASE WHEN COALESCE(bp.qty_on_hand, 0) <= {$threshold} THEN 1 ELSE 0 END) as low, "
            .'SUM(CASE WHEN COALESCE(bp.qty_on_hand, 0) <= 0 THEN 1 ELSE 0 END) as out_count',
            $bindings,
        )->first();

        return [
            'lines' => (int) ($row->lines_count ?? 0),
            'units' => Units::decimal(Units::of($row->units ?? null), 4),
            'value' => Money::round(Units::decimal(Units::of($row->value_units ?? null), 4), 2),
            'costed' => (int) ($row->costed ?? 0),
            'low' => (int) ($row->low ?? 0),
            'out' => (int) ($row->out_count ?? 0),
        ];
    }

    /**
     * Lines of a view (`all`, `low`, `out`): out of stock first, then low, then by product name.
     *
     * @return array{total: int, rows: list<array<string, string|null>>}
     */
    public function lines(ReportScope $scope, string $view, int $limit, int $offset): array
    {
        [$threshold, $bindings] = $this->threshold($scope);
        $query = $this->base($scope)
            ->when($view === 'low', fn (Builder $q) => $q->whereRaw("COALESCE(bp.qty_on_hand, 0) <= {$threshold}", $bindings))
            ->when($view === 'out', fn (Builder $q) => $q->whereRaw('COALESCE(bp.qty_on_hand, 0) <= 0'));
        $total = (clone $query)->count();

        $rows = $query
            ->select(['bp.id', 'p.name', 'p.sku', 'b.name as shop', 'bp.qty_on_hand', 'bp.qty_reserved', 'bp.qty_available', 'p.cost_price'])
            ->selectRaw("{$threshold} as threshold", $bindings)
            ->orderByRaw("CASE WHEN COALESCE(bp.qty_on_hand, 0) <= 0 THEN 0 WHEN COALESCE(bp.qty_on_hand, 0) <= {$threshold} THEN 1 ELSE 2 END", $bindings)
            ->orderBy('p.name')->orderBy('bp.id')
            ->offset($offset)->limit($limit)->get();

        return ['total' => $total, 'rows' => $rows->map(function (object $r) {
            $onHand = Money::normalise($r->qty_on_hand ?? 0, 4);
            $threshold = Money::normalise($r->threshold ?? 0, 4);
            $cost = $r->cost_price === null ? null : Money::normalise($r->cost_price, 4);
            $status = Money::compare($onHand, '0') <= 0 ? 'out' : (Money::compare($onHand, $threshold) <= 0 ? 'low' : 'ok');

            return [
                'id' => (string) $r->id,
                'name' => (string) ($r->name ?? '') !== '' ? (string) $r->name : 'Unknown product',
                'sku' => (string) ($r->sku ?? ''),
                'shop' => (string) ($r->shop ?? ''),
                'onHand' => $onHand,
                'reserved' => Money::normalise($r->qty_reserved ?? 0, 4),
                'available' => $r->qty_available !== null ? Money::normalise($r->qty_available, 4) : Money::sub($onHand, $r->qty_reserved ?? 0, 4),
                'reorderAt' => $threshold,
                'cost' => $cost === null || Money::isZero($cost) ? null : Money::round($cost, 2),
                'value' => $cost === null || Money::isZero($cost) ? null : Money::mul($onHand, $cost),
                'status' => $status,
            ];
        })->values()->all()];
    }

    private function base(ReportScope $scope): Builder
    {
        return DB::table('branch_products as bp')
            ->join('products as p', fn (JoinClause $j) => $j->on('p.id', '=', 'bp.product_id')->on('p.company_id', '=', 'bp.company_id'))
            ->leftJoin('branches as b', fn (JoinClause $j) => $j->on('b.id', '=', 'bp.branch_id')->on('b.company_id', '=', 'bp.company_id'))
            ->where('bp.company_id', $this->companyOf($scope))
            ->when($scope->branchIds !== null, fn (Builder $q) => $q->whereIn('bp.branch_id', $scope->branchIds ?? []))
            ->whereNull('bp.deleted_at')
            ->where('bp.is_active', true)
            ->whereRaw('COALESCE(bp.is_stock_tracked, p.track_stock) = ?', [true]);
    }

    /**
     * The reorder point of a line as SQL (the till's rule) and its bindings.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function threshold(ReportScope $scope): array
    {
        [$case, $bindings] = $this->operations->thresholdSql($this->companyOf($scope));

        return ["COALESCE(bp.reorder_point, bp.min_qty, p.min_stock_qty, {$case})", $bindings];
    }

    private function companyOf(ReportScope $scope): string
    {
        if ($scope->admin || $scope->companyId === null) {
            throw new InvalidArgumentException('Stock is read for one business (tenant scope) only.');
        }

        return $scope->companyId;
    }
}
