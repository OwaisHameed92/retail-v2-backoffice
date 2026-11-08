<?php

namespace App\Domain\Salon\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The staff calendar feed's limiter (`throttle:salon-ical`, W3b): `salon.ical_per_minute` (60) per IP. Calendar apps
 * read a feed every 5 to 60 minutes, so a whole salon on one connection stays far below it. The bucket key is a hash
 * of the IP, never the address or its token.
 */
final class SalonCalendarRateLimit
{
    public const NAME = 'salon-ical';

    public static function register(): void
    {
        RateLimiter::for(self::NAME, fn (Request $request) => Limit::perMinute(max(1, (int) config('salon.ical_per_minute', 60)))
            ->by('salon-ical:'.hash('sha256', (string) $request->ip())));
    }
}
