<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\TenderTotals;
use App\Domain\Reporting\Models\RptTenderDaily;
use App\Domain\Reporting\ReportTables;
use App\Domain\Shared\Support\Money;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * "Takings by payment type" (DASHBOARD.md §2.3, §5.2) from `rpt_tender_daily`. Name: the payment type's current
 * name, else the name the payments carried. Types are made on each shop's till (till 0.1.15 seeds "Order deposit"
 * and "Loyalty points" per shop, PORTAL-CHANGES-0.1.15 item 3), so one name is one line: same-named types of several
 * shops are added up (the id kept is the biggest one's).
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
            $name = $name !== '' ? $name : 'Unknown';
            $key = mb_strtolower(trim($name));
            $same = $out[$key] ?? null;

            $out[$key] = $same === null
                ? new TenderTotals((string) $row->payment_type_id, $name, (string) $s['amount'], (int) $s['count'], (string) $s['refunds'])
                : new TenderTotals($same->paymentTypeId, $same->name, Money::add($same->amount, (string) $s['amount']), $same->payments + (int) $s['count'], Money::add($same->refunds, (string) $s['refunds']));
        }

        $out = array_values($out);
        usort($out, fn (TenderTotals $a, TenderTotals $b) => Money::compare($b->amount, $a->amount));

        return $out;
    }
}
