<?php

namespace App\Http\Middleware;

use App\Domain\Partners\Support\PartnerApiErrors;
use App\Domain\Partners\Support\PartnerRateLimit;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the partner API's named limiter (`partner-api`, PartnerRateLimit) after AuthenticatePartnerKey. Not the
 * framework's `throttle:` middleware on purpose: that one is sorted ahead of every route middleware (middleware
 * priority), before the key is known, so all partners would share one bucket per IP.
 */
class ThrottlePartnerApi
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limit = $this->limiter->limiter(PartnerRateLimit::NAME)?->__invoke($request);

        if (! $limit instanceof Limit) {
            return $next($request);
        }

        $bucket = PartnerRateLimit::NAME.':'.$limit->key;

        if ($this->limiter->tooManyAttempts($bucket, $limit->maxAttempts)) {
            throw PartnerApiErrors::rateLimited($this->limiter->availableIn($bucket));
        }

        $this->limiter->hit($bucket, $limit->decaySeconds);

        return $next($request);
    }
}
