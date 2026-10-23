<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\Billing\Actions\ApplySetupFeeTerms;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Licensing\Models\Licence;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;

/**
 * The online store add-on of one business (OS-6, docs/online-store.md "Turn on"): an admin switches it on
 * (`online_stores.addon_on`, SetOnlineStoreAddOn) at £20 + VAT a month by default (`online_store.default_monthly_price`),
 * or the business's own price (`online_stores.monthly_price`). Owner 2026-10-24: only for a business on a monthly
 * (recurring) plan with cloud sync; it is a line "Online store" on that business's period invoice (CompanyPricing,
 * InvoiceLineBuilder), so the Direct Debit collects it with the plan. Read across businesses by company id (the
 * public store runs without a current company), never through the ambient scope. Nothing goes to the tills.
 *
 * - On (`isOn`): the add-on is on and the country runs online stores (flag `onlineStore`: never on PK).
 * - Billed (`billedPrice`, read by CompanyPricing only on a recurring plan): on, and the business has cloud sync.
 * - Usable (`OnlineStoreSwitch::availableFor`): billed, a recurring plan, and the `ONLINE_STORE` server switch on.
 *   A business that leaves its monthly plan (or loses cloud sync) stops paying for it and its store goes off line.
 */
final class OnlineStoreAddOn
{
    /** The business's store row (with or without the add-on), or null. */
    public static function store(Company|string $company): ?OnlineStore
    {
        return OnlineStore::withoutCompanyScope()->where('company_id', $company instanceof Company ? $company->id : $company)->first();
    }

    /** The add-on is on for this business (and the country runs online stores). */
    public static function isOn(Company|string $company, ?OnlineStore $store = null): bool
    {
        $store ??= self::store($company);

        return $store !== null && $store->addon_on && app(Country::class)->feature(OnlineStoreSwitch::FEATURE);
    }

    /**
     * What the add-on costs this business a month before VAT while it is on and the business has cloud sync, else
     * null. CompanyPricing asks only for a business on a recurring plan.
     */
    public static function billedPrice(Company|string $company): ?string
    {
        $store = self::store($company);

        if ($store === null || ! self::isOn($company, $store) || ! self::hasCloudSync($company)) {
            return null;
        }

        return Money::normalise(OnlineStoreSwitch::monthlyPrice($store->monthly_price));
    }

    /** The add-on's net price for one billing cycle: the monthly price, or 12 × it on a yearly cycle. */
    public static function cyclePrice(string $monthly, BillingCycle $cycle): string
    {
        return $cycle === BillingCycle::Yearly ? Money::mul($monthly, '12') : Money::normalise($monthly);
    }

    /** The business is on a recurring (monthly or yearly) plan: the only plans the add-on is for. */
    public static function onRecurringPlan(Company $company): bool
    {
        return ApplySetupFeeTerms::planType($company)?->recurs() ?? false;
    }

    /**
     * The business has cloud sync: a live licence (not revoked) that includes `cloud_sync`, the feature that sends
     * its products, prices and stock to us (the store shows them).
     */
    public static function hasCloudSync(Company|string $company): bool
    {
        return Licence::withoutCompanyScope()->where('company_id', $company instanceof Company ? $company->id : $company)->live()
            ->get(['id', 'features', 'status'])
            ->contains(fn (Licence $licence) => ! $licence->isRevoked() && $licence->features->contains(Feature::CloudSync));
    }
}
