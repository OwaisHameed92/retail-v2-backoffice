<?php

namespace App\Domain\Partners\Models;

use App\Domain\Partners\Support\PartnerKeySecret;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner API key: the Bearer of `/api/partner/v1/*`. Only an HMAC of the key and its last 4 characters are stored
 * ({@see PartnerKeySecret}); the plain key exists only in the reply that created it. Revoked keys stop at once.
 *
 * @property string $id
 * @property string $partner_id
 * @property string|null $name
 * @property string $key_hash
 * @property string $key_last4
 * @property string|null $created_by_admin_id
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Partner|null $partner
 */
class PartnerKey extends Model
{
    use HasPortalUlid;

    /** @var list<string> */
    protected $fillable = ['partner_id', 'name', 'key_hash', 'key_last4', 'created_by_admin_id'];

    /** @var list<string> */
    protected $hidden = ['key_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
