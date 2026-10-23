<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\Vat;
use App\Domain\OnlineStore\Support\OnlineStoreAddOn;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Models\Company;

/**
 * The admin Billing tab's "Online store add-on" card (OS-6): whether it is on and billed, its monthly price (the
 * business's own or the default, before VAT) and what the invoice line comes to per cycle with VAT, the store's
 * template and address, and why it cannot be used or billed (not a monthly plan, no cloud sync, server switch off).
 * Null where the country has no online stores (Pakistan).
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
        $monthly = OnlineStoreSwitch::monthlyPrice($store?->monthly_price);
        $amounts = InvoiceMaths::line('1', OnlineStoreAddOn::cyclePrice($monthly, $account->cycle), Vat::rateFor($account));

        return [
            'on' => $on,
            'since' => $on ? $store?->addon_on_at?->toIso8601String() : null,
            'monthlyPrice' => $store?->monthly_price,
            'defaultPrice' => OnlineStoreSwitch::monthlyPrice(null),
            'defaultPriceLabel' => BillingFormat::money(OnlineStoreSwitch::monthlyPrice(null)),
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
        ];
    }
}
