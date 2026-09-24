<?php

namespace App\Domain\Mail\Policies;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;

/**
 * Admin email screens (log, template previews, test sends). Auto-discovered for EmailLog.
 *
 * Owner and support staff only: the log shows customer addresses and the lifecycle of their accounts.
 * There is no separate "emails" ability yet, so this reuses `licences.manage`, which exactly those two roles hold.
 */
class EmailLogPolicy
{
    public const ABILITY = AdminRole::LICENCES_MANAGE;

    public function viewAny(Admin $admin): bool
    {
        return $admin->hasAbility(self::ABILITY);
    }

    public function sendTest(Admin $admin): bool
    {
        return $admin->hasAbility(self::ABILITY);
    }
}
