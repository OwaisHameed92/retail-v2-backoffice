<?php

namespace App\Domain\Partners\Jobs;

use App\Domain\Partners\Actions\DeliverPartnerWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One delivery attempt of a partner webhook, queued after the transaction that made the event commits. One try per
 * job: retries are the outbox's (next_attempt_at, picked up by `partners:send-webhooks` every minute), so a slow
 * partner never holds a worker. The job's timeout (30 s) stays below the queue's retry_after (90 s).
 */
class SendPartnerWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $eventId)
    {
        $this->afterCommit = true;
        $this->timeout = max(10, (int) config('partners.webhooks.job_timeout', 30));
    }

    public function handle(DeliverPartnerWebhook $deliver): void
    {
        $deliver->handle($this->eventId);
    }
}
