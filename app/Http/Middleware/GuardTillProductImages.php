<?php

namespace App\Http\Middleware;

use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\TillImages\Support\TillImageErrors;
use App\Domain\TillImages\Support\TillImageSwitch;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/v1/sync/product-images*` after the sync key check (AuthenticateSyncKey, GuardSyncRequest), contract proposal
 * product-images §3, §6:
 *
 * - TILL_PRODUCT_IMAGES off (the default) → 501 image.not_available with Retry-After, before the body is read. An
 *   unknown key is still 401: the till learns nothing about the portal before it proves who it is.
 * - its own limit, `sync.product_images.per_minute` calls per sync key (default 60) → 429 rate.limited with
 *   Retry-After, on top of the sync key's general limit, so a "Find pictures" sweep never crowds out push and pull.
 */
class GuardTillProductImages
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! TillImageSwitch::enabled()) {
            throw TillImageErrors::notAvailable();
        }

        $bucket = 'sync-product-images:key:'.AuthenticateSyncKey::caller($request)->syncKeyId;

        if ($this->limiter->tooManyAttempts($bucket, TillImageSwitch::perMinute())) {
            throw SyncApiErrors::rateLimited($this->limiter->availableIn($bucket));
        }

        $this->limiter->hit($bucket, 60);

        return $next($request);
    }
}
