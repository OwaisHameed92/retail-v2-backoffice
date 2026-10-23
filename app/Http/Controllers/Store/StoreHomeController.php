<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Queries\CollectionDays;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StorePresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /{slug}` (OS-2, OS-5): the store's home page in its template (Fresh Market or Butcher's Block, `store.template`):
 * the sections the store turned on, in its order (banner, departments, featured products, how click & collect works,
 * shops and hours, about). Each template draws the same sections in its own style.
 */
class StoreHomeController extends Controller
{
    public const FEATURED = 8;

    public function __invoke(StoreContext $context, StorefrontCatalogue $catalogue, CollectionDays $days): Response
    {
        $store = $context->store;
        $shop = $context->shop();
        $featured = $shop === null ? [] : $catalogue->products($store, $shop, perPage: self::FEATURED, filters: new CatalogueFilters(inStockOnly: true))->items;
        $next = $shop === null || ! $context->takingOrders() ? null : $days->next($shop);

        return Inertia::render('store/home', [
            'sections' => StorePresenter::sections($store),
            'headline' => trim((string) $store->headline) ?: null,
            'bannerUrl' => StorePresenter::bannerUrl($store),
            'nextCollection' => $next === null ? null : CollectionDays::describe($next->from, $next->to),
            'featured' => StorePresenter::products($store, $featured),
            'shops' => $context->shops->map(fn ($shop) => StorePresenter::shop($shop))->values()->all(),
        ]);
    }
}
