<?php

namespace App\Console\Commands;

use App\Domain\Reporting\Actions\ProcessDirtyReportDays;
use App\Domain\Reporting\Jobs\ProcessDirtyReportDaysJob;
use App\Domain\Reporting\Support\DirtyDays;
use Illuminate\Console\Command;

/**
 * Module 3.1: the safety net behind the per-push rebuild job. Scheduled every minute: re-queues the businesses whose
 * shop-days have waited longer than `reporting.sweep_after_minutes` (a lost or failed job). `--now` rebuilds them
 * here instead of queueing.
 */
class ReportsProcessDirtyCommand extends Command
{
    protected $signature = 'reports:process-dirty {--now : Rebuild in this process instead of queueing}';

    protected $description = 'Queue (or run) the rebuild of reporting days waiting too long';

    public function handle(ProcessDirtyReportDays $process): int
    {
        $minutes = max(0, (int) config('reporting.sweep_after_minutes', 2));
        $companies = DirtyDays::companiesMarkedBefore(now('UTC')->subMinutes($minutes)->addSecond()->format('Y-m-d H:i:s'));
        $days = 0;

        foreach ($companies as $companyId) {
            if ($this->option('now')) {
                $days += $process->handle($companyId);
            } else {
                ProcessDirtyReportDaysJob::dispatch($companyId);
            }
        }

        $this->info($this->option('now')
            ? 'Rebuilt '.$days.' shop-days of '.count($companies).' businesses.'
            : 'Queued '.count($companies).' businesses.');

        return self::SUCCESS;
    }
}
