<?php

namespace App\Domain\Reporting\Models;

/**
 * `rpt_sales_hourly` (contract v1.4.1 DASHBOARD.md §4.3): per shop, till, trading day and local hour.
 *
 * @property int $hour
 * @property string $net
 * @property string $gross
 * @property int $txn_count
 */
final class RptSalesHourly extends ReportRow
{
    protected $table = 'rpt_sales_hourly';
}
