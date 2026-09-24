<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Casts\AreaCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Enums\Nation;
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
 * @property Nation $nation
 * @property string|null $licensed_hours_json
 * @property bool $is_drs_return_point
 * @property string|null $area_m2
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToCompany, HasFactory, HasPortalUlid, SoftDeletes;

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
