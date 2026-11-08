<?php

namespace App\Domain\Salon\Models;

use App\Domain\Salon\Calendar\FeedToken;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stylist's staff calendar feed (salon W3b, docs/salon.md): tenant-owned, one per stylist and shop, never sent to a
 * till. `user_id` is the till user id of the stylist (SalonStaffProfile.user_id). The token itself is never stored:
 * only its SHA-256 hash and last 4 characters ({@see FeedToken}). Off = no hash.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $user_id
 * @property string|null $token_hash
 * @property string|null $token_last4
 * @property bool $enabled
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_fetched_at
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch|null $branch
 */
class SalonCalendarFeed extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $fillable = ['company_id', 'branch_id', 'user_id', 'token_hash', 'token_last4', 'enabled', 'issued_at', 'revoked_at', 'last_fetched_at', 'created_by_user_id'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'issued_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_fetched_at' => 'immutable_datetime',
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

    public function isOn(): bool
    {
        return $this->enabled && $this->token_hash !== null;
    }
}
