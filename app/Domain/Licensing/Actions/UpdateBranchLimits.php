<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\TenantLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A company's branch limits (module 1.11, contract §17.16): multi-branch (the key's feature `multi_branch`) and
 * branches allowed (`limits.branches`). Without multi-branch the company runs one branch. Cannot go below the
 * active branches. Every key of the company gets its new token at its next validate.
 */
class UpdateBranchLimits
{
    public const MAX_BRANCHES = 99;

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, bool $multiBranch, int $maxBranches): Company
    {
        $maxBranches = $multiBranch ? $maxBranches : 1;

        if ($maxBranches < 1 || $maxBranches > self::MAX_BRANCHES) {
            throw ValidationException::withMessages(['max_branches' => 'Allow between 1 and '.self::MAX_BRANCHES.' branches.']);
        }

        return DB::transaction(function () use ($company, $multiBranch, $maxBranches) {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $active = TenantLimits::activeBranches($company->id);

            if (! $multiBranch && $active > 1) {
                throw ValidationException::withMessages(['multi_branch' => "{$company->name} runs {$active} branches. Deactivate branches until one is left before turning multi-branch off."]);
            }

            if ($maxBranches < $active) {
                throw ValidationException::withMessages(['max_branches' => "{$company->name} runs {$active} branches. Deactivate one before allowing fewer."]);
            }

            $before = ['multi_branch' => $company->multi_branch, 'max_branches' => $company->max_branches];
            $company->multi_branch = $multiBranch;
            $company->max_branches = $maxBranches;

            if (! $company->isDirty()) {
                return $company;
            }

            $company->save();

            $this->audit->handle('company.branch_limits_updated', $company, $before, ['multi_branch' => $multiBranch, 'max_branches' => $maxBranches], companyId: $company->id);

            return $company;
        });
    }
}
