<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;

/**
 * Updates a company's business details. Status is not changed here (see the status actions).
 */
class UpdateCompany
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Company $company, CompanyDetails $details): Company
    {
        $company->fill($details->toAttributes());

        [$before, $after] = AuditChanges::of($company);

        if ($after === []) {
            return $company;
        }

        $company->save();

        $this->audit->handle('company.updated', $company, $before, $after, companyId: $company->id);

        return $company;
    }
}
