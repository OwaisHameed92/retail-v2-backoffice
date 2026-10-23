<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use Illuminate\Support\Facades\URL;

/**
 * Public store links (docs/online-store.md). The routes are registered by the public store (OS-2, routes/store.php):
 * `store.home` {slug} and `store.order` {slug, order}. The order page is a signed link to the order's id, so emails
 * can link to it at any time; nothing secret is stored for it.
 *
 *     app(StoreLinks::class)->orderPage($order);
 */
final class StoreLinks
{
    public function home(OnlineStore $store): string
    {
        return route('store.home', ['slug' => $store->slug]);
    }

    public function orderPage(OnlineOrder $order): string
    {
        $slug = OnlineStore::query()->withoutGlobalScopes()->whereKey($order->online_store_id)->value('slug');

        return URL::signedRoute('store.order', ['slug' => $slug, 'order' => $order->id]);
    }
}
