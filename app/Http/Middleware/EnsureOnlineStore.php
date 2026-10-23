<?php

namespace App\Http\Middleware;

use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal's online store pages (OS-3, OS-4): 404 while the online store is not available on this instance (the
 * `ONLINE_STORE` switch and the country flag `onlineStore`, OnlineStoreSwitch).
 */
class EnsureOnlineStore
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(OnlineStoreSwitch::enabled(), 404);

        return $next($request);
    }
}
