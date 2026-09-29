<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Exceptions\ApiExceptionRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract v1.4.1 §17.11 for the licence, device and sync endpoints:
 *
 * - `X-SSPOS-Contract: 1` required (anything else → 409 contract.unsupported with supportedContracts), echoed
 *   on every reply with the HTTP `Date` header and `Cache-Control: no-store`.
 * - Never 426 here (contract v1.4.1 ANSWERS-2026-09-29 §3): a till that cannot validate its licence locks after the
 *   offline grace just because it was not updated. `minimumAppVersion` travels in the validate reply instead
 *   (informational); sync/* alone may answer 426 for a version known to damage data (GuardSyncRequest).
 * - No query string on a POST: a key, code or token never travels in a URL (§17.11 rule 12) → 400 request.invalid.
 *   GETs may page with one (sync/pull `since`/`max`, module 2.5).
 */
class EnsureTillContract
{
    public const CONTRACT_HEADER = 'X-SSPOS-Contract';

    public const APP_VERSION_HEADER = 'X-SSPOS-App-Version';

    public const INSTALL_ID_HEADER = 'X-SSPOS-Install-Id';

    public const CONTRACT = '1';

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->check($request);
            /** @var Response $response */
            $response = $next($request);
        } catch (ApiException $e) {
            $response = ApiExceptionRenderer::render($e, $request);
        }

        $response->headers->set(self::CONTRACT_HEADER, self::CONTRACT);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Date', gmdate('D, d M Y H:i:s').' GMT');

        return $response;
    }

    /**
     * @throws ApiException
     */
    private function check(Request $request): void
    {
        if (trim((string) $request->header(self::CONTRACT_HEADER)) !== self::CONTRACT) {
            throw LicenceApiErrors::contractUnsupported();
        }

        if (! $request->isMethod('GET') && $request->getQueryString() !== null && $request->getQueryString() !== '') {
            throw LicenceApiErrors::invalid('Keys, codes and tokens must be sent in the request body, never in the address.');
        }
    }
}
