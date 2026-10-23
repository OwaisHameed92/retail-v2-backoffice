<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStore;
use Illuminate\Support\Facades\Route;

/**
 * The public store's full address for the portal (OS-3): the link StoreLinks::home() makes once the public store's
 * routes exist (OS-2, `store.home`), else the same shape from the config (`https://{ONLINE_STORE_HOST}/{slug}`, or
 * `/store/{slug}` on the app host). `prefix()` + slug is what the store builder previews while the owner types.
 */
final class StoreAddress
{
    private const MARK = 'slug-placeholder';

    /** Everything before the slug, e.g. "https://shops.example.test/". */
    public static function prefix(): string
    {
        if (Route::has('store.home')) {
            $url = route('store.home', ['slug' => self::MARK]);
            $at = strpos($url, self::MARK);

            if ($at !== false) {
                return substr($url, 0, $at);
            }
        }

        $host = config('online_store.host');

        return is_string($host) && $host !== '' ? 'https://'.rtrim($host, '/').'/' : url('/store').'/';
    }

    public static function of(OnlineStore $store): string
    {
        return Route::has('store.home') ? app(StoreLinks::class)->home($store) : self::prefix().$store->slug;
    }
}
