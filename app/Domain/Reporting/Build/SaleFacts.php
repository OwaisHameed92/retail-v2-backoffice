<?php

namespace App\Domain\Reporting\Build;

use App\Domain\Reporting\Support\Units;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The grouped raw-row queries behind one rebuild of a shop's trading days (DASHBOARD.md §1.4–1.6, §4.2–4.6), in
 * plain SQL that runs on MySQL 8 and SQLite: no LATERAL, no FILTER, no time-zone functions (the day and hour were
 * stamped on the sale at ingest). Each query returns a few hundred grouped rows at most per shop-day, with money in
 * whole pence (quantities and costs in ten-thousandths) so nothing is summed as a float.
 *
 * Counted sale (§1.4): status `completed`, `completed_at` set, type sale / refund / exchange / deposit, not deleted.
 * Children join their sale (§1.6) and skip their own deleted rows. Returned line (§1.5): a line of a refund, or a
 * line with qty < 0 on an exchange. Payments: `approved` and `recovered` only (`reversed` was never money taken).
 */
final readonly class SaleFacts
{
    public const TRADING_TYPES = ['sale', 'refund', 'exchange', 'deposit'];

    private const COUNTED = "s.status = 'completed' AND s.completed_at IS NOT NULL";

    private const VOIDED = "s.status = 'voided'";

    private const RETURNED_LINE = "(s.type = 'refund' OR (s.type = 'exchange' AND l.qty < 0))";

    private const REFUND_PAYMENT = "(s.type = 'refund' OR (s.type = 'exchange' AND p.amount < 0))";

    /**
     * @param  list<string>  $days  trading days "Y-m-d"
     */
    public function __construct(private string $companyId, private string $branchId, private array $days) {}

    /**
     * Sale counts and sale-level money per till, cashier and hour (counted and voided sales).
     *
     * @return list<object>
     */
    public function sales(): array
    {
        return $this->base()->whereIn('s.status', ['completed', 'voided'])
            ->groupBy('s.trading_day', 's.register_id', 's.user_id', 's.trading_hour')
            ->select([
                's.trading_day', 's.register_id', 's.user_id', 's.trading_hour',
                Units::countIf(self::COUNTED." AND s.type <> 'refund'", 'txn_count'),
                Units::countIf(self::COUNTED." AND s.type = 'refund'", 'refund_count'),
                Units::countIf(self::VOIDED, 'void_count'),
                Units::sumIf(self::COUNTED, 's.total', 2, 'takings'),
                Units::sumIf(self::COUNTED, 's.deposit_total', 2, 'container_deposits'),
                Units::sumIf(self::VOIDED, 's.total', 2, 'void_total'),
            ])->get()->all();
    }

    /**
     * SaleVat per till, cashier, hour and rate: the source of every sales figure (§2.2).
     *
     * @return list<object>
     */
    public function vat(): array
    {
        return $this->counted()
            ->join('sale_vats as v', fn (JoinClause $j) => $j->on('v.sale_id', '=', 's.id')->on('v.company_id', '=', 's.company_id'))
            ->whereNull('v.deleted_at')
            ->groupBy('s.trading_day', 's.register_id', 's.user_id', 's.trading_hour', 'v.vat_rate_id', 'v.percentage')
            ->select([
                's.trading_day', 's.register_id', 's.user_id', 's.trading_hour', 'v.vat_rate_id', 'v.percentage',
                Units::sum('v.net', 2, 'net'),
                Units::sum('v.vat', 2, 'vat'),
                Units::sum('v.gross', 2, 'gross'),
                DB::raw('MAX(v.code) as code'),
            ])->get()->all();
    }

    /**
     * SaleLine per till, product, returned-or-not and name (the newest name wins the label).
     *
     * @return list<object>
     */
    public function lines(): array
    {
        $returned = 'CASE WHEN '.self::RETURNED_LINE.' THEN 1 ELSE 0 END';

        return $this->lineQuery()
            ->groupBy('s.trading_day', 's.register_id', 'l.product_id', 'l.name')
            ->groupByRaw($returned)
            ->select([
                's.trading_day', 's.register_id', 'l.product_id', 'l.name',
                DB::raw($returned.' as returned'),
                Units::sum('l.base_qty', 4, 'qty'),
                Units::sum('l.goods_total', 2, 'goods'),
                Units::sum('l.vat_amount', 2, 'vat'),
                Units::sum('l.line_discount', 2, 'discount'),
                Units::sum('l.promotion_discount', 2, 'promo'),
                Units::sum('l.coupon_discount', 2, 'coupon'),
                Units::sum('l.cost_at_sale', 4, 'cost'),
                DB::raw('MAX(s.completed_at) as latest'),
            ])->get()->all();
    }

    /**
     * Returned goods per till and cashier (the staff table's refund value).
     *
     * @return list<object>
     */
    public function staffReturns(): array
    {
        return $this->lineQuery()->whereRaw(self::RETURNED_LINE)
            ->groupBy('s.trading_day', 's.register_id', 's.user_id')
            ->select(['s.trading_day', 's.register_id', 's.user_id', Units::sum('l.goods_total', 2, 'goods')])
            ->get()->all();
    }

    /**
     * Payments per till, payment type (and name), refund-or-not: takings are amount − cashback − change.
     *
     * @return list<object>
     */
    public function payments(): array
    {
        $refund = 'CASE WHEN '.self::REFUND_PAYMENT.' THEN 1 ELSE 0 END';

        return $this->counted()
            ->join('sale_payments as p', fn (JoinClause $j) => $j->on('p.sale_id', '=', 's.id')->on('p.company_id', '=', 's.company_id'))
            ->whereNull('p.deleted_at')
            ->whereIn('p.status', ['approved', 'recovered'])
            ->groupBy('s.trading_day', 's.register_id', 'p.payment_type_id', 'p.payment_type_name')
            ->groupByRaw($refund)
            ->select([
                's.trading_day', 's.register_id', 'p.payment_type_id', 'p.payment_type_name',
                DB::raw($refund.' as refund'),
                Units::sum('p.amount - COALESCE(p.cashback, 0) - COALESCE(p.change_given, 0)', 2, 'amount'),
                DB::raw('COUNT(*) as payments'),
                DB::raw('MAX(s.completed_at) as latest'),
            ])->get()->all();
    }

    /**
     * Always from the shop-day index: without it SQLite (no ANALYZE statistics) drove the child joins from the
     * business's whole `sale_lines` / `sale_vats` / `sale_payments` through a `(company_id, …)` index, so every rebuild
     * read every child row the business had. MySQL gets the same index as FORCE INDEX (its own choice anyway).
     */
    private function base(): Builder
    {
        return DB::table('sales as s')
            ->forceIndex('sales_company_branch_trading_day_index')
            ->where('s.company_id', $this->companyId)
            ->where('s.branch_id', $this->branchId)
            ->whereIn('s.trading_day', $this->days)
            ->whereIn('s.type', self::TRADING_TYPES)
            ->whereNull('s.deleted_at');
    }

    private function counted(): Builder
    {
        return $this->base()->where('s.status', 'completed')->whereNotNull('s.completed_at');
    }

    private function lineQuery(): Builder
    {
        return $this->counted()
            ->join('sale_lines as l', fn (JoinClause $j) => $j->on('l.sale_id', '=', 's.id')->on('l.company_id', '=', 's.company_id'))
            ->whereNull('l.deleted_at');
    }
}
