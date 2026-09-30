<?php

namespace App\Domain\PortalUsers\Support;

use App\Domain\Mail\Data\PortalInvitationData;
use App\Domain\Mail\Mailables\PortalInvitationMail;
use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Gives an invitation a fresh token and 7-day period, and queues the branded email with the signed link. Call inside
 * the action's transaction: the mail is queued after commit, and the token only ever lives in the email.
 */
final class InvitationMailer
{
    /**
     * Rotate the token (older links stop working) and restart the validity period. Saves the invitation.
     */
    public function issue(CompanyInvitation $invitation, Company $company): void
    {
        $token = Str::random(48);

        $invitation->token_hash = CompanyInvitation::hashToken($token);
        $invitation->expires_at = now()->addDays(CompanyInvitation::VALID_DAYS);
        $invitation->last_sent_at = now();
        $invitation->save();

        Mail::to($invitation->email)->queue(new PortalInvitationMail(new PortalInvitationData(
            name: $invitation->name,
            email: $invitation->email,
            businessName: $company->name,
            roleLabel: $invitation->role->label(),
            branchName: MemberAccess::branchName($company, $invitation->branch_id),
            inviterName: $invitation->inviter?->name,
            url: $this->link($invitation, $token),
            expiresAt: $invitation->expires_at,
            companyId: $company->getKey(),
        )));
    }

    /**
     * The signed accept link: the invitation id and token in the path, the signature and expiry in the query.
     */
    public function link(CompanyInvitation $invitation, #[SensitiveParameter] string $token): string
    {
        return URL::temporarySignedRoute('app.invitations.show', $invitation->expires_at, [
            'invitation' => $invitation->getKey(),
            'token' => $token,
        ]);
    }
}
