<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Licence and device endpoint rate limits (contract v1.4.1 §17.12): `licence/activate` and `cloud/migrate` 10 an hour
 * per IP, `licence/validate` 60 an hour per install, `licence/redeem` 10 an hour per branch (branch key) or per install
 * (a local key report), `devices/deactivate` 20 an hour per install (the install id from
 * `X-SSPOS-Install-Id` or the body, else the IP). Those three also share a per-IP ceiling (`per_ip_per_hour`), and
 * deactivate has its own per-IP limit (security review L1, H2). Over a limit → 429 `rate.limited` with `Retry-After`.
 * The wrong-key limit of `licence/activate` is separate (WrongKeyLimiter). Buckets hold hashes only.
 */
class ThrottleLicenceApi
{
    public const HOUR = 3600;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $buckets = $this->limitsFor($request);

        foreach ($buckets as [$bucket, $max]) {
            if ($this->limiter->tooManyAttempts($bucket, $max)) {
                throw LicenceApiErrors::rateLimited($this->limiter->availableIn($bucket));
            }
        }

        foreach ($buckets as [$bucket]) {
            $this->limiter->hit($bucket, self::HOUR);
        }

        return $next($request);
    }

    /**
     * The route's own bucket, plus (security review L1) a per-IP ceiling where the route's bucket is keyed on the
     * caller-chosen install id: validate, redeem and deactivate.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function limitsFor(Request $request): array
    {
        $route = (string) $request->route()?->getName();
        $limits = (array) config('licence.api.rate_limits', []);
        $install = $request->header(EnsureTillContract::INSTALL_ID_HEADER) ?: $request->json('installId');
        $caller = is_string($install) && $install !== '' ? 'install:'.strtoupper($install) : 'ip:'.$request->ip();
        $ip = hash('sha256', 'ip:'.$request->ip());

        return match ($route) {
            'api.licence.activate' => [['licence-api:activate:'.$ip, (int) ($limits['activate_per_ip_per_hour'] ?? 10)]],
            'api.licence.validate' => [
                ['licence-api:validate:'.hash('sha256', $caller), (int) ($limits['validate_per_install_per_hour'] ?? 60)],
                ['licence-api:ip:'.$ip, (int) ($limits['per_ip_per_hour'] ?? 600)],
            ],
            // Module 2.8: migrate shares activate's 10 an hour per IP; redeem 10 an hour per branch (linked till) or install.
            'api.cloud.migrate' => [['licence-api:migrate:'.$ip, (int) ($limits['activate_per_ip_per_hour'] ?? 10)]],
            'api.licence.redeem' => [
                ['licence-api:redeem:'.hash('sha256', $request->bearerToken() !== null ? 'branch:'.strtoupper((string) $request->header('X-SSPOS-Branch-Id')) : $caller), (int) ($limits['redeem_per_hour'] ?? 10)],
                ['licence-api:ip:'.$ip, (int) ($limits['per_ip_per_hour'] ?? 600)],
            ],
            default => [
                ['licence-api:deactivate:'.hash('sha256', $caller), (int) ($limits['deactivate_per_install_per_hour'] ?? 20)],
                ['licence-api:deactivate-ip:'.$ip, (int) ($limits['deactivate_per_ip_per_hour'] ?? 30)],
                ['licence-api:ip:'.$ip, (int) ($limits['per_ip_per_hour'] ?? 600)],
            ],
        };
    }
}
