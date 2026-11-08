<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Workshop\Enums\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A webhook to BikeBook (outbox, WORKSHOP-BIKEBOOK.md §3.8). The id is the `X-Pos-Event-Id`. `payload` is the exact
 * body signed and sent (an `inventory.updated` keeps merging `subject_ids` until its first attempt, when its body is
 * built from the stock at that moment and frozen). Retries 1 min, 5 min, 15 min, 1 h, 4 h, then `failed_at`
 * (Replay sends it again).
 *
 * @property string $id
 * @property string $company_id
 * @property string $connection_id
 * @property WebhookEvent $event
 * @property string|null $order_id
 * @property list<string>|null $subject_ids
 * @property string|null $payload
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WorkshopConnection|null $connection
 */
class WorkshopWebhookDelivery extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'workshop_webhook_deliveries';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => WebhookEvent::class,
            'subject_ids' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'last_status' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkshopConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(WorkshopConnection::class);
    }

    public function isPending(): bool
    {
        return $this->delivered_at === null && $this->failed_at === null;
    }

    public function state(): string
    {
        return match (true) {
            $this->delivered_at !== null => 'delivered',
            $this->failed_at !== null => 'failed',
            $this->attempts > 0 => 'retrying',
            default => 'queued',
        };
    }
}
