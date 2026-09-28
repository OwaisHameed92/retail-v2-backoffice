<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Data\AdminData;
use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Queries\LeadNavCount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the signed-in admin with every admin page as the "admin" prop.
 * Applied to the protected admin route group only (not in HandleInertiaRequests).
 * `admin.navCounts.leads` (module 1.9): uncontacted leads, for admins with leads.manage only.
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

            return $admin->hasAbility(AdminRole::LEADS_MANAGE) ? [...$session, 'navCounts' => ['leads' => LeadNavCount::get()]] : $session;
        });

        return $next($request);
    }
}
