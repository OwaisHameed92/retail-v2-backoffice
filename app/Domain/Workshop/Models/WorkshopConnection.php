<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Enums\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shop's connection to BikeBook (WORKSHOP-BIKEBOOK.md §1.1, wave W5). Tenant-owned. BikeBook's "tenant" is the
 * shop (`branch_id`); `default_store_id` is always that shop. The API key is an HMAC + last 4 (ConnectorSecret); the
 * webhook secret BikeBook gives us is encrypted at rest and never shown or logged.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $provider
 * @property ConnectionStatus $status
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $disconnected_at
 * @property CarbonImmutable|null $last_pulled_at
 * @property string|null $last_error
 * @property string|null $default_store_id
 * @property string|null $product_scope
 * @property string|null $webhook_id
 * @property string|null $webhook_name
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property list<string>|null $webhook_subscriptions
 * @property bool $webhook_enabled
 * @property string|null $api_key_hash
 * @property string|null $api_key_last4
 * @property CarbonImmutable|null $api_key_created_at
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch|null $branch
 */
class WorkshopConnection extends Model
{
    use BelongsToCompany, HasPortalUlid;

    public const PROVIDER = 'bikebook';

    protected $table = 'workshop_connections';

    protected $guarded = ['id'];

    protected $hidden = ['webhook_secret', 'api_key_hash'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'provider' => self::PROVIDER,
        'webhook_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'connected_at' => 'immutable_datetime',
            'disconnected_at' => 'immutable_datetime',
            'last_pulled_at' => 'immutable_datetime',
            'webhook_secret' => 'encrypted',
            'webhook_subscriptions' => 'array',
            'webhook_enabled' => 'boolean',
            'api_key_created_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function hasWebhook(): bool
    {
        return $this->webhook_url !== null && $this->webhook_url !== '' && $this->webhook_secret !== null && $this->webhook_secret !== '';
    }

    /** BikeBook has an enabled webhook on us that subscribes to this event. */
    public function wants(WebhookEvent $event): bool
    {
        return $this->hasWebhook() && $this->webhook_enabled && in_array($event->value, $this->webhook_subscriptions ?? [], true);
    }
}
