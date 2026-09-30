<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\PortalUsers\Support\InvitationMailer;
use App\Domain\PortalUsers\Support\MemberAccess;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An owner invites someone to the business's portal with a role, optionally limited to one shop (module 4.1).
 * Emails a signed link valid for 7 days. Refused for someone who is already a user (active or deactivated: reactivate
 * them instead) or already has an open invitation (resend it instead).
 */
class InviteUser
{
    public function __construct(
        private readonly InvitationMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, User $inviter, string $name, string $email, CompanyRole $role, ?string $branchId = null): CompanyInvitation
    {
        $email = Str::lower(trim($email));
        $name = trim($name);

        MemberAccess::ensureBranchFits($company, $role, $branchId);

        return DB::transaction(function () use ($company, $inviter, $name, $email, $role, $branchId) {
            $existing = User::query()->where('email', $email)->first();
            $membership = $existing !== null ? MemberAccess::membership($company, $existing) : null;

            if ($membership !== null) {
                throw ValidationException::withMessages(['email' => (bool) $membership->is_active
                    ? "{$email} is already a user of {$company->name}."
                    : "{$email} is a deactivated user of {$company->name}. Reactivate them on the Users tab instead."]);
            }

            $open = CompanyInvitation::withoutCompanyScope()
                ->where('company_id', $company->getKey())
                ->where('email', $email)
                ->open()
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw ValidationException::withMessages(['email' => "{$email} already has an invitation. Resend it from the Invitations tab."]);
            }

            $invitation = new CompanyInvitation([
                'company_id' => $company->getKey(),
                'email' => $email,
                'name' => $name,
                'role' => $role,
                'branch_id' => $branchId,
                'invited_by' => $inviter->getKey(),
            ]);
            $invitation->setRelation('inviter', $inviter);

            $this->mailer->issue($invitation, $company);

            $this->audit->handle('company.user_invited', $invitation, null, [
                'role' => $role->value,
                'branch_id' => $branchId,
            ], ['email' => $email, 'existing_account' => $existing !== null], companyId: $company->getKey());

            return $invitation;
        });
    }
}
