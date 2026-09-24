<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;

trait TenancyTestHelpers
{
    protected function memberOf(Company $company, CompanyRole $role = CompanyRole::Owner, bool $active = true, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => $active]);

        return $user;
    }
}
