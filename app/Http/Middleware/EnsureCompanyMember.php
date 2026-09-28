<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Models\Admin;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Tenancy\Actions\ResolveCurrentCompany;
use App\Domain\Tenancy\Actions\StopImpersonating;
use App\Domain\Tenancy\Actions\SwitchCurrentCompany;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Impersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `company`. Must run after `auth`. For every tenant request it:
 *
 * 1. Checks "login as customer": the admin who started it must still be signed in and allowed, else it ends.
 *    Account screens (profile/password) cannot be changed while impersonating.
 * 2. Resolves the current company (session choice, else first active membership; cancelled companies are
 *    skipped) and sets CurrentCompany. No usable membership → signed out with a message.
 * 3. Enforces company status: suspended → the "on hold" page (no tenant data; Billing stays open and the page
 *    links to it for users who can see billing, module 1.13); cancelled companies never
 *    resolve, so their users are signed out. Admins viewing as a customer skip the on-hold page.
 */
class EnsureCompanyMember
{
    public const NOT_LINKED_MESSAGE = 'Your account is not linked to a business';

    public const CANCELLED_MESSAGE = 'This Switch & Save account has been closed. Contact Switch & Save support if you think this is a mistake.';

    /** Routes a user of a suspended company may still use (billing: so the owner can set up Direct Debit). */
    private const ALLOWED_WHILE_SUSPENDED = ['app.company.switch', 'app.billing', 'app.billing.*'];

    public function __construct(
        private readonly ResolveCurrentCompany $resolver,
        private readonly CurrentCompany $currentCompany,
        private readonly StopImpersonating $stopImpersonating,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->guest(route('login'));
        }

        $session = $request->session();
        $impersonating = Impersonation::active($session);

        if ($impersonating) {
            $admin = Auth::guard('admin')->user();

            if (! Impersonation::isValid($session, $admin instanceof Admin ? $admin : null, $user)) {
                $this->stopImpersonating->handle($session, 'admin session ended');

                return redirect()->route('admin.login');
            }

            abort_if($request->routeIs(...Impersonation::BLOCKED_ROUTES), 403, 'Not available while viewing as a customer.');
        }

        $company = $this->resolver->handle($user, $session->get(SwitchCurrentCompany::SESSION_KEY));

        if ($company === null) {
            if ($impersonating) {
                $companyId = $this->stopImpersonating->handle($session, 'company closed');

                return redirect()->route('admin.tenants.show', $companyId)->with('error', 'That business is closed, so its portal is not available.');
            }

            $message = $this->resolver->hasCancelledMembership($user) ? self::CANCELLED_MESSAGE : self::NOT_LINKED_MESSAGE;

            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        $session->put(SwitchCurrentCompany::SESSION_KEY, $company->id);

        if ($company->isSuspended() && ! $impersonating && ! $request->routeIs(...self::ALLOWED_WHILE_SUSPENDED)) {
            return $this->onHold($request, $user, $company);
        }

        $this->currentCompany->set($company, $company->membership?->role);

        return $next($request);
    }

    /**
     * A friendly holding page with no tenant data. Other businesses the user belongs to can still be chosen.
     */
    private function onHold(Request $request, User $user, Company $company): Response
    {
        if (! $request->isMethod('GET')) {
            return redirect()->route('app.dashboard');
        }

        $others = $this->resolver->activeCompanies($user)
            ->reject(fn (Company $other) => $other->is($company) || $other->isSuspended())
            ->map(fn (Company $other) => ['id' => $other->id, 'name' => $other->name])
            ->values()
            ->all();

        $canBilling = $company->membership?->role->can(Ability::BillingView) ?? false;
        $account = $canBilling ? app(BillingAccounts::class)->for($company) : null;

        return Inertia::render('app/account-on-hold', [
            'companyName' => $company->name,
            'otherCompanies' => $others,
            // Module 1.13: suspended for want of a Direct Debit → straight to the Billing page to set it up.
            'directDebitUrl' => $account !== null && $account->isDirectDebit() && ! $account->hasUsableMandate() ? route('app.billing') : null,
            'billingUrl' => $canBilling ? route('app.billing') : null,
        ])->toResponse($request);
    }
}
