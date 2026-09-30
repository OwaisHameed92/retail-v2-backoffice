<?php

namespace App\Domain\Reporting\Models;

/**
 * `rpt_vat_daily` (contract v1.4.1 DASHBOARD.md §4.6): per shop, till, trading day, VAT rate and percentage.
 *
 * @property string $vat_rate_id
 * @property string $percentage
 * @property string $code
 * @property string $net
 * @property string $vat
 * @property string $gross
 */
final class RptVatDaily extends ReportRow
{
    protected $table = 'rpt_vat_daily';
}
