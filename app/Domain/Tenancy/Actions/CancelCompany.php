<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a customer account: any status → cancelled. Its users are signed out on their next request.
 * Data is kept; ActivateCompany can reinstate the account.
 */
class CancelCompany
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $reason): Company
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter a reason for the cancellation.']);
        }

        return DB::transaction(function () use ($company, $reason) {
            $company->refresh();

            if ($company->status === CompanyStatus::Cancelled) {
                throw ValidationException::withMessages(['status' => "{$company->name} is already cancelled."]);
            }

            $company->status = CompanyStatus::Cancelled;
            $company->cancelled_at = now();
            $company->cancellation_reason = $reason;
            $company->suspended_from_status = null;
            $company->suspended_at = null;
            $company->suspension_reason = null;

            [$before, $after] = AuditChanges::of($company);
            $company->save();

            $this->audit->handle('company.cancelled', $company, $before, $after, ['reason' => $reason], companyId: $company->id);

            return $company;
        });
    }
}
