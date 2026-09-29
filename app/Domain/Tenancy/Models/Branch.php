<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Licensing\Enums\LicenceLengthUnit;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Tenancy\Casts\AreaCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\TillData\Concerns\SentToTills;
use Carbon\CarbonImmutable;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A shop of a tenant. Fields mirror the till's Branch entity (`nextPoNo` is till-owned and not kept here).
 * Tenant-owned: every query is scoped to the current company (see BelongsToCompany).
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $vat_number
 * @property string|null $town
 * @property string|null $postcode
 * @property string|null $receipt_footer
 * @property Nation $nation
 * @property string|null $licensed_hours_json
 * @property bool $is_drs_return_point
 * @property string|null $area_m2
 * @property bool $is_active
 * @property int $max_registers Module 1.11: tills allowed (key `maxRegisters`). Set via UpdateBranchLicence.
 * @property TokenKind $licence_kind
 * @property int|null $licence_length Null = the plan's trial length (trial only).
 * @property LicenceLengthUnit|null $licence_length_unit
 * @property CarbonImmutable|null $licence_valid_from Null = each till's first activation.
 * @property list<string>|null $licence_features Feature values; null = the plan's.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToCompany, HasFactory, HasPortalUlid, SentToTills, SoftDeletes;

    public const CODE_PATTERN = '/^[A-Z]{2,5}$/';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'address',
        'phone',
        'vat_number',
        'town',
        'postcode',
        'receipt_footer',
        'nation',
        'licensed_hours_json',
        'is_drs_return_point',
        'area_m2',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'nation' => 'england',
        'is_drs_return_point' => false,
        'is_active' => true,
        'max_registers' => 1,
        'licence_kind' => 'trial',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nation' => Nation::class,
            'is_drs_return_point' => 'boolean',
            'area_m2' => AreaCast::class,
            'is_active' => 'boolean',
            'max_registers' => 'integer',
            'licence_kind' => TokenKind::class,
            'licence_length' => 'integer',
            'licence_length_unit' => LicenceLengthUnit::class,
            'licence_valid_from' => 'immutable_datetime',
            'licence_features' => 'array',
        ];
    }

    /**
     * @return HasMany<Register, $this>
     */
    public function registers(): HasMany
    {
        return $this->hasMany(Register::class)->orderBy('code');
    }

    /**
     * @param  Builder<Branch>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
