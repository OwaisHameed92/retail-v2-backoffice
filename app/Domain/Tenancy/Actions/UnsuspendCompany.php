<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifts a suspension: suspended → the status the company had before (trial, active or overdue).
 */
class UnsuspendCompany
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company): Company
    {
        return DB::transaction(function () use ($company) {
            $company->refresh();

            if ($company->status !== CompanyStatus::Suspended) {
                throw ValidationException::withMessages(['status' => "{$company->name} is not suspended."]);
            }

            $company->status = $company->suspended_from_status ?? CompanyStatus::Active;
            $company->suspended_from_status = null;
            $company->suspended_at = null;
            $company->suspension_reason = null;

            [$before, $after] = AuditChanges::of($company);
            $company->save();

            $this->audit->handle('company.unsuspended', $company, $before, $after, companyId: $company->id);

            return $company;
        });
    }
}
