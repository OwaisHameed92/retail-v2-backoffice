<?php

use App\Domain\Salon\Support\SalonCalendarRateLimit;
use App\Http\Controllers\Salon\CalendarFeedController;
use Illuminate\Support\Facades\Route;

/*
| Salon W3b: the staff calendar feed (iCal) per stylist, salon README §12. Loaded without the web middleware (no
| session, no cookies, no CSRF): the random token in the address is the key. Behind SALON_ONLINE (404 when off) and
| rate limited per IP. Parameters are checked in BuildCalendarFeed so every refusal is the same plain 404.
*/

Route::get('salon/ical/{branchId}/{userId}/{feedToken}.ics', CalendarFeedController::class)
    ->where(['branchId' => '[0-9A-Za-z]{1,64}', 'userId' => '[0-9A-Za-z]{1,64}', 'feedToken' => '[A-Za-z0-9_-]{1,128}'])
    ->middleware('throttle:'.SalonCalendarRateLimit::NAME)
    ->name('salon.ical');
