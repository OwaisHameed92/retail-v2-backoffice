<?php

namespace App\Http\Middleware;

use App\Http\Support\Store\StorePages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Audit OSA-8: with `online_store.host` (ONLINE_STORE_HOST) set, the store's host answers only the public store
 * (routes named `store.*`, routes/store.php), the store's own DNA card callback (`webhooks.dna`: StartCardPayment
 * builds it on the host the customer is on, and it is checked by its HMAC signature) and the health check `/up`.
 * Everything else there (portal, admin, sign-in, APIs, GoCardless, Direct Debit links, unknown addresses) is the
 * store's 404 page (`api/*`: the API's error body; other JSON callers: a plain JSON 404), so the admin sign-in never sits on the shop's domain.
 * Global middleware (bootstrap/app.php): it runs before routing, so it matches the route itself. Without a store host,
 * or on any other host, it does nothing.
 */
class KeepStoreHostToStore
{
    /** Route names served on the store host besides `store.*`. */
    private const ALSO_ALLOWED = ['webhooks.dna'];

    public function handle(Request $request, Closure $next): Response
    {
        $host = config('online_store.host');

        if (! is_string($host) || $host === '' || strcasecmp($request->getHost(), $host) !== 0 || $request->is('up') || self::storeRoute($request)) {
            return $next($request);
        }

        // api/*: a 404 in the API's own error body (ApiExceptionRenderer; on a till endpoint the contract's 400
        // request.invalid). Other JSON callers: a plain JSON 404.
        abort_if($request->is('api', 'api/*'), 404);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return StorePages::error($request, 404);
    }

    private static function storeRoute(Request $request): bool
    {
        try {
            /** @var Route $route */
            $route = app('router')->getRoutes()->match($request);
        } catch (HttpExceptionInterface) {
            return false;
        }

        $name = (string) $route->getName();

        return str_starts_with($name, 'store.') || in_array($name, self::ALSO_ALLOWED, true);
    }
}
