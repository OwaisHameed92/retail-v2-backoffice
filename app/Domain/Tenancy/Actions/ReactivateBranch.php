<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;

class ReactivateBranch
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Branch $branch): Branch
    {
        return $this->tenancy->runAs($branch->company()->firstOrFail(), fn () => DB::transaction(function () use ($branch) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());

            if ($branch->is_active) {
                return $branch;
            }

            $branch->is_active = true;
            $branch->save();

            $this->audit->handle('branch.reactivated', $branch, ['is_active' => false], ['is_active' => true]);

            return $branch;
        }));
    }
}
