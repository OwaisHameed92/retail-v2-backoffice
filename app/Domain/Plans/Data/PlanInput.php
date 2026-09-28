<?php

namespace App\Domain\Plans\Data;

use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\Money;

/**
 * Validated values for creating or updating a plan. Prices are normalised to 2 dp strings, never floats.
 */
final readonly class PlanInput
{
    /** @var list<Feature> */
    public array $features;

    public string $pricePerTillMonthly;

    public string $pricePerTillYearly;

    public string $setupFee;

    /**
     * @param  iterable<Feature|string>  $features
     */
    public function __construct(
        public string $name,
        public string $code,
        public ?string $description,
        string|int $pricePerTillMonthly,
        string|int $pricePerTillYearly,
        iterable $features = [],
        public int $trialDays = Plan::DEFAULT_TRIAL_DAYS,
        public int $trialGraceDays = Plan::DEFAULT_TRIAL_GRACE_DAYS,
        public int $graceDays = Plan::DEFAULT_GRACE_DAYS,
        public bool $isActive = true,
        public bool $isPublic = false,
        public int $sortOrder = 0,
        string|int $setupFee = '0.00',
    ) {
        $this->pricePerTillMonthly = Money::normalise($pricePerTillMonthly);
        $this->pricePerTillYearly = Money::normalise($pricePerTillYearly);
        $this->setupFee = Money::normalise($setupFee);
        $this->features = Feature::normalise($features);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $description = $this->description === null ? null : trim($this->description);

        return [
            'name' => trim($this->name),
            'code' => strtolower(trim($this->code)),
            'description' => $description === '' ? null : $description,
            'price_per_till_monthly' => $this->pricePerTillMonthly,
            'price_per_till_yearly' => $this->pricePerTillYearly,
            'setup_fee' => $this->setupFee,
            'currency' => Plan::CURRENCY,
            'trial_days' => $this->trialDays,
            'trial_grace_days' => $this->trialGraceDays,
            'grace_days' => $this->graceDays,
            'features' => $this->features,
            'is_active' => $this->isActive,
            'is_public' => $this->isPublic,
            'sort_order' => $this->sortOrder,
        ];
    }
}
