<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Security\Actions\DisableTwoFactor;
use App\Domain\Security\Actions\RegenerateRecoveryCodes;
use App\Domain\Security\Actions\SetCompanyTwoFactorRequirement;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\PasswordCheckRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Security: the user's own two-factor sign-in, and (owner) whether the business requires it.
 */
class SecurityController extends Controller
{
    public function edit(Request $request, CurrentCompany $tenancy): Response
    {
        $user = $this->user($request);
        $company = $tenancy->require();

        return Inertia::render('settings/security', [
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'confirmedAt' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recoveryCodesLeft' => $user->remainingRecoveryCodes(),
            ],
            'company' => [
                'name' => $company->name,
                'requireTwoFactor' => $company->require_two_factor,
                'canManage' => $tenancy->can(Ability::BusinessManage),
            ],
        ]);
    }

    public function disable(PasswordCheckRequest $request, DisableTwoFactor $disable, CurrentCompany $tenancy): RedirectResponse
    {
        $disable->handle($request->session(), $this->user($request), $tenancy->get());

        return back()->with('success', 'Two-factor sign-in is off.');
    }

    /** JSON: the new codes exist only in this reply. */
    public function recoveryCodes(PasswordCheckRequest $request, RegenerateRecoveryCodes $regenerate, CurrentCompany $tenancy): JsonResponse
    {
        $codes = $regenerate->handle(TwoFactorArea::Web, $this->user($request), $tenancy->id());

        return response()->json(['recoveryCodes' => $codes])->header('Cache-Control', 'no-store');
    }

    public function company(Request $request, SetCompanyTwoFactorRequirement $set, CurrentCompany $tenancy): RedirectResponse
    {
        $validated = $request->validate(['requireTwoFactor' => ['required', 'boolean']]);
        $required = (bool) $validated['requireTwoFactor'];

        $set->handle($tenancy->require(), $required, $this->user($request));

        return back()->with('success', $required
            ? 'Two-factor sign-in is now required. People without it set it up at their next request.'
            : 'Two-factor sign-in is now optional.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
