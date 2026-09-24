<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Data\AdminData;
use App\Domain\Admin\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the signed-in admin with every admin page as the "admin" prop.
 * Applied to the protected admin route group only (not in HandleInertiaRequests).
 */
class ShareAdminInertiaData
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('admin', function () {
            $admin = Auth::guard('admin')->user();

            return $admin instanceof Admin ? AdminData::forSession($admin) : null;
        });

        return $next($request);
    }
}
