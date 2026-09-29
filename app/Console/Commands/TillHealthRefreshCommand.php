<?php

namespace App\Console\Commands;

use App\Domain\TillHealth\Actions\RefreshTillHealth;
use Illuminate\Console\Command;

/**
 * Module 2.7: rebuilds the till and shop health rows and raises / clears the health alerts. Idempotent; scheduled
 * every 5 minutes in routes/console.php. Prints counts only.
 */
class TillHealthRefreshCommand extends Command
{
    protected $signature = 'till-health:refresh {--company=* : Only these business ids}';

    protected $description = 'Work out every till\'s health (online, versions, sync, clock) and raise or clear health alerts';

    public function handle(RefreshTillHealth $refresh): int
    {
        /** @var list<string> $companies */
        $companies = array_values(array_filter((array) $this->option('company'), 'is_string'));
        $totals = $refresh->handle(null, $companies === [] ? null : $companies);

        $this->info("Checked {$totals['tills']} tills in {$totals['branches']} shops of {$totals['companies']} businesses.");
        $this->line("  Alerts raised: {$totals['raised']}, cleared: {$totals['resolved']}");

        return self::SUCCESS;
    }
}
