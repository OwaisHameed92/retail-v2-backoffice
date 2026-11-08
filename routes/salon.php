<?php

use App\Domain\Salon\Support\SalonPublicRateLimits;
use App\Http\Controllers\SalonPublic\BookingPageController;
use App\Http\Controllers\SalonPublic\BookingRequestController;
use App\Http\Controllers\SalonPublic\ManageLinkController;
use App\Http\Controllers\SalonPublic\QueueJoinController;
use App\Http\Middleware\ResolveSalonShop;
use Illuminate\Support\Facades\Route;

/*
| Salon / Barber public pages (W3a, salon pack 2026-11-08 salon/README.md §4.1, §8, §9; docs/salon.md). Loaded with the
| `web` middleware before web.php (bootstrap/app.php). SALON_ONLINE off → every page is a 404 (ResolveSalonShop).
| The writes are JSON calls from the page (no session needed, so they work inside another site's frame) and are left
| out of the CSRF check; they are rate limited per visitor and shop (SalonPublicRateLimits).
|
|   /book/{slug}                        booking page (bookingUrl; ?src=, ?embed=1)
|   /book/{slug}/r/{requestId}          "request sent, waiting for the shop"
|   /book/{slug}/m/{appointmentId}.{token}   manage link (manageUrl + appointmentId.token)
|   /book/{slug}/queue                  queue join (queueJoinUrl), /queue/{joinId} "my position"
*/

$ulid = '[0-9A-Za-z]{26}';

Route::prefix('book/{slug}')->name('salon.public.')
    ->where(['slug' => '[a-z0-9-]{3,60}'])
    ->middleware(['throttle:'.SalonPublicRateLimits::VIEWS, ResolveSalonShop::class])
    ->group(function () use ($ulid) {
        $writes = 'throttle:'.SalonPublicRateLimits::WRITES;

        Route::get('/', [BookingPageController::class, 'show'])->name('book');
        Route::get('times', [BookingPageController::class, 'times'])->name('times');
        Route::post('/', [BookingPageController::class, 'store'])->name('book.store')->middleware($writes);

        Route::get('r/{requestId}', [BookingRequestController::class, 'show'])->name('request')->where('requestId', $ulid);
        Route::post('r/{requestId}/withdraw', [BookingRequestController::class, 'withdraw'])->name('request.withdraw')->where('requestId', $ulid)->middleware($writes);

        Route::prefix('m/{link}')->where(['link' => $ulid.'\.[A-Za-z0-9_-]{22}'])->name('manage')->group(function () use ($writes) {
            Route::get('/', [ManageLinkController::class, 'show'])->name('');
            Route::get('times', [ManageLinkController::class, 'times'])->name('.times');
            Route::post('move', [ManageLinkController::class, 'move'])->name('.move')->middleware($writes);
            Route::post('cancel', [ManageLinkController::class, 'cancel'])->name('.cancel')->middleware($writes);
            Route::post('preferences', [ManageLinkController::class, 'preferences'])->name('.preferences')->middleware($writes);
        });

        Route::get('queue', [QueueJoinController::class, 'show'])->name('queue');
        Route::post('queue', [QueueJoinController::class, 'store'])->name('queue.store')->middleware($writes);
        Route::get('queue/{joinId}', [QueueJoinController::class, 'position'])->name('queue.position')->where('joinId', $ulid);
    });
