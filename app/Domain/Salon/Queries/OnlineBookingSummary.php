<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\SalonOnlineShops;
use App\Domain\Salon\Online\SalonShopStatus;
use Carbon\CarbonImmutable;

/**
 * The "Online booking" card of the tenant Salon page (W3b's slot, filled by W3a): for the first shop in view whose till
 * has published a booking page, whether it takes requests, when its till was last heard from, and the booking and
 * queue-join addresses to share. `state` is open, offline ("request only": the till is away) or closed. Null when no
 * shop in view has published a page yet (the card then says "Soon"). Never the manage-link key. In the company scope.
 */
final class OnlineBookingSummary
{
    /**
     * @param  list<string>  $branchIds  the shops in view, in their order
     * @return array{accepting: bool, lastHeardAt: string|null, bookingUrl: string, queueJoinUrl: string, shopName: string, state: string}|null
     */
    public static function for(array $branchIds, CarbonImmutable $now): ?array
    {
        if ($branchIds === []) {
            return null;
        }

        $shops = SalonOnlineShop::query()->whereIn('branch_id', $branchIds)->whereNotNull('page')->get()->keyBy('branch_id');

        foreach ($branchIds as $id) {
            $shop = $shops->get($id);

            if ($shop instanceof SalonOnlineShop) {
                $state = SalonShopStatus::booking($shop, $now);
                $urls = SalonOnlineShops::addresses($shop);

                return [
                    'accepting' => $state !== SalonShopStatus::CLOSED,
                    'lastHeardAt' => $shop->last_heard_at?->toIso8601ZuluString(),
                    'bookingUrl' => $urls['bookingUrl'],
                    'queueJoinUrl' => $urls['queueJoinUrl'],
                    'shopName' => (string) ($shop->page['shopName'] ?? ''),
                    'state' => $state,
                ];
            }
        }

        return null;
    }
}
