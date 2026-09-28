<?php

namespace App\Domain\Billing\GoCardless\Jobs;

use App\Domain\Billing\GoCardless\Actions\ProcessGoCardlessEvent;
use App\Domain\Billing\GoCardless\Models\GoCardlessEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processes one stored GoCardless webhook event off the request. Retried with backoff; after the last try the
 * event stays `failed` and the daily reconcile replays it.
 */
class ProcessGoCardlessEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $eventId)
    {
        $this->afterCommit = true;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ProcessGoCardlessEvent $process): void
    {
        $event = GoCardlessEvent::query()->find($this->eventId);

        if ($event !== null) {
            $process->handle($event);
        }
    }
}
