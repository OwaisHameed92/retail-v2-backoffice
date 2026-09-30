<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\VatRateTotals;
use App\Domain\Reporting\Models\RptVatDaily;
use App\Domain\Reporting\Queries\Sums;
use App\Domain\Reporting\Queries\VatReport;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Reporting\ReportTables;
use App\Domain\Shared\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * VAT on sales per rate and per period (`rpt_vat_daily`), net of refunds, for the VAT return: box 1 (VAT due on
 * sales) and box 6 (sales excluding VAT) from the tills. Purchases (boxes 4 and 7) are not in till sales.
 */
final class VatReturnReport implements ReportBuilder
{
    private const T = ReportTables::VAT_DAILY;

    public function __construct(private VatReport $vat) {}

    public function build(ReportOptions $options): ReportResult
    {
        $scope = $options->scope();
        $compare = $options->compareScope();
        $rates = $this->vat->byRate($scope);
        [$net, $vat, $gross] = self::sum($rates);
        $before = $compare === null ? null : self::sum($this->vat->byRate($compare));
        $c = $before !== null;

        $columns = [
            ReportTable::col('code', 'Rate'),
            ReportTable::col('percentage', 'VAT %', 'percent'),
            ReportTable::col('net', 'Net', 'money'),
            ReportTable::col('vat', 'VAT', 'money'),
            ReportTable::col('gross', 'Gross', 'money'),
        ];
        $rateRow = fn (VatRateTotals $r) => ['code' => $r->code !== '' ? $r->code : 'Unnamed rate', 'percentage' => $r->percentage, 'net' => $r->net, 'vat' => $r->vat, 'gross' => $r->gross];
        $total = ['code' => 'Total', 'percentage' => null, 'net' => $net, 'vat' => $vat, 'gross' => $gross];

        $periodRows = [];

        foreach ($this->byPeriod($scope, $options) as $key => $group) {
            foreach ($group['rates'] as $r) {
                $periodRows[] = ['period' => $group['label'], ...$r];
            }
        }

        return new ReportResult([
            Figures::of('box1', 'VAT due on sales', $vat, 'money', $before[1] ?? null, $c, hint: 'VAT return box 1 (tills only)'),
            Figures::of('box6', 'Sales excluding VAT', $net, 'money', $before[0] ?? null, $c, hint: 'VAT return box 6 (tills only)'),
            Figures::of('gross', 'Sales including VAT', $gross, 'money', $before[2] ?? null, $c),
        ], [
            new ReportTable('rates', 'By VAT rate', $columns, array_map($rateRow, $rates), $total),
            new ReportTable('periods', 'By '.$options->group->value.' and rate', [ReportTable::col('period', 'Period'), ...$columns], $periodRows, ['period' => 'Total', ...$total], empty: 'No VAT in this range.'),
        ], null, [
            'Figures are sales through your tills, net of refunds, on the day of each sale (Europe/London). Add other income, and your purchases (boxes 4 and 7), before you file.',
            'Order deposits and charity round-ups are not sales and carry no VAT here; VAT on an order is due when the goods are sold at collection.',
        ]);
    }

    /**
     * Rates per period, oldest first: one grouped query per trading day and rate, folded into the periods in PHP.
     *
     * @return array<string, array{label: string, rates: list<array<string, string|null>>}>
     */
    private function byPeriod(ReportScope $scope, ReportOptions $options): array
    {
        $decimals = ReportTables::TABLES[self::T]['decimals'];
        $rows = $scope->query(RptVatDaily::class)
            ->groupBy(self::T.'.trading_day', self::T.'.vat_rate_id', self::T.'.percentage')
            ->select([DB::raw(self::T.'.trading_day as day'), self::T.'.vat_rate_id', self::T.'.percentage', DB::raw('MAX('.self::T.'.code) as code'), ...Sums::select(self::T, $decimals)])
            ->orderBy(self::T.'.trading_day')->get();

        $periods = [];

        foreach ($rows as $row) {
            $key = $options->group->key(substr((string) $row->day, 0, 10));
            $s = Sums::read($row, $decimals);
            $pct = Money::normalise($row->percentage ?? 0, 2);
            $rateKey = $row->vat_rate_id.'|'.$pct;
            $periods[$key] ??= ['label' => $options->group->label($key), 'rates' => []];
            $had = $periods[$key]['rates'][$rateKey] ?? ['code' => (string) ($row->code ?? '') !== '' ? (string) $row->code : 'Unnamed rate', 'percentage' => $pct, 'net' => '0.00', 'vat' => '0.00', 'gross' => '0.00'];

            foreach (['net', 'vat', 'gross'] as $col) {
                $had[$col] = Money::add($had[$col], (string) $s[$col]);
            }

            $periods[$key]['rates'][$rateKey] = $had;
        }

        ksort($periods);

        foreach ($periods as $key => $period) {
            $rates = array_values($period['rates']);
            usort($rates, fn (array $a, array $b) => [$a['code'], $a['percentage']] <=> [$b['code'], $b['percentage']]);
            $periods[$key]['rates'] = $rates;
        }

        return $periods;
    }

    /**
     * @param  list<VatRateTotals>  $rates
     * @return array{0: string, 1: string, 2: string} net, VAT, gross
     */
    private static function sum(array $rates): array
    {
        return [
            Money::sum(array_map(fn (VatRateTotals $r) => $r->net, $rates)),
            Money::sum(array_map(fn (VatRateTotals $r) => $r->vat, $rates)),
            Money::sum(array_map(fn (VatRateTotals $r) => $r->gross, $rates)),
        ];
    }
}
