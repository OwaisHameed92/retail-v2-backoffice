<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\Queries\StockLevels;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;

/**
 * Stock on hand now, per shop and product, from the tills' own stock rows (`BranchProduct`). Before any till has
 * sent stock the report is "not available" and says it comes with stock control (module 5.1).
 */
final class StockReport implements ReportBuilder
{
    public function __construct(private StockLevels $stock) {}

    public function build(ReportOptions $options): ReportResult
    {
        if (! $this->stock->hasAny($options->companyId())) {
            return new ReportResult([], [], null, ['Stock figures come with stock control (module 5.1): once your tills send their stock, on-hand, low-stock and stock value appear here.'], false);
        }

        $scope = $options->scope();
        $view = in_array($options->view, ['low', 'out'], true) ? $options->view : 'all';
        $s = $this->stock->summary($scope);
        $lines = $this->stock->lines($scope, $view, $options->limit(), $options->offset());
        $allShops = $options->window->branchId === null;

        return new ReportResult([
            Figures::of('lines', 'Stocked lines', $s['lines'], 'count', hint: 'Active, stock-tracked'),
            Figures::of('units', 'Units on hand', $s['units'], 'qty'),
            Figures::of('value', 'Stock value at cost', $s['costed'] > 0 ? $s['value'] : null, 'money', hint: $s['costed'] > 0 ? $s['costed'].' of '.$s['lines'].' lines have a cost' : 'No product has a cost price'),
            Figures::of('low', 'Low stock', $s['low'], 'count', goodWhen: 'down', hint: 'At or below the reorder point'),
            Figures::of('out', 'Out of stock', $s['out'], 'count', goodWhen: 'down'),
        ], [
            new ReportTable('lines', match ($view) {
                'low' => 'Low stock lines',
                'out' => 'Out of stock lines',
                default => 'Stock on hand',
            }, [
                ReportTable::col('name', 'Product'),
                ReportTable::col('sku', 'Code'),
                ...($allShops ? [ReportTable::col('shop', 'Shop')] : []),
                ReportTable::col('onHand', 'On hand', 'qty'),
                ReportTable::col('reserved', 'Reserved', 'qty'),
                ReportTable::col('available', 'Available', 'qty'),
                ReportTable::col('reorderAt', 'Reorder at', 'qty'),
                ReportTable::col('cost', 'Cost', 'money'),
                ReportTable::col('value', 'Value at cost', 'money'),
                ReportTable::col('status', 'Status', 'status'),
            ], $lines['rows'], null, 'Out of stock first, then low. Reorder at: the line\'s reorder point, else its minimum, else the product\'s, else the shop\'s low-stock setting (5 when none).',
                match ($view) {
                    'low' => 'No low stock lines. Well done.',
                    'out' => 'Nothing is out of stock.',
                    default => 'No stock-tracked lines in this shop yet.',
                }, $options->export ? null : ReportTable::page($lines['total'], $options)),
        ], null, ['Stock is as the tills last sent it (now); the date range does not apply.']);
    }
}
