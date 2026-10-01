<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Cache\RateLimiter;
use SensitiveParameter;

/**
 * Security review M6: failed sync-key checks (`sync/*`, `cloud/migrate/complete`) per IP and per presented key,
 * checked before the key lookup. Over `sync.failed_auth.per_ip` or `per_key` failures in the window → 429
 * rate.limited with Retry-After until the window passes (a lockout), so bad Bearers cannot load the database.
 * Buckets hold SHA-256 hashes only, never the key.
 */
final class SyncAuthLimiter
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * @throws ApiException rate.limited
     */
    public function ensureAllowed(?string $ip, #[SensitiveParameter] ?string $bearer): void
    {
        foreach (self::buckets($ip, $bearer) as [$bucket, $max]) {
            if ($this->limiter->tooManyAttempts($bucket, $max)) {
                throw SyncApiErrors::rateLimited($this->limiter->availableIn($bucket));
            }
        }
    }

    public function failed(?string $ip, #[SensitiveParameter] ?string $bearer): void
    {
        $window = max(60, (int) config('sync.failed_auth.window_seconds', 900));

        foreach (self::buckets($ip, $bearer) as [$bucket]) {
            $this->limiter->hit($bucket, $window);
        }
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private static function buckets(?string $ip, #[SensitiveParameter] ?string $bearer): array
    {
        $buckets = [['sync-auth:ip:'.hash('sha256', (string) $ip), max(1, (int) config('sync.failed_auth.per_ip', 30))]];
        $bearer = trim((string) $bearer);

        if ($bearer !== '') {
            $buckets[] = ['sync-auth:key:'.hash('sha256', SyncKeySecret::canonical($bearer)), max(1, (int) config('sync.failed_auth.per_key', 10))];
        }

        return $buckets;
    }
}
