<?php

namespace App\Domain\Reporting\Models;

/**
 * `rpt_tender_daily` (contract v1.4.1 DASHBOARD.md §4.4): takings per shop, till, trading day and payment type.
 *
 * @property string $payment_type_id
 * @property string $payment_type_name
 * @property string $amount
 * @property string $refunds
 * @property int $count
 */
final class RptTenderDaily extends ReportRow
{
    protected $table = 'rpt_tender_daily';
}
