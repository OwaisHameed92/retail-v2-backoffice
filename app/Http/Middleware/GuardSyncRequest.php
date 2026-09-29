<?php

namespace App\Http\Middleware;

use App\Domain\Sync\Support\SyncApiErrors;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/v1/sync/*` after AuthenticateSyncKey (module 2.2, contract §3, §17.12):
 *
 * - config('sync.rate_limit_per_minute') requests per sync key (hello + push + pull); over it → 429 rate.limited
 *   with Retry-After. The default never slows a till syncing every 30 s or an initial upload sent back to back.
 * - the §3 identity headers the key check does not need are required: `X-SSPOS-App-Version`,
 *   `X-SSPOS-Store-Protocol`, `X-SSPOS-Company-Id`, `X-SSPOS-Register-Id` → else 400 request.invalid.
 */
class GuardSyncRequest
{
    public const REQUIRED = ['X-SSPOS-App-Version', 'X-SSPOS-Store-Protocol', 'X-SSPOS-Company-Id', 'X-SSPOS-Register-Id'];

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bucket = 'sync-api:key:'.AuthenticateSyncKey::caller($request)->syncKeyId;
        $max = max(12, (int) config('sync.rate_limit_per_minute', 240));

        if ($this->limiter->tooManyAttempts($bucket, $max)) {
            throw SyncApiErrors::rateLimited($this->limiter->availableIn($bucket));
        }

        $this->limiter->hit($bucket, 60);

        $missing = array_values(array_filter(self::REQUIRED, fn (string $header) => trim((string) $request->header($header)) === ''));

        if ($missing !== []) {
            throw SyncApiErrors::invalid('Missing header: '.implode(', ', $missing).'.');
        }

        return $next($request);
    }
}
