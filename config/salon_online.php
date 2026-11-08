<?php

/*
|--------------------------------------------------------------------------
| Salon / Barber online booking and queue join (W3a)
|--------------------------------------------------------------------------
|
| Salon pack 2026-11-08 (docs/contracts/salon-barber-2026-11-08/docs/web-portal-api/salon/README.md, docs/salon.md).
| Off (the default, and in production until the owner says so): every `sync/salon/*` call answers
| 501 salon.not_available with Retry-After 3600, and every public page (booking, manage link, queue join) is a 404.
|
*/

return [

    'enabled' => (bool) env('SALON_ONLINE', false),

    // Salon calls per minute per sync key (README §3 "Rate": "suggest a salon limit of 60 calls a minute per key"), on
    // top of the sync key's own limit.
    'per_minute' => max(6, (int) env('SALON_ONLINE_PER_MINUTE', 60)),

    // The booking page once unzipped (README §4.1): over it → 413 salon.page_too_large.
    'max_page_bytes' => 8 * 1024 * 1024,

    // A phone join nobody answered within this many minutes is dropped (README §4.3); the client asks at the desk.
    'join_ttl_minutes' => 15,

    // No heartbeat for this long → "request only" (README §6), when neither the page nor a heartbeat says.
    'default_offline_after_min' => 10,

    // Public pages, per visitor (a hash of the IP) and shop: page views and time look-ups, and writes (booking
    // requests, withdrawals, manage-link requests, queue joins) per minute and per hour.
    'public' => [
        'views_per_minute' => max(10, (int) env('SALON_ONLINE_VIEWS_PER_MINUTE', 120)),
        'writes_per_minute' => max(1, (int) env('SALON_ONLINE_WRITES_PER_MINUTE', 6)),
        'writes_per_hour' => max(1, (int) env('SALON_ONLINE_WRITES_PER_HOUR', 30)),
        // Writes per shop from everyone together, per minute: a flood from many addresses never buries the desk.
        'shop_writes_per_minute' => max(10, (int) env('SALON_ONLINE_SHOP_WRITES_PER_MINUTE', 60)),
    ],

];
