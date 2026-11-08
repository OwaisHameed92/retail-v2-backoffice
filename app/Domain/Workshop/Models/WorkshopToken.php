<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Workshop\Enums\WorkshopTokenKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An OAuth authorisation code, access token or refresh token of a BikeBook connection (SHA-256 hash only; the plain
 * value exists only in the redirect or the token reply). `parent_id`: the code a token pair came from, or the refresh
 * token it replaced, so a replayed code revokes what it issued.
 *
 * @property string $id
 * @property string $company_id
 * @property string $connection_id
 * @property WorkshopTokenKind $kind
 * @property string $token_hash
 * @property string $scopes
 * @property string|null $redirect_uri
 * @property string|null $parent_id
 * @property int|null $user_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read WorkshopConnection|null $connection
 */
class WorkshopToken extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'workshop_tokens';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WorkshopTokenKind::class,
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkshopConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(WorkshopConnection::class);
    }

    public function isUsable(CarbonImmutable $now): bool
    {
        return $this->revoked_at === null && $this->expires_at->greaterThan($now);
    }
}
