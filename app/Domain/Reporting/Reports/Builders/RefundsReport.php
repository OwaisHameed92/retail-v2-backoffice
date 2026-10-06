<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Queries\StaffReport;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\Queries\ProductBreakdown;
use App\Domain\Reporting\Reports\Queries\SalesBreakdown;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Shared\Country\Country;

/**
 * Refunds and voids (DASHBOARD.md §1.5, 3.1 "Voids"): refunds shown positive, on the day they were given; voided
 * baskets were never completed and are in no sales figure. By period, by till, by till user and the most refunded
 * products. `rpt_sales_daily`, `rpt_staff_daily`, `rpt_product_daily` only.
 */
final class RefundsReport implements ReportBuilder
{
    public function __construct(private SalesReport $sales, private SalesBreakdown $breakdown, private StaffReport $staff, private ProductBreakdown $products) {}

    public function build(ReportOptions $options): ReportResult
    {
        $scope = $options->scope();
        $compare = $options->compareScope();
        $now = $this->sales->totals($scope);
        $before = $compare === null ? null : $this->sales->totals($compare);
        $c = $before !== null;

        $summary = [
            Figures::of('refundGross', 'Refunds', $now->refundGross, 'money', $before?->refundGross, $c, 'down', Country::tax('Inc VAT')),
            Figures::of('refundCount', 'Refunds given', $now->refundCount, 'count', $before?->refundCount, $c, 'down'),
            Figures::of('refundRate', 'Refunds as % of sales', Figures::percent($now->refundGross, $now->gross), 'percent', $before === null ? null : Figures::percent($before->refundGross, $before->gross), $c, 'down'),
            Figures::of('voidTotal', 'Voided baskets', $now->voidTotal, 'money', $before?->voidTotal, $c, 'down', 'Never completed, not in sales'),
            Figures::of('voidCount', 'Voids', $now->voidCount, 'count', $before?->voidCount, $c, 'down'),
        ];

        $columns = fn (string $heading) => [
            ReportTable::col('label', $heading),
            ReportTable::col('refundCount', 'Refunds', 'count'),
            ReportTable::col('refundGross', Country::tax('Refunded inc VAT'), 'money'),
            ReportTable::col('refundNet', Country::tax('Refunded ex VAT'), 'money'),
            ReportTable::col('voidCount', 'Voids', 'count'),
            ReportTable::col('voidTotal', 'Voided value', 'money'),
        ];
        $row = fn (string $label, SalesTotals $t) => ['label' => $label, 'refundCount' => $t->refundCount, 'refundGross' => $t->refundGross, 'refundNet' => $t->refundNet, 'voidCount' => $t->voidCount, 'voidTotal' => $t->voidTotal];
        $total = $row('Total', $now);

        $tables = [new ReportTable('periods', 'By '.$options->group->value, $columns('Period'), array_map(fn (array $p) => $row($p['label'], $p['totals']), $this->breakdown->byPeriod($scope, $options->group)), $total)];

        if ($options->window->registerId === null) {
            $tills = array_values(array_filter($this->breakdown->byRegister($scope), fn (array $g) => $g['totals']->refundCount > 0 || $g['totals']->voidCount > 0));
            $tables[] = new ReportTable('tills', 'By till', $columns('Till'), array_map(fn (array $g) => $row($g['label'].($g['shop'] !== '' && $options->window->branchId === null ? ' ('.$g['shop'].')' : ''), $g['totals']), $tills), $total, empty: 'No refunds or voids on any till.');
        }

        $staff = array_values(array_filter($this->staff->byUser($scope), fn ($s) => $s->refundCount > 0 || $s->voidCount > 0));
        $tables[] = new ReportTable('staff', 'By till user', [
            ReportTable::col('label', 'Till user'),
            ReportTable::col('refundCount', 'Refunds', 'count'),
            ReportTable::col('refundGross', Country::tax('Refunded inc VAT'), 'money'),
            ReportTable::col('voidCount', 'Voids', 'count'),
        ], array_map(fn ($s) => ['label' => $s->name, 'refundCount' => $s->refundCount, 'refundGross' => $s->refundGross, 'voidCount' => $s->voidCount], $staff), null, 'The user who served the refund or voided the basket.', 'No refunds or voids by anyone.');

        $refunded = $this->products->products($scope, $options->export ? $options->limit() : 20, 0, 'refund_net', true);
        $tables[] = new ReportTable('products', 'Most refunded products', [
            ReportTable::col('name', 'Product'),
            ReportTable::col('department', 'Department'),
            ReportTable::col('refundQty', 'Returned', 'qty'),
            ReportTable::col('refundNet', Country::tax('Refunded ex VAT'), 'money'),
        ], array_map(fn (array $p) => ['name' => $p['name'], 'department' => $p['department'], 'refundQty' => $p['refund_qty'], 'refundNet' => $p['refund_net']], $refunded), null, $options->export ? null : 'Top 20; the CSV holds every refunded product.', 'No products were refunded.');

        return new ReportResult($summary, $tables, [
            'type' => 'series',
            'metric' => Country::tax('Refunds inc VAT'),
            'points' => array_map(fn (array $r) => ['label' => $r['label'], 'value' => $r['refundGross'], 'compare' => null, 'compareLabel' => null], $tables[0]->rows),
        ]);
    }
}
