<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Security\Actions\BeginTwoFactorSetup;
use App\Domain\Security\Actions\ConfirmTwoFactorSetup;
use App\Domain\Security\Actions\VerifyTwoFactorChallenge;
use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Security\Support\RememberedDevice;
use App\Domain\Security\Support\TwoFactorSession;
use App\Domain\Tenancy\Support\Impersonation;
use App\Http\Requests\Security\TwoFactorCodeRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The code page and the set-up page, shared by the admin and portal sign-in (thin: everything is in the Actions).
 * Pages: `auth/two-factor-challenge` and `auth/two-factor-setup`.
 */
trait HandlesTwoFactorSignIn
{
    abstract protected function area(): TwoFactorArea;

    /** The business the audit entries belong to (portal users), or null (admins). */
    abstract protected function auditCompanyId(Request $request): ?string;

    /** @return array<string, mixed> Extra props for the set-up page. */
    abstract protected function setupContext(Request $request): array;

    public function challenge(Request $request): Response|RedirectResponse
    {
        $user = $this->twoFactorUser();

        if (! $user->hasTwoFactorEnabled() || TwoFactorSession::passed($request->session(), $this->area(), $user)) {
            return redirect()->intended(route($this->area()->homeRoute()));
        }

        return Inertia::render('auth/two-factor-challenge', [
            'staff' => $this->area() === TwoFactorArea::Admin,
            'email' => $user->twoFactorAccountName(),
            'verifyUrl' => route($this->area()->challengeRoute()),
            'logoutUrl' => route($this->area() === TwoFactorArea::Admin ? 'admin.logout' : 'logout'),
            'rememberDays' => RememberedDevice::DAYS,
        ]);
    }

    public function verify(TwoFactorCodeRequest $request, VerifyTwoFactorChallenge $verify): RedirectResponse
    {
        $user = $this->twoFactorUser();

        $verify->handle($request->session(), $this->area(), $user, $request->code(), $this->auditCompanyId($request));

        $response = redirect()->intended(route($this->area()->homeRoute()));

        return $request->boolean('remember') ? $response->withCookie(RememberedDevice::issue($this->area(), $user)) : $response;
    }

    public function setup(Request $request, BeginTwoFactorSetup $begin): Response|RedirectResponse
    {
        abort_if(Impersonation::active($request->session()), 403, 'Not available while viewing as a customer.');

        $user = $this->twoFactorUser();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route($this->area()->homeRoute());
        }

        return Inertia::render('auth/two-factor-setup', [
            'staff' => $this->area() === TwoFactorArea::Admin,
            'email' => $user->twoFactorAccountName(),
            'setup' => $begin->handle($request->session(), $this->area(), $user),
            'confirmUrl' => route($this->area()->setupRoute()),
            'continueUrl' => $this->continueUrl($request),
            'logoutUrl' => route($this->area() === TwoFactorArea::Admin ? 'admin.logout' : 'logout'),
            ...$this->setupContext($request),
        ]);
    }

    /** JSON: the recovery codes exist only in this reply. */
    public function confirm(TwoFactorCodeRequest $request, ConfirmTwoFactorSetup $confirm): JsonResponse
    {
        abort_if(Impersonation::active($request->session()), 403, 'Not available while viewing as a customer.');

        $codes = $confirm->handle($request->session(), $this->area(), $this->twoFactorUser(), $request->code(), $this->auditCompanyId($request));
        $request->session()->migrate(true);
        // The page already holds where "Continue" goes; a stale intended URL must not hijack a later sign-in.
        $request->session()->forget('url.intended');

        return response()->json(['recoveryCodes' => $codes])->header('Cache-Control', 'no-store');
    }

    /** Where "Continue" goes after the recovery codes: the page that sent the person here (same site only). */
    protected function continueUrl(Request $request): string
    {
        $home = route($this->area()->homeRoute());
        $intended = $request->session()->get('url.intended');

        return is_string($intended) && str_starts_with($intended, url('/').'/') ? $intended : $home;
    }

    protected function twoFactorUser(): TwoFactorUser&Model
    {
        $user = Auth::guard($this->area()->value)->user();

        abort_unless($user instanceof TwoFactorUser, 403);

        return $user;
    }
}
