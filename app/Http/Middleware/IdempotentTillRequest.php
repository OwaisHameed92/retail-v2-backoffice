<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` on the licence and device POSTs (contract v1.3.1 §17.11 rule 5). The reply to
 * (key, endpoint, caller install, body) is kept for config('licence.api.idempotency_hours'):
 *
 * - same key and body again → the same status and body (header `Idempotency-Replayed: true`);
 * - same key, different body → 422 request.idempotency_mismatch;
 * - the first request still running → 409 request.in_progress + Retry-After.
 *
 * Only final answers are kept (2xx and 4xx except 409 in_progress and 429). The body is fingerprinted with an
 * HMAC (it holds the licence key on activate); replies never contain a key. No header → no replay.
 */
class IdempotentTillRequest
{
    public const HEADER = 'Idempotency-Key';

    private const PATTERN = '/^([0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i';

    public function __construct(private readonly Cache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER));

        if ($key === '') {
            return $next($request);
        }

        if (preg_match(self::PATTERN, $key) !== 1) {
            throw LicenceApiErrors::invalid('The Idempotency-Key must be a ULID or a UUID.', self::HEADER);
        }

        $install = $request->header(EnsureTillContract::INSTALL_ID_HEADER) ?: $request->json('installId');
        $slot = 'licence-api:idem:'.hash('sha256', implode('|', [$request->route()?->getName(), strtoupper((string) $install), strtoupper($key)]));
        $fingerprint = hash_hmac('sha256', (string) $request->getContent(), (string) config('app.key'));

        $stored = $this->cache->get($slot);

        if (is_array($stored)) {
            return $this->replay($stored, $fingerprint);
        }

        if (! $this->cache->add($slot.':running', true, 60)) {
            throw LicenceApiErrors::inProgress();
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            $status = $response->getStatusCode();
            $final = $status < 500 && $status !== 429 && ! ($status === 409 && str_contains((string) $response->getContent(), '"request.in_progress"'));

            if ($final) {
                $this->cache->put($slot, [
                    'fingerprint' => $fingerprint,
                    'status' => $status,
                    'body' => (string) $response->getContent(),
                ], now()->addHours(max(24, (int) config('licence.api.idempotency_hours', 24))));
            }

            return $response;
        } finally {
            $this->cache->forget($slot.':running');
        }
    }

    /**
     * @param  array<mixed>  $stored
     */
    private function replay(array $stored, string $fingerprint): Response
    {
        if (! hash_equals((string) ($stored['fingerprint'] ?? ''), $fingerprint)) {
            throw LicenceApiErrors::idempotencyMismatch();
        }

        $response = new JsonResponse(null, (int) $stored['status']);
        $response->setJson((string) $stored['body']);
        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }
}
