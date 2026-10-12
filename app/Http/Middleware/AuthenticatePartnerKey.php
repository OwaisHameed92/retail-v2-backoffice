<?php

namespace App\Http\Middleware;

use App\Domain\Partners\Actions\AuthenticatePartner;
use App\Domain\Partners\Models\PartnerKey;
use App\Domain\Partners\Support\PartnerApiErrors;
use App\Domain\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/partner/v1/*` (docs/partner-api.md): `Authorization: Bearer <partner key>` (AuthenticatePartner). The
 * per-key rate limit is the named limiter `partner-api` (`throttle:partner-api`, after this middleware).
 *
 * Failed key checks are throttled per IP and per presented key before any lookup (buckets hold SHA-256 hashes, never
 * the key). Controllers read the caller with `AuthenticatePartnerKey::key($request)`.
 */
class AuthenticatePartnerKey
{
    public const ATTRIBUTE = 'partners.key';

    public function __construct(
        private readonly AuthenticatePartner $authenticate,
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $failures = $this->failureBuckets($request->ip(), $bearer);

        foreach ($failures as [$bucket, $max]) {
            if ($this->limiter->tooManyAttempts($bucket, $max)) {
                throw PartnerApiErrors::rateLimited($this->limiter->availableIn($bucket));
            }
        }

        try {
            $key = $this->authenticate->handle($bearer);
        } catch (ApiException $e) {
            $window = max(60, (int) config('partners.failed_auth.window_seconds', 900));

            foreach ($failures as [$bucket]) {
                $this->limiter->hit($bucket, $window);
            }

            throw $e;
        }

        $request->attributes->set(self::ATTRIBUTE, $key);

        return $next($request);
    }

    public static function key(Request $request): PartnerKey
    {
        $key = $request->attributes->get(self::ATTRIBUTE);

        if (! $key instanceof PartnerKey) {
            throw new \LogicException('The route is not behind AuthenticatePartnerKey.');
        }

        return $key;
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function failureBuckets(?string $ip, #[SensitiveParameter] ?string $bearer): array
    {
        $buckets = [['partner-auth:ip:'.hash('sha256', (string) $ip), max(1, (int) config('partners.failed_auth.per_ip', 30))]];
        $bearer = trim((string) $bearer);

        if ($bearer !== '') {
            $buckets[] = ['partner-auth:key:'.hash('sha256', $bearer), max(1, (int) config('partners.failed_auth.per_key', 10))];
        }

        return $buckets;
    }
}
