<?php

namespace App\Domain\Reporting\Reports;

/**
 * One report of module 4.8. Sales builders read `rpt_*` only (through the 3.1 read side or `SalesBreakdown`); stock
 * and shifts read the till's rows with indexed, grouped queries. Tenant scope only.
 */
interface ReportBuilder
{
    public function build(ReportOptions $options): ReportResult;
}
