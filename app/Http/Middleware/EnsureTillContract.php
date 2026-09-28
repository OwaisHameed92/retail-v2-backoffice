<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceReply;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Exceptions\ApiExceptionRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract v1.3.1 §17.11 for the licence and device endpoints:
 *
 * - `X-SSPOS-Contract: 1` required (anything else → 409 contract.unsupported with supportedContracts), echoed
 *   on every reply with the HTTP `Date` header and `Cache-Control: no-store`.
 * - `X-SSPOS-App-Version` (else the body's appVersion) below config('licence.api.minimum_app_version') →
 *   426 app.update_required.
 * - No query string: a key, code or token never travels in a URL (§17.11 rule 12) → 400 request.invalid.
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

        if ($request->getQueryString() !== null && $request->getQueryString() !== '') {
            throw LicenceApiErrors::invalid('Keys, codes and tokens must be sent in the request body, never in the address.');
        }

        $minimum = LicenceReply::minimumAppVersion();
        $version = trim((string) ($request->header(self::APP_VERSION_HEADER) ?: $request->json('appVersion')));

        if ($minimum !== null && $version !== '' && version_compare(self::numeric($version), self::numeric($minimum), '<')) {
            throw LicenceApiErrors::updateRequired($minimum);
        }
    }

    /** "3.0.412-beta+5" → "3.0.412" (pre-release and build parts ignored). */
    private static function numeric(string $version): string
    {
        return (string) preg_replace('/[-+].*$/', '', $version);
    }
}
