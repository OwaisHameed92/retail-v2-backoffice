<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Events\BranchDeactivated;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a branch. Its tills are left as they are, but an inactive branch cannot trade (the licence
 * module checks the branch too). A business must keep at least one active branch: cancel it instead.
 */
class DeactivateBranch
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Branch $branch): Branch
    {
        return $this->tenancy->runAs($branch->company()->firstOrFail(), fn () => DB::transaction(function () use ($branch) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());

            if (! $branch->is_active) {
                return $branch;
            }

            $otherActive = Branch::query()->active()->whereKeyNot($branch->getKey())->lockForUpdate()->exists();

            if (! $otherActive) {
                throw ValidationException::withMessages([
                    'branch' => "{$branch->name} is the only active branch. A business needs at least one; cancel the business instead.",
                ]);
            }

            $branch->is_active = false;
            $branch->save();

            $this->audit->handle('branch.deactivated', $branch, ['is_active' => true], ['is_active' => false]);
            BranchDeactivated::dispatch($branch);

            return $branch;
        }));
    }
}
