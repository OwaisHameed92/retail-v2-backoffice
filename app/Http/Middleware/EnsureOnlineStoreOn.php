<?php

namespace App\Http\Middleware;

use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal online store routes (docs/online-store.md) answer 404 while the online store is switched off on this
 * instance (`ONLINE_STORE` and the country flag `onlineStore`), as if they did not exist. OS-6: also while the
 * current business cannot use its store (add-on off, or no till with cloud sync: OnlineStoreSwitch::availableFor).
 */
class EnsureOnlineStoreOn
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = app(CurrentCompany::class)->get();

        abort_unless($company !== null && OnlineStoreSwitch::availableFor($company), 404);

        return $next($request);
    }
}
