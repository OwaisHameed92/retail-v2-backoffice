<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Models\Admin;
use App\Domain\Security\Actions\RegenerateRecoveryCodes;
use App\Domain\Security\Actions\ResetAdminTwoFactor;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\PasswordCheckRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in admin's own sign-in security (two-factor status, new recovery codes), and an owner resetting
 * another admin's two-factor.
 */
class AccountSecurityController extends Controller
{
    public function show(Request $request): Response
    {
        $admin = $this->admin($request);

        return Inertia::render('admin/security', [
            'twoFactor' => [
                'enabled' => $admin->hasTwoFactorEnabled(),
                'confirmedAt' => $admin->two_factor_confirmed_at?->toIso8601String(),
                'recoveryCodesLeft' => $admin->remainingRecoveryCodes(),
            ],
        ]);
    }

    /** JSON: the new codes exist only in this reply. */
    public function recoveryCodes(PasswordCheckRequest $request, RegenerateRecoveryCodes $regenerate): JsonResponse
    {
        $codes = $regenerate->handle(TwoFactorArea::Admin, $this->admin($request));

        return response()->json(['recoveryCodes' => $codes])->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request, Admin $admin, ResetAdminTwoFactor $reset): RedirectResponse
    {
        $reset->handle($admin, $this->admin($request));

        return back()->with('success', "Two-factor sign-in reset for {$admin->name}. They set it up again at their next sign-in.");
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
