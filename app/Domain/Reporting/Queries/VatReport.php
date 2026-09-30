<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\VatRateTotals;
use App\Domain\Reporting\Models\RptVatDaily;
use App\Domain\Reporting\ReportTables;
use App\Domain\Shared\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * "VAT by rate" (DASHBOARD.md §2.3, §5.5) from `rpt_vat_daily`: net, VAT and gross per rate and percentage, net of
 * refunds, ordered by code. The till's VAT summary report groups the same way.
 */
final class VatReport
{
    private const T = ReportTables::VAT_DAILY;

    /**
     * @return list<VatRateTotals>
     */
    public function byRate(ReportScope $scope): array
    {
        $decimals = ReportTables::TABLES[self::T]['decimals'];
        $rows = $scope->query(RptVatDaily::class)
            ->groupBy(self::T.'.vat_rate_id', self::T.'.percentage')
            ->select([self::T.'.vat_rate_id', self::T.'.percentage', DB::raw('MAX('.self::T.'.code) as code'), ...Sums::select(self::T, $decimals)])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $s = Sums::read($row, $decimals);
            $out[] = new VatRateTotals((string) $row->vat_rate_id, (string) ($row->code ?? ''), Money::normalise($row->percentage ?? 0, 2), (string) $s['net'], (string) $s['vat'], (string) $s['gross']);
        }

        usort($out, fn (VatRateTotals $a, VatRateTotals $b) => [$a->code, $a->percentage] <=> [$b->code, $b->percentage]);

        return $out;
    }
}
