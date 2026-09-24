<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a branch (shop) to a company, optionally with its first tills ("Till 1", "Till 2"…; the first is main).
 */
class AddBranch
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly AddRegister $addRegister,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, BranchDetails $details, int $tills = 0): Branch
    {
        if ($company->isCancelled()) {
            throw ValidationException::withMessages(['code' => "{$company->name} is cancelled. Reactivate it before adding branches."]);
        }

        if ($tills < 0 || $tills > NewTenant::MAX_TILLS) {
            throw ValidationException::withMessages(['tills' => 'Choose between 1 and '.NewTenant::MAX_TILLS.' tills.']);
        }

        return $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($details, $tills) {
            $attributes = $details->toAttributes();
            BranchCodes::ensureUnique($attributes['code']);

            $branch = Branch::query()->create($attributes);

            $this->audit->handle('branch.created', $branch, null, $branch->only(array_keys($attributes)) + ['id' => $branch->id]);

            for ($i = 0; $i < $tills; $i++) {
                $this->addRegister->handle($branch);
            }

            return $branch;
        }));
    }
}
