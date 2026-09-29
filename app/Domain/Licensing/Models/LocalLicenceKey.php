<?php

namespace App\Domain\Licensing\Models;

use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The local key register (module 2.8, contract v1.4.1 §17.6 (b), §17.10, §17.16): a licence-generator token
 * (`source: local`) reported by a till through `licence/redeem` or `cloud/migrate`, bound to the install code that
 * reported it first. Written only by LocalKeyRegister; an admin may clear a record (ClearLocalLicenceKey).
 *
 * Admin data, not tenant-scoped: a local shop may not be one of our customers. `company_id` / `branch_id` are ours
 * when we could tell (the till's ids in id_map, or the sync key that called); `claimed_*` are the token's own.
 *
 * @property string $id
 * @property string $licence_id The token's licenceId (the generator's).
 * @property string $install_code
 * @property string|null $install_id
 * @property string $kid
 * @property string|null $issuer
 * @property string|null $kind
 * @property string $token_sha256 The last reported token's hash.
 * @property string|null $claimed_company_id
 * @property string|null $claimed_branch_id
 * @property string|null $business_name
 * @property string|null $branch_name
 * @property array<string, string>|null $company
 * @property int|null $max_registers
 * @property list<string>|null $features
 * @property array<string, int>|null $limits
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $expires_at
 * @property string|null $company_id
 * @property string|null $branch_id
 * @property string|null $device_name
 * @property string|null $app_version
 * @property string|null $os
 * @property string $reported_via report | redeem | migrate
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_reported_at
 * @property int $report_count
 * @property int $refused_count
 * @property CarbonImmutable|null $last_refused_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Company|null $portalCompany
 * @property-read Branch|null $portalBranch
 */
class LocalLicenceKey extends Model
{
    use HasPortalUlid;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => 'array',
            'features' => 'array',
            'limits' => 'array',
            'max_registers' => 'integer',
            'report_count' => 'integer',
            'refused_count' => 'integer',
            'issued_at' => 'immutable_datetime',
            'valid_from' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_reported_at' => 'immutable_datetime',
            'last_refused_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function portalCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function portalBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id')->withoutGlobalScopes();
    }

    /**
     * @return HasMany<LocalLicenceKeyRefusal, $this>
     */
    public function refusals(): HasMany
    {
        return $this->hasMany(LocalLicenceKeyRefusal::class);
    }
}
