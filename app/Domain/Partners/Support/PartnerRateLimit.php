<?php

namespace App\Domain\Partners\Support;

use App\Domain\Partners\Models\PartnerKey;
use App\Http\Middleware\AuthenticatePartnerKey;
use App\Http\Middleware\ThrottlePartnerApi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The partner API's own named rate limiter, `partner-api` (applied by ThrottlePartnerApi after AuthenticatePartnerKey):
 * `partners.rate_limit_per_minute` (60) requests per partner key and minute, in buckets of its own (never shared with
 * the till, public or login throttles). Over it → 429 rate.limited in the API envelope with Retry-After.
 *
 * @see ThrottlePartnerApi
 */
final class PartnerRateLimit
{
    public const NAME = 'partner-api';

    public static function register(): void
    {
        RateLimiter::for(self::NAME, function (Request $request) {
            $key = $request->attributes->get(AuthenticatePartnerKey::ATTRIBUTE);

            return Limit::perMinute(max(1, (int) config('partners.rate_limit_per_minute', 60)))
                ->by('key:'.($key instanceof PartnerKey ? $key->id : 'ip:'.hash('sha256', (string) $request->ip())));
        });
    }
}
