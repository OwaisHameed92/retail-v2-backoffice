<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/v1/pharmacy/dmd/latest` (W4): the contract asks for `Cache-Control: no-cache` (or max-age ≤ 300) on the
 * manifest, so a till never keeps a stale week or an expired link. Listed before EnsureTillContract (which sets
 * `no-store, private` on every till reply) so this is the value the till sees, on errors too.
 */
class DmdManifestCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-cache, private');

        return $response;
    }
}
