<?php

namespace App\Domain\Salon\Online;

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Support\SalonOnlineSwitch;
use Carbon\CarbonImmutable;

/**
 * Whether a salon shop's public pages take requests (W3a, salon pack README §6):
 *
 * - **closed**: the page says `isOpen: false`, or the last heartbeat said `accepting: false` → the booking page is
 *   closed and shows the shop's phone;
 * - **offline**: no heartbeat for `offlineAfterMin` (the last heartbeat's, else the page's, else 10) → "request only":
 *   the page stays, still takes pending requests, and promises no time;
 * - **open** otherwise.
 *
 * The queue-join page takes joins only while the till is heard, its last heartbeat and page say phone join is on, and
 * its last queue push (if any) says the queue is open; else "ask at the desk".
 */
final class SalonShopStatus
{
    public const OPEN = 'open';

    public const OFFLINE = 'offline';

    public const CLOSED = 'closed';

    public static function booking(SalonOnlineShop $shop, CarbonImmutable $now): string
    {
        $page = $shop->page;

        if ($page === null || ($page['rules']['isOpen'] ?? false) !== true || $shop->accepting === false) {
            return self::CLOSED;
        }

        return self::offline($shop, $now) ? self::OFFLINE : self::OPEN;
    }

    public static function offline(SalonOnlineShop $shop, CarbonImmutable $now): bool
    {
        return $shop->last_heard_at === null || $shop->last_heard_at->lessThan($now->subMinutes(self::offlineAfter($shop)));
    }

    public static function offlineAfter(SalonOnlineShop $shop): int
    {
        return max(1, (int) ($shop->offline_after_min ?? $shop->page['rules']['offlineAfterMin'] ?? SalonOnlineSwitch::defaultOfflineAfterMin()));
    }

    public static function queueOpen(SalonOnlineShop $shop, CarbonImmutable $now): bool
    {
        return $shop->page !== null
            && ! self::offline($shop, $now)
            && $shop->queue_join_open === true
            && ($shop->page['rules']['queueJoinOpen'] ?? false) === true
            && ($shop->queue === null || ($shop->queue['isOpen'] ?? false) === true);
    }
}
