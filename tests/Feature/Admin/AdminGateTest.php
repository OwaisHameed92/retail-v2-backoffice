<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Support\Facades\Gate;

it('lets each admin role use its named abilities through the gate', function (AdminRole $role, string $ability, bool $allowed) {
    $admin = Admin::factory()->create(['role' => $role]);

    expect(Gate::forUser($admin)->allows($ability))->toBe($allowed);
})->with([
    'owner manages admins' => [AdminRole::Owner, AdminRole::ADMINS_MANAGE, true],
    'sales manages leads' => [AdminRole::Sales, AdminRole::LEADS_MANAGE, true],
    'sales cannot manage billing' => [AdminRole::Sales, AdminRole::BILLING_MANAGE, false],
    'support manages licences' => [AdminRole::Support, AdminRole::LICENCES_MANAGE, true],
    'accounts manages billing' => [AdminRole::Accounts, AdminRole::BILLING_MANAGE, true],
    'accounts cannot manage licences' => [AdminRole::Accounts, AdminRole::LICENCES_MANAGE, false],
]);

it('denies every named ability to an inactive admin', function () {
    $admin = Admin::factory()->create(['role' => AdminRole::Owner, 'is_active' => false]);

    expect(Gate::forUser($admin)->allows(AdminRole::TENANTS_VIEW))->toBeFalse();
});
