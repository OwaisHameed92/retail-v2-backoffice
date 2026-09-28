<?php

namespace App\Domain\Sync\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Support\SyncKeySecret;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch's sync API key (module 2.1, contract §2.3, §3, SIMPLE-SETUP.md): the Bearer of `sync/*`. Only an
 * HMAC of the key and its last 4 characters are stored ({@see SyncKeySecret}).
 *
 * A branch has at most one current key (not replaced, not revoked). A replaced key keeps working for
 * REPLACED_GRACE_DAYS (validate-reply `apiKey`: "keep the old key valid for 7 days"); a revoked one stops at once.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $key_hash
 * @property string $key_last4
 * @property SyncKeySource $source
 * @property int|null $created_by
 * @property string|null $delivered_install_id The main till's installId it was sent to in a licence reply.
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $rotate_requested_at An admin asked for a new key at the main till's next check.
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $replaced_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch|null $branch
 * @property-read Admin|null $creator
 */
class SyncKey extends Model
{
    use BelongsToCompany, HasPortalUlid;

    public const REPLACED_GRACE_DAYS = 7;

    /** @var list<string> */
    protected $fillable = ['company_id', 'branch_id', 'key_hash', 'key_last4', 'source', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['key_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => SyncKeySource::class,
            'delivered_at' => 'immutable_datetime',
            'rotate_requested_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * The branch's current key (not replaced, not revoked).
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('replaced_at')->whereNull('revoked_at');
    }

    public function isCurrent(): bool
    {
        return $this->replaced_at === null && $this->revoked_at === null;
    }

    /** Accepted as a Bearer: current, or replaced less than REPLACED_GRACE_DAYS ago, and never revoked. */
    public function isUsable(CarbonImmutable $now): bool
    {
        return $this->revoked_at === null
            && ($this->replaced_at === null || $this->replaced_at->addDays(self::REPLACED_GRACE_DAYS)->greaterThan($now));
    }
}
