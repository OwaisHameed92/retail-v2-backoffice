<?php

namespace App\Domain\Plans\Data;

use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\Money;

/**
 * Validated values for creating or updating a plan. Prices are normalised to 2 dp strings, never floats.
 */
final readonly class PlanInput
{
    /** @var list<Feature> */
    public array $features;

    public string $priceMonthly;

    public string $priceYearly;

    public string $setupFee;

    /**
     * @param  iterable<Feature|string>  $features
     */
    public function __construct(
        public string $name,
        public string $code,
        public ?string $description,
        string|int $priceMonthly,
        string|int $priceYearly,
        iterable $features = [],
        public int $trialDays = Plan::DEFAULT_TRIAL_DAYS,
        public int $trialGraceDays = Plan::DEFAULT_TRIAL_GRACE_DAYS,
        public int $graceDays = Plan::DEFAULT_GRACE_DAYS,
        public bool $isActive = true,
        public bool $isPublic = false,
        public int $sortOrder = 0,
        string|int $setupFee = '0.00',
        /** Module 1.13: the prices are per till or per branch. */
        public PricingMode $pricingMode = PricingMode::PerTill,
    ) {
        $this->priceMonthly = Money::normalise($priceMonthly);
        $this->priceYearly = Money::normalise($priceYearly);
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
            'pricing_mode' => $this->pricingMode,
            'price_monthly' => $this->priceMonthly,
            'price_yearly' => $this->priceYearly,
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
