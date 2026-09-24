<?php

namespace App\Domain\Leads\Policies;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Models\Lead;

/**
 * Super admin access to leads (module 1.6). Auto-discovered for App\Domain\Leads\Models\Lead.
 *
 * - Read (list, board, detail): `leads.manage` or `tenants.view`, so support and accounts can look a caller up.
 * - Work leads (add, edit, notes, assign, follow-up, contacted, reject, reopen, archive): `leads.manage`
 *   (owner, sales).
 * - Approve a trial also creates a tenant: `leads.manage` and `tenants.manage`.
 *
 * Customer users (web guard) never pass: the methods only accept an Admin.
 */
class LeadPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAbility(AdminRole::LEADS_MANAGE) || $actor->hasAbility(AdminRole::TENANTS_VIEW);
    }

    public function view(Admin $actor, Lead $lead): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasAbility(AdminRole::LEADS_MANAGE);
    }

    public function update(Admin $actor, Lead $lead): bool
    {
        return $actor->hasAbility(AdminRole::LEADS_MANAGE);
    }

    public function approve(Admin $actor, Lead $lead): bool
    {
        return $actor->hasAbility(AdminRole::LEADS_MANAGE) && $actor->hasAbility(AdminRole::TENANTS_MANAGE);
    }
}
