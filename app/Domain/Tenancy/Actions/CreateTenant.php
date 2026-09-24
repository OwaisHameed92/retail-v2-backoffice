<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a customer in one transaction: the company, its first branch with N tills (the first is the main
 * till) and the owner login. A new owner gets the branded 7-day "set your password" email once everything is committed.
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

        if (! in_array($data->status, [CompanyStatus::Trial, CompanyStatus::Active], true)) {
            throw ValidationException::withMessages(['status' => 'A new business starts as a trial or active customer.']);
        }

        return DB::transaction(function () use ($data) {
            $attributes = $data->company->toAttributes();

            if ($data->status !== CompanyStatus::Trial) {
                $attributes['trial_ends_at'] = null;
            }

            $company = new Company($attributes);
            $company->status = $data->status;
            $company->activated_at = $data->status === CompanyStatus::Active ? now() : null;
            $company->save();

            $this->audit->handle('company.created', $company, null, [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->status->value,
            ], [
                'tills' => $data->tills,
                'owner_email' => $data->ownerEmail,
            ], companyId: $company->id);

            $this->addBranch->handle($company, $data->branch, $data->tills);
            $this->addCompanyUser->handle($company, $data->ownerName, $data->ownerEmail, CompanyRole::Owner);

            return $company;
        });
    }
}
