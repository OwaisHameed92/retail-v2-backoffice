<?php

namespace App\Http\Middleware;

use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal online store routes (docs/online-store.md) answer 404 while the online store is switched off on this
 * instance (`ONLINE_STORE` and the country flag `onlineStore`), as if they did not exist.
 */
class EnsureOnlineStoreOn
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(OnlineStoreSwitch::enabled(), 404);

        return $next($request);
    }
}
