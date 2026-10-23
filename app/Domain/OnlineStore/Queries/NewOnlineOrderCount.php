<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\PortalOrders;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;

/**
 * The sidebar's "Online orders" count (shared prop `onlineOrdersNew`): the signed-in user's new orders, any day.
 * Null when the item is not shown: the store is not available to the business (switch, add-on, cloud sync), or the
 * user cannot run it (`store.manage`).
 */
final class NewOnlineOrderCount
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly PortalOrders $orders) {}

    public function get(): ?int
    {
        $company = $this->tenancy->get();

        if ($company === null || ! $this->tenancy->can(Ability::StoreManage) || ! OnlineStoreSwitch::availableFor($company)) {
            return null;
        }

        return $this->orders->query()->where('status', OnlineOrderStatus::New)->count();
    }
}
