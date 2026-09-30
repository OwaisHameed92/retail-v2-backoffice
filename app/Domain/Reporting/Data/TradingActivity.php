<?php

namespace App\Domain\Reporting\Data;

/**
 * Who traded in a range and how fresh the figures are (module 3.2, DASHBOARD.md §1.8): businesses, shops and
 * tills with reporting rows; the newest rebuild of those rows and the newest till push (ISO-8601 UTC); shop-days
 * still waiting to be rebuilt (`rpt_dirty_days`).
 */
final readonly class TradingActivity
{
    public function __construct(
        public int $companies,
        public int $branches,
        public int $registers,
        public ?string $rebuiltAt,
        public ?string $lastPushAt,
        public int $pendingDays,
    ) {}
}
