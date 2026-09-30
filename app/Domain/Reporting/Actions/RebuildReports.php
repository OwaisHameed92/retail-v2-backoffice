<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Support\ReportDayPlan;
use App\Domain\Reporting\Support\SaleDayStamper;
use Closure;

/**
 * Full rebuild of the reporting tables from the raw till rows (`reports:rebuild`, DASHBOARD.md §4.8 "formula change
 * on your side"): for each business, stamp the trading day of any sale that has none (rows stored before 3.1),
 * then rebuild every shop-day in the range a few days at a time. Idempotent; safe to run while tills push.
 */
final class RebuildReports
{
    public function __construct(private readonly RebuildReportDays $rebuild, private readonly SaleDayStamper $stamper) {}

    /**
     * @param  list<string>  $companies  empty = every business
     * @param  (Closure(string, int): void)|null  $progress  called per business with the shop-days rebuilt
     * @return array{companies: int, days: int, rows: int, stamped: int}
     */
    public function handle(array $companies = [], ?string $from = null, ?string $to = null, ?Closure $progress = null): array
    {
        $totals = ['companies' => 0, 'days' => 0, 'rows' => 0, 'stamped' => 0];
        $perChunk = max(1, (int) config('reporting.days_per_rebuild', 7));

        foreach (ReportDayPlan::companies($companies) as $companyId) {
            $totals['stamped'] += $this->stamper->stampMissing($companyId);
            $days = 0;

            foreach (ReportDayPlan::chunks($companyId, $from, $to, $perChunk) as $chunk) {
                $totals['rows'] += $this->rebuild->handle($companyId, $chunk['branch'], $chunk['days']);
                $days += count($chunk['days']);
            }

            $totals['companies']++;
            $totals['days'] += $days;

            if ($progress !== null) {
                $progress($companyId, $days);
            }
        }

        return $totals;
    }
}
