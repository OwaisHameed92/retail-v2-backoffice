<?php

namespace App\Console\Commands;

use App\Domain\MasterCatalogue\Actions\LoadStarterSet;
use App\Domain\Shared\Country\CountryModules;
use Illuminate\Console\Command;

/**
 * Loads the master catalogue's starter set (~600 UK convenience lines from the demo catalogue, marked "Starter set").
 * Safe to run again: rows an admin or an import changed are left alone. See docs/master-catalogue.md. Refused where
 * the country profile hides the UK starter set (Pakistan plan P10).
 */
class CatalogueStarterCommand extends Command
{
    protected $signature = 'catalogue:starter';

    protected $description = 'Load the starter set into the SSPOS master catalogue (barcode lookup and starter packs)';

    public function handle(LoadStarterSet $load): int
    {
        if (! CountryModules::on(CountryModules::UK_STARTER_SET)) {
            $this->error('The starter set is UK products: it is not offered on this country\'s instance.');

            return self::FAILURE;
        }

        $counts = $load->handle();

        $this->info("Starter set: {$counts['created']} added, {$counts['updated']} updated, {$counts['unchanged']} unchanged, {$counts['skipped']} skipped.");

        return self::SUCCESS;
    }
}
