<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBranch
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Branch $branch, BranchDetails $details): Branch
    {
        return $this->tenancy->runAs($branch->company()->firstOrFail(), fn () => DB::transaction(function () use ($branch, $details) {
            $attributes = $details->toAttributes();

            if ($attributes['code'] !== $branch->code) {
                BranchCodes::ensureUnique($attributes['code'], $branch);
            }

            $branch->fill($attributes);

            [$before, $after] = AuditChanges::of($branch);

            if ($after === []) {
                return $branch;
            }

            $branch->save();

            $this->audit->handle('branch.updated', $branch, $before, $after);

            return $branch;
        }));
    }
}
