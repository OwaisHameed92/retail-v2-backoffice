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

    // OS-9: how long an unpaid card order holds its slot while the customer pays on DNA's page; after that the slot
    // is free again and the order is marked as not paid (online-store:expire-payments).
    'card_hold_minutes' => (int) env('ONLINE_STORE_CARD_HOLD_MINUTES', 15),

    // Against fake orders (audit OSA-1, OrderAbuseGuard and CheckoutChallenge): placed orders per visitor (IP) an hour;
    // per customer (email or mobile) open orders and orders an hour; orders per store an hour; the checkout's proof of
    // work (no third-party service): hex zeros the hash must start with, and the seconds the page must be open first.
    'checkout_per_hour' => (int) env('ONLINE_STORE_CHECKOUT_PER_HOUR', 20),
    'customer_limits' => (bool) env('ONLINE_STORE_CUSTOMER_LIMITS', true),
    'max_open_orders_per_customer' => (int) env('ONLINE_STORE_MAX_OPEN_ORDERS_PER_CUSTOMER', 3),
    'orders_per_customer_hour' => (int) env('ONLINE_STORE_ORDERS_PER_CUSTOMER_HOUR', 5),
    'orders_per_store_hour' => (int) env('ONLINE_STORE_ORDERS_PER_STORE_HOUR', 60),
    'checkout_challenge' => (bool) env('ONLINE_STORE_CHECKOUT_CHALLENGE', true),
    'challenge_zeros' => (int) env('ONLINE_STORE_CHALLENGE_ZEROS', 3),
    'challenge_min_seconds' => (int) env('ONLINE_STORE_CHALLENGE_MIN_SECONDS', 2),

    // Orders (audit OSA-21, OSA-22): a move staff can undo within these minutes; a ready order nobody collected is marked
    // "not collected" this many hours after its slot (0 = never); the customer's order link works until this many days
    // after the slot; customer details are anonymised this many months after the order is finished (0 = never).
    'undo_minutes' => (int) env('ONLINE_STORE_UNDO_MINUTES', 10),
    'not_collected_after_hours' => (int) env('ONLINE_STORE_NOT_COLLECTED_AFTER_HOURS', 48),
    'order_link_days' => (int) env('ONLINE_STORE_ORDER_LINK_DAYS', 60),
    'anonymise_after_months' => (int) env('ONLINE_STORE_ANONYMISE_AFTER_MONTHS', 24),
];
