<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Jobs\ProcessGoCardlessEventJob;
use App\Domain\Billing\GoCardless\Models\GoCardlessEvent;
use App\Domain\Billing\GoCardless\Support\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /webhooks/gocardless`: checks the signature, stores every event once (unique GoCardless event id, so
 * re-deliveries are no-ops) and queues each new one for processing. Returns null for a bad signature, else the
 * counts. Never throws for one bad event: GoCardless would retry the whole batch.
 */
class ReceiveGoCardlessWebhook
{
    /**
     * @return array{received: int, duplicates: int}|null
     */
    public function handle(string $body, ?string $signature): ?array
    {
        if (! WebhookSignature::valid($body, $signature)) {
            Log::warning('GoCardless webhook with an invalid signature', ['has_signature' => $signature !== null && $signature !== '', 'bytes' => strlen($body)]);

            return null;
        }

        $events = json_decode($body, true)['events'] ?? [];
        $received = 0;
        $duplicates = 0;

        foreach (is_array($events) ? $events : [] as $data) {
            if (! is_array($data) || ! is_string($data['id'] ?? null)) {
                continue;
            }

            try {
                $event = GoCardlessEvent::query()->create([
                    'gc_event_id' => $data['id'],
                    'resource_type' => mb_substr((string) ($data['resource_type'] ?? 'unknown'), 0, 40),
                    'action' => mb_substr((string) ($data['action'] ?? 'unknown'), 0, 60),
                    'links' => is_array($data['links'] ?? null) ? $data['links'] : null,
                    'details' => is_array($data['details'] ?? null) ? $data['details'] : null,
                    'payload' => $data,
                    'gc_created_at' => is_string($data['created_at'] ?? null) ? CarbonImmutable::parse($data['created_at']) : null,
                ]);
            } catch (UniqueConstraintViolationException) {
                $duplicates++;

                continue;
            }

            $received++;

            try {
                ProcessGoCardlessEventJob::dispatch($event->id);
            } catch (Throwable $exception) {
                // Sync queue (tests, local): the event is kept as failed and replayed later.
                Log::error('GoCardless event failed', ['event' => $event->gc_event_id, 'error' => $exception->getMessage()]);
            }
        }

        return ['received' => $received, 'duplicates' => $duplicates];
    }
}
