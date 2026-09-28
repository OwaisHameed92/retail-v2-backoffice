<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for the public form API (module 1.10). Browser origins listed in `sspos.public_form_origins`
 * (`PUBLIC_FORM_ORIGINS`) and our own origin (the hosted /trial page) are allowed; any other browser origin gets
 * 403 `cors.origin_not_allowed`, preflight included. Requests without an Origin (servers, curl) pass.
 */
class PublicFormCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null && ! $this->allowed($origin, $request)) {
            throw new ApiException('cors.origin_not_allowed', 'This website is not allowed to send trial requests. Please use the form at switchandsave.co.uk.', 403);
        }

        $response = $request->isMethod('OPTIONS') ? response()->noContent() : $next($request);

        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, X-Requested-With');
            $response->headers->set('Access-Control-Expose-Headers', 'X-Trace-Id, Retry-After');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        $response->headers->set('Vary', 'Origin', false);

        return $response;
    }

    private function allowed(string $origin, Request $request): bool
    {
        $origin = rtrim($origin, '/');

        return $origin === $request->getSchemeAndHttpHost()
            || in_array($origin, (array) config('sspos.public_form_origins', []), true);
    }
}
