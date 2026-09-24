<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;
use PHPUnit\Framework\TestCase;

class CompanyRoleTest extends TestCase
{
    public function test_owner_has_every_ability(): void
    {
        foreach (Ability::cases() as $ability) {
            $this->assertTrue(CompanyRole::Owner->can($ability), $ability->value);
        }
    }

    public function test_manager_cannot_manage_users_or_view_billing(): void
    {
        $this->assertTrue(CompanyRole::Manager->can('catalogue.manage'));
        $this->assertTrue(CompanyRole::Manager->can('prices.manage'));
        $this->assertFalse(CompanyRole::Manager->can('users.manage'));
        $this->assertFalse(CompanyRole::Manager->can('billing.view'));
    }

    public function test_accountant_sees_reports_and_billing_only(): void
    {
        $this->assertTrue(CompanyRole::Accountant->can('reports.view'));
        $this->assertTrue(CompanyRole::Accountant->can('billing.view'));
        $this->assertFalse(CompanyRole::Accountant->can('catalogue.manage'));
        $this->assertFalse(CompanyRole::Accountant->can('users.manage'));
    }

    public function test_staff_is_read_only(): void
    {
        foreach (CompanyRole::Staff->abilities() as $ability) {
            $this->assertStringEndsWith('.view', $ability->value);
        }

        $this->assertFalse(CompanyRole::Staff->can('reports.view'));
        $this->assertFalse(CompanyRole::Staff->can('billing.view'));
    }

    public function test_unknown_ability_is_denied(): void
    {
        $this->assertFalse(CompanyRole::Owner->can('nope'));
    }
}
