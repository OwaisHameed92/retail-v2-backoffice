<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a company as a paying, active customer: trial | overdue | cancelled → active.
 * Suspended companies go through UnsuspendCompany instead.
 */
class ActivateCompany
{
    private const FROM = [CompanyStatus::Trial, CompanyStatus::Overdue, CompanyStatus::Cancelled];

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company): Company
    {
        return DB::transaction(function () use ($company) {
            $company->refresh();

            if (! in_array($company->status, self::FROM, true)) {
                throw ValidationException::withMessages([
                    'status' => $company->status === CompanyStatus::Active
                        ? "{$company->name} is already active."
                        : "{$company->name} is suspended. Unsuspend it instead.",
                ]);
            }

            $company->status = CompanyStatus::Active;
            $company->activated_at ??= now();
            $company->cancelled_at = null;
            $company->cancellation_reason = null;

            [$before, $after] = AuditChanges::of($company);
            $company->save();

            $this->audit->handle('company.activated', $company, $before, $after, companyId: $company->id);

            return $company;
        });
    }
}
