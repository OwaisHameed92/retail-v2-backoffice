<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\TillData\Concerns\SentToTills;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property BusinessType|null $business_type Module 1.11: the till's BusinessType name (key `company` block).
 * @property string|null $town
 * @property string|null $postcode
 * @property string|null $owner_name Name for the till's first user (key `company.ownerName`); defaults to the owner login.
 * @property string|null $receipt_footer
 * @property bool $multi_branch May run more than one branch (key feature `multi_branch`). Set via UpdateBranchLimits.
 * @property int $max_branches Branches allowed (key `limits.branches`). Set via UpdateBranchLimits.
 * @property string|null $data_connection Database connection holding its data (sharding groundwork, CompanyConnection).
 * @property CompanyStatus $status
 * @property bool $share_unknown_barcodes Tills' unknown barcodes go to the master catalogue's review queue, anonymously (SetCatalogueSharing).
 * @property bool $require_two_factor Everyone in the business must use two-factor sign-in (SetCompanyTwoFactorRequirement).
 * @property string|null $plan_id Plan for new tills (module 1.3); null = portal default. Set via ChangeCompanyPlan.
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
 * @property-read Plan|null $plan
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasPortalUlid, SentToTills, SoftDeletes;

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
        'business_type',
        'town',
        'postcode',
        'owner_name',
        'receipt_footer',
        'status',
        'notes',
        'trial_ends_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'trial',
        'multi_branch' => false,
        'max_branches' => 1,
        'require_two_factor' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'business_type' => BusinessType::class,
            'multi_branch' => 'boolean',
            'require_two_factor' => 'boolean',
            'share_unknown_barcodes' => 'boolean',
            'max_branches' => 'integer',
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
            ->withPivot(['role', 'is_active', 'branch_id'])
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

    /**
     * The plan new tills are licensed on (module 1.3). Null means the portal default: see DefaultPlan.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * Licences of this company's tills (module 1.3). Licence is tenant-scoped like branches().
     *
     * @return HasMany<Licence, $this>
     */
    public function licences(): HasMany
    {
        return $this->hasMany(Licence::class);
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
