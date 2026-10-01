<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Security\Enums\TwoFactorArea;
use App\Http\Controllers\Concerns\HandlesTwoFactorSignIn;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Admin two-factor sign-in: required for every admin. After the password an admin without it is sent to set it up
 * (RequireTwoFactor); with it, to the code page.
 */
class TwoFactorController extends Controller
{
    use HandlesTwoFactorSignIn;

    protected function area(): TwoFactorArea
    {
        return TwoFactorArea::Admin;
    }

    protected function auditCompanyId(Request $request): ?string
    {
        return null;
    }

    protected function setupContext(Request $request): array
    {
        return ['required' => true, 'requiredBy' => null];
    }
}
