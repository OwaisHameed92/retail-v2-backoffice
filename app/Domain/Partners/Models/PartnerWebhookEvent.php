<?php

namespace App\Domain\Partners\Models;

use App\Domain\Partners\Enums\PartnerWebhookType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One webhook in the outbox: the exact JSON body (`payload`, signed as is on every attempt), how often it was tried,
 * when next, and whether it was delivered or given up (24 h of retries). Id = the event id the partner sees
 * (`evt_` + ULID), so a retry is recognisable as the same event.
 *
 * @property string $id
 * @property string $partner_id
 * @property string $partner_tenant_request_id
 * @property PartnerWebhookType $type
 * @property string $dedupe_key
 * @property string $payload
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Partner|null $partner
 * @property-read PartnerTenantRequest|null $tenantRequest
 */
class PartnerWebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'partner_id', 'partner_tenant_request_id', 'type', 'dedupe_key', 'payload', 'next_attempt_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PartnerWebhookType::class,
            'attempts' => 'integer',
            'last_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<PartnerTenantRequest, $this>
     */
    public function tenantRequest(): BelongsTo
    {
        return $this->belongsTo(PartnerTenantRequest::class, 'partner_tenant_request_id');
    }

    public function isPending(): bool
    {
        return $this->delivered_at === null && $this->failed_at === null;
    }
}
