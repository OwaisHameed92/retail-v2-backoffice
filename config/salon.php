<?php

/*
| Salon / Barber on the portal (docs/salon.md). The till's salon part is not released yet, so everything the portal
| serves for it from W3 on sits behind one switch, `SALON_ONLINE`, default off and off in production until the salon
| release: the staff calendar feeds (W3b), the tenant "Salon" page and the admin salon summary. Off: the iCal address
| answers 404, the page and its menu item are hidden and its routes answer 404.
*/

return [
    'online' => (bool) env('SALON_ONLINE', false),

    // Staff calendar feed (iCal): requests per minute per address (IP), on top of the unguessable token.
    'ical_per_minute' => (int) env('SALON_ICAL_PER_MINUTE', 60),

    // W3a, the salon calls and public pages (salon README §3–§11). Off: every `sync/salon/*` call is 501
    // salon.not_available with Retry-After 3600, every public page (/book/…) a 404.
    // Salon calls per minute per sync key (README §3 "suggest 60"), on top of the sync key's own limit.
    'online_per_minute' => max(6, (int) env('SALON_ONLINE_PER_MINUTE', 60)),

    // The booking page once unzipped (README §4.1): over it → 413 salon.page_too_large.
    'max_page_bytes' => 8 * 1024 * 1024,

    // A phone join nobody answered within this many minutes is dropped (README §4.3); the client asks at the desk.
    'join_ttl_minutes' => 15,

    // No heartbeat for this long → "request only" (README §6), when neither the page nor a heartbeat says.
    'default_offline_after_min' => 10,

    // Public pages, per visitor (a hash of the IP) and shop: views and time look-ups, and writes (requests,
    // withdrawals, manage-link requests, queue joins) per minute and per hour, and writes per shop from everyone.
    'public' => [
        'views_per_minute' => max(10, (int) env('SALON_ONLINE_VIEWS_PER_MINUTE', 120)),
        'writes_per_minute' => max(1, (int) env('SALON_ONLINE_WRITES_PER_MINUTE', 6)),
        'writes_per_hour' => max(1, (int) env('SALON_ONLINE_WRITES_PER_HOUR', 30)),
        'shop_writes_per_minute' => max(10, (int) env('SALON_ONLINE_SHOP_WRITES_PER_MINUTE', 60)),
    ],
];
