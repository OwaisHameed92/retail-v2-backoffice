<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\PortalOrders;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;

/**
 * The sidebar's "Online orders" count (shared prop `onlineOrdersNew`): the signed-in user's new orders, any day.
 * Null when the item is not shown: the online store is switched off, or the user cannot run it (`store.manage`).
 */
final class NewOnlineOrderCount
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly PortalOrders $orders) {}

    public function get(): ?int
    {
        if (! OnlineStoreSwitch::enabled() || ! $this->tenancy->can(Ability::StoreManage)) {
            return null;
        }

        return $this->orders->query()->where('status', OnlineOrderStatus::New)->count();
    }
}
