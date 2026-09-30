<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\TenderTotals;
use App\Domain\Reporting\Models\RptTenderDaily;
use App\Domain\Reporting\ReportTables;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * "Takings by payment type" (DASHBOARD.md §2.3, §5.2) from `rpt_tender_daily`. Name: the payment type's current
 * name, else the name the payments carried.
 */
final class TenderReport
{
    private const T = ReportTables::TENDER_DAILY;

    /**
     * @return list<TenderTotals>
     */
    public function byPaymentType(ReportScope $scope): array
    {
        $decimals = ReportTables::TABLES[self::T]['decimals'];
        $rows = $scope->query(RptTenderDaily::class)
            ->leftJoin('payment_types as pt', fn (JoinClause $j) => $j->on('pt.id', '=', self::T.'.payment_type_id')->on('pt.company_id', '=', self::T.'.company_id'))
            ->groupBy(self::T.'.payment_type_id')
            ->select([
                self::T.'.payment_type_id',
                DB::raw('MAX(pt.name) as current_name'),
                DB::raw('MAX('.self::T.'.payment_type_name) as sent_name'),
                ...Sums::select(self::T, $decimals, ['count']),
            ])
            ->orderByRaw(Sums::orderExpression(self::T, 'amount', 2).' desc')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $s = Sums::read($row, $decimals, ['count']);
            $name = (string) ($row->current_name ?? '') !== '' ? (string) $row->current_name : (string) ($row->sent_name ?? '');
            $out[] = new TenderTotals((string) $row->payment_type_id, $name !== '' ? $name : 'Unknown', (string) $s['amount'], (int) $s['count'], (string) $s['refunds']);
        }

        return $out;
    }
}
