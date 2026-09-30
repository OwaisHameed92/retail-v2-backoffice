<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a pending or expired invitation: its link stops working for good. Kept for the audit trail.
 */
class RevokeInvitation
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, CompanyInvitation $invitation): CompanyInvitation
    {
        return DB::transaction(function () use ($company, $invitation) {
            /** @var CompanyInvitation $locked */
            $locked = CompanyInvitation::withoutCompanyScope()
                ->where('company_id', $company->getKey())
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status()->isOpen()) {
                throw ValidationException::withMessages(['status' => "This invitation was already {$locked->status()->value}."]);
            }

            $locked->revoked_at = now();
            $locked->save();

            $this->audit->handle('company.invitation_revoked', $locked, null, null, ['email' => $locked->email], companyId: $company->getKey());

            return $locked;
        });
    }
}
