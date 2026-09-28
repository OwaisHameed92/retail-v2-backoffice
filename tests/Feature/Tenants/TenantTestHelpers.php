<?php

namespace Tests\Feature\Tenants;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use PHPUnit\Framework\Assert;

/**
 * Helpers for the module 1.2 tests. Use with `uses(TenantTestHelpers::class)`.
 */
trait TenantTestHelpers
{
    public function admin(AdminRole $role = AdminRole::Owner): Admin
    {
        return Admin::factory()->role($role)->create();
    }

    public function newTenant(string $name = 'Khan Mini Mart', int $tills = 2, string $code = 'LDS', string $ownerEmail = 'owner@khan.test', CompanyStatus $status = CompanyStatus::Trial): NewTenant
    {
        return new NewTenant(
            company: new CompanyDetails(name: $name, legalName: $name.' Ltd', email: 'hello@khan.test'),
            branch: new BranchDetails(code: $code, name: 'Leeds', nation: Nation::England, address: '12 Kirkgate, Leeds'),
            tills: $tills,
            ownerName: 'Aisha Khan',
            ownerEmail: $ownerEmail,
            status: $status,
            // Module 1.11: room for more branches (tills allowed = the tills; see allowTills()).
            multiBranch: true,
            maxBranches: 10,
        );
    }

    /** Module 1.11: raise a branch's tills allowed (the licence form) so tests can add tills. */
    public function allowTills(Branch $branch, int $tills = 20): Branch
    {
        $branch->forceFill(['max_registers' => $tills])->saveQuietly();

        return $branch;
    }

    public function tenant(string $name = 'Khan Mini Mart', int $tills = 2, string $code = 'LDS', ?string $ownerEmail = null): Company
    {
        $ownerEmail ??= str()->slug($name).'@owner.test';

        return app(CreateTenant::class)->handle($this->newTenant($name, $tills, $code, $ownerEmail));
    }

    public function branchOf(Company $company, string $code = 'LDS'): Branch
    {
        return Branch::withoutCompanyScope()->whereBelongsTo($company)->where('code', $code)->firstOrFail();
    }

    public function registerOf(Branch $branch, string $code): Register
    {
        return Register::withoutCompanyScope()->where('branch_id', $branch->id)->where('code', $code)->firstOrFail();
    }

    public function ownerOf(Company $company): User
    {
        return $company->owners()->firstOrFail();
    }

    public function addMember(Company $company, CompanyRole $role = CompanyRole::Manager, bool $active = true): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => $active]);

        return $user;
    }

    /**
     * The branch keeps the invariant: exactly one active main till when it has active tills, none otherwise,
     * and no inactive till is main.
     */
    public function assertMainTillInvariant(Branch $branch): void
    {
        $registers = Register::withoutCompanyScope()->where('branch_id', $branch->id)->get();
        $active = $registers->where('is_active', true);
        $mains = $registers->where('is_main_till', true);

        Assert::assertSame($active->isEmpty() ? 0 : 1, $mains->count(), 'Main till count');
        Assert::assertTrue($mains->every(fn (Register $r) => $r->is_active), 'Main till is active');
    }

    public function mainTillCode(Branch $branch): ?string
    {
        return Register::withoutCompanyScope()->where('branch_id', $branch->id)->where('is_main_till', true)->value('code');
    }
}
