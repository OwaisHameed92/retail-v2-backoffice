<?php

namespace App\Console\Commands;

use App\Domain\Billing\Actions\BackfillSetupFeeCoverage;
use Illuminate\Console\Command;

/**
 * P11: marks the tills of every business whose setup fee is settled as covered, so moving to a per-till setup fee
 * never charges them again. Run once on each server after the P11 deploy; safe to run again (idempotent).
 */
class BillingBackfillSetupFeeCoverageCommand extends Command
{
    protected $signature = 'billing:backfill-setup-fee-coverage {--dry-run : Show what would change, change nothing}';

    protected $description = 'Mark the tills of businesses whose setup fee is paid as covered by it (per-till setup fee, P11)';

    public function handle(BackfillSetupFeeCoverage $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changes = $backfill->handle($dryRun);

        if ($changes === []) {
            $this->info('Every business with a paid setup fee already has its tills covered. Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(['Business', 'Covered before', 'Covered now'], array_map(
            fn (array $row) => [$row['name'], $row['before'] ?? 'not tracked', $row['after']],
            $changes,
        ));

        $count = count($changes);
        $this->info(($dryRun ? 'Would update ' : 'Updated ').$count.' '.($count === 1 ? 'business' : 'businesses').'.');

        return self::SUCCESS;
    }
}
