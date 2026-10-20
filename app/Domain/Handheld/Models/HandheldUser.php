<?php

namespace App\Domain\Handheld\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A handheld (PDA) app login (docs/pda-api.md): tenant-owned, tied to one shop, never sent to a till. Signs in with
 * email + password at `POST /api/pda/login`; its bearer tokens are {@see HandheldToken}. Managed on the portal under
 * Settings → Handheld (PDA) users. The `handheld` guard carries it during an API call, so audit entries name it.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property bool $can_change_prices
 * @property CarbonImmutable|null $last_login_at
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Branch|null $branch
 * @property-read User|null $createdBy
 */
class HandheldUser extends Model implements AuthenticatableContract
{
    use Authenticatable, BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $fillable = ['company_id', 'branch_id', 'name', 'email', 'password', 'is_active', 'can_change_prices', 'created_by_user_id'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_change_prices' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Handheld users have no "remember me". */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<HandheldToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(HandheldToken::class);
    }
}
