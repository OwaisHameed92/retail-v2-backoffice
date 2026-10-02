<?php

namespace App\Console\Commands;

use App\Domain\Anomalies\Actions\DetectAnomalies;
use App\Domain\Anomalies\Data\DetectionWindow;
use Illuminate\Console\Command;

/**
 * Module 6.6: the anomaly checks. Scheduled hourly (today so far) and daily at 06:30 London (yesterday) in
 * routes/console.php; safe to run again (findings are de-duplicated). Prints counts only.
 */
class AnomaliesDetectCommand extends Command
{
    protected $signature = 'anomalies:detect {--daily : Check yesterday with every daily detector (default: the hourly checks of today)} {--company=* : Only these business ids}';

    protected $description = 'Look for unusual activity (staff voids and refunds, sales gaps, cash shortfalls, out-of-hours sales…) and alert';

    public function handle(DetectAnomalies $detect): int
    {
        /** @var list<string> $companies */
        $companies = array_values(array_filter((array) $this->option('company'), 'is_string'));
        $mode = $this->option('daily') ? DetectionWindow::DAILY : DetectionWindow::HOURLY;
        $totals = $detect->handle($mode, null, $companies === [] ? null : $companies);

        $this->info("Checked {$totals['companies']} businesses ({$mode}): {$totals['found']} findings, {$totals['raised']} new or more serious.");
        $this->line("  Bell entries: {$totals['notified']}, emails queued: {$totals['emailed']}");

        return self::SUCCESS;
    }
}
