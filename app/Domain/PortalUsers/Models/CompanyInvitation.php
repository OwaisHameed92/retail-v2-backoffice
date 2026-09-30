<?php

namespace App\Domain\PortalUsers\Models;

use App\Domain\PortalUsers\Enums\InvitationStatus;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to one business's portal (module 4.1). Tenant-owned. The emailed token is never stored: only its
 * SHA-256 hash (`token_hash`, hidden). Created, resent, revoked and accepted through the PortalUsers actions only.
 *
 * @property string $id
 * @property string $company_id
 * @property string $email
 * @property string $name
 * @property CompanyRole $role
 * @property string|null $branch_id
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $last_sent_at
 * @property int $send_count
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_user_id
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $inviter
 */
class CompanyInvitation extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** Days an invitation link stays valid (each resend starts a new period). */
    public const VALID_DAYS = 7;

    /**
     * @var list<string>
     */
    protected $fillable = ['company_id', 'email', 'name', 'role', 'branch_id', 'token_hash', 'invited_by', 'expires_at', 'last_sent_at'];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    /**
     * The invitation an emailed link points at, across businesses: the invitee is not a member yet, so there is no
     * current company. Only the accept screen uses it, and it always checks the token as well.
     */
    public static function findForLink(string $id): ?self
    {
        return static::withoutCompanyScope()->whereKey($id)->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Not accepted and not revoked (it may have expired).
     *
     * @param  Builder<CompanyInvitation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    public function status(?Carbon $now = null): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->expires_at->lte($now ?? now()) => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function matchesToken(string $token): bool
    {
        return hash_equals($this->token_hash, self::hashToken($token));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
