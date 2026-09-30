<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Data\StaffSales;
use App\Domain\Reporting\Queries\StaffReport;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Shared\Support\Money;

/**
 * Staff sales per till user (`rpt_staff_daily`, `Sale.userId`), best first, each against the compare window.
 */
final class StaffSalesReport implements ReportBuilder
{
    public function __construct(private StaffReport $staff) {}

    public function build(ReportOptions $options): ReportResult
    {
        $compare = $options->compareScope();
        $users = $this->staff->byUser($options->scope());
        $previous = [];

        foreach ($compare === null ? [] : $this->staff->byUser($compare) as $s) {
            $previous[$s->userId] = $s;
        }

        $c = $compare !== null;
        $net = Money::sum(array_map(fn (StaffSales $s) => $s->net, $users));
        $gross = Money::sum(array_map(fn (StaffSales $s) => $s->gross, $users));
        $txns = array_sum(array_map(fn (StaffSales $s) => $s->transactions, $users));
        $refundCount = array_sum(array_map(fn (StaffSales $s) => $s->refundCount, $users));
        $refundGross = Money::sum(array_map(fn (StaffSales $s) => $s->refundGross, $users));
        $voids = array_sum(array_map(fn (StaffSales $s) => $s->voidCount, $users));
        $netBefore = $c ? Money::sum(array_map(fn (StaffSales $s) => $s->net, $previous)) : null;

        $rows = array_map(fn (StaffSales $s) => [
            'name' => $s->name, 'transactions' => $s->transactions, 'gross' => $s->gross, 'net' => $s->net, 'share' => Figures::percent($s->net, $net),
            'averageBasket' => $s->averageBasketExVat, 'refundCount' => $s->refundCount, 'refundGross' => $s->refundGross, 'voidCount' => $s->voidCount,
            ...($c ? ['change' => Figures::change($s->net, $previous[$s->userId]->net ?? null)] : []),
        ], $users);

        return new ReportResult([
            Figures::of('net', 'Net sales', $net, 'money', $netBefore, $c),
            Figures::of('staff', 'Till users who sold', count(array_filter($users, fn (StaffSales $s) => $s->transactions > 0)), 'count'),
            Figures::of('averageBasket', 'Average basket', Figures::average($net, $txns), 'money', hint: 'Excluding VAT'),
            Figures::of('voids', 'Voids', $voids, 'count', goodWhen: 'down'),
        ], [
            new ReportTable('staff', 'By till user', [
                ReportTable::col('name', 'Till user'),
                ReportTable::col('transactions', 'Transactions', 'count'),
                ReportTable::col('gross', 'Sales inc VAT', 'money'),
                ReportTable::col('net', 'Net sales', 'money'),
                ReportTable::col('share', 'Share', 'percent'),
                ReportTable::col('averageBasket', 'Average basket', 'money'),
                ReportTable::col('refundCount', 'Refunds', 'count'),
                ReportTable::col('refundGross', 'Refunded', 'money'),
                ReportTable::col('voidCount', 'Voids', 'count'),
                ...($c ? [ReportTable::col('change', 'Net vs compare', 'percent')] : []),
            ], $rows, [
                'name' => 'Total', 'transactions' => $txns, 'gross' => $gross, 'net' => $net, 'share' => null, 'averageBasket' => Figures::average($net, $txns),
                'refundCount' => $refundCount, 'refundGross' => $refundGross, 'voidCount' => $voids, ...($c ? ['change' => Figures::change($net, $netBefore)] : []),
            ], 'The user logged in on the till for each sale. Names are the staff list\'s current ones.', 'No sales by any till user in this range.'),
        ]);
    }
}
