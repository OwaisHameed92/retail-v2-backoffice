<?php

namespace App\Domain\Salon\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public salon pages' named limiters (W3a: booking page, manage link, queue join), used as `throttle:<name>`.
 * Keyed per visitor (a hash of the IP, never the IP itself) and shop, plus a per-shop ceiling on writes from everyone
 * together, so spam never buries the desk with requests:
 *
 * - `salon-public-views`: page views, polls and time look-ups (`salon_online.public.views_per_minute`, 120);
 * - `salon-public-writes`: booking requests, withdrawals, manage-link requests and queue joins (6 a minute and 30 an
 *   hour per visitor, 60 a minute per shop).
 */
final class SalonPublicRateLimits
{
    public const VIEWS = 'salon-public-views';

    public const WRITES = 'salon-public-writes';

    /** The request attribute holding the shop's slug once ResolveSalonShop has taken it off the route. */
    public const SLUG_ATTRIBUTE = 'salon_slug';

    public static function register(): void
    {
        RateLimiter::for(self::VIEWS, fn (Request $request) => Limit::perMinute(SalonOnlineSwitch::public('views_per_minute', 120))->by('salon-view:'.self::visitor($request)));

        RateLimiter::for(self::WRITES, fn (Request $request) => [
            Limit::perMinute(SalonOnlineSwitch::public('writes_per_minute', 6))->by('salon-write:'.self::visitor($request)),
            Limit::perHour(SalonOnlineSwitch::public('writes_per_hour', 30))->by('salon-write-hour:'.self::visitor($request)),
            Limit::perMinute(SalonOnlineSwitch::public('shop_writes_per_minute', 60))->by('salon-write-shop:'.self::shop($request)),
        ]);
    }

    private static function visitor(Request $request): string
    {
        return self::shop($request).'|'.hash('sha256', (string) $request->ip());
    }

    private static function shop(Request $request): string
    {
        return strtolower((string) ($request->attributes->get(self::SLUG_ATTRIBUTE) ?? $request->route('slug')));
    }
}
