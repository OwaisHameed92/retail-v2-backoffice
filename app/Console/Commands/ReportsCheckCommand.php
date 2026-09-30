<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsReportRange;
use App\Domain\Reporting\Actions\CheckReports;
use Illuminate\Console\Command;

/**
 * Module 3.1: proves the incrementally maintained reporting tables equal a full rebuild from the raw rows. Exit 1
 * when any shop-day differs (or sales lack a trading day); `--fix` rebuilds the shop-days that differ.
 */
class ReportsCheckCommand extends Command
{
    use ReadsReportRange;

    protected $signature = 'reports:check
        {--company=* : Only these business ids}
        {--from= : First trading day (Y-m-d)}
        {--to= : Last trading day (Y-m-d)}
        {--fix : Rebuild the shop-days that differ}';

    protected $description = 'Compare the reporting tables (rpt_*) with a rebuild from the raw till rows';

    public function handle(CheckReports $check): int
    {
        $range = $this->range();

        if ($range === null) {
            return self::INVALID;
        }

        $result = $check->handle($this->companies(), $range[0], $range[1], (bool) $this->option('fix'));

        foreach (array_slice($result['mismatches'], 0, 50) as $mismatch) {
            $this->line('  '.$mismatch);
        }

        if ($result['unstamped'] > 0) {
            $this->warn("{$result['unstamped']} sales have no trading day yet: run reports:rebuild.");
        }

        if ($result['mismatches'] === [] && $result['unstamped'] === 0) {
            $this->info("Checked {$result['days']} shop-days: the reporting tables match the raw rows.");

            return self::SUCCESS;
        }

        $count = count($result['mismatches']);
        $this->error("Checked {$result['days']} shop-days: {$count} rows differ.".($result['fixed'] > 0 ? " Rebuilt {$result['fixed']} shop-days." : ''));

        return self::FAILURE;
    }
}
