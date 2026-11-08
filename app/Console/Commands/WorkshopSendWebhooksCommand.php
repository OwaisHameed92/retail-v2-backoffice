<?php

namespace App\Console\Commands;

use App\Domain\Workshop\Actions\SendDueWorkshopWebhooks;
use Illuminate\Console\Command;

/**
 * W5 BikeBook connector: the webhook outbox's retry loop. Scheduled every minute: queues an attempt for every due
 * webhook to BikeBook (first attempts are queued when the webhook is made; this picks up retries, debounced
 * `inventory.updated` and lost jobs). Does nothing while WORKSHOP_CONNECTOR is off.
 */
class WorkshopSendWebhooksCommand extends Command
{
    protected $signature = 'workshop:send-webhooks';

    protected $description = 'Queue the BikeBook webhooks that are due';

    public function handle(SendDueWorkshopWebhooks $due): int
    {
        $this->info('Queued '.$due->handle().' BikeBook webhooks.');

        return self::SUCCESS;
    }
}
