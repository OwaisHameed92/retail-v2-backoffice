<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Data\AdminData;
use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Queries\LeadNavCount;
use App\Domain\Partners\Queries\PartnerRequestNavCount;
use App\Domain\Partners\Support\PartnerApi;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the signed-in admin with every admin page as the "admin" prop.
 * Applied to the protected admin route group only (not in HandleInertiaRequests).
 * `admin.navCounts.leads` (module 1.9): uncontacted leads, for admins with leads.manage only; `partnerRequests`: partner
 * requests waiting for review (tenants.manage, when any).
 */
class ShareAdminInertiaData
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('admin', function () {
            $admin = Auth::guard('admin')->user();

            if (! $admin instanceof Admin) {
                return null;
            }

            $session = AdminData::forSession($admin);
            $counts = $admin->hasAbility(AdminRole::LEADS_MANAGE) ? ['leads' => LeadNavCount::get()] : [];

            // Partner onboarding API: requests waiting for review, for tenants.manage (only when there are some).
            $partnerApi = PartnerApi::enabled();

            if ($partnerApi && $admin->hasAbility(AdminRole::TENANTS_MANAGE) && ($pending = PartnerRequestNavCount::get()) > 0) {
                $counts['partnerRequests'] = $pending;
            }

            // The partner pages exist only where the partner API is on (UK): the nav hides them elsewhere.
            $session['partnerApi'] = $partnerApi;

            return $counts === [] ? $session : [...$session, 'navCounts' => $counts];
        });

        return $next($request);
    }
}
