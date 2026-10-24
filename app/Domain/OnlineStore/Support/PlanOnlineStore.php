<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * OS-7 (owner 2026-10-24, option B): a plan can include the online store add-on (`plans.includes_online_store`), like
 * the handheld app. Only a recurring (monthly or yearly) plan can, and only where the country runs online stores (never
 * on PK). The add-on stays its own "Online store" line on the same monthly invoice, once per business whatever its
 * branches and tills, at the business's own price, else the plan's (`online_store_monthly_price`), else the default.
 * Never a till Feature: nothing goes to the tills.
 */
final class PlanOnlineStore
{
    /** The country runs online stores (flag `onlineStore`: GB on, PK off). */
    public static function offered(): bool
    {
        return app(Country::class)->feature(OnlineStoreSwitch::FEATURE);
    }

    /** The plan includes the online store here (a recurring plan with the box ticked, in a country that runs them). */
    public static function includes(?Plan $plan): bool
    {
        return $plan !== null && self::offered() && $plan->includesOnlineStore();
    }

    /** The add-on's monthly price before VAT on this plan: the plan's own when it includes it, else the default. */
    public static function price(?Plan $plan): string
    {
        $own = $plan !== null && $plan->includes_online_store ? $plan->online_store_monthly_price : null;

        return Money::normalise($own ?? (string) config('online_store.default_monthly_price', '20.00'));
    }

    /**
     * For screens: the plan's add-on price (formatted), or null when the plan does not include the online store.
     *
     * @return array{price: string, priceLabel: string}|null
     */
    public static function summary(Plan $plan): ?array
    {
        if (! self::includes($plan)) {
            return null;
        }

        $price = self::price($plan);

        return ['price' => $price, 'priceLabel' => BillingFormat::money($price)];
    }

    /**
     * The plan as it will be saved may include the online store: recurring only, a price of 0 or more, and only where
     * the country runs online stores.
     *
     * @throws ValidationException
     */
    public static function assertValid(Plan $plan): void
    {
        if (! $plan->includes_online_store) {
            return;
        }

        if (! self::offered()) {
            throw ValidationException::withMessages(['includes_online_store' => 'Online stores are not offered in this country.']);
        }

        if (! $plan->billingType()->recurs()) {
            throw ValidationException::withMessages(['includes_online_store' => 'Only a monthly or yearly plan can include the online store. Untick it, or choose a plan type with a monthly fee.']);
        }

        if ($plan->online_store_monthly_price !== null && Money::isNegative($plan->online_store_monthly_price)) {
            throw ValidationException::withMessages(['online_store_monthly_price' => 'The online store fee cannot be below 0.']);
        }
    }
}
