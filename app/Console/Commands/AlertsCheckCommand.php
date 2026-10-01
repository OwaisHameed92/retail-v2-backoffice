<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\DispatchUrgentAlerts;
use Illuminate\Console\Command;

/**
 * Module 7.8: urgent owner alerts (till offline, sync failing) from the Till health alerts, with their "resolved"
 * emails. Scheduled every 5 minutes, two minutes after `till-health:refresh`. Idempotent. Prints counts only.
 */
class AlertsCheckCommand extends Command
{
    protected $signature = 'alerts:check {--company=* : Only these business ids}';

    protected $description = 'Email owners straight away about tills offline and sync failing, and when they clear';

    public function handle(DispatchUrgentAlerts $dispatch): int
    {
        /** @var list<string> $companies */
        $companies = array_values(array_filter((array) $this->option('company'), 'is_string'));
        $totals = $dispatch->handle(null, $companies === [] ? null : $companies);

        $this->info("Alert emails queued: {$totals['emailed']}, bell entries: {$totals['notified']}.");
        $this->line("  Resolved: {$totals['resolved']}, held back (within 6 hours): {$totals['muted']}");

        return self::SUCCESS;
    }
}
