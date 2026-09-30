<?php

namespace App\Domain\PortalUsers\Data;

use App\Domain\PortalUsers\Enums\InvitationStatus;
use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\PortalUsers\Support\MemberAccess;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * What the invitation link screen shows (module 4.1):
 * - `invalid` / `expired` / `revoked` / `accepted`: a message, nothing to do;
 * - `register`: no account for the email yet → name and password;
 * - `signIn`: an account exists → sign in first (the login screen returns here);
 * - `join`: signed in as the invited email → one button;
 * - `wrongAccount`: signed in as someone else → "Not you?" signs them out and returns here.
 * Nothing about the business is shown unless the link is signed and its token matches.
 */
final class InvitationLinkState
{
    public const INVALID_MESSAGE = 'This invitation link is not valid. Ask the business owner to send a new one.';

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, ?CompanyInvitation $invitation, string $token): array
    {
        if ($invitation === null || ! $invitation->matchesToken($token)) {
            return ['state' => 'invalid'];
        }

        $status = $invitation->status();
        $company = Company::query()->find($invitation->company_id);

        if ($company === null || ! $company->status->allowsPortalAccess()) {
            return ['state' => 'invalid'];
        }

        $details = [
            'businessName' => $company->name,
            'inviterName' => $invitation->inviter?->name,
        ];

        // An old (resent) link fails the token check above; a link past its date fails the signature.
        if ($status === InvitationStatus::Pending && ! $request->hasValidSignature()) {
            $status = InvitationStatus::Expired;
        }

        if ($status !== InvitationStatus::Pending) {
            return ['state' => $status->value] + $details;
        }

        $signedIn = $request->user();
        $accountExists = User::query()->where('email', $invitation->email)->exists();

        $state = match (true) {
            $signedIn instanceof User && $signedIn->email !== $invitation->email => 'wrongAccount',
            $signedIn instanceof User => 'join',
            $accountExists => 'signIn',
            default => 'register',
        };

        return $details + [
            'state' => $state,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'roleLabel' => $invitation->role->label(),
            'branchName' => MemberAccess::branchName($company, $invitation->branch_id),
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'signedInAs' => $signedIn instanceof User ? $signedIn->email : null,
            'url' => $request->fullUrl(),
        ];
    }
}
