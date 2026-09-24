<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an admin is logged in as a customer, the admin area is off limits: they must use
 * "Return to admin" (admin.impersonation.stop) first. Keeps the two identities from mixing in one tab.
 */
class BlockAdminWhileImpersonating
{
    public const MESSAGE = 'You are viewing the portal as a customer. Return to admin first.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && Impersonation::active($request->session())) {
            return redirect()->route('app.dashboard')->with('error', self::MESSAGE);
        }

        return $next($request);
    }
}
