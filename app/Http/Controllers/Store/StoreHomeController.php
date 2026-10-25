<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Support\Store\NextSlot;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StoreHomeRows;
use App\Http\Support\Store\StorePresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /{slug}` (OS-2, OS-5, OS-8): the store's home page in its template (Fresh Market or Butcher's Block,
 * `store.template`): the sections the store turned on, in its order (banners, departments with photos, featured
 * products, how ordering works (collection, delivery or both, OS-12), shops and hours, about, and the optional "On offer" and department rows).
 * Each template draws the same sections in its own style. `headline` / `bannerUrl` are the first banner's.
 */
class StoreHomeController extends Controller
{
    public function __invoke(StoreContext $context, StoreHomeRows $rows, NextSlot $nextSlot): Response
    {
        $store = $context->store;
        $sections = StorePresenter::sections($store);
        $next = $nextSlot->for($context);
        $banners = $rows->banners($context);

        return Inertia::render('store/home', [
            'sections' => $sections,
            'banners' => $banners,
            'headline' => $banners[0]['headline'] ?? null,
            'bannerUrl' => $banners[0]['photoUrl'] ?? StorePresenter::bannerUrl($store),
            'nextCollection' => $next['collection'],
            // OS-12: the first slot with room over the shop's methods ("delivery", "Today 14:00–15:00").
            'nextSlot' => $next['next'],
            ...$rows->featured($context),
            'offers' => in_array('offers', $sections, true) ? $rows->offers($context) : [],
            'departmentRows' => in_array('departmentRows', $sections, true) ? $rows->departmentRows($context) : [],
            'departmentTiles' => in_array('departments', $sections, true) ? $rows->departmentTiles($context) : [],
            'aboutText' => trim((string) $store->about_text) ?: null,
            'shops' => $context->shops->map(fn ($shop) => StorePresenter::shop($shop))->values()->all(),
        ]);
    }
}
