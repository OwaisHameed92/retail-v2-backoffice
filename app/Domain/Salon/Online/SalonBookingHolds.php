<?php

namespace App\Domain\Salon\Online;

use App\Domain\Salon\Availability\PageMenu;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Carbon\CarbonImmutable;

/**
 * Times the booking page holds for requests already made (W3a, salon pack README §1.3 "holds the requested time as
 * pending on the page until expiresAt", §6): a named stylist's start that overlaps one of these is not offered again.
 *
 * - a pending `new` or `reschedule` request for a named stylist, until its `expiresAt` (while the till is away, until
 *   it is back: it answers within a minute of coming back);
 * - a request the till accepted after it published the page held now (the next page shows it booked).
 *
 * A request for "any stylist" holds nothing: the till picks the stylist when it accepts. Runs in the shop's company.
 */
final class SalonBookingHolds
{
    private const FALLBACK_MINUTES = 30;

    /**
     * @return list<array{userId: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    public static function for(SalonOnlineShop $shop, PageMenu $menu, CarbonImmutable $now): array
    {
        $offline = SalonShopStatus::offline($shop, $now);
        $holds = [];

        $rows = SalonOnlineBooking::query()
            ->where('branch_id', $shop->branch_id)
            ->whereIn('kind', ['new', 'reschedule'])
            ->whereNotNull('requested_user_id')
            ->where('requested_starts_at', '>=', $now->subDay()->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('status', 'pending')->when(! $offline, fn ($e) => $e->where('expires_at', '>', $now->format('Y-m-d H:i:s'))))
                ->orWhere(fn ($a) => $a->where('status', 'accepted')->when($shop->page_generated_at !== null, fn ($d) => $d->where('decided_at', '>=', $shop->page_generated_at?->format('Y-m-d H:i:s')))))
            ->get(['id', 'requested_user_id', 'requested_starts_at', 'services_json']);

        foreach ($rows as $row) {
            $userId = (string) $row->requested_user_id;
            $products = array_values(array_filter(array_map(fn ($line) => is_array($line) ? (string) ($line['productId'] ?? '') : '', (array) json_decode((string) $row->services_json, true))));
            $basket = $menu->basketOf($products);
            $minutes = $basket === null ? null : PageMenu::totalMinutes($basket, $userId);

            $holds[] = ['userId' => $userId, 'from' => $row->requested_starts_at, 'to' => $row->requested_starts_at->addMinutes($minutes ?? self::FALLBACK_MINUTES)];
        }

        return $holds;
    }
}
