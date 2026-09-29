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
 * `X-SSPOS-Install-Id` or the body, else the IP). Over a limit → 429 `rate.limited` with `Retry-After`.
 * The wrong-key limit of `licence/activate` is separate (WrongKeyLimiter). Buckets hold hashes only.
 */
class ThrottleLicenceApi
{
    public const HOUR = 3600;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        [$bucket, $max] = $this->limitFor($request);

        if ($this->limiter->tooManyAttempts($bucket, $max)) {
            throw LicenceApiErrors::rateLimited($this->limiter->availableIn($bucket));
        }

        $this->limiter->hit($bucket, self::HOUR);

        return $next($request);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function limitFor(Request $request): array
    {
        $route = (string) $request->route()?->getName();
        $limits = (array) config('licence.api.rate_limits', []);
        $install = $request->header(EnsureTillContract::INSTALL_ID_HEADER) ?: $request->json('installId');
        $caller = is_string($install) && $install !== '' ? 'install:'.strtoupper($install) : 'ip:'.$request->ip();

        return match ($route) {
            'api.licence.activate' => ['licence-api:activate:'.hash('sha256', 'ip:'.$request->ip()), (int) ($limits['activate_per_ip_per_hour'] ?? 10)],
            'api.licence.validate' => ['licence-api:validate:'.hash('sha256', $caller), (int) ($limits['validate_per_install_per_hour'] ?? 60)],
            // Module 2.8: migrate shares activate's 10 an hour per IP; redeem 10 an hour per branch (linked till) or install.
            'api.cloud.migrate' => ['licence-api:migrate:'.hash('sha256', 'ip:'.$request->ip()), (int) ($limits['activate_per_ip_per_hour'] ?? 10)],
            'api.licence.redeem' => ['licence-api:redeem:'.hash('sha256', $request->bearerToken() !== null ? 'branch:'.strtoupper((string) $request->header('X-SSPOS-Branch-Id')) : $caller), (int) ($limits['redeem_per_hour'] ?? 10)],
            default => ['licence-api:deactivate:'.hash('sha256', $caller), (int) ($limits['deactivate_per_install_per_hour'] ?? 20)],
        };
    }
}
