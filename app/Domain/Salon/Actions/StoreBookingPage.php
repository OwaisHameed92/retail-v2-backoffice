<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\BookingPageReader;
use App\Domain\Salon\Online\SalonOnlineShops;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `PUT sync/salon/booking-page` (W3a, salon pack README §4.1): stores the page the shop's main till published, whole,
 * one per shop, keeping only the newest.
 *
 * - The same page again (same content, key included) → `unchanged`, nothing written.
 * - A page older than the one held (`generatedAt` earlier) → `unchanged`: never backwards (README §3 "Idempotent").
 * - Otherwise → `stored`: the page replaces the last one. The manage-link key is kept apart, encrypted, never in the
 *   page column and never logged; a page with `manageLink: null` leaves the key held as it was.
 *
 * Replies with the shop's public addresses, the same every time (SalonOnlineShops).
 */
final class StoreBookingPage
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array{result: string, bookingUrl: string, queueJoinUrl: string, manageUrl: string}
     *
     * @throws ApiException
     */
    public function handle(SyncCaller $caller, mixed $body): array
    {
        ['page' => $page, 'manageLink' => $link] = BookingPageReader::read($body);
        $hash = hash('sha256', (string) json_encode([$page, $link], JSON_UNESCAPED_UNICODE));
        $generated = CarbonImmutable::parse($page['generatedAt']);

        return $this->tenancy->runAs($caller->company, function () use ($caller, $page, $link, $hash, $generated): array {
            $shop = SalonOnlineShops::forBranch($caller->branch, $page['shopName']);

            $result = DB::transaction(function () use ($shop, $page, $link, $hash, $generated): string {
                $held = SalonOnlineShop::query()->whereKey($shop->id)->lockForUpdate()->firstOrFail();

                if ($held->page_hash === $hash || ($held->page_generated_at !== null && $generated->lessThan($held->page_generated_at))) {
                    return 'unchanged';
                }

                $held->forceFill([
                    'page' => $page,
                    'page_hash' => $hash,
                    'page_generated_at' => $generated,
                    'page_stored_at' => CarbonImmutable::now('UTC'),
                    ...($link === null ? [] : ['manage_key_id' => $link['keyId'], 'manage_key' => $link['key']]),
                ])->save();

                return 'stored';
            });

            return ['result' => $result, ...SalonOnlineShops::addresses($shop)];
        });
    }
}
