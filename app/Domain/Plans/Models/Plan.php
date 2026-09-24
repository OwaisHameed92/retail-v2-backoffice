<?php

namespace App\Domain\Plans\Models;

use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanStatus;
use App\Domain\Shared\Casts\MoneyCast;
use Carbon\CarbonInterface;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A subscription plan. Licences (module 1.3) and invoices (module 1.8) reference it. One licence = one till,
 * so prices are per till, in pounds (MoneyCast strings, never floats).
 *
 * Not tenant-owned: plans are global and managed by SSPOS staff with the `billing.manage` ability.
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string $price_per_till_monthly
 * @property string $price_per_till_yearly
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
        'price_per_till_monthly',
        'price_per_till_yearly',
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
            'price_per_till_monthly' => MoneyCast::class,
            'price_per_till_yearly' => MoneyCast::class,
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
     * Whether any licence uses this plan. An in-use plan cannot be archived.
     *
     * Always false until module 1.3 (Licences) adds the `licences` table: 1.3 wires this to
     * `$this->licences()->exists()` (all licences, including suspended and expired ones).
     */
    public function isInUse(): bool
    {
        return false;
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
