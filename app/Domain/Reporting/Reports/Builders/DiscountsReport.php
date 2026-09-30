<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\Queries\ProductBreakdown;
use App\Domain\Reporting\Reports\Queries\SalesBreakdown;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;

/**
 * Discounts by source (`SaleLine.discountSource`, 3.1): offers (promotions), coupons, staff purchases and manual
 * discounts (the line discount less the other three). Discounts of refunded lines are not counted (3.1 rule). By
 * period, by shop or till, and the most discounted products. `rpt_sales_daily` / `rpt_product_daily` only.
 */
final class DiscountsReport implements ReportBuilder
{
    public function __construct(private SalesReport $sales, private SalesBreakdown $breakdown, private ProductBreakdown $products) {}

    public function build(ReportOptions $options): ReportResult
    {
        $scope = $options->scope();
        $compare = $options->compareScope();
        $now = $this->sales->totals($scope);
        $before = $compare === null ? null : $this->sales->totals($compare);
        $c = $before !== null;

        $summary = [
            Figures::of('discount', 'Total discounts', $now->discount, 'money', $before?->discount, $c, 'down', (Figures::percent($now->discount, $now->gross) ?? '0.0').'% of sales inc VAT'),
            Figures::of('promo', 'Offers', $now->promo, 'money', $before?->promo, $c, 'down'),
            Figures::of('coupon', 'Coupons', $now->coupon, 'money', $before?->coupon, $c, 'down'),
            Figures::of('staff', 'Staff discount', $now->staffDiscount, 'money', $before?->staffDiscount, $c, 'down'),
            Figures::of('manual', 'Manual discounts', $now->manualDiscount(), 'money', $before?->manualDiscount(), $c, 'down', 'Given at the till by hand'),
        ];

        $sources = [['Offers (promotions)', $now->promo], ['Coupons', $now->coupon], ['Staff discount', $now->staffDiscount], ['Manual discounts', $now->manualDiscount()]];

        $columns = fn (string $heading) => [
            ReportTable::col('label', $heading),
            ReportTable::col('promo', 'Offers', 'money'),
            ReportTable::col('coupon', 'Coupons', 'money'),
            ReportTable::col('staff', 'Staff', 'money'),
            ReportTable::col('manual', 'Manual', 'money'),
            ReportTable::col('discount', 'Total', 'money'),
            ReportTable::col('rate', '% of sales', 'percent'),
        ];
        $row = fn (string $label, SalesTotals $t) => [
            'label' => $label, 'promo' => $t->promo, 'coupon' => $t->coupon, 'staff' => $t->staffDiscount, 'manual' => $t->manualDiscount(),
            'discount' => $t->discount, 'rate' => Figures::percent($t->discount, $t->gross),
        ];

        $tables = [
            new ReportTable('sources', 'By source', [
                ReportTable::col('label', 'Source'),
                ReportTable::col('amount', 'Amount', 'money'),
                ReportTable::col('share', 'Share', 'percent'),
            ], array_map(fn (array $s) => ['label' => $s[0], 'amount' => $s[1], 'share' => Figures::percent($s[1], $now->discount)], $sources), ['label' => 'Total', 'amount' => $now->discount, 'share' => null]),
            new ReportTable('periods', 'By '.$options->group->value, $columns('Period'), array_map(fn (array $p) => $row($p['label'], $p['totals']), $this->breakdown->byPeriod($scope, $options->group)), $row('Total', $now)),
        ];

        if ($options->window->branchId === null) {
            $tables[] = new ReportTable('shops', 'By shop', $columns('Shop'), array_map(fn (array $g) => $row($g['label'], $g['totals']), $this->breakdown->byBranch($scope)), $row('Total', $now));
        } elseif ($options->window->registerId === null) {
            $tables[] = new ReportTable('tills', 'By till', $columns('Till'), array_map(fn (array $g) => $row($g['label'], $g['totals']), $this->breakdown->byRegister($scope)), $row('Total', $now));
        }

        $top = $this->products->products($scope, $options->export ? $options->limit() : 20, 0, 'discount', true);
        $tables[] = new ReportTable('products', 'Most discounted products', [
            ReportTable::col('name', 'Product'),
            ReportTable::col('department', 'Department'),
            ReportTable::col('qty', 'Quantity', 'qty'),
            ReportTable::col('promo', 'Offers', 'money'),
            ReportTable::col('discount', 'All discounts', 'money'),
            ReportTable::col('net', 'Net sales', 'money'),
        ], array_map(fn (array $p) => ['name' => $p['name'], 'department' => $p['department'], 'qty' => $p['qty'], 'promo' => $p['promo'], 'discount' => $p['discount'], 'net' => $p['net']], $top),
            null, $options->export ? null : 'Top 20; the CSV holds every discounted product.', 'No discounts were given.');

        return new ReportResult($summary, $tables, [
            'type' => 'series',
            'metric' => 'Discounts',
            'points' => array_map(fn (array $r) => ['label' => $r['label'], 'value' => $r['discount'], 'compare' => null, 'compareLabel' => null], $tables[1]->rows),
        ]);
    }
}
