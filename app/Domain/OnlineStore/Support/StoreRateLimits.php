<?php

namespace App\Domain\OnlineStore\Support;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public online store's named limiters (OS-2, docs/online-store.md "Security"), used as `throttle:<name>` on
 * its POST routes. Keyed per visitor (a hash of the IP) and store, so one shop's rush never slows another:
 *
 * - `online-store-basket`: basket changes and the shop choice, `online_store.basket_per_minute` (60).
 * - `online-store-checkout`: placed orders, `online_store.checkout_per_minute` (6), and `online_store.checkout_per_hour`
 *   (20) an hour (audit OSA-1).
 *
 * Over the limit the store answers 429 with its own page (the response callback set by the public store).
 */
final class StoreRateLimits
{
    public const BASKET = 'online-store-basket';

    public const CHECKOUT = 'online-store-checkout';

    /** The request attribute the public store sets to the store's id once it is found by its address. */
    public const STORE_ATTRIBUTE = 'online_store_id';

    /** @var (Closure(Request, array<string, string>): Response)|null */
    private static ?Closure $response = null;

    /**
     * @param  (Closure(Request, array<string, string>): Response)|null  $response  the 429 page
     */
    public static function register(?Closure $response = null): void
    {
        self::$response = $response;

        RateLimiter::for(self::BASKET, fn (Request $request) => self::limit($request, 'basket', (int) config('online_store.basket_per_minute', 60)));
        RateLimiter::for(self::CHECKOUT, fn (Request $request) => [
            self::limit($request, 'checkout', (int) config('online_store.checkout_per_minute', 6)),
            self::limit($request, 'checkout-hour', (int) config('online_store.checkout_per_hour', 20), 60),
        ]);
    }

    private static function limit(Request $request, string $bucket, int $perPeriod, int $minutes = 1): Limit
    {
        $store = (string) ($request->attributes->get(self::STORE_ATTRIBUTE) ?? strtolower((string) $request->route('slug')));
        $limit = ($minutes === 1 ? Limit::perMinute(max(1, $perPeriod)) : Limit::perMinutes($minutes, max(1, $perPeriod)))->by("store-{$bucket}:{$store}|".hash('sha256', (string) $request->ip()));

        return self::$response === null ? $limit : $limit->response(self::$response);
    }
}
