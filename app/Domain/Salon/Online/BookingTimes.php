<?php

namespace App\Domain\Salon\Online;

use App\Domain\Salon\Availability\FreeTimes;
use App\Domain\Salon\Availability\LondonClock;
use App\Domain\Salon\Availability\PageMenu;
use App\Domain\Salon\Availability\StylistChoice;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The free start times a salon shop's public pages offer for a basket and a stylist choice (W3a, README §10): the
 * till's published ranges (FreeTimes), less times held for earlier requests (SalonBookingHolds). Used by the booking
 * page, the manage link's "move" and, on a request, to check the chosen time is still offered.
 */
final class BookingTimes
{
    /**
     * Every date with a start, for the page's date and time picker; null when the basket or stylist is not on the page.
     *
     * @param  list<array{productId: string, kind: string, grid: string, minutes: array<string, int>|int}>  $basket
     * @return list<array{date: string, label: string, times: list<string>}>|null
     */
    public static function days(SalonOnlineShop $shop, array $basket, string $stylist, CarbonImmutable $now): ?array
    {
        $menu = new PageMenu((array) $shop->page);
        $candidates = StylistChoice::candidates($menu, $basket, $stylist);

        if ($candidates === []) {
            return null;
        }

        $days = (new FreeTimes($menu))->days($basket, $candidates, $now, self::holds($shop, $menu, $now));

        return array_map(fn (array $day) => [
            ...$day,
            'label' => CarbonImmutable::parse($day['date'], LondonClock::ZONE)->format('D j M'),
        ], $days);
    }

    /**
     * The UTC start when this date and time is still offered for the basket and choice; else null.
     *
     * @param  list<array{productId: string, kind: string, grid: string, minutes: array<string, int>|int}>  $basket
     */
    public static function check(SalonOnlineShop $shop, array $basket, string $stylist, string $date, string $time, CarbonImmutable $now): ?CarbonImmutable
    {
        $menu = new PageMenu((array) $shop->page);
        $candidates = StylistChoice::candidates($menu, $basket, $stylist);
        $who = $candidates === [] ? [] : (new FreeTimes($menu))->whoCan($date, $time, $basket, $candidates, $now, self::holds($shop, $menu, $now));

        if ($who === []) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return LondonClock::toUtc($date, $hours * 60 + $minutes);
    }

    /**
     * @return list<array{userId: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    private static function holds(SalonOnlineShop $shop, PageMenu $menu, CarbonImmutable $now): array
    {
        $company = $shop->company()->firstOrFail();

        return app(CurrentCompany::class)->runAs($company, fn () => SalonBookingHolds::for($shop, $menu, $now));
    }
}
