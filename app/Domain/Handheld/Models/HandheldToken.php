<?php

namespace App\Domain\Handheld\Models;

use App\Domain\Handheld\Support\HandheldTokenSecret;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A handheld app's bearer token. Only the SHA-256 hash and the last 4 characters are stored
 * ({@see HandheldTokenSecret}); the plain token exists only in the login reply. Revoked at logout, by a password
 * reset, a deactivation or "Sign out devices" on the portal.
 *
 * @property string $id
 * @property string $company_id
 * @property string $handheld_user_id
 * @property string $token_hash
 * @property string $token_last4
 * @property string|null $device_name
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read HandheldUser|null $user
 */
class HandheldToken extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $fillable = ['company_id', 'handheld_user_id', 'token_hash', 'token_last4', 'device_name'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

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
     * @return BelongsTo<HandheldUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(HandheldUser::class, 'handheld_user_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
