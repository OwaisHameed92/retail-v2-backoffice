<?php

namespace App\Http\Middleware;

use App\Domain\Pharmacy\Support\DmdErrors;
use App\Domain\Pharmacy\Support\DmdPackageLink;
use App\Domain\Pharmacy\Support\DmdSwitch;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dm+d release server's routes (W4):
 *
 * - `GuardDmdServer:manifest`, after the sync key check (AuthenticateSyncKey, GuardSyncRequest): PHARMACY_DMD off
 *   (the default) → 501 dmd.not_available with Retry-After (an unknown key is still 401 first); then its own limit,
 *   `pharmacy.dmd.per_minute` calls per sync key → 429 rate.limited.
 * - `GuardDmdServer:package`, the zip (no sync headers): off → 501; `pharmacy.dmd.downloads_per_minute` per IP →
 *   429; a missing, altered or expired signature → 403 dmd.link_invalid.
 */
class GuardDmdServer
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, string $what = 'manifest'): Response
    {
        if (! DmdSwitch::enabled()) {
            throw DmdErrors::notAvailable();
        }

        [$bucket, $max] = $what === 'package'
            ? ['pharmacy-dmd:ip:'.$request->ip(), DmdSwitch::downloadsPerMinute()]
            : ['pharmacy-dmd:key:'.AuthenticateSyncKey::caller($request)->syncKeyId, DmdSwitch::perMinute()];

        if ($this->limiter->tooManyAttempts($bucket, $max)) {
            throw DmdErrors::rateLimited($this->limiter->availableIn($bucket));
        }

        $this->limiter->hit($bucket, 60);

        if ($what === 'package' && ! DmdPackageLink::valid($request)) {
            throw DmdErrors::linkInvalid();
        }

        return $next($request);
    }
}
