<?php

namespace App\Domain\Reporting\Models;

/**
 * `rpt_staff_daily` (contract v1.4.1 DASHBOARD.md §4.6): per shop, till, trading day and cashier (till user).
 *
 * @property string $user_id
 * @property string $gross
 * @property string $net
 * @property string $refund_gross
 * @property int $txn_count
 * @property int $refund_count
 * @property int $void_count
 */
final class RptStaffDaily extends ReportRow
{
    protected $table = 'rpt_staff_daily';
}
