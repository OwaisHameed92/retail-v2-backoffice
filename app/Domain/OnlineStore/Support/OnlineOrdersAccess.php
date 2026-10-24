<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\Tenancy\Models\Company;

/**
 * Whether a business can work its online orders in the portal (audit OSA-5): while its store is available
 * (OnlineStoreSwitch::availableFor), and also after the add-on, cloud sync or the monthly plan went off for as long as
 * it still has orders to finish (new, picking, ready, out for delivery). Only new orders stop. Needs the instance
 * switch on.
 */
final class OnlineOrdersAccess
{
    public static function reachable(Company $company): bool
    {
        return OnlineStoreSwitch::enabled() && (OnlineStoreSwitch::availableFor($company) || self::openOrders($company) > 0);
    }

    /** Orders still to be worked, any shop, any day. */
    public static function openOrders(Company $company): int
    {
        $open = array_values(array_map(fn (OnlineOrderStatus $s) => $s->value, array_filter(OnlineOrderStatus::cases(), fn (OnlineOrderStatus $s) => $s->isOpen())));

        return OnlineOrder::withoutCompanyScope()->where('company_id', $company->id)->whereIn('status', $open)->count();
    }
}
