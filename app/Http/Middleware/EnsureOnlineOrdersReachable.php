<?php

namespace App\Http\Middleware;

use App\Domain\OnlineStore\Support\OnlineOrdersAccess;
use App\Domain\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal's Online orders pages (audit OSA-5): 404 unless the business can use its store, or still has open
 * orders to finish after the store went off (OnlineOrdersAccess), so no order is stranded.
 */
class EnsureOnlineOrdersReachable
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = app(CurrentCompany::class)->get();

        abort_unless($company !== null && OnlineOrdersAccess::reachable($company), 404);

        return $next($request);
    }
}
