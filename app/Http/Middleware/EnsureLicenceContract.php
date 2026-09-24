<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Licence API (module 1.5): the till must say which contract it speaks (`X-SSPOS-Licence-Contract: 1`), else
 * 409 contract.unsupported. Also refuses any query string on the licence endpoints: the key is a secret and
 * must never travel in a URL (URLs end up in access logs and proxies).
 */
class EnsureLicenceContract
{
    public const HEADER = 'X-SSPOS-Licence-Contract';

    public const APP_VERSION_HEADER = 'X-SSPOS-App-Version';

    public const VERSION = '1';

    public function handle(Request $request, Closure $next): Response
    {
        if (trim((string) $request->header(self::HEADER)) !== self::VERSION) {
            throw LicenceApiErrors::contractUnsupported();
        }

        if ($request->getQueryString() !== null && $request->getQueryString() !== '') {
            throw LicenceApiErrors::keyInAddress();
        }

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, self::VERSION);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
