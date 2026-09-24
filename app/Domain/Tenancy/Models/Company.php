<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A tenant. Fields mirror the till's Company entity; the ULID is created here (upper case) and sent to the till.
 * Portal-only fields: contact_name, notes, status and its timestamps/reasons.
 *
 * Status changes go through the Activate/Suspend/Unsuspend/CancelCompany actions only.
 *
 * @property string $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $vat_number
 * @property string|null $company_number
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $contact_name
 * @property CompanyStatus $status
 * @property string|null $notes
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $suspended_at
 * @property CompanyStatus|null $suspended_from_status
 * @property string|null $suspension_reason
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read CompanyMembership|null $membership
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasPortalUlid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'legal_name',
        'vat_number',
        'company_number',
        'address',
        'phone',
        'email',
        'contact_name',
        'status',
        'notes',
        'trial_ends_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'trial',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'suspended_from_status' => CompanyStatus::class,
            'trial_ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, CompanyMembership, 'membership'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(CompanyMembership::class)
            ->as('membership')
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * Active members with the owner role.
     *
     * @return BelongsToMany<User, $this, CompanyMembership, 'membership'>
     */
    public function owners(): BelongsToMany
    {
        return $this->users()
            ->wherePivot('role', CompanyRole::Owner->value)
            ->wherePivot('is_active', true);
    }

    /**
     * Branches of this company. Branch is tenant-scoped: in admin code load it inside
     * `CurrentCompany::runAs($company, ...)` (or drop CompanyScope in a withCount constraint).
     *
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Registers (tills) of this company. Tenant-scoped like branches().
     *
     * @return HasMany<Register, $this>
     */
    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === CompanyStatus::Suspended;
    }

    public function isCancelled(): bool
    {
        return $this->status === CompanyStatus::Cancelled;
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
