<?php

namespace App\Http\Controllers\App;

use App\Domain\PortalUsers\Actions\AcceptInvitation;
use App\Domain\PortalUsers\Data\InvitationLinkState;
use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\Tenancy\Actions\SwitchCurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\AcceptInvitationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The emailed invitation link (module 4.1). Open to guests and signed-in users alike: the invitee is not a member yet.
 * The link is signed (7-day expiry) and carries a one-time token; both are checked on every request, and the token
 * again by AcceptInvitation under a lock.
 */
class InvitationAcceptController extends Controller
{
    public function show(Request $request, string $invitation, string $token): Response
    {
        $state = InvitationLinkState::for($request, CompanyInvitation::findForLink($invitation), $token);

        if ($state['state'] === 'signIn') {
            // After signing in, the login screen brings them straight back here.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return Inertia::render('auth/accept-invitation', $state);
    }

    public function accept(AcceptInvitationRequest $request, string $invitation, string $token, AcceptInvitation $accept): RedirectResponse
    {
        $model = CompanyInvitation::findForLink($invitation);

        if ($model === null || ! $request->hasValidSignature()) {
            return redirect()->to($request->fullUrl())->withErrors(['invitation' => InvitationLinkState::INVALID_MESSAGE]);
        }

        $signedIn = $request->user();
        $user = $accept->handle($model, $token, $signedIn, $request->input('name'), $request->input('password'));

        if ($signedIn === null) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $request->session()->put(SwitchCurrentCompany::SESSION_KEY, $model->company_id);

        return redirect()->route('app.dashboard')->with('success', 'Welcome! You now have access to this business.');
    }

    /**
     * "Not you?": sign the other account out and come back to the same link.
     */
    public function switchAccount(Request $request): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to($request->fullUrl());
    }
}
