<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Reporting\Reports\ReportKind;

/**
 * Read: refunds and voided baskets for a period (the "Refunds and voids" report, module 4.8).
 */
final class GetRefundsAndVoids extends GetReportFigures
{
    public function name(): string
    {
        return 'get_refunds_and_voids';
    }

    public function description(): string
    {
        return 'Refunds and voided baskets for a period, with the change against the previous period: totals, by day '
            .'(or week / month), by till, by till user and the most refunded products.';
    }

    protected function report(): ReportKind
    {
        return ReportKind::Refunds;
    }
}
