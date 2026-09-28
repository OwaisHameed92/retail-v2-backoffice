<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Illuminate\Validation\ValidationException;

/**
 * What a customer's licence form allows (module 1.11, contract §17.16): tills per branch (`max_registers`, the
 * key's `maxRegisters`) and branches per company (`multi_branch` + `max_branches`, the key's feature
 * `multi_branch` and `limits.branches`). Counted on active, not deleted rows; admin code, so no tenant scope.
 */
final class TenantLimits
{
    public static function activeTills(string $branchId, ?string $exceptRegisterId = null): int
    {
        return Register::withoutGlobalScope(CompanyScope::class)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->when($exceptRegisterId !== null, fn ($query) => $query->whereKeyNot($exceptRegisterId))
            ->count();
    }

    public static function activeBranches(string $companyId, ?string $exceptBranchId = null): int
    {
        return Branch::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($exceptBranchId !== null, fn ($query) => $query->whereKeyNot($exceptBranchId))
            ->count();
    }

    /** Branches the company may run: 1 without multi-branch. */
    public static function branchesAllowed(Company $company): int
    {
        return $company->multi_branch ? max(1, $company->max_branches) : 1;
    }

    /**
     * Refuses one more active till past the branch's tills allowed ("3 of 3 in use").
     *
     * @throws ValidationException
     */
    public static function ensureCanAddTill(Branch $branch, ?string $exceptRegisterId = null, string $field = 'code'): void
    {
        $inUse = self::activeTills($branch->id, $exceptRegisterId);

        if ($inUse >= $branch->max_registers) {
            throw ValidationException::withMessages([$field => "{$branch->name} has {$inUse} of {$branch->max_registers} tills allowed in use. Raise the tills allowed in its licence settings first."]);
        }
    }

    /**
     * Refuses one more active branch without multi-branch, or past the branches allowed.
     *
     * @throws ValidationException
     */
    public static function ensureCanAddBranch(Company $company, ?string $exceptBranchId = null, string $field = 'code'): void
    {
        $inUse = self::activeBranches($company->id, $exceptBranchId);

        if ($inUse === 0) {
            return;
        }

        if (! $company->multi_branch) {
            throw ValidationException::withMessages([$field => "{$company->name} is licensed for one branch. Turn on multi-branch in its licence settings first."]);
        }

        $allowed = self::branchesAllowed($company);

        if ($inUse >= $allowed) {
            throw ValidationException::withMessages([$field => "{$company->name} has {$inUse} of {$allowed} branches allowed in use. Raise the branches allowed first."]);
        }
    }
}
