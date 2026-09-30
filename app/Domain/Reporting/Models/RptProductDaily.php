<?php

namespace App\Domain\Reporting\Models;

/**
 * `rpt_product_daily` (contract v1.4.1 DASHBOARD.md §4.5): per shop, till, trading day and product (department and category are joined when read).
 *
 * @property string $product_id
 * @property string $qty
 * @property string $refund_qty
 * @property string $gross
 * @property string $net
 * @property string $vat
 * @property string $refund_net
 * @property string $discount
 * @property string $promo
 * @property string $cost
 * @property string $last_name
 */
final class RptProductDaily extends ReportRow
{
    protected $table = 'rpt_product_daily';
}
