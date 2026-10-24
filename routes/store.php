<?php

use App\Domain\OnlineStore\Support\StoreRateLimits;
use App\Http\Controllers\Store\StoreBasketController;
use App\Http\Controllers\Store\StoreCheckoutController;
use App\Http\Controllers\Store\StoreDepartmentController;
use App\Http\Controllers\Store\StoreHomeController;
use App\Http\Controllers\Store\StoreNotFoundController;
use App\Http\Controllers\Store\StoreOrderController;
use App\Http\Controllers\Store\StoreOrderLinkController;
use App\Http\Controllers\Store\StoreProductController;
use App\Http\Controllers\Store\StoreSearchController;
use App\Http\Controllers\Store\StoreShopController;
use App\Http\Middleware\ResolveOnlineStore;
use Illuminate\Support\Facades\Route;

/*
| The public online store (OS-2, docs/online-store.md): click & collect, pay at the shop. Loaded before web.php
| (bootstrap/app.php) with the `web` middleware (session, CSRF). With `online_store.host` (ONLINE_STORE_HOST) the
| store lives at https://{host}/{slug}/…, else at /store/{slug}/… on the app host. ResolveOnlineStore finds the
| store by its slug (unknown, closed or switched off → the store's 404). POSTs are rate limited (StoreRateLimits).
*/

$id = '[0-9A-Za-z]{26}';

$pages = function () use ($id) {
    Route::get('/', StoreHomeController::class)->name('home');
    Route::get('department/{department}', StoreDepartmentController::class)->name('department')->where('department', $id);
    Route::get('search', StoreSearchController::class)->name('search');
    Route::get('product/{product}', StoreProductController::class)->name('product')->where('product', $id);

    Route::get('basket', [StoreBasketController::class, 'show'])->name('basket');
    Route::middleware('throttle:'.StoreRateLimits::BASKET)->group(function () use ($id) {
        Route::post('basket', [StoreBasketController::class, 'add'])->name('basket.add');
        Route::patch('basket/{product}', [StoreBasketController::class, 'update'])->name('basket.update')->where('product', $id);
        Route::delete('basket/{product}', [StoreBasketController::class, 'remove'])->name('basket.remove')->where('product', $id);
        Route::post('shop', StoreShopController::class)->name('shop');
    });

    Route::get('checkout', [StoreCheckoutController::class, 'show'])->name('checkout');
    Route::post('checkout', [StoreCheckoutController::class, 'place'])->name('checkout.place')
        ->middleware('throttle:'.StoreRateLimits::CHECKOUT);
    // OS-9: collect or delivery, and the delivery postcode (checked on the server), kept in the session.
    Route::post('checkout/method', [StoreCheckoutController::class, 'method'])->name('checkout.method')
        ->middleware('throttle:'.StoreRateLimits::BASKET);

    // Signed link to the order's id (StoreLinks::orderPage); the controller checks the signature.
    Route::get('order/{order}', StoreOrderController::class)->name('order')->where('order', $id);
};

// Audit OSA-6, OSA-22: the order page by a link signed over the order id only (works while the store is offline or
// after its address changed; expires a while after the slot). Outside ResolveOnlineStore: the controller finds the store.
$orderLink = fn () => Route::get('o/{order}', StoreOrderLinkController::class)->name('store.order-link')->where('order', $id)
    ->middleware('throttle:60,1');

$host = config('online_store.host');
$store = fn () => Route::middleware(ResolveOnlineStore::class)->name('store.')->where(['slug' => '[A-Za-z0-9-]{3,40}']);

if (is_string($host) && $host !== '') {
    Route::domain($host)->group(function () use ($store, $pages, $orderLink) {
        Route::get('/', StoreNotFoundController::class)->name('store.root');
        $orderLink();
        $store()->prefix('{slug}')->group($pages);
    });
} else {
    Route::prefix('store')->group($orderLink);
    $store()->prefix('store/{slug}')->group($pages);
}
