<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Cache\RateLimiter;

/**
 * `licence/activate` wrong-key limit (contract v1.4.1 §17.15.1, §17.12): 5 wrong keys per install per 15 minutes,
 * then 429 `activation.too_many_attempts` with `retryAfterSeconds`. Counted on a hash of the install id.
 */
final class WrongKeyLimiter
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * @throws ApiException activation.too_many_attempts
     */
    public function ensureAllowed(string $installId): void
    {
        $bucket = self::bucket($installId);

        if ($this->limiter->tooManyAttempts($bucket, self::max())) {
            throw LicenceApiErrors::tooManyAttempts($this->limiter->availableIn($bucket));
        }
    }

    public function hit(string $installId): void
    {
        $this->limiter->hit(self::bucket($installId), max(60, (int) config('licence.api.rate_limits.wrong_keys_window_seconds', 900)));
    }

    private static function max(): int
    {
        return max(1, (int) config('licence.api.rate_limits.wrong_keys_per_install', 5));
    }

    private static function bucket(string $installId): string
    {
        return 'licence-api:wrong-key:'.hash('sha256', strtoupper($installId));
    }
}
