<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a company on hold: trial | active | overdue → suspended. Its users see an "on hold" page instead of
 * the portal. The previous status is remembered so UnsuspendCompany can restore it.
 */
class SuspendCompany
{
    private const FROM = [CompanyStatus::Trial, CompanyStatus::Active, CompanyStatus::Overdue];

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $reason): Company
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter a reason for the suspension.']);
        }

        return DB::transaction(function () use ($company, $reason) {
            $company->refresh();

            if (! in_array($company->status, self::FROM, true)) {
                throw ValidationException::withMessages([
                    'status' => "{$company->name} cannot be suspended while it is {$company->status->label()}.",
                ]);
            }

            $company->suspended_from_status = $company->status;
            $company->status = CompanyStatus::Suspended;
            $company->suspended_at = now();
            $company->suspension_reason = $reason;

            [$before, $after] = AuditChanges::of($company);
            $company->save();

            $this->audit->handle('company.suspended', $company, $before, $after, ['reason' => $reason], companyId: $company->id);

            return $company;
        });
    }
}
