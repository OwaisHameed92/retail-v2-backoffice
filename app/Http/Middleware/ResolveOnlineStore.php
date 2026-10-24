<?php

namespace App\Http\Middleware;

use App\Domain\OnlineStore\Basket\PriceBasket;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\OnlineStore\Queries\StorefrontShops;
use App\Domain\OnlineStore\Queries\StoreOffers;
use App\Domain\OnlineStore\Support\StoreOldSlugs;
use App\Domain\OnlineStore\Support\StoreRateLimits;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StorePages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public store's first step (OS-2, routes/store.php): finds the store by the `{slug}` in the address
 * (FindStoreBySlug: switch on, live, business trading); an address the store changed from in the last 90 days
 * redirects (301) to the new one (OS-11, StoreOldSlugs); else the store's own 404 page. Then sets the
 * request's StoreContext (container + request attribute), the store's page frame, and `slug` as the default for
 * store links; the `slug` route parameter is dropped so controllers get only their own parameters. OS-8: one
 * StoreOffers per request (its offers are read once), and an `X-Robots-Tag` on private and duplicate pages.
 */
class ResolveOnlineStore
{
    public function __construct(
        private readonly FindStoreBySlug $stores,
        private readonly StorefrontShops $shops,
        private readonly PriceBasket $pricer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route instanceof Route ? (string) $route->parameter('slug') : '';
        $store = $this->stores->handle($slug);

        if ($store === null && ($moved = $this->moved($request, $slug)) !== null) {
            return $moved;
        }

        if ($store === null || ! $request->hasSession()) {
            return StorePages::error($request, 404);
        }

        $context = new StoreContext($store, $this->shops->handle($store), $request->session(), $this->pricer);

        app()->instance(StoreContext::class, $context);
        app()->instance(StoreOffers::class, new StoreOffers);
        $request->attributes->set(StoreContext::class, $context);
        $request->attributes->set(StoreRateLimits::STORE_ATTRIBUTE, $store->id);
        URL::defaults(['slug' => $store->slug]);
        StorePages::share($context);

        if ($route instanceof Route) {
            $route->forgetParameter('slug');
        }

        $response = $next($request);

        // Audits OSA-9: private and duplicate pages are kept out of search engines (StoreMeta has the rules).
        $robots = match (true) {
            $request->routeIs('store.basket*', 'store.checkout*', 'store.order', 'store.shop') => 'noindex, nofollow',
            $request->routeIs('store.search') || array_intersect(array_keys($request->query()), ['sort', 'stock', 'min', 'max', 'page', 'q']) !== [] => 'noindex, follow',
            default => null,
        };

        if ($robots !== null && ! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', $robots);
        }

        return $response;
    }

    /**
     * OS-11 (audit OSA-6): an address the store used until it changed (StoreOldSlugs, 90 days) → 301 to the same
     * page at the store's current address, query string kept. Pages only (GET, HEAD); the new address must open.
     */
    private function moved(Request $request, string $slug): ?Response
    {
        $slug = strtolower(trim($slug));

        if (! $request->isMethodSafe() || preg_match('/^[a-z0-9-]{3,40}$/', $slug) !== 1) {
            return null;
        }

        $current = StoreOldSlugs::currentFor($slug);

        if ($current === null || $this->stores->handle($current) === null) {
            return null;
        }

        $path = preg_replace('#^(/(?:store/)?)'.preg_quote($slug, '#').'(?=/|$)#i', '${1}'.$current, $request->getPathInfo(), 1);

        if (! is_string($path) || $path === $request->getPathInfo()) {
            return null;
        }

        $query = $request->getQueryString();

        return redirect()->to($request->getSchemeAndHttpHost().$request->getBaseUrl().$path.($query !== null ? '?'.$query : ''), 301);
    }
}
