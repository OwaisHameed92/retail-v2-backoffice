<?php

namespace App\Console\Commands;

use App\Domain\Pharmacy\Actions\FetchDmdRelease;
use App\Domain\Pharmacy\Enums\DmdFetchOutcome;
use App\Domain\Pharmacy\Models\DmdRelease;
use App\Domain\Pharmacy\Support\DmdSwitch;
use Illuminate\Console\Command;

/**
 * W4: fetch this week's NHSBSA dm+d release from NHS TRUD (item 24, our key) and publish it to pharmacy tills
 * (FetchDmdRelease). Scheduled Monday, retried Tuesday for a bank-holiday week (routes/console.php); safe to run by
 * hand at any time (idempotent). Does nothing while PHARMACY_DMD is off or no TRUD key is set. Never prints the key.
 */
class PharmacyDmdFetchCommand extends Command
{
    protected $signature = 'pharmacy:dmd-fetch';

    protected $description = 'Fetch the weekly NHS dm+d release from TRUD and publish it to pharmacy tills';

    public function handle(FetchDmdRelease $fetch): int
    {
        if (! DmdSwitch::enabled()) {
            $this->line('The dm+d release server is off (PHARMACY_DMD). Nothing to do.');

            return self::SUCCESS;
        }

        if (! DmdSwitch::hasTrudKey()) {
            $this->warn('No TRUD API key is set (PHARMACY_DMD_TRUD_KEY). Nothing to do.');

            return self::SUCCESS;
        }

        $outcome = $fetch->handle();
        $newest = DmdRelease::newest();
        $held = $newest === null ? 'none' : "{$newest->archive_file_name} ({$newest->week})";

        return match ($outcome) {
            DmdFetchOutcome::Published => $this->done("Published a new week. Serving {$held}."),
            DmdFetchOutcome::Unchanged => $this->done("TRUD has nothing new. Serving {$held}."),
            DmdFetchOutcome::Skipped => $this->done('Another fetch is running. Nothing to do.'),
            DmdFetchOutcome::Failed => $this->failed("The fetch failed; see the log and Admin → Till health. Still serving {$held}."),
        };
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failed(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
