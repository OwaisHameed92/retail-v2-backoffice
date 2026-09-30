<?php

namespace App\Domain\Reporting\Models;

/**
 * `rpt_sales_daily` (contract v1.4.1 DASHBOARD.md §4.2): one row per shop, till and trading day.
 *
 * @property string $gross
 * @property string $net
 * @property string $vat
 * @property string $refund_gross
 * @property string $refund_net
 * @property string $discount
 * @property string $promo
 * @property string $coupon
 * @property string $cost
 * @property string $container_deposits
 * @property string $takings
 * @property string $void_total
 * @property int $txn_count
 * @property int $refund_count
 * @property int $void_count
 */
final class RptSalesDaily extends ReportRow
{
    protected $table = 'rpt_sales_daily';
}
