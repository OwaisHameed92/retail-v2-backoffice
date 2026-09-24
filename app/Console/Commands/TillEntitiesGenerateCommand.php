<?php

namespace App\Console\Commands;

use App\Domain\TillData\Actions\GenerateTillEntities;
use Illuminate\Console\Command;

/**
 * Regenerates the till entity store (migrations, models, enums, EntityRegistry) from the contract schemas,
 * samples/ownership.json and app/Domain/TillData/definitions.php. See docs/till-data.md.
 */
class TillEntitiesGenerateCommand extends Command
{
    protected $signature = 'till:entities:generate {--check : Only report files that would change; exit 1 if any}';

    protected $description = 'Generate the till entity store from the portal API contract';

    public function handle(GenerateTillEntities $generate): int
    {
        $check = (bool) $this->option('check');
        $result = $generate->handle(write: ! $check);

        foreach ($result['changed'] as $file) {
            $this->line(($check ? 'Would write ' : 'Wrote ').$file);
        }

        foreach ($result['deleted'] as $file) {
            $this->line(($check ? 'Would delete ' : 'Deleted ').$file);
        }

        $this->info(sprintf('%d changed, %d deleted, %d unchanged.', count($result['changed']), count($result['deleted']), $result['unchanged']));

        if ($check && ($result['changed'] !== [] || $result['deleted'] !== [])) {
            $this->error('Generated till entity files are out of date. Run php artisan till:entities:generate.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
