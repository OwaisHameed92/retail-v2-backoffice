<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;

/**
 * The `throttle:N,M` middleware with one bucket per signed-in user AND route (UI audit 2026-10-11, UI-1).
 *
 * The framework keys an unnamed `throttle:N,M` by the user id only, so every throttled portal and admin route
 * shared one counter per user: ten "Add product" views or a polling import page used up the sales export's
 * `throttle:10,1`. Here a signed-in user's key also carries the user class (an admin and a customer can share an
 * id) and the route name (method + URI for an unnamed route), so each route keeps its own limit.
 *
 * Unchanged on purpose: guests are still keyed by domain + IP exactly like the framework (the password reset,
 * sign-in challenge and webhook throttles), and named limiters (`throttle:name`) never reach this signature.
 * Registered as the `throttle` alias in bootstrap/app.php; as a subclass it keeps ThrottleRequests' priority.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    /**
     * @param  Request  $request
     */
    protected function resolveRequestSignature($request): string
    {
        $user = $request->user();
        $route = $request->route();

        if ($user === null || ! $route instanceof Route) {
            return parent::resolveRequestSignature($request);
        }

        $routeKey = $route->getName() ?? implode('|', $route->methods()).' '.$route->uri();

        $identifier = get_class($user).'|'.$user->getAuthIdentifier().'|'.$routeKey;

        return self::$shouldHashKeys ? sha1($identifier) : $identifier;
    }
}
