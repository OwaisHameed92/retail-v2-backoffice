<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Data\StaffSales;
use App\Domain\Reporting\Models\RptStaffDaily;
use App\Domain\Reporting\ReportTables;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * "Staff sales" (DASHBOARD.md §2.3, §2.7) from `rpt_staff_daily`, per till user (`Sale.userId`), best first.
 */
final class StaffReport
{
    private const T = ReportTables::STAFF_DAILY;

    /**
     * @return list<StaffSales>
     */
    public function byUser(ReportScope $scope): array
    {
        $def = ReportTables::TABLES[self::T];
        $rows = $scope->query(RptStaffDaily::class)
            ->leftJoin('till_users as u', fn (JoinClause $j) => $j->on('u.id', '=', self::T.'.user_id')->on('u.company_id', '=', self::T.'.company_id'))
            ->groupBy(self::T.'.user_id')
            ->select([self::T.'.user_id', DB::raw('MAX(u.name) as name'), ...Sums::select(self::T, $def['decimals'], $def['counts'])])
            ->orderByRaw(Sums::orderExpression(self::T, 'net', 2).' desc')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $s = Sums::read($row, $def['decimals'], $def['counts']);
            $name = (string) ($row->name ?? '');
            $out[] = new StaffSales(
                (string) $row->user_id, $name !== '' ? $name : 'Unknown user', (string) $s['gross'], (string) $s['net'],
                (int) $s['txn_count'], (int) $s['refund_count'], (string) $s['refund_gross'], (int) $s['void_count'],
                SalesTotals::average((string) $s['net'], (int) $s['txn_count']),
            );
        }

        return $out;
    }
}
