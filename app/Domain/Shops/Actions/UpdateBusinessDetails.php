<?php

namespace App\Domain\Shops\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shops\Data\BusinessDetails;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner edits the business's details from the portal (module 4.7). Only BusinessDetails::COLUMNS are written; a
 * change to one of the till's Company members reaches every till in the pull (SentToTills). Never by a one-shop user
 * (the business is every shop's). Audited as `company.updated` (via portal).
 */
class UpdateBusinessDetails
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Company $company, BusinessDetails $details): Company
    {
        if ($this->tenancy->id() !== $company->id || $this->tenancy->restrictedBranchId() !== null) {
            throw new AuthorizationException('Only the owner can change the business details.');
        }

        if ($details->name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the business name.']);
        }

        return DB::transaction(function () use ($company, $details) {
            $company->fill(array_intersect_key($details->toAttributes(), array_flip(BusinessDetails::COLUMNS)));

            [$before, $after] = AuditChanges::of($company);

            if ($after === []) {
                return $company;
            }

            $company->save();

            $this->audit->handle('company.updated', $company, $before, $after, ['via' => 'portal'], companyId: $company->id);

            return $company;
        });
    }
}
