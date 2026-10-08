<?php

namespace App\Console\Commands;

use App\Domain\Shared\Country\TillProfile;
use App\Domain\Tenancy\Actions\SetCountryNation;
use Illuminate\Console\Command;

/**
 * Pak POS pack 2026-10-07 (Branch `nation` "Pakistan"): sets the Pakistan instance's shops still at the column default
 * `england` to `Pakistan`; each one reaches its till at the next pull. Refused on the UK instance. Safe to run again.
 */
class BranchesPakistanNationCommand extends Command
{
    protected $signature = 'branches:pakistan-nation {--dry-run : List the shops that would change, change nothing}';

    protected $description = 'Set the nation of Pakistan shops still at the default (england) to Pakistan';

    public function handle(SetCountryNation $set): int
    {
        if (TillProfile::branchNation() === null) {
            $this->error('Only on the Pakistan instance (COUNTRY=PK): the UK picks a nation per shop.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = $set->handle($dryRun);

        if ($rows === []) {
            $this->info('Every shop already says '.TillProfile::branchNation().'. Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(['Business', 'Shop', 'Code'], array_map(fn (array $row) => [$row['company'], $row['branch'], $row['code']], $rows));
        $this->info(sprintf('%s %d shop%s to %s.', $dryRun ? 'Would set' : 'Set', count($rows), count($rows) === 1 ? '' : 's', TillProfile::branchNation()));

        return self::SUCCESS;
    }
}
