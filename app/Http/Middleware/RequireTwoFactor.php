<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Models\Admin;
use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Security\Support\RememberedDevice;
use App\Domain\Security\Support\TwoFactorSession;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `two-factor:<guard>`. The second sign-in step, after the password (or a "keep me signed in" cookie):
 *
 * - account has two-factor on → this session must have passed the code (or the device is remembered), else the
 *   code page;
 * - no two-factor yet but required (every admin; a portal user whose company requires it) → the set-up page;
 * - "login as customer" never skips anything: the portal is open only while the admin's own session has passed
 *   the admin's two-factor (the customer's own second factor is not asked of the admin).
 *
 * For `web` it must run after `company` (the requirement belongs to the current company).
 */
class RequireTwoFactor
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        if (! config('security.two_factor.enabled')) {
            return $next($request);
        }

        $area = TwoFactorArea::from($guard);
        $user = Auth::guard($guard)->user();

        if (! $user instanceof TwoFactorUser) {
            return $next($request);
        }

        $session = $request->session();

        if ($area === TwoFactorArea::Web && Impersonation::active($session)) {
            $admin = Auth::guard('admin')->user();

            return $admin instanceof Admin && TwoFactorSession::passed($session, TwoFactorArea::Admin, $admin)
                ? $next($request)
                : $this->send($request, 'admin.two-factor.challenge');
        }

        if (TwoFactorSession::passed($session, $area, $user)) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled()) {
            if (RememberedDevice::valid($request, $area, $user)) {
                TwoFactorSession::markPassed($session, $area, $user);

                return $next($request);
            }

            return $this->send($request, $area->challengeRoute());
        }

        if ($area === TwoFactorArea::Admin || $this->currentCompany->get()?->require_two_factor === true) {
            return $this->send($request, $area->setupRoute());
        }

        return $next($request);
    }

    private function send(Request $request, string $route): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Finish two-factor sign-in first.'], 403);
        }

        return $request->isMethod('GET') ? redirect()->guest(route($route)) : redirect()->route($route);
    }
}
