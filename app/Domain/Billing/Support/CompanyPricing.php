<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Collection;

/**
 * How a company is charged each cycle (module 1.13): per live till or per active branch, at a monthly or yearly
 * price per unit. The company's override (billing settings) wins; otherwise the plan's mode and prices. The plan is
 * the one its live tills are licensed on (else the plan new tills get).
 *
 * Per till without a price override keeps each till's own plan price (a company can have tills on two plans).
 */
final readonly class CompanyPricing
{
    public function __construct(
        public PricingMode $mode,
        public ?Plan $plan,
        public ?string $monthlyOverride = null,
        public ?string $yearlyOverride = null,
        public bool $overridden = false,
    ) {}

    /**
     * @param  Collection<int, Licence>|null  $licences  Renewable licences (plan loaded), when already read.
     */
    public static function for(Company $company, BillingAccount $account, ?Collection $licences = null): self
    {
        $licensed = $licences?->first(fn (Licence $licence) => $licence->plan !== null);
        $plan = $licensed instanceof Licence ? $licensed->plan : DefaultPlan::for($company);
        $modeOverride = $account->pricing_mode_override;

        return new self(
            mode: $modeOverride ?? $plan->pricing_mode ?? PricingMode::PerTill,
            plan: $plan,
            monthlyOverride: $account->price_monthly_override,
            yearlyOverride: $account->price_yearly_override,
            overridden: $modeOverride !== null || $account->price_monthly_override !== null || $account->price_yearly_override !== null,
        );
    }

    /** Price of one unit (till or branch) for the cycle, net. `$unitPlan` is a till's own plan (per till). */
    public function unitPrice(BillingCycle $cycle, ?Plan $unitPlan = null): string
    {
        $override = $cycle === BillingCycle::Yearly ? $this->yearlyOverride : $this->monthlyOverride;

        if ($override !== null) {
            return $override;
        }

        $plan = $this->mode === PricingMode::PerTill ? ($unitPlan ?? $this->plan) : $this->plan;

        if ($plan === null) {
            return '0.00';
        }

        return $cycle === BillingCycle::Yearly ? $plan->price_yearly : $plan->price_monthly;
    }
}
