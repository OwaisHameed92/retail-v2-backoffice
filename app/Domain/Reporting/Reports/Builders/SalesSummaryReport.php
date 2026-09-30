<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\Queries\SalesBreakdown;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;

/**
 * Sales summary: headline figures against the compare window, net sales by period (with the compare window's
 * periods side by side), and the same columns by period, by shop (all shops) and by till. `rpt_sales_daily` only.
 */
final class SalesSummaryReport implements ReportBuilder
{
    public function __construct(private SalesReport $sales, private SalesBreakdown $breakdown) {}

    public function build(ReportOptions $options): ReportResult
    {
        $scope = $options->scope();
        $compareScope = $options->compareScope();
        $now = $this->sales->totals($scope);
        $before = $compareScope === null ? null : $this->sales->totals($compareScope);
        $periods = $this->breakdown->byPeriod($scope, $options->group);
        $previous = $compareScope === null ? [] : $this->breakdown->byPeriod($compareScope, $options->group);

        $tables = [$this->table('periods', 'By '.$options->group->value, 'Period', $periods, $now)];

        if ($options->window->branchId === null) {
            $tables[] = $this->table('shops', 'By shop', 'Shop', $this->breakdown->byBranch($scope), $now);
        }

        if ($options->window->registerId === null) {
            $tables[] = $this->table('tills', 'By till', 'Till', $this->breakdown->byRegister($scope), $now, true);
        }

        return new ReportResult(
            self::summary($now, $before),
            $tables,
            [
                'type' => 'series',
                'metric' => 'Net sales',
                'points' => array_map(fn (array $p, int $i) => [
                    'label' => $p['label'],
                    'value' => $p['totals']->net,
                    'compare' => isset($previous[$i]) ? $previous[$i]['totals']->net : null,
                    'compareLabel' => isset($previous[$i]) ? $previous[$i]['label'] : null,
                ], $periods, array_keys($periods)),
            ],
            ['Sales exclude order deposits and charity round-ups (not sales until the goods are collected); both are in takings.'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function summary(SalesTotals $now, ?SalesTotals $before): array
    {
        $c = $before !== null;

        $figures = [
            Figures::of('net', 'Net sales', $now->net, 'money', $before?->net, $c, hint: 'Excluding VAT, after refunds'),
            Figures::of('gross', 'Sales inc VAT', $now->gross, 'money', $before?->gross, $c),
            Figures::of('vat', 'VAT', $now->vat, 'money', $before?->vat, $c),
            Figures::of('transactions', 'Transactions', $now->transactions, 'count', $before?->transactions, $c),
            Figures::of('averageBasket', 'Average basket', $now->averageBasketExVat(), 'money', $before?->averageBasketExVat(), $c, hint: 'Excluding VAT'),
            Figures::of('takings', 'Takings', $now->takings, 'money', $before?->takings, $c, hint: 'Money taken, deposits included'),
            Figures::of('refunds', 'Refunds', $now->refundGross, 'money', $before?->refundGross, $c, 'down', $now->refundCount.' refunds'),
        ];

        if ($now->grossProfit() !== null) {
            $figures[] = Figures::of('grossProfit', 'Gross profit', $now->grossProfit(), 'money', $before?->grossProfit(), $c, hint: 'Margin '.(Figures::margin($now->net, $now->cost) ?? '—').'%');
        }

        return $figures;
    }

    /**
     * @param  list<array{id: string, label: string, shop?: string, totals: SalesTotals}>  $groups
     */
    private function table(string $key, string $title, string $heading, array $groups, SalesTotals $total, bool $withShop = false): ReportTable
    {
        $columns = [
            ReportTable::col('label', $heading),
            ...($withShop ? [ReportTable::col('shop', 'Shop')] : []),
            ReportTable::col('transactions', 'Transactions', 'count'),
            ReportTable::col('gross', 'Sales inc VAT', 'money'),
            ReportTable::col('vat', 'VAT', 'money'),
            ReportTable::col('net', 'Net sales', 'money'),
            ReportTable::col('discount', 'Discounts', 'money'),
            ReportTable::col('refunds', 'Refunds', 'money'),
            ReportTable::col('averageBasket', 'Average basket', 'money'),
            ReportTable::col('takings', 'Takings', 'money'),
        ];

        $row = fn (string $label, SalesTotals $t, ?string $shop = null) => [
            'label' => $label,
            ...($withShop ? ['shop' => $shop] : []),
            'transactions' => $t->transactions, 'gross' => $t->gross, 'vat' => $t->vat, 'net' => $t->net, 'discount' => $t->discount,
            'refunds' => $t->refundGross, 'averageBasket' => $t->averageBasketExVat(), 'takings' => $t->takings,
        ];

        return new ReportTable(
            $key, $title, $columns,
            array_map(fn (array $g) => $row($g['label'], $g['totals'], $g['shop'] ?? null), $groups),
            $row('Total', $total, $withShop ? '' : null),
        );
    }
}
