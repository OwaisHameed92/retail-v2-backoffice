<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\Vat;
use App\Domain\OnlineStore\Support\OnlineOrdersAccess;
use App\Domain\OnlineStore\Support\OnlineStoreAddOn;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\PlanOnlineStore;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Models\Company;

/**
 * The admin Billing tab's "Online store add-on" card (OS-6): whether it is on and billed, its monthly price (the
 * business's own or the default, before VAT) and what the invoice line comes to per cycle with VAT, the store's
 * template and address, and why it cannot be used or billed (not a monthly plan, no cloud sync, server switch off).
 * Null where the country has no online stores (Pakistan).
 *
 * OS-7: the price is the business's own, else its plan's when the plan includes the online store, else the default
 * (`defaultPrice` = what a blank price means for this business; `priceSource` says which). `byPlan`: its plan includes
 * the online store (Change plan switches the add-on with the plan); otherwise an add-on switched on here is the
 * exception path and keeps working as before.
 */
final class AdminAddOnData
{
    /**
     * @return array<string, mixed>|null
     */
    public static function for(Company $company, BillingAccount $account): ?array
    {
        if (! app(Country::class)->feature(OnlineStoreSwitch::FEATURE)) {
            return null;
        }

        $store = OnlineStoreAddOn::store($company);
        $on = OnlineStoreAddOn::isOn($company, $store);
        $cloudSync = OnlineStoreAddOn::hasCloudSync($company);
        $serverOn = OnlineStoreSwitch::enabled();
        $monthlyPlan = OnlineStoreAddOn::onRecurringPlan($company);
        $plan = CompanyPricing::for($company, $account)->plan;
        $byPlan = PlanOnlineStore::includes($plan);
        $blank = OnlineStoreSwitch::monthlyPrice(null, $plan);
        $monthly = OnlineStoreSwitch::monthlyPrice($store?->monthly_price, $plan);
        $amounts = InvoiceMaths::line('1', OnlineStoreAddOn::cyclePrice($monthly, $account->cycle), Vat::rateFor($account));

        return [
            'on' => $on,
            'since' => $on ? $store?->addon_on_at?->toIso8601String() : null,
            'monthlyPrice' => $store?->monthly_price,
            'defaultPrice' => $blank,
            'defaultPriceLabel' => BillingFormat::money($blank),
            'priceSource' => match (true) {
                $store?->monthly_price !== null => 'business',
                $byPlan && $plan?->online_store_monthly_price !== null => 'plan',
                default => 'default',
            },
            'byPlan' => $byPlan,
            'planName' => $plan?->name,
            'priceLabel' => BillingFormat::money($monthly),
            'cycleNet' => BillingFormat::money($amounts['net']),
            'cycleGross' => BillingFormat::money($amounts['gross']),
            'per' => $account->cycle->per(),
            'billed' => $on && $monthlyPlan && $cloudSync,
            'monthlyPlan' => $monthlyPlan,
            'custom' => $store?->monthly_price !== null,
            'template' => $store?->template->value,
            'templateLabel' => $store?->template->label(),
            'templates' => OnlineStoreAddOnInput::templateOptions(),
            'slug' => $store?->slug,
            'url' => $store !== null ? app(StoreLinks::class)->home($store) : null,
            'live' => (bool) $store?->is_live,
            'serverOn' => $serverOn,
            'cloudSync' => $cloudSync,
            'available' => $on && $serverOn && $cloudSync && $monthlyPlan,
            // Audit OSA-5: orders still to finish; switching off keeps the Online orders board open for them.
            'openOrders' => $store === null ? 0 : OnlineOrdersAccess::openOrders($company),
        ];
    }
}
