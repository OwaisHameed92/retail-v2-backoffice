<?php

namespace App\Domain\Admin\Policies;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;

/**
 * Admin user management. Auto-discovered for App\Domain\Admin\Models\Admin.
 * Only admins with the "admins.manage" ability (owners) may manage other admins.
 */
class AdminPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAbility(AdminRole::ADMINS_MANAGE);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasAbility(AdminRole::ADMINS_MANAGE);
    }

    public function update(Admin $actor, Admin $admin): bool
    {
        return $actor->hasAbility(AdminRole::ADMINS_MANAGE);
    }

    public function deactivate(Admin $actor, Admin $admin): bool
    {
        return $actor->hasAbility(AdminRole::ADMINS_MANAGE);
    }

    public function reactivate(Admin $actor, Admin $admin): bool
    {
        return $actor->hasAbility(AdminRole::ADMINS_MANAGE);
    }
}
