<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The business dashboard's tiles that are not sales (module 3.3, DASHBOARD.md §2.2, §2.3, §2.5, §2.6), read from the
 * till's own rows because they are "now" or per shift: low stock (the till's exact rule), cash variance of the shifts
 * closed in the range, and web orders ready to collect. Tenant scope only; one grouped query each, never raw sales.
 */
final class OperationsReport
{
    public const DEFAULT_LOW_STOCK_THRESHOLD = '5';

    private const THRESHOLD_KEY = 'stock.low_stock_threshold';

    /**
     * Low stock per shop now (§2.6): active, tracked (`BranchProduct.isStockTracked`, else `Product.trackStock`) and
     * `qtyOnHand` ≤ the first of `reorderPoint`, `minQty`, `Product.minStockQty`, the shop's (else the business's)
     * `stock.low_stock_threshold` setting, 5. Ignores the date range and the till filter.
     *
     * @return array{total: int, byShop: array<string, int>}
     */
    public function lowStock(ReportScope $scope): array
    {
        $companyId = $this->companyOf($scope);
        [$caseSql, $bindings] = $this->thresholdSql($companyId);

        $rows = DB::table('branch_products as bp')
            ->join('products as p', fn (JoinClause $j) => $j->on('p.id', '=', 'bp.product_id')->on('p.company_id', '=', 'bp.company_id'))
            ->where('bp.company_id', $companyId)
            ->when($scope->branchIds !== null, fn (Builder $q) => $q->whereIn('bp.branch_id', $scope->branchIds ?? []))
            ->whereNull('bp.deleted_at')
            ->where('bp.is_active', true)
            ->whereRaw('COALESCE(bp.is_stock_tracked, p.track_stock) = ?', [true])
            ->whereRaw("bp.qty_on_hand <= COALESCE(bp.reorder_point, bp.min_qty, p.min_stock_qty, {$caseSql})", $bindings)
            ->groupBy('bp.branch_id')
            ->select(['bp.branch_id', DB::raw('COUNT(*) as low')])
            ->get();

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(string) $row->branch_id] = (int) $row->low;
        }

        return ['total' => array_sum($byShop), 'byShop' => $byShop];
    }

    /**
     * Cash variance (§2.5): Σ `ShiftTender.variance` of cash tenders of shifts closed in the range (local days);
     * negative = short. `shifts` closed shifts with a cash line, `shortShifts` those that ended short.
     *
     * @return array{variance: string, shifts: int, shortShifts: int}
     */
    public function cashVariance(ReportScope $scope): array
    {
        $companyId = $this->companyOf($scope);
        [$start] = TradingDay::window($scope->from->toDateString());
        [, $end] = TradingDay::window($scope->to->toDateString());

        $perShift = DB::table('shifts as sh')
            ->join('shift_tenders as st', fn (JoinClause $j) => $j->on('st.shift_id', '=', 'sh.id')->on('st.company_id', '=', 'sh.company_id'))
            ->join('payment_types as pt', fn (JoinClause $j) => $j->on('pt.id', '=', 'st.payment_type_id')->on('pt.company_id', '=', 'st.company_id'))
            ->where('sh.company_id', $companyId)
            ->when($scope->branchIds !== null, fn (Builder $q) => $q->whereIn('sh.branch_id', $scope->branchIds ?? []))
            ->when($scope->registerIds !== null, fn (Builder $q) => $q->whereIn('sh.register_id', $scope->registerIds ?? []))
            ->where('sh.status', 'closed')
            ->whereNull('sh.deleted_at')
            ->whereNull('st.deleted_at')
            ->where('pt.is_cash', true)
            ->where('sh.closed_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('sh.closed_at', '<', $end->format('Y-m-d H:i:s'))
            ->groupBy('sh.id')
            ->select(['sh.id', DB::raw('SUM(ROUND(st.variance * 100)) as pence')]);

        $row = DB::query()->fromSub($perShift, 'v')
            ->selectRaw('COALESCE(SUM(pence), 0) as pence, COUNT(*) as shifts, COALESCE(SUM(CASE WHEN pence < 0 THEN 1 ELSE 0 END), 0) as short_shifts')
            ->first();

        return [
            'variance' => Money::round(bcdiv((string) (int) round((float) ($row->pence ?? 0)), '100', 2)),
            'shifts' => (int) ($row->shifts ?? 0),
            'shortShifts' => (int) ($row->short_shifts ?? 0),
        ];
    }

    /** Web / click-and-collect orders ready to collect now (§2.3 notes): `CustomerOrder.status = ready`. */
    public function ordersReady(ReportScope $scope): int
    {
        return DB::table('customer_orders')
            ->where('company_id', $this->companyOf($scope))
            ->when($scope->branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $scope->branchIds ?? []))
            ->where('status', 'ready')
            ->whereNull('deleted_at')
            ->count();
    }

    private function companyOf(ReportScope $scope): string
    {
        if ($scope->admin || $scope->companyId === null) {
            throw new InvalidArgumentException('Shop operations are read for one business (tenant scope) only.');
        }

        return $scope->companyId;
    }

    /**
     * The shops' low-stock thresholds as SQL: `CASE bp.branch_id WHEN ? THEN ? … ELSE ? END` (the business's value,
     * else 5), from the synced `Setting` rows (§10.3). A value that is not a number is ignored, as on the till.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function thresholdSql(string $companyId): array
    {
        $settings = DB::table('till_settings')->where('company_id', $companyId)->where('setting_key', self::THRESHOLD_KEY)
            ->whereNull('deleted_at')->whereIn('scope', ['company', 'branch'])->get(['scope', 'scope_id', 'value']);
        $number = fn (?string $v) => is_numeric(trim((string) $v, " \"'")) ? (string) (float) trim((string) $v, " \"'") : null;

        $default = self::DEFAULT_LOW_STOCK_THRESHOLD;
        $branches = [];

        foreach ($settings as $s) {
            $value = $number($s->value);

            if ($value === null) {
                continue;
            }

            if ($s->scope === 'company') {
                $default = $value;
            } elseif (is_string($s->scope_id) && $s->scope_id !== '') {
                $branches[$s->scope_id] = $value;
            }
        }

        if ($branches === []) {
            return ['CAST(? AS DECIMAL(14,4))', [$default]];
        }

        $sql = 'CASE bp.branch_id'.str_repeat(' WHEN ? THEN CAST(? AS DECIMAL(14,4))', count($branches)).' ELSE CAST(? AS DECIMAL(14,4)) END';
        $bindings = [];

        foreach ($branches as $branchId => $value) {
            array_push($bindings, $branchId, $value);
        }

        return [$sql, [...$bindings, $default]];
    }
}
