<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Exceptions\ApiExceptionRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for the public form API (module 1.10). Browser origins listed in `sspos.public_form_origins`
 * (`PUBLIC_FORM_ORIGINS`) and our own origin (the hosted /trial page) are allowed. Requests without an Origin
 * (servers, curl) pass.
 *
 * Any other browser origin is refused: its POST gets 403 `cors.origin_not_allowed` and nothing is read or stored.
 * So that the page can show that message instead of a network error, the refusal is readable by the browser: the
 * preflight is answered (204) and the 403 echoes the origin in `Access-Control-Allow-Origin`, never with
 * `Access-Control-Allow-Credentials` (no cookies are sent or read). CORS is not what protects this endpoint (a server
 * can post without an Origin); the server-side refusal is. Every other error reply of the form API (400, 422, 429)
 * carries the same headers, so the browser can read it too.
 */
class PublicFormCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null && ! $this->allowed($origin, $request)) {
            $response = $request->isMethod('OPTIONS')
                ? response()->noContent()
                : ApiExceptionRenderer::render(new ApiException('cors.origin_not_allowed', 'This website is not allowed to send trial requests yet. Please use the form at switchandsave.co.uk.', 403), $request);

            return self::withHeaders($response, $origin);
        }

        $response = $request->isMethod('OPTIONS') ? response()->noContent() : $next($request);

        return $origin === null ? self::vary($response) : self::withHeaders($response, $origin);
    }

    /** The CORS reply headers for a browser origin (no credentials). */
    public static function withHeaders(Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, X-Requested-With');
        $response->headers->set('Access-Control-Expose-Headers', 'X-Trace-Id, Retry-After');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->remove('Access-Control-Allow-Credentials');

        return self::vary($response);
    }

    private static function vary(Response $response): Response
    {
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
