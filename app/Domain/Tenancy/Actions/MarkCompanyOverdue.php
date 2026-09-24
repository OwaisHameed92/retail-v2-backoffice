<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;

/**
 * trial | active → overdue: the company has an unpaid invoice past its due date (cash billing, module 1.8).
 * The portal stays fully usable; ActivateCompany turns it back to active once nothing is overdue. Any other
 * status is left alone (returns false), so billing:run can call it every day.
 */
class MarkCompanyOverdue
{
    private const FROM = [CompanyStatus::Trial, CompanyStatus::Active];

    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Company $company, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($company, $reason) {
            $company->refresh();

            if (! in_array($company->status, self::FROM, true)) {
                return false;
            }

            $company->status = CompanyStatus::Overdue;

            [$before, $after] = AuditChanges::of($company);
            $company->save();

            $this->audit->handle('company.overdue', $company, $before, $after, $reason === null ? [] : ['reason' => $reason], companyId: $company->id);

            return true;
        });
    }
}
