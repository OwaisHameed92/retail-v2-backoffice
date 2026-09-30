<?php

namespace App\Http\Controllers\App;

use App\Domain\PortalUsers\Actions\InviteUser;
use App\Domain\PortalUsers\Actions\ResendInvitation;
use App\Domain\PortalUsers\Actions\RevokeInvitation;
use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\PortalUserAccessRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Invite, resend and revoke on the Portal users page (module 4.1). `company.can:users.manage` (owner only). Invitations
 * are tenant-scoped: another business's invitation is simply not found.
 */
class PortalInvitationController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function store(PortalUserAccessRequest $request, InviteUser $invite): RedirectResponse
    {
        $invitation = $invite->handle(
            $this->tenancy->require(),
            $request->user(),
            (string) $request->input('name'),
            (string) $request->input('email'),
            $request->role(),
            $request->branchId(),
        );

        return back()->with('success', "Invitation sent to {$invitation->email}. The link works for ".CompanyInvitation::VALID_DAYS.' days.');
    }

    public function resend(string $invitation, ResendInvitation $resend): RedirectResponse
    {
        $sent = $resend->handle($this->tenancy->require(), CompanyInvitation::query()->findOrFail($invitation));

        return back()->with('success', "A new invitation link is on its way to {$sent->email}. The old link no longer works.");
    }

    public function destroy(string $invitation, RevokeInvitation $revoke): RedirectResponse
    {
        $revoked = $revoke->handle($this->tenancy->require(), CompanyInvitation::query()->findOrFail($invitation));

        return back()->with('success', "The invitation for {$revoked->email} is cancelled.");
    }
}
