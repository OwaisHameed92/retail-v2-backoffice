<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Cache\RateLimiter;

/**
 * `licence/activate` wrong-key limit (contract v1.4.1 §17.15.1, §17.12): 5 wrong keys per install per 15 minutes,
 * then 429 `activation.too_many_attempts` with `retryAfterSeconds`. The install id is the caller's choice, so the
 * caller's IP has a ceiling too (`wrong_keys_per_ip`, security review L1). Counted on hashes only.
 */
final class WrongKeyLimiter
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * @throws ApiException activation.too_many_attempts
     */
    public function ensureAllowed(TillRequest $till): void
    {
        foreach (self::buckets($till) as [$bucket, $max]) {
            if ($this->limiter->tooManyAttempts($bucket, $max)) {
                throw LicenceApiErrors::tooManyAttempts($this->limiter->availableIn($bucket));
            }
        }
    }

    public function hit(TillRequest $till): void
    {
        foreach (self::buckets($till) as [$bucket]) {
            $this->limiter->hit($bucket, max(60, (int) config('licence.api.rate_limits.wrong_keys_window_seconds', 900)));
        }
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private static function buckets(TillRequest $till): array
    {
        $buckets = [['licence-api:wrong-key:'.hash('sha256', strtoupper($till->installId)), max(1, (int) config('licence.api.rate_limits.wrong_keys_per_install', 5))]];

        if ($till->ip !== null && $till->ip !== '') {
            $buckets[] = ['licence-api:wrong-key-ip:'.hash('sha256', $till->ip), max(1, (int) config('licence.api.rate_limits.wrong_keys_per_ip', 20))];
        }

        return $buckets;
    }
}
