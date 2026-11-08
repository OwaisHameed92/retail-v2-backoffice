<?php

namespace App\Domain\Workshop\Policies;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;

/**
 * Super admin access to the BikeBook connector cards (W5). Auto-discovered for App\Domain\Workshop\Models\
 * WorkshopConnection. `tenants.view` reads the card and the call log; `licences.manage` (like a shop's sync key and
 * licence) ticks the add-on, connects, disconnects, creates or revokes the API key and replays webhooks. Customer
 * users never pass: the methods only accept an Admin.
 */
class WorkshopConnectionPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAbility(AdminRole::TENANTS_VIEW);
    }

    public function manage(Admin $actor): bool
    {
        return $actor->hasAbility(AdminRole::LICENCES_MANAGE);
    }
}
