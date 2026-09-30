<?php

namespace App\Http\Controllers\Settings;

use App\Domain\PortalUsers\Actions\DeleteOwnAccount;
use App\Domain\PortalUsers\Actions\UpdateOwnProfile;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request, UpdateOwnProfile $update, CurrentCompany $tenancy): RedirectResponse
    {
        $update->handle($request->user(), (string) $request->validated('name'), (string) $request->validated('email'), $tenancy->id());

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, DeleteOwnAccount $delete): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        // Refused (with a message on `password`) while they are a business's last owner (module 4.1).
        $delete->handle($request->user());

        // Not logout(): it would save the deleted user again to cycle its remember token.
        $guard = Auth::guard('web');
        $guard instanceof SessionGuard ? $guard->logoutCurrentDevice() : $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
