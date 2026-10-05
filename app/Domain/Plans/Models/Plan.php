<?php

namespace App\Domain\Plans\Models;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\PlanStatus;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonInterface;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A subscription plan. Licences (module 1.3) and invoices (module 1.8) reference it. One licence = one till,
 * Module 1.13: `pricing_mode` says whether the monthly/yearly price is per live till or per active branch. Prices
 * are in pounds (MoneyCast strings, never floats).
 *
 * Not tenant-owned: plans are global and managed by SSPOS staff with the `billing.manage` ability.
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property PricingMode $pricing_mode
 * @property PlanBillingType|null $billing_type Null on rows saved before 2026-10-05: see billingType().
 * @property string $price_monthly
 * @property string $price_yearly
 * @property string $setup_fee
 * @property string $currency
 * @property int $trial_days
 * @property int $trial_grace_days
 * @property int $grace_days
 * @property Collection<int, Feature> $features
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public const CURRENCY = 'GBP';

    public const DEFAULT_TRIAL_DAYS = 7;

    public const DEFAULT_TRIAL_GRACE_DAYS = 3;

    public const DEFAULT_GRACE_DAYS = 7;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'code',
        'description',
        'pricing_mode',
        'billing_type',
        'price_monthly',
        'price_yearly',
        'setup_fee',
        'currency',
        'trial_days',
        'trial_grace_days',
        'grace_days',
        'features',
        'is_active',
        'is_public',
        'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => self::CURRENCY,
        'pricing_mode' => 'perTill',
        'setup_fee' => '0.00',
        'trial_days' => self::DEFAULT_TRIAL_DAYS,
        'trial_grace_days' => self::DEFAULT_TRIAL_GRACE_DAYS,
        'grace_days' => self::DEFAULT_GRACE_DAYS,
        'features' => '[]',
        'is_active' => true,
        'is_public' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_mode' => PricingMode::class,
            'billing_type' => PlanBillingType::class,
            'price_monthly' => MoneyCast::class,
            'price_yearly' => MoneyCast::class,
            'setup_fee' => MoneyCast::class,
            'trial_days' => 'integer',
            'trial_grace_days' => 'integer',
            'grace_days' => 'integer',
            'features' => AsEnumCollection::of(Feature::class),
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }

    public function status(): PlanStatus
    {
        return match (true) {
            $this->trashed() => PlanStatus::Archived,
            ! $this->is_active => PlanStatus::Inactive,
            ! $this->is_public => PlanStatus::Hidden,
            default => PlanStatus::Active,
        };
    }

    /** Setup only, setup + recurring or recurring only: the stored type, else what the prices imply. */
    public function billingType(): PlanBillingType
    {
        return $this->billing_type ?? PlanBillingType::infer((string) $this->setup_fee, (string) $this->price_monthly, (string) $this->price_yearly);
    }

    public function hasFeature(Feature $feature): bool
    {
        return $this->features->contains($feature);
    }

    /**
     * @return list<string>
     */
    public function featureValues(): array
    {
        return $this->features->map(fn (Feature $feature) => $feature->value)->values()->all();
    }

    /**
     * Licences on this plan across every company (the documented admin escape hatch: a plan is global),
     * including revoked and deleted ones.
     *
     * @return HasMany<Licence, $this>
     */
    public function licences(): HasMany
    {
        return $this->hasMany(Licence::class)->withoutGlobalScope(CompanyScope::class)->withTrashed();
    }

    /**
     * Whether any licence uses this plan (any status, revoked and deleted included). An in-use plan cannot be
     * archived: make it inactive to stop new tills getting it.
     */
    public function isInUse(): bool
    {
        return $this->licences()->exists();
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Filter by display status. Archived plans are only included for PlanStatus::Archived.
     *
     * @param  Builder<Plan>  $query
     */
    public function scopeWhereStatus(Builder $query, PlanStatus $status): void
    {
        match ($status) {
            PlanStatus::Archived => $query->onlyTrashed(),
            PlanStatus::Inactive => $query->where('is_active', false),
            PlanStatus::Hidden => $query->where('is_active', true)->where('is_public', false),
            PlanStatus::Active => $query->where('is_active', true)->where('is_public', true),
        };
    }
}
