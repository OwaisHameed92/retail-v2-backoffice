<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\Queries\ProductBreakdown;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Shared\Support\Money;

/**
 * Product and department sales from `rpt_product_daily`: quantity (sold − returned), net, gross, cost, gross profit
 * and margin. Profit and margin are "—" without a cost (most shops enter none, DASHBOARD.md §1.2). Products are
 * paged on screen, best sellers first; the CSV and print hold every product.
 */
final class ProductSalesReport implements ReportBuilder
{
    public function __construct(private ProductBreakdown $products) {}

    public function build(ReportOptions $options): ReportResult
    {
        $scope = $options->scope();
        $compare = $options->compareScope();
        $now = $this->products->totals($scope);
        $before = $compare === null ? null : $this->products->totals($compare);
        $count = $this->products->productCount($scope);
        $c = $before !== null;

        $summary = array_values(array_filter([
            Figures::of('net', 'Net sales', $now['net'], 'money', $before['net'] ?? null, $c),
            Figures::of('qty', 'Quantity sold', $now['qty'], 'qty', $before['qty'] ?? null, $c, hint: 'After returns'),
            Figures::of('products', 'Products sold', $count, 'count'),
            Money::isZero($now['cost']) ? null : Figures::of('profit', 'Gross profit', Figures::profit($now['net'], $now['cost']), 'money', $before === null ? null : Figures::profit($before['net'], $before['cost']), $c),
            Money::isZero($now['cost']) ? null : Figures::of('margin', 'Margin', Figures::margin($now['net'], $now['cost']), 'percent'),
        ]));

        $departments = $this->products->departments($scope);
        $products = $this->products->products($scope, $options->limit(), $options->offset());

        return new ReportResult($summary, [
            new ReportTable('departments', 'By department', [
                ReportTable::col('name', 'Department'),
                ReportTable::col('qty', 'Quantity', 'qty'),
                ReportTable::col('gross', 'Sales inc VAT', 'money'),
                ReportTable::col('net', 'Net sales', 'money'),
                ReportTable::col('share', 'Share of net', 'percent'),
                ReportTable::col('cost', 'Cost', 'money'),
                ReportTable::col('profit', 'Gross profit', 'money'),
                ReportTable::col('margin', 'Margin', 'percent'),
            ], array_map(fn (array $d) => $this->row($d, $now['net']) + ['name' => $d['name']], $departments), $this->row($now, $now['net']) + ['name' => 'Total']),
            new ReportTable('products', 'By product', [
                ReportTable::col('name', 'Product'),
                ReportTable::col('sku', 'Code'),
                ReportTable::col('department', 'Department'),
                ReportTable::col('qty', 'Quantity', 'qty'),
                ReportTable::col('refundQty', 'Returned', 'qty'),
                ReportTable::col('gross', 'Sales inc VAT', 'money'),
                ReportTable::col('net', 'Net sales', 'money'),
                ReportTable::col('cost', 'Cost', 'money'),
                ReportTable::col('profit', 'Gross profit', 'money'),
                ReportTable::col('margin', 'Margin', 'percent'),
            ], array_map(fn (array $p) => $this->row($p, $now['net']) + ['name' => $p['name'], 'sku' => $p['sku'], 'department' => $p['department'], 'refundQty' => $p['refund_qty']], $products),
                $this->row($now, $now['net']) + ['name' => 'Total', 'refundQty' => $now['refund_qty']],
                'Best sellers first by net sales. Department and name are the product\'s current ones.',
                pagination: $options->export ? null : ReportTable::page($count, $options)),
        ], null, ['Profit and margin use the cost recorded on each sale line; lines without a cost count as zero cost.']);
    }

    /**
     * @param  array<string, string|null>  $s
     * @return array<string, string|null>
     */
    private function row(array $s, string $totalNet): array
    {
        $net = (string) $s['net'];
        $cost = Money::round((string) $s['cost'], 2);

        return [
            'qty' => $s['qty'], 'gross' => $s['gross'], 'net' => $net, 'share' => Figures::percent($net, $totalNet),
            'cost' => Money::isZero($cost) ? null : $cost, 'profit' => Figures::profit($net, $cost), 'margin' => Figures::margin($net, $cost),
        ];
    }
}
