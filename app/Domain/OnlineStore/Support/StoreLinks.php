<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * Public store links (docs/online-store.md). The routes are registered by the public store (OS-2, routes/store.php):
 * `store.home` {slug} and `store.order-link` {order}. The order page is a signed link to the order's id, so emails
 * can link to it until it expires; nothing secret is stored for it.
 *
 *     app(StoreLinks::class)->orderPage($order);
 */
final class StoreLinks
{
    public function home(OnlineStore $store): string
    {
        return route('store.home', ['slug' => $store->slug]);
    }

    /**
     * The customer's order page (audit OSA-6, OSA-22): `store.order-link`, signed over the order id only, so it keeps
     * working when the store goes offline or changes its address; it expires `online_store.order_link_days` after the
     * slot. `$extra`: more signed parameters (OS-9, DNA's failure return: `payment=failed`).
     *
     * @param  array<string, string>  $extra
     */
    public function orderPage(OnlineOrder $order, array $extra = []): string
    {
        $expires = self::expiresAt($order);

        return URL::temporarySignedRoute('store.order-link', $expires, ['order' => $order->id, ...$extra]);
    }

    /** When the order's link stops working (OSA-22). */
    public static function expiresAt(OnlineOrder $order): CarbonImmutable
    {
        return $order->collect_to->addDays(max(1, (int) config('online_store.order_link_days', 60)));
    }
}
