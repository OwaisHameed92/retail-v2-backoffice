<?php

namespace App\Domain\Anomalies\Queries;

use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\Units;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Per shop and trading day figures the shop-level detectors compare (module 6.6): sales served (`rpt_sales_daily`),
 * manual discounts given (sale lines with `discountSource` manual), price overrides logged by the till, and refunds
 * with no original sale. Money in pounds (strings).
 */
final class ShopDays
{
    /**
     * @return array<string, int> day => sales served
     */
    public static function transactions(string $companyId, string $branchId, string $from, string $to): array
    {
        return DB::table(ReportTables::SALES_DAILY)->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereBetween('trading_day', [$from, $to])->groupBy('trading_day')->selectRaw('trading_day, SUM(txn_count) as n')->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->trading_day, 0, 10) => (int) $r->n])->all();
    }

    /**
     * @return array<string, string> day => manual discount in pounds
     */
    public static function manualDiscounts(string $companyId, string $branchId, string $from, string $to): array
    {
        return DB::table('sales as s')
            ->join('sale_lines as l', fn (JoinClause $j) => $j->on('l.sale_id', '=', 's.id')->on('l.company_id', '=', 's.company_id'))
            ->where('s.company_id', $companyId)->where('s.branch_id', $branchId)->whereBetween('s.trading_day', [$from, $to])
            ->whereIn('s.type', ['sale', 'exchange'])->where('s.status', 'completed')->whereNotNull('s.completed_at')
            ->whereNull('s.deleted_at')->whereNull('l.deleted_at')->where('l.discount_source', 'manual')
            ->groupBy('s.trading_day')
            ->select(['s.trading_day', Units::sum('l.line_discount - COALESCE(l.promotion_discount, 0) - COALESCE(l.coupon_discount, 0)', 2, 'd')])->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->trading_day, 0, 10) => Units::decimal(Units::of($r->d), 2)])->all();
    }

    /**
     * @return array<string, int> day => price overrides logged
     */
    public static function priceOverrides(string $companyId, string $branchId, string $from, string $to): array
    {
        return array_map(fn (array $users) => array_sum($users), StaffDays::exceptions($companyId, $branchId, $from, $to, 'priceoverride'));
    }

    /**
     * Completed refunds with no original sale, per day: count, value (pounds, positive) and the largest one.
     *
     * @return array<string, array{count: int, amount: string, largest: string}>
     */
    public static function unlinkedRefunds(string $companyId, string $branchId, string $from, string $to): array
    {
        return DB::table('sales as s')->where('s.company_id', $companyId)->where('s.branch_id', $branchId)
            ->whereBetween('s.trading_day', [$from, $to])->where('s.type', 'refund')->where('s.status', 'completed')
            ->whereNotNull('s.completed_at')->whereNull('s.deleted_at')
            ->where(fn ($q) => $q->whereNull('s.original_sale_id')->orWhere('s.original_sale_id', ''))
            ->groupBy('s.trading_day')
            ->select(['s.trading_day', DB::raw('COUNT(*) as n'), Units::sum('ABS(s.total)', 2, 'amount'), DB::raw('MAX(ABS(s.total)) as largest')])->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->trading_day, 0, 10) => [
                'count' => (int) $r->n,
                'amount' => Units::decimal(Units::of($r->amount), 2),
                'largest' => number_format((float) $r->largest, 2, '.', ''),
            ]])->all();
    }
}
