<?php

namespace App\Http\Middleware;

use App\Domain\Partners\Models\PartnerKey;
use App\Domain\Shared\Actions\RecordAudit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Every partner API call's outcome goes to the audit log (`partner_api.call`, or `partner_api.refused` without a
 * valid key): endpoint, externalId, HTTP status and error code, and the key's last 4 characters. Never the key, the
 * Authorization header or the body. Outermost on the partner routes, so refused keys and rate limits are recorded too.
 */
class AuditPartnerCall
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->record($request, $response);
        } catch (Throwable $e) {
            report($e); // an audit hiccup never changes the partner's reply
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $key = $request->attributes->get(AuthenticatePartnerKey::ATTRIBUTE);
        $key = $key instanceof PartnerKey ? $key : null;
        $code = $response instanceof JsonResponse && $response->getStatusCode() >= 400 ? ($response->getData(true)['code'] ?? null) : null;
        $externalId = $request->route('externalId');

        $this->audit->handle($key !== null ? 'partner_api.call' : 'partner_api.refused', $key?->partner, null, null, array_filter([
            'partner' => $key?->partner?->name,
            'key_ending' => $key?->key_last4,
            'method' => $request->method(),
            'endpoint' => $request->route()?->getName(),
            'external_id' => is_string($externalId) ? mb_substr($externalId, 0, 64) : null,
            'status' => $response->getStatusCode(),
            'code' => is_string($code) ? $code : null,
        ], fn ($value) => $value !== null));
    }
}
