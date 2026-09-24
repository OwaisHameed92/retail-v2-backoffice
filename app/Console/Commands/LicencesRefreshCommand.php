<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Actions\RefreshLicenceStatuses;
use Illuminate\Console\Command;

/**
 * Moves stored licence statuses along their dates (trial → grace → expired, active → grace → expired).
 * Idempotent. Scheduled daily in routes/console.php. Prints counts only, never keys.
 */
class LicencesRefreshCommand extends Command
{
    protected $signature = 'licences:refresh';

    protected $description = 'Move licence statuses along their trial, expiry and grace dates';

    public function handle(RefreshLicenceStatuses $refresh): int
    {
        $moves = $refresh->handle();

        if ($moves === []) {
            $this->info('All licence statuses are up to date.');

            return self::SUCCESS;
        }

        $this->info('Updated '.array_sum($moves).' '.(array_sum($moves) === 1 ? 'licence' : 'licences').'.');

        foreach ($moves as $move => $count) {
            $this->line("  {$move}: {$count}");
        }

        return self::SUCCESS;
    }
}
