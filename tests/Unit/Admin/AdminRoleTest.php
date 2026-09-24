<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Enums\AdminRole;
use PHPUnit\Framework\TestCase;

class AdminRoleTest extends TestCase
{
    public function test_values_are_camel_case(): void
    {
        $this->assertSame(['owner', 'sales', 'support', 'accounts'], array_map(fn (AdminRole $r) => $r->value, AdminRole::cases()));
    }

    public function test_owner_can_do_everything(): void
    {
        foreach (AdminRole::allAbilities() as $ability) {
            $this->assertTrue(AdminRole::Owner->can($ability), $ability);
        }
    }

    public function test_only_owner_can_manage_admins(): void
    {
        $this->assertTrue(AdminRole::Owner->can(AdminRole::ADMINS_MANAGE));
        $this->assertFalse(AdminRole::Sales->can(AdminRole::ADMINS_MANAGE));
        $this->assertFalse(AdminRole::Support->can(AdminRole::ADMINS_MANAGE));
        $this->assertFalse(AdminRole::Accounts->can(AdminRole::ADMINS_MANAGE));
    }

    public function test_default_permission_map(): void
    {
        $this->assertTrue(AdminRole::Sales->can(AdminRole::LEADS_MANAGE));
        $this->assertFalse(AdminRole::Sales->can(AdminRole::BILLING_MANAGE));
        $this->assertTrue(AdminRole::Support->can(AdminRole::LICENCES_MANAGE));
        $this->assertFalse(AdminRole::Support->can(AdminRole::LEADS_MANAGE));
        $this->assertTrue(AdminRole::Accounts->can(AdminRole::BILLING_MANAGE));
        $this->assertFalse(AdminRole::Accounts->can(AdminRole::TENANTS_MANAGE));

        foreach (AdminRole::cases() as $role) {
            $this->assertTrue($role->can(AdminRole::TENANTS_VIEW));
            $this->assertFalse($role !== AdminRole::Owner && $role->can('something.unknown'));
        }
    }
}
