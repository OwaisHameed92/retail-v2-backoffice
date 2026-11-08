<?php

namespace App\Domain\Workshop\Jobs;

use App\Domain\Workshop\Actions\DeliverWorkshopWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One delivery attempt of a webhook to BikeBook, queued after the transaction that made it commits. One try per job:
 * retries are the outbox's (`next_attempt_at`, picked up by `workshop:send-webhooks` every minute), so a slow BikeBook
 * never holds a worker.
 */
class SendWorkshopWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $deliveryId)
    {
        $this->afterCommit = true;
        $this->timeout = max(10, (int) config('workshop.webhooks.job_timeout', 30));
    }

    public function handle(DeliverWorkshopWebhook $deliver): void
    {
        $deliver->handle($this->deliveryId);
    }
}
