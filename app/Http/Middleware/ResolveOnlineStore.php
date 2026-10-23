<?php

namespace App\Http\Middleware;

use App\Domain\OnlineStore\Basket\PriceBasket;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\OnlineStore\Queries\StorefrontShops;
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
 * (FindStoreBySlug: switch on, live, business trading), else answers the store's own 404 page. Then sets the
 * request's StoreContext (container + request attribute), the store's page frame, and `slug` as the default for
 * store links; the `slug` route parameter is dropped so controllers get only their own parameters.
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

        if ($store === null || ! $request->hasSession()) {
            return StorePages::error($request, 404);
        }

        $context = new StoreContext($store, $this->shops->handle($store), $request->session(), $this->pricer);

        app()->instance(StoreContext::class, $context);
        $request->attributes->set(StoreContext::class, $context);
        $request->attributes->set(StoreRateLimits::STORE_ATTRIBUTE, $store->id);
        URL::defaults(['slug' => $store->slug]);
        StorePages::share($context);

        if ($route instanceof Route) {
            $route->forgetParameter('slug');
        }

        return $next($request);
    }
}
