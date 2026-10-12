<?php

namespace App\Domain\Partners\Models;

use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reseller platform that sends us signed deals (docs/partner-api.md; Zivaro is the first). Platform-level, not
 * tenant-owned. The webhook signing secret is encrypted at rest (shown once when generated; its last 4 for staff).
 *
 * @property string $id
 * @property string $name
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property string|null $webhook_secret_last4
 * @property bool $is_active
 * @property string|null $created_by_admin_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Partner extends Model
{
    use HasPortalUlid;

    /** @var list<string> */
    protected $fillable = ['name', 'webhook_url', 'is_active', 'created_by_admin_id'];

    /** @var list<string> */
    protected $hidden = ['webhook_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<PartnerKey, $this>
     */
    public function keys(): HasMany
    {
        return $this->hasMany(PartnerKey::class);
    }

    /**
     * @return HasMany<PartnerTenantRequest, $this>
     */
    public function tenantRequests(): HasMany
    {
        return $this->hasMany(PartnerTenantRequest::class);
    }

    /** Webhooks go out only with a URL and a signing secret. */
    public function sendsWebhooks(): bool
    {
        return $this->webhook_url !== null && $this->webhook_url !== '' && $this->webhook_secret !== null && $this->webhook_secret !== '';
    }
}
