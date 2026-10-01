<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\SendDigests;
use Illuminate\Console\Command;

/**
 * Module 7.8: queues the daily alert digest of every portal user who chose it. Scheduled at 07:00 London in
 * routes/console.php; safe to run again the same day (each user gets one digest per London day). Prints counts only.
 */
class AlertsDigestCommand extends Command
{
    protected $signature = 'alerts:digest {--company=* : Only these business ids}';

    protected $description = 'Email the daily alert digest (tills, sync, stock, cash, compliance, sync conflicts) to owners and managers';

    public function handle(SendDigests $digests): int
    {
        /** @var list<string> $companies */
        $companies = array_values(array_filter((array) $this->option('company'), 'is_string'));
        $totals = $digests->handle(null, $companies === [] ? null : $companies);

        $this->info("Queued {$totals['sent']} digests for {$totals['companies']} businesses.");
        $this->line("  Nothing to say: {$totals['empty']}, already sent today: {$totals['already']}");

        return self::SUCCESS;
    }
}
