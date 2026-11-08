<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Workshop\Enums\WebhookEvent;
use App\Domain\Workshop\Jobs\SendWorkshopWebhookJob;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use App\Domain\Workshop\Support\CallLogger;
use App\Domain\Workshop\Support\WorkshopSwitch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Puts a webhook to BikeBook in the outbox (WORKSHOP-BIKEBOOK.md §3.8) when the connection carries traffic and
 * BikeBook's webhook subscribes to the event; the first attempt is queued after the surrounding transaction commits,
 * retries come from `workshop:send-webhooks`. Returns the delivery, or null when nothing is sent.
 *
 * `inventory()` merges variants into the connection's `inventory.updated` that has not been tried yet, or makes a
 * new one due no sooner than 30 s after the last one (debounce, §3.4); its body is built when it is first sent.
 */
final class QueueWorkshopWebhook
{
    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     */
    public function handle(WorkshopConnection $connection, WebhookEvent $event, array $payload, ?string $orderId = null): ?WorkshopWebhookDelivery
    {
        if (! $this->sends($connection, $event)) {
            return null;
        }

        return $this->create($connection, $event, [
            'order_id' => $orderId,
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'next_attempt_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /**
     * @param  list<string>  $variantIds
     */
    public function inventory(WorkshopConnection $connection, array $variantIds): ?WorkshopWebhookDelivery
    {
        if ($variantIds === [] || ! $this->sends($connection, WebhookEvent::InventoryUpdated)) {
            return null;
        }

        return DB::transaction(function () use ($connection, $variantIds): WorkshopWebhookDelivery {
            $open = WorkshopWebhookDelivery::withoutCompanyScope()->where('connection_id', $connection->id)
                ->where('event', WebhookEvent::InventoryUpdated->value)->whereNull('payload')->where('attempts', 0)
                ->whereNull('delivered_at')->whereNull('failed_at')->lockForUpdate()->first();

            if ($open !== null) {
                $open->forceFill(['subject_ids' => array_values(array_unique([...($open->subject_ids ?? []), ...$variantIds]))])->save();

                return $open;
            }

            $last = WorkshopWebhookDelivery::withoutCompanyScope()->where('connection_id', $connection->id)
                ->where('event', WebhookEvent::InventoryUpdated->value)->max('created_at');
            $now = CarbonImmutable::now('UTC');
            $due = $last === null ? $now : CarbonImmutable::parse((string) $last, 'UTC')->addSeconds((int) config('workshop.webhooks.inventory_debounce_seconds', 30));

            return $this->create($connection, WebhookEvent::InventoryUpdated, [
                'subject_ids' => array_values(array_unique($variantIds)),
                'payload' => null,
                'next_attempt_at' => $due->greaterThan($now) ? $due : $now,
            ]);
        });
    }

    private function sends(WorkshopConnection $connection, WebhookEvent $event): bool
    {
        return WorkshopSwitch::enabled() && $connection->status->carriesTraffic() && $connection->wants($event);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function create(WorkshopConnection $connection, WebhookEvent $event, array $columns): WorkshopWebhookDelivery
    {
        $delivery = WorkshopWebhookDelivery::withoutCompanyScope()->create([
            'company_id' => $connection->company_id,
            'connection_id' => $connection->id,
            'event' => $event,
            ...$columns,
        ]);

        $delay = $delivery->next_attempt_at?->diffInSeconds(CarbonImmutable::now('UTC'), true) ?? 0;
        $job = SendWorkshopWebhookJob::dispatch($delivery->id);

        if ($delivery->next_attempt_at !== null && $delivery->next_attempt_at->isFuture()) {
            $job->delay((int) ceil($delay));
        }

        CallLogger::prune('workshop_webhook_deliveries', $connection->id, 'id', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('delivered_at')->orWhereNotNull('failed_at')));

        return $delivery;
    }
}
