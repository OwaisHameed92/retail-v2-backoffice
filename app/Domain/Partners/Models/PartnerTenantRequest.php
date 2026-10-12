<?php

namespace App\Domain\Partners\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner's request to onboard a business (docs/partner-api.md): one per partner `external_id`, ever. Holds the
 * partner's latest body (`payload`, camelCase as sent, validated and tidied) until an admin approves it into a
 * business (`company_id`) or rejects it. Not tenant-owned: admins read it across businesses.
 *
 * @property string $id
 * @property string $partner_id
 * @property string $external_id
 * @property PartnerRequestStatus $status
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string $business_name
 * @property string $seller_name
 * @property string $seller_email
 * @property string|null $company_id
 * @property CarbonImmutable|null $approved_at
 * @property string|null $approved_by_admin_id
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejected_by_admin_id
 * @property string|null $rejected_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_reason
 * @property bool $partner_cancelled_after_approval
 * @property CarbonImmutable|null $partner_cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Partner|null $partner
 * @property-read Company|null $company
 * @property-read Admin|null $approvedBy
 * @property-read Admin|null $rejectedBy
 */
class PartnerTenantRequest extends Model
{
    use HasPortalUlid;

    /** Millisecond timestamps: `updatedAt` orders the partner's view of the request (docs/partner-api.md). */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @var list<string> */
    protected $fillable = ['partner_id', 'external_id', 'status', 'payload', 'payload_hash', 'business_name', 'seller_name', 'seller_email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PartnerRequestStatus::class,
            'payload' => 'array',
            'partner_cancelled_after_approval' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'partner_cancelled_at' => 'immutable_datetime',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'rejected_by_admin_id');
    }

    /**
     * @return HasMany<PartnerWebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PartnerWebhookEvent::class);
    }

    /** Moves `updatedAt` on (see updateTimestamps) without any other change, e.g. before a webhook is made. */
    public function touchForward(): void
    {
        $this->touch();
    }

    /**
     * `updated_at` only moves forward: every save sets it at least 1 ms past the stored value (even within the same
     * millisecond, or if the clock stepped back), so a partner keeping the newest `updatedAt` it saw can drop older
     * webhooks (docs/partner-api.md "Ordering").
     */
    public function updateTimestamps(): static
    {
        parent::updateTimestamps();
        $stored = $this->getOriginal('updated_at');

        if ($stored instanceof CarbonImmutable && $this->updated_at !== null && ! $this->updated_at->greaterThan($stored)) {
            $this->updated_at = $stored->addMillisecond();
        }

        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === PartnerRequestStatus::PendingReview;
    }

    /** The total of tills over every shop in the partner's body. */
    public function tills(): int
    {
        $shops = is_array($this->payload['shops'] ?? null) ? $this->payload['shops'] : [];

        return array_sum(array_map(fn ($shop) => is_array($shop) ? (int) ($shop['tills'] ?? 0) : 0, $shops));
    }
}
