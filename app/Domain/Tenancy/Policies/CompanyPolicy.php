<?php

namespace App\Domain\Tenancy\Policies;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Tenancy\Models\Company;

/**
 * Super admin access to tenants. Auto-discovered for App\Domain\Tenancy\Models\Company.
 * `tenants.view` reads (every admin role); `tenants.manage` changes and "login as customer" (owner, sales, support).
 * Customer users (web guard) never pass: the methods only accept an Admin.
 */
class CompanyPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAbility(AdminRole::TENANTS_VIEW);
    }

    public function view(Admin $actor, Company $company): bool
    {
        return $actor->hasAbility(AdminRole::TENANTS_VIEW);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasAbility(AdminRole::TENANTS_MANAGE);
    }

    public function update(Admin $actor, Company $company): bool
    {
        return $actor->hasAbility(AdminRole::TENANTS_MANAGE);
    }

    public function impersonate(Admin $actor, Company $company): bool
    {
        return $actor->hasAbility(AdminRole::TENANTS_MANAGE);
    }
}
