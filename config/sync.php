<?php

/*
|--------------------------------------------------------------------------
| Till sync (Phase 2)
|--------------------------------------------------------------------------
|
| Contract v1.3.3 docs/web-portal-api.md §2–4, SIMPLE-SETUP.md. The till has our address built in; `hub_url` is
| sent to it (`hubUrl` in licence/activate and licence/validate replies, with a sync key) only when sync runs on
| another host than APP_URL. Must be https.
|
*/

return [

    'hub_url' => env('SYNC_HUB_URL'),

    // Sync keys: a key's last use is written at most this often (seconds), not on every push.
    'touch_every_seconds' => (int) env('SYNC_KEY_TOUCH_SECONDS', 60),

    // Hello and push (module 2.2, contract §3, §4.1, §7, §17.12).
    'push' => [
        // Rows one push may carry; sent as hello's maxBatchRows (the contract ceiling is 5,000).
        'max_rows' => min(5000, (int) env('SYNC_PUSH_MAX_ROWS', 5000)),
        // Largest body after gunzip (§3: allow 50 MB). Larger → 413 batch.too_large.
        'max_bytes' => (int) env('SYNC_PUSH_MAX_BYTES', 50 * 1024 * 1024),
        // PHP memory for one push: a 50 MB JSON body decodes to several hundred MB of arrays.
        'memory_limit' => env('SYNC_PUSH_MEMORY_LIMIT', '1024M'),
        // Per-branch lock: how long a push may hold it, and how long a second push waits before 503 server.busy.
        'lock_seconds' => (int) env('SYNC_PUSH_LOCK_SECONDS', 120),
        'lock_wait_seconds' => (int) env('SYNC_PUSH_LOCK_WAIT_SECONDS', 10),
        'busy_retry_after' => (int) env('SYNC_PUSH_BUSY_RETRY_AFTER', 5),
        // Idempotency-Key replies are kept this long (§17.11 rule 5: at least 24 hours).
        'idempotency_hours' => max(24, (int) env('SYNC_PUSH_IDEMPOTENCY_HOURS', 24)),
    ],

    // Requests per minute per sync key (hello + push + pull). A normal run every 30 s is a handful of calls and an
    // initial upload sends back to back; §17.12 asks for never less than one per 5 seconds.
    'rate_limit_per_minute' => max(12, (int) env('SYNC_RATE_LIMIT_PER_MINUTE', 240)),

];
