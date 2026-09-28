<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Support\TenantLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReactivateBranch
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException past the company's branches allowed (module 1.11)
     */
    public function handle(Branch $branch): Branch
    {
        $company = $branch->company()->firstOrFail();

        return $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($branch, $company) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());

            if ($branch->is_active) {
                return $branch;
            }

            TenantLimits::ensureCanAddBranch($company, $branch->id, 'branch');

            $branch->is_active = true;
            $branch->save();

            $this->audit->handle('branch.reactivated', $branch, ['is_active' => false], ['is_active' => true]);

            return $branch;
        }));
    }
}
