<?php

namespace App\Domain\Reporting\Data;

/**
 * One local hour (0–23) of "Sales by hour" (DASHBOARD.md §2.3, rpt_sales_hourly). Every hour is included.
 */
final readonly class HourSales
{
    public function __construct(
        public int $hour,
        public string $net,
        public string $gross,
        public int $transactions,
    ) {}
}
