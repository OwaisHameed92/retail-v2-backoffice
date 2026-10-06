<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsReportRange;
use App\Domain\Reporting\Actions\RebuildReports;
use Illuminate\Console\Command;

/**
 * Module 3.1: rebuilds the `rpt_*` reporting tables from the raw till rows (after a formula change, a restore, or
 * once after deploying 3.1 to stamp older sales). Chunked per shop and a few trading days at a time; idempotent.
 */
class ReportsRebuildCommand extends Command
{
    use ReadsReportRange;

    protected $signature = 'reports:rebuild
        {--company=* : Only these business ids}
        {--from= : First trading day (Y-m-d, shop time zone)}
        {--to= : Last trading day (Y-m-d)}';

    protected $description = 'Rebuild the reporting tables (rpt_*) from the raw till rows';

    public function handle(RebuildReports $rebuild): int
    {
        $range = $this->range();

        if ($range === null) {
            return self::INVALID;
        }

        $totals = $rebuild->handle($this->companies(), $range[0], $range[1], function (string $company, int $days) {
            if ($this->output->isVerbose()) {
                $this->line("  {$company}: {$days} shop-days");
            }
        });

        $this->info("Rebuilt {$totals['days']} shop-days ({$totals['rows']} rows) of {$totals['companies']} businesses.");

        if ($totals['stamped'] > 0) {
            $this->line("  Trading day stamped on {$totals['stamped']} older sales.");
        }

        return self::SUCCESS;
    }
}
