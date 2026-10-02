<?php

namespace App\Domain\Anomalies\Queries;

use App\Domain\Reporting\Build\SaleFacts;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Reporting\Support\Units;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Per till user and trading day at one shop (module 6.6), from the till's own rows: sales served, voided sales,
 * refunds, manual discounts and no-sale drawer opens. Money in pounds (strings), counts as ints. Sales use the stamped
 * `trading_day`; exception logs are bucketed into London days in PHP (no SQL time-zone functions).
 *
 * @phpstan-type Day array{txn: int, voids: int, voidAmount: string, refunds: int, refundAmount: string, discount: string, noSales: int}
 */
final class StaffDays
{
    private const COUNTED = "s.status = 'completed' AND s.completed_at IS NOT NULL";

    /**
     * @return array<string, array<string, Day>> user id => day => figures
     */
    public static function for(string $companyId, string $branchId, string $from, string $to): array
    {
        $out = [];
        $blank = ['txn' => 0, 'voids' => 0, 'voidAmount' => '0.00', 'refunds' => 0, 'refundAmount' => '0.00', 'discount' => '0.00', 'noSales' => 0];

        $sales = DB::table('sales as s')->where('s.company_id', $companyId)->where('s.branch_id', $branchId)
            ->whereBetween('s.trading_day', [$from, $to])->whereIn('s.type', SaleFacts::TRADING_TYPES)->whereNull('s.deleted_at')
            ->whereIn('s.status', ['completed', 'voided'])->whereNotNull('s.user_id')
            ->groupBy('s.user_id', 's.trading_day')
            ->select([
                's.user_id', 's.trading_day',
                Units::countIf(self::COUNTED." AND s.type NOT IN ('refund', 'deposit')", 'txn'),
                Units::countIf("s.status = 'voided'", 'voids'),
                Units::sumIf("s.status = 'voided'", 'ABS(s.total)', 2, 'void_amount'),
                Units::countIf(self::COUNTED." AND s.type = 'refund'", 'refunds'),
                Units::sumIf(self::COUNTED." AND s.type = 'refund'", 'ABS(s.total)', 2, 'refund_amount'),
            ])->get();

        foreach ($sales as $row) {
            $day = substr((string) $row->trading_day, 0, 10);
            $out[(string) $row->user_id][$day] = [
                ...$blank,
                'txn' => (int) $row->txn,
                'voids' => (int) $row->voids,
                'voidAmount' => Units::decimal(Units::of($row->void_amount), 2),
                'refunds' => (int) $row->refunds,
                'refundAmount' => Units::decimal(Units::of($row->refund_amount), 2),
            ];
        }

        $discounts = DB::table('sales as s')
            ->join('sale_lines as l', fn (JoinClause $j) => $j->on('l.sale_id', '=', 's.id')->on('l.company_id', '=', 's.company_id'))
            ->where('s.company_id', $companyId)->where('s.branch_id', $branchId)->whereBetween('s.trading_day', [$from, $to])
            ->whereIn('s.type', ['sale', 'exchange'])->whereNull('s.deleted_at')->whereNull('l.deleted_at')
            ->whereRaw(self::COUNTED)->where('l.discount_source', 'manual')->whereNotNull('s.user_id')
            ->groupBy('s.user_id', 's.trading_day')
            ->select(['s.user_id', 's.trading_day', Units::sum('l.line_discount - COALESCE(l.promotion_discount, 0) - COALESCE(l.coupon_discount, 0)', 2, 'discount')])
            ->get();

        foreach ($discounts as $row) {
            $day = substr((string) $row->trading_day, 0, 10);
            $user = (string) $row->user_id;
            $out[$user][$day] = [...($out[$user][$day] ?? $blank), 'discount' => Units::decimal(Units::of($row->discount), 2)];
        }

        foreach (self::exceptions($companyId, $branchId, $from, $to, 'nosale') as $day => $users) {
            foreach ($users as $user => $n) {
                $out[$user][$day] = [...($out[$user][$day] ?? $blank), 'noSales' => $n];
            }
        }

        return $out;
    }

    /**
     * Till exception logs of one type (compared without case or spaces, e.g. "nosale", "priceoverride") per London
     * day and till user.
     *
     * @return array<string, array<string, int>> day => user id ('' = unknown) => count
     */
    public static function exceptions(string $companyId, string $branchId, string $from, string $to, string $type): array
    {
        [$start] = TradingDay::window($from);
        [, $end] = TradingDay::window($to);
        $out = [];

        $rows = DB::table('exception_logs')->where('company_id', $companyId)->where('branch_id', $branchId)->whereNull('deleted_at')
            ->where('at', '>=', $start->format('Y-m-d H:i:s'))->where('at', '<', $end->format('Y-m-d H:i:s'))
            ->whereRaw("LOWER(REPLACE(type, ' ', '')) = ?", [$type])
            ->get(['user_id', 'at']);

        foreach ($rows as $row) {
            [$day] = TradingDay::of((string) $row->at);
            $user = (string) ($row->user_id ?? '');
            $out[$day][$user] = ($out[$day][$user] ?? 0) + 1;
        }

        return $out;
    }
}
