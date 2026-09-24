<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Events\TenantCreated;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a customer in one transaction: the company, its first branch with N tills (the first is the main
 * till), any further branches with their tills (module 1.6: one per shop of an approved lead) and the owner
 * login. A new owner gets the branded 7-day "set your password" email once everything is committed.
 *
 * Licences (module 1.3): each till gets its licence as it is added (RegisterAdded), on the chosen plan or the
 * portal default. TenantCreated then queues the welcome email with every till's key to the owner.
 *
 * Trial companies have no end date unless one is given: the 7-day trial starts on the first till activation.
 */
class CreateTenant
{
    public function __construct(
        private readonly AddBranch $addBranch,
        private readonly AddCompanyUser $addCompanyUser,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(NewTenant $data): Company
    {
        if ($data->tills < 1 || $data->tills > NewTenant::MAX_TILLS) {
            throw ValidationException::withMessages(['tills' => 'Choose between 1 and '.NewTenant::MAX_TILLS.' tills.']);
        }

        foreach ($data->moreBranches as $index => $branch) {
            if ($branch->tills < 1 || $branch->tills > NewTenant::MAX_TILLS) {
                throw ValidationException::withMessages(["branches.{$index}.tills" => 'Choose between 1 and '.NewTenant::MAX_TILLS.' tills.']);
            }
        }

        if (! in_array($data->status, [CompanyStatus::Trial, CompanyStatus::Active], true)) {
            throw ValidationException::withMessages(['status' => 'A new business starts as a trial or active customer.']);
        }

        $plan = $data->planId === null ? null : Plan::query()->whereKey($data->planId)->where('is_active', true)->first();

        if ($data->planId !== null && $plan === null) {
            throw ValidationException::withMessages(['plan_id' => 'Choose an active plan.']);
        }

        return DB::transaction(function () use ($data, $plan) {
            $attributes = $data->company->toAttributes();

            if ($data->status !== CompanyStatus::Trial) {
                $attributes['trial_ends_at'] = null;
            }

            $company = new Company($attributes);
            $company->status = $data->status;
            $company->activated_at = $data->status === CompanyStatus::Active ? now() : null;
            $company->plan_id = $plan?->id;
            $company->save();

            $this->audit->handle('company.created', $company, null, [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->status->value,
                'plan' => $plan?->code,
            ], [
                'tills' => $data->totalTills(),
                'branches' => 1 + count($data->moreBranches),
                'owner_email' => $data->ownerEmail,
            ], companyId: $company->id);

            $this->addBranch->handle($company, $data->branch, $data->tills);

            foreach ($data->moreBranches as $branch) {
                $this->addBranch->handle($company, $branch->details, $branch->tills);
            }
            $owner = $this->addCompanyUser->handle($company, $data->ownerName, $data->ownerEmail, CompanyRole::Owner);

            TenantCreated::dispatch($company, $owner);

            return $company;
        });
    }
}
