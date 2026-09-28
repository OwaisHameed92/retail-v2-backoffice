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

];
