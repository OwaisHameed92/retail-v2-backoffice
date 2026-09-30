<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Data\TenderTotals;
use App\Domain\Reporting\Models\RptTenderDaily;
use App\Domain\Reporting\Queries\Sums;
use App\Domain\Reporting\Queries\TenderReport;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Reporting\ReportTables;
use App\Domain\Shared\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Payments by type (`rpt_tender_daily`, 3.1): amount = money kept (after change and cashback), refunds paid out
 * shown positive; same-named types of several shops are one line. Each type against the compare window, and the
 * takings per period.
 */
final class TendersReport implements ReportBuilder
{
    private const T = ReportTables::TENDER_DAILY;

    public function __construct(private TenderReport $tenders) {}

    public function build(ReportOptions $options): ReportResult
    {
        $scope = $options->scope();
        $compare = $options->compareScope();
        $types = $this->tenders->byPaymentType($scope);
        $previous = [];

        foreach ($compare === null ? [] : $this->tenders->byPaymentType($compare) as $t) {
            $previous[mb_strtolower(trim($t->name))] = $t;
        }

        $amount = Money::sum(array_map(fn (TenderTotals $t) => $t->amount, $types));
        $refunds = Money::sum(array_map(fn (TenderTotals $t) => $t->refunds, $types));
        $payments = array_sum(array_map(fn (TenderTotals $t) => $t->payments, $types));
        $c = $compare !== null;
        $before = $c ? Money::sum(array_map(fn (TenderTotals $t) => $t->amount, $previous)) : null;

        $columns = [
            ReportTable::col('name', 'Payment type'),
            ReportTable::col('payments', 'Payments', 'count'),
            ReportTable::col('amount', 'Amount', 'money'),
            ReportTable::col('share', 'Share', 'percent'),
            ReportTable::col('refunds', 'Refunds paid', 'money'),
            ...($c ? [ReportTable::col('previous', 'Compare period', 'money'), ReportTable::col('change', 'Change', 'percent')] : []),
        ];

        $rows = array_map(function (TenderTotals $t) use ($amount, $previous, $c) {
            $was = $previous[mb_strtolower(trim($t->name))] ?? null;

            return [
                'name' => $t->name, 'payments' => $t->payments, 'amount' => $t->amount, 'share' => Figures::percent($t->amount, $amount), 'refunds' => $t->refunds,
                ...($c ? ['previous' => $was->amount ?? '0.00', 'change' => Figures::change($t->amount, $was?->amount)] : []),
            ];
        }, $types);

        $periods = $this->byPeriod($options);

        return new ReportResult([
            Figures::of('amount', 'Payments taken', $amount, 'money', $before, $c, hint: 'After change and cashback'),
            Figures::of('payments', 'Payments', $payments, 'count'),
            Figures::of('refunds', 'Refunds paid out', $refunds, 'money', goodWhen: 'down'),
        ], [
            new ReportTable('types', 'By payment type', $columns, $rows, ['name' => 'Total', 'payments' => $payments, 'amount' => $amount, 'share' => null, 'refunds' => $refunds, ...($c ? ['previous' => $before, 'change' => Figures::change($amount, $before)] : [])]),
            new ReportTable('periods', 'By '.$options->group->value, [
                ReportTable::col('label', 'Period'),
                ReportTable::col('payments', 'Payments', 'count'),
                ReportTable::col('amount', 'Amount', 'money'),
                ReportTable::col('refunds', 'Refunds paid', 'money'),
            ], $periods, ['label' => 'Total', 'payments' => $payments, 'amount' => $amount, 'refunds' => $refunds]),
        ], [
            'type' => 'series',
            'metric' => 'Payments taken',
            'points' => array_map(fn (array $p) => ['label' => $p['label'], 'value' => $p['amount'], 'compare' => null, 'compareLabel' => null], $periods),
        ], ['Order deposits and loyalty points are payment types like any other here, as on the till\'s own tender report.']);
    }

    /**
     * @return list<array{label: string, payments: int, amount: string, refunds: string}>
     */
    private function byPeriod(ReportOptions $options): array
    {
        $scope = $options->scope();
        $decimals = ReportTables::TABLES[self::T]['decimals'];
        $rows = $scope->query(RptTenderDaily::class)->groupBy(self::T.'.trading_day')
            ->select([DB::raw(self::T.'.trading_day as day'), ...Sums::select(self::T, $decimals, ['count'])])->get()
            ->keyBy(fn ($r) => substr((string) $r->day, 0, 10));
        $out = [];

        for ($day = $scope->from; $day->lessThanOrEqualTo($scope->to); $day = $day->addDay()) {
            $key = $options->group->key($day->toDateString());
            $s = Sums::read($rows->get($day->toDateString()), $decimals, ['count']);
            $had = $out[$key] ?? ['label' => $options->group->label($key), 'payments' => 0, 'amount' => '0.00', 'refunds' => '0.00'];
            $out[$key] = ['label' => $had['label'], 'payments' => $had['payments'] + (int) $s['count'], 'amount' => Money::add($had['amount'], (string) $s['amount']), 'refunds' => Money::add($had['refunds'], (string) $s['refunds'])];
        }

        return array_values($out);
    }
}
