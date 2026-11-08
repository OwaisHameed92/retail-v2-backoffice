<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Workshop\Jobs\SendWorkshopWebhookJob;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Staff press Replay on a webhook delivery (WORKSHOP-BIKEBOOK.md §3.8, §8): the same body and event id go again now,
 * with a fresh round of retries. Refused while the shop is not connected. Audited (no body, no secret).
 */
final class ReplayWorkshopWebhook
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(WorkshopWebhookDelivery $delivery, ?Model $actor = null): WorkshopWebhookDelivery
    {
        $connection = WorkshopConnection::withoutCompanyScope()->find($delivery->connection_id);

        if ($connection === null || ! $connection->status->carriesTraffic()) {
            throw ValidationException::withMessages(['delivery' => 'This shop is not connected to BikeBook. Connect it before replaying.']);
        }

        if ($delivery->isPending() && $delivery->attempts === 0) {
            return $delivery;
        }

        $delivery->forceFill([
            'attempts' => 0, 'delivered_at' => null, 'failed_at' => null, 'locked_until' => null,
            'next_attempt_at' => CarbonImmutable::now('UTC'), 'last_error' => null, 'last_status' => null,
        ])->save();

        $this->audit->handle('workshop.webhook_replayed', $connection, null, null, ['event' => $delivery->event->value, 'delivery' => $delivery->id], $actor);
        SendWorkshopWebhookJob::dispatch($delivery->id);

        return $delivery;
    }
}
