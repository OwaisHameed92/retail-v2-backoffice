<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Signing\Actions\PruneSigningKeys;
use Illuminate\Console\Command;

/**
 * Deletes retired licence signing keys past the keep period. Scheduled daily in routes/console.php.
 */
class LicenceKeysPruneCommand extends Command
{
    protected $signature = 'licence:keys:prune';

    protected $description = 'Delete retired licence signing keys older than the keep period';

    public function handle(PruneSigningKeys $prune): int
    {
        $pruned = $prune->handle();

        $this->info($pruned === []
            ? 'No expired licence signing keys to prune.'
            : 'Pruned: '.implode(', ', $pruned).'.');

        return self::SUCCESS;
    }
}
