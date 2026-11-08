<?php

namespace App\Http\Middleware;

use App\Domain\Workshop\Support\WorkshopSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W5: every BikeBook connector route (`/connectors/bikebook/*`, `/oauth/*`) and the admin / tenant workshop routes
 * answer 404 while WORKSHOP_CONNECTOR is off, exactly as a route that does not exist (DECISIONS "BikeBook connector").
 */
class EnsureWorkshopConnector
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! WorkshopSwitch::enabled()) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
