<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Queries\OperationsReport;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\Queries\ShiftLedger;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;

/**
 * Shifts and Z reports: closed shifts with expected against counted (all tenders and cash), the till's variance,
 * per-tender reconciliation, and the Z report list. Cash variance is the dashboard tile's figure (§2.5).
 */
final class ShiftsReport implements ReportBuilder
{
    public function __construct(private ShiftLedger $ledger, private OperationsReport $operations) {}

    public function build(ReportOptions $options): ReportResult
    {
        $scope = $options->scope();
        $s = $this->ledger->summary($scope);
        $cash = $this->operations->cashVariance($scope);
        $allShops = $options->window->branchId === null;

        $summary = [
            Figures::of('shifts', 'Shifts closed', $s['shifts'], 'count', hint: $s['open'] > 0 ? $s['open'].' open now' : null),
            Figures::of('cashVariance', 'Cash variance', $cash['variance'], 'signedMoney', hint: 'Negative = short'),
            Figures::of('variance', 'Variance, all tenders', $s['variance'], 'signedMoney'),
            Figures::of('short', 'Shifts short', $s['short'], 'count', goodWhen: 'down', hint: $s['over'].' over'),
            Figures::of('warnings', 'Over the alert threshold', $s['warnings'], 'count', goodWhen: 'down', hint: 'The till\'s own warning flag'),
        ];

        if ($options->view === 'z') {
            $z = $this->ledger->zReports($scope, $options->limit(), $options->offset());

            return new ReportResult($summary, [new ReportTable('z', 'Z reports', [
                ReportTable::col('sequenceNo', 'Z no.', 'count'),
                ...($allShops ? [ReportTable::col('shop', 'Shop')] : []),
                ReportTable::col('till', 'Till'),
                ReportTable::col('periodStart', 'From', 'datetime'),
                ReportTable::col('periodEnd', 'To', 'datetime'),
                ReportTable::col('printedAt', 'Printed', 'datetime'),
                ReportTable::col('reprints', 'Reprints', 'count'),
                ReportTable::col('variance', 'Variance', 'signedMoney'),
                ReportTable::col('warning', 'Alert', 'flag'),
            ], $z['rows'], null, 'Z reports whose period ended in the range, newest first. The printable Z is on the till.', 'No Z reports in this range.', $options->export ? null : ReportTable::page($z['total'], $options))]);
        }

        $shifts = $this->ledger->shifts($scope, $options->limit(), $options->offset());

        return new ReportResult($summary, [
            new ReportTable('shifts', 'Closed shifts', [
                ReportTable::col('closedAt', 'Closed', 'datetime'),
                ...($allShops ? [ReportTable::col('shop', 'Shop')] : []),
                ReportTable::col('till', 'Till'),
                ReportTable::col('user', 'User'),
                ReportTable::col('float', 'Float', 'money'),
                ReportTable::col('cashExpected', 'Cash expected', 'money'),
                ReportTable::col('cashDeclared', 'Cash counted', 'money'),
                ReportTable::col('cashVariance', 'Cash variance', 'signedMoney'),
                ReportTable::col('expected', 'All expected', 'money'),
                ReportTable::col('declared', 'All counted', 'money'),
                ReportTable::col('variance', 'Variance', 'signedMoney'),
                ReportTable::col('z', 'Z no.', 'count'),
                ReportTable::col('warning', 'Alert', 'flag'),
            ], $shifts['rows'], null, 'Newest first. Variance = counted − expected: negative is short, positive is over.', 'No shifts were closed in this range.', $options->export ? null : ReportTable::page($shifts['total'], $options)),
            new ReportTable('tenders', 'Per payment type', [
                ReportTable::col('closedAt', 'Shift closed', 'datetime'),
                ReportTable::col('till', 'Till'),
                ReportTable::col('tender', 'Payment type'),
                ReportTable::col('expected', 'Expected', 'money'),
                ReportTable::col('declared', 'Counted', 'money'),
                ReportTable::col('terminal', 'Card terminal', 'money'),
                ReportTable::col('variance', 'Variance', 'signedMoney'),
            ], $shifts['tenders'], null, $options->export ? null : 'The reconciliation lines of the shifts on this page.', 'No reconciliation lines.'),
        ], null, ['Expected figures are the till\'s own (sales, float, paid in and out, cashback), as counted at close.']);
    }
}
