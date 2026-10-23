<?php

/*
| Online store, stage S1: click & collect, pay at the shop (docs/online-store.md, DECISIONS "Online store, click &
| collect first"). Read the switch through App\Domain\OnlineStore\Support\OnlineStoreSwitch, which also needs the
| country flag `onlineStore` (config/country.php: GB on, PK off).
*/

return [
    // OFF by default: no store is found by its address and none can go live.
    'enabled' => (bool) env('ONLINE_STORE', false),

    // The public store's own host (https://{host}/{slug}); null = `/store/{slug}` on the app host (wave E2).
    'host' => env('ONLINE_STORE_HOST') ?: null,

    // The add-on's monthly price before VAT, unless a business has its own (`online_stores.monthly_price`).
    'default_monthly_price' => '20.00',

    // How many days ahead (today included, shop time) a customer may book a collection slot.
    'order_days_ahead' => (int) env('ONLINE_STORE_ORDER_DAYS_AHEAD', 7),

    // Public store POSTs per minute (StoreRateLimits): basket changes and shop choice per visitor (IP) and store,
    // and placed orders per visitor and store.
    'basket_per_minute' => (int) env('ONLINE_STORE_BASKET_PER_MINUTE', 60),
    'checkout_per_minute' => (int) env('ONLINE_STORE_CHECKOUT_PER_MINUTE', 6),
];
