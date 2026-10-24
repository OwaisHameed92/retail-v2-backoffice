<?php

namespace Tests\Feature\OnlineStore;

use Illuminate\Support\Facades\Route;

/**
 * The public store's named routes (`store.home`, signed `store.order`) for tests that make store links, registered
 * only when the public store (OS-2, routes/store.php) is not installed, so these tests do not depend on it.
 */
final class StoreTestRoutes
{
    public static function ensure(): void
    {
        if (! Route::has('store.home')) {
            Route::get('/store/{slug}', fn () => 'store')->name('store.home');
        }

        if (! Route::has('store.order')) {
            Route::get('/store/{slug}/order/{order}', fn () => 'order')->middleware('signed')->name('store.order');
        }

        if (! Route::has('store.order-link')) {
            Route::get('/store/o/{order}', fn () => 'order')->middleware('signed')->name('store.order-link');
        }

        Route::getRoutes()->refreshNameLookups();
    }
}
