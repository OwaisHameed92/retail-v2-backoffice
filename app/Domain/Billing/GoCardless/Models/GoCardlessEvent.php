<?php

namespace App\Domain\Billing\GoCardless\Models;

use App\Domain\Billing\GoCardless\Enums\EventStatus;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A GoCardless webhook event as received (unique by GoCardless event id, so a re-delivery is a no-op), processed
 * by a queued job. A system log like the audit log: `company_id` is filled in once the event is matched to a
 * company, and only admin and system code read it.
 *
 * @property string $id
 * @property string $gc_event_id
 * @property string|null $company_id
 * @property string $resource_type
 * @property string $action
 * @property array<string, string>|null $links
 * @property array<string, mixed>|null $details
 * @property array<string, mixed> $payload
 * @property EventStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property CarbonImmutable|null $gc_created_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GoCardlessEvent extends Model
{
    use HasPortalUlid;

    protected $table = 'gocardless_events';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'links' => 'array',
            'details' => 'array',
            'payload' => 'array',
            'status' => EventStatus::class,
            'attempts' => 'integer',
            'gc_created_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function link(string $name): ?string
    {
        $value = $this->links[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** "payments.confirmed" */
    public function type(): string
    {
        return $this->resource_type.'.'.$this->action;
    }
}
