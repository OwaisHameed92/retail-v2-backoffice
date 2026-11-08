<?php

/*
 * BikeBook POS connector, wave W5 (docs/verticals-0.1.85.md "BikeBook connector"; EPOS contract
 * docs/contracts/portal-api-0.1.85/docs/web-portal-api/WORKSHOP-BIKEBOOK.md).
 *
 * WORKSHOP_CONNECTOR is OFF by default and stays off in production until BikeBook registers SSPOS as a POS type and
 * the till applies the `WorkshopOrder` pull row. While off: every `/connectors/bikebook/*` and `/oauth/*` route
 * answers 404, no `WorkshopOrder` row or `workshop.connector_status` setting enters any pull, no webhook is made or
 * sent, the admin and tenant cards are hidden. Direct mode (the till talks to BikeBook with its own key) needs none
 * of this and is never affected.
 */
return [
    'connector' => (bool) env('WORKSHOP_CONNECTOR', false),

    // When set, WorkshopOrder rows go only to tills whose X-SSPOS-App-Version is at least this; empty = every till.
    'connector_min_till' => (string) env('WORKSHOP_CONNECTOR_MIN_TILL', ''),

    'bikebook' => [
        // Our OAuth client for BikeBook (owner to-do: register with BikeBook). The secret is never logged.
        'client_id' => (string) env('BIKEBOOK_CLIENT_ID', ''),
        'client_secret' => (string) env('BIKEBOOK_CLIENT_SECRET', ''),
        // Exact redirect URIs BikeBook gives us, comma separated; anything else is refused.
        'redirect_uris' => array_values(array_filter(array_map('trim', explode(',', (string) env('BIKEBOOK_REDIRECT_URIS', 'https://workshop.bikebook.co.uk'))))),
        'access_token_seconds' => 86400,
        'refresh_token_days' => 365,
        'code_seconds' => 600,
    ],

    // WORKSHOP-BIKEBOOK.md §7: per connection.
    'requests_per_minute' => (int) env('WORKSHOP_CONNECTOR_PER_MINUTE', 600),
    'max_page_size' => 500,
    'default_page_size' => 100,
    'max_order_lines' => 200,
    'max_body_bytes' => 1048576,

    // Failed sign-ins (bad token or key) per IP before 15 minutes of 401s.
    'failed_auth_per_ip' => 30,

    'webhooks' => [
        // Waits after each failed attempt: 1 min, 5 min, 15 min, 1 h, 4 h; then the delivery is failed (Replay).
        'retry_minutes' => [1, 5, 15, 60, 240],
        'connect_timeout' => 5,
        'timeout' => 10,
        'job_timeout' => 30,
        'lock_seconds' => 60,
        // inventory.updated: at most one per connection every this many seconds (changes in between are merged).
        'inventory_debounce_seconds' => 30,
    ],

    // Call log and webhook deliveries kept per connection.
    'log_keep' => 1000,
];
