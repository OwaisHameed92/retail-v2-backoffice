<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Events\BranchAdded;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\TenantLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a branch (shop) to a company, optionally with its first tills ("Till 1", "Till 2"…; the first is main).
 *
 * Module 1.11: refused past the company's branches allowed, or for a second branch without multi-branch. The
 * branch gets licence settings: the given ones, else a copy of the company's first active branch's (kind,
 * length, start, features), else the defaults; tills allowed is at least the tills added. Features equal to the
 * company's plan (as it is now) are stored as null, so the branch follows the plan (a stale form cannot pin them).
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
    public function handle(Company $company, BranchDetails $details, int $tills = 0, ?BranchLicenceSettings $licence = null): Branch
    {
        if ($company->isCancelled()) {
            throw ValidationException::withMessages(['code' => "{$company->name} is cancelled. Reactivate it before adding branches."]);
        }

        if ($tills < 0 || $tills > NewTenant::MAX_TILLS) {
            throw ValidationException::withMessages(['tills' => 'Choose between 1 and '.NewTenant::MAX_TILLS.' tills.']);
        }

        $licence?->validate();

        return $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($company, $details, $tills, $licence) {
            // Lock the company so two requests cannot both take the last branch allowed.
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            TenantLimits::ensureCanAddBranch($locked);

            $attributes = $details->toAttributes();
            BranchCodes::ensureUnique($attributes['code']);

            $settings = $licence ?? $this->inherited();
            $settings = $settings->withMaxRegisters(max(1, $tills, $settings->maxRegisters))->followingPlan(DefaultPlan::for($locked));

            $branch = new Branch($attributes);
            $branch->forceFill($settings->toAttributes());
            $branch->save();

            $this->audit->handle('branch.created', $branch, null, $branch->only(array_keys($attributes)) + ['id' => $branch->id], [
                'licence' => $settings->toAudit(),
            ]);

            for ($i = 0; $i < $tills; $i++) {
                $this->addRegister->handle($branch);
            }

            BranchAdded::dispatch($branch);

            return $branch;
        }));
    }

    /** The company's first active branch's settings for one till, else the defaults. */
    private function inherited(): BranchLicenceSettings
    {
        $first = Branch::query()->where('is_active', true)->orderBy('created_at')->orderBy('id')->first();

        return $first === null ? new BranchLicenceSettings : BranchLicenceSettings::of($first)->withMaxRegisters(1);
    }
}
