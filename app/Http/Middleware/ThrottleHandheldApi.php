<?php

namespace App\Http\Middleware;

use App\Domain\Handheld\Support\HandheldRateLimits;
use App\Domain\Handheld\Support\PdaErrors;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies one of the handheld API's named limiters (HandheldRateLimits): `ThrottleHandheldApi::class.':pda-login'`
 * on the login, `':pda-api'` after AuthenticateHandheldToken everywhere else. Over a limit → 429 RATE_LIMITED.
 */
class ThrottleHandheldApi
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, string $name = HandheldRateLimits::API): Response
    {
        $limits = $this->limiter->limiter($name)?->__invoke($request);
        $limits = array_filter(is_array($limits) ? $limits : [$limits], fn (mixed $limit) => $limit instanceof Limit);

        foreach ($limits as $limit) {
            $bucket = $name.':'.$limit->key;

            if ($this->limiter->tooManyAttempts($bucket, $limit->maxAttempts)) {
                throw PdaErrors::rateLimited($this->limiter->availableIn($bucket));
            }
        }

        foreach ($limits as $limit) {
            $this->limiter->hit($name.':'.$limit->key, $limit->decaySeconds);
        }

        return $next($request);
    }
}
