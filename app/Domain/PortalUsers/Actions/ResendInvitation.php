<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\PortalUsers\Support\InvitationMailer;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a pending or expired invitation again with a new link and a new 7-day period. The previous link stops working.
 */
class ResendInvitation
{
    public function __construct(
        private readonly InvitationMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

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
                throw ValidationException::withMessages(['status' => "This invitation was {$locked->status()->value}, so it cannot be sent again."]);
            }

            $locked->send_count++;
            $this->mailer->issue($locked, $company);

            $this->audit->handle('company.invitation_resent', $locked, null, null, [
                'email' => $locked->email,
                'send_count' => $locked->send_count,
            ], companyId: $company->getKey());

            return $locked;
        });
    }
}
