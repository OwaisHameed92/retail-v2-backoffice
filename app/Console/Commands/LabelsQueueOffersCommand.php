<?php

namespace App\Console\Commands;

use App\Domain\Labels\Actions\QueueOfferDayLabels;
use Illuminate\Console\Command;

/**
 * Gap #6: queue shelf labels for offers that start today or ended yesterday (London). Scheduled daily just after
 * midnight London. Idempotent.
 */
class LabelsQueueOffersCommand extends Command
{
    protected $signature = 'labels:queue-offers';

    protected $description = 'Queue shelf-edge labels for offers that start today or ended yesterday';

    public function handle(QueueOfferDayLabels $queue): int
    {
        $this->info('Shelf labels queued: '.$queue->handle().'.');

        return self::SUCCESS;
    }
}
