<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\OnlineStore\Support\OnlineStoreAddOn;
use App\Domain\OnlineStore\Support\OnlineStoreOnly;
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
 *
 * OS-6: `onlineStoreMonthly` is the online store add-on's monthly price before VAT while it is billed (switched on by
 * an admin, a recurring plan, cloud sync; never on PK: OnlineStoreAddOn::billedPrice), else null. InvoiceLineBuilder
 * adds it as its own line on the period invoice. OS-7: its price is the business's own, else the plan's (when the plan
 * includes the online store), else the default; once per business, whatever its branches and tills.
 *
 * OS-13: `withoutTills` is true for a business with no live till that sells without tills (OnlineStoreOnly: an online
 * store only plan, or started on one): its invoice is the "Online store" line alone. The plan of such a business is
 * its own (`companies.plan_id`) even when that plan is no longer offered.
 */
final readonly class CompanyPricing
{
    public function __construct(
        public PricingMode $mode,
        public ?Plan $plan,
        public ?string $monthlyOverride = null,
        public ?string $yearlyOverride = null,
        public bool $overridden = false,
        public ?string $onlineStoreMonthly = null,
        /** OS-13: no live till and the business sells without tills (OnlineStoreOnly): the store line bills alone. */
        public bool $withoutTills = false,
    ) {}

    /**
     * @param  Collection<int, Licence>|null  $licences  Renewable licences (plan loaded), when already read.
     */
    public static function for(Company $company, BillingAccount $account, ?Collection $licences = null): self
    {
        $licensed = $licences?->first(fn (Licence $licence) => $licence->plan !== null);
        // OS-13: an online store only business keeps its own plan even when it is no longer offered (inactive).
        $plan = $licensed instanceof Licence ? $licensed->plan : (OnlineStoreOnly::ownPlan($company) ?? DefaultPlan::for($company));
        $modeOverride = $account->pricing_mode_override;
        $recurs = $plan?->billingType()->recurs() ?? false;

        return new self(
            mode: $modeOverride ?? $plan->pricing_mode ?? PricingMode::PerTill,
            plan: $plan,
            monthlyOverride: $account->price_monthly_override,
            yearlyOverride: $account->price_yearly_override,
            overridden: $modeOverride !== null || $account->price_monthly_override !== null || $account->price_yearly_override !== null,
            onlineStoreMonthly: $recurs ? OnlineStoreAddOn::billedPrice($company, $plan) : null,
            withoutTills: $recurs && ($licences === null || $licences->isEmpty()) && OnlineStoreOnly::sellsWithoutTills($company),
        );
    }

    /**
     * The same pricing with another online store add-on amount (OS-7: the Change plan preview works out the amount
     * after the plan switches the add-on on or off). Null = no add-on line. OS-13: `$withoutTills` as it will be after.
     */
    public function withOnlineStore(?string $monthly, ?bool $withoutTills = null): self
    {
        return new self($this->mode, $this->plan, $this->monthlyOverride, $this->yearlyOverride, $this->overridden, $monthly, $withoutTills ?? $this->withoutTills);
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
