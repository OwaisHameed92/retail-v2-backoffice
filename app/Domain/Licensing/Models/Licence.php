<?php

namespace App\Domain\Licensing\Models;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Database\Factories\LicenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * The licence of one till (register). Tenant-owned (BelongsToCompany): admin and till-API code reads it with
 * `Licence::withoutCompanyScope()`.
 *
 * The key is never stored: `key_hash` is an HMAC-SHA256 keyed with APP_KEY ({@see LicenceKey}), `key_last4` is
 * safe to show. Change a licence only through the actions in App\Domain\Licensing\Actions.
 *
 * Kept on save: `live_register_id` (= register_id unless revoked or deleted; unique, so a till has at most one
 * live licence) and `ends_at` / `grace_ends_at` (see LicenceTerms).
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $register_id
 * @property string $plan_id
 * @property string|null $live_register_id
 * @property string $key_hash
 * @property string $key_last4
 * @property LicenceStatus $status
 * @property Collection<int, Feature> $features
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $expires_at
 * @property int $grace_days
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $grace_ends_at
 * @property string|null $device_id The bound till's installId (contract §17.15), null when not bound.
 * @property string|null $device_name
 * @property string|null $install_code
 * @property array{companyId?: string, branchId?: string, registerId?: string}|null $existing_ids The till's own ids.
 * @property CarbonImmutable|null $bound_at
 * @property CarbonImmutable|null $last_check_in_at
 * @property string|null $last_app_version
 * @property array{name?: string, version?: string, architecture?: string|null}|null $os
 * @property int|null $till_clock_skew_seconds Till clock minus portal time at the last call.
 * @property CarbonImmutable|null $clock_watermark_at
 * @property CarbonImmutable|null $last_validated_at
 * @property bool|null $lock_locked
 * @property string|null $lock_reason
 * @property string|null $token_sha256
 * @property string|null $token_kid
 * @property string|null $token_fingerprint
 * @property string|null $last_ip
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspended_reason
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Company|null $company
 * @property-read Branch|null $branch
 * @property-read Register|null $register
 * @property-read Plan|null $plan
 */
class Licence extends Model
{
    /** @use HasFactory<LicenceFactory> */
    use BelongsToCompany, HasFactory, HasPortalUlid, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'branch_id',
        'register_id',
        'plan_id',
        'key_hash',
        'key_last4',
        'status',
        'features',
        'activated_at',
        'trial_ends_at',
        'expires_at',
        'grace_days',
        'device_id',
        'device_name',
        'bound_at',
        'last_check_in_at',
        'last_app_version',
        'last_ip',
        'notes',
    ];

    /** @var list<string> */
    protected $hidden = ['key_hash', 'token_sha256', 'token_fingerprint'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'issued',
        'features' => '[]',
        'grace_days' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (Licence $licence): void {
            $live = $licence->status !== LicenceStatus::Revoked && $licence->deleted_at === null;
            $licence->live_register_id = $live ? $licence->register_id : null;
            $licence->ends_at = LicenceTerms::endsAt($licence);
            $licence->grace_ends_at = LicenceTerms::graceEndsAt($licence);
        });

        // Soft delete frees the till for a new licence (the saving hook does not run on delete()).
        static::softDeleted(function (Licence $licence): void {
            if ($licence->live_register_id !== null) {
                $licence->live_register_id = null;
                $licence->saveQuietly();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LicenceStatus::class,
            'features' => AsEnumCollection::of(Feature::class),
            'grace_days' => 'integer',
            'activated_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'bound_at' => 'immutable_datetime',
            'existing_ids' => 'array',
            'os' => 'array',
            'till_clock_skew_seconds' => 'integer',
            'clock_watermark_at' => 'immutable_datetime',
            'last_validated_at' => 'immutable_datetime',
            'lock_locked' => 'boolean',
            'last_check_in_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): LicenceFactory
    {
        return LicenceFactory::new();
    }

    /**
     * The company, even when soft deleted (overrides BelongsToCompany::company()).
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * The licence's branch. The licence row already belongs to one company, so the branch is read without the
     * company scope (and even when soft deleted) so admin and till-API code can load it.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withoutGlobalScope(CompanyScope::class)->withTrashed();
    }

    /**
     * The licence's till. Unscoped for the same reason as branch().
     *
     * @return BelongsTo<Register, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class)->withoutGlobalScope(CompanyScope::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * Not revoked: the till's current licence.
     *
     * @param  Builder<Licence>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('live_register_id'));
    }

    /** "SSP-••••-••••-••••-B6WN" */
    public function maskedKey(): string
    {
        return LicenceKey::mask($this->key_last4);
    }

    public function isRevoked(): bool
    {
        return $this->status === LicenceStatus::Revoked;
    }

    public function isBound(): bool
    {
        return $this->device_id !== null;
    }

    /** What the till would be told right now (company, branch and till included). */
    public function state(?CarbonImmutable $now = null): LicenceState
    {
        return LicenceState::for($this, $now ?? CarbonImmutable::now());
    }

    /**
     * "Till 2 (02) at Leeds" (loads the branch and till when needed).
     */
    public function tillLabel(): string
    {
        $register = $this->register;
        $branch = $this->branch;

        return trim(($register->name ?? 'A till').($register ? " ({$register->code})" : '').($branch ? " at {$branch->name}" : ''));
    }
}
