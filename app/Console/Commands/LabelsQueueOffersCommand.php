<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EndsWithBusinessFailures;
use App\Domain\Labels\Actions\QueueOfferDayLabels;
use Illuminate\Console\Command;

/**
 * Gap #6: queue shelf labels for offers that start today or ended yesterday (London). Scheduled daily just after
 * midnight London. Idempotent. Exits non-zero, naming them, when some businesses' offers failed (each reported).
 */
class LabelsQueueOffersCommand extends Command
{
    use EndsWithBusinessFailures;

    protected $signature = 'labels:queue-offers';

    protected $description = 'Queue shelf-edge labels for offers that start today or ended yesterday';

    public function handle(QueueOfferDayLabels $queue): int
    {
        $this->info('Shelf labels queued: '.$queue->handle().'.');

        return $this->endWith($queue->failedCompanyIds(), 'shelf labels');
    }
}
