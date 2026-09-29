<?php

/*
| Module 2.7 (Till health). Thresholds for online / stale / offline and for the alerts raised by
| `till-health:refresh` (scheduled every 5 minutes). Contract v1.4.1 DASHBOARD.md §3: a till validates its licence
| once a day ("alive" = validated within 26 h, "offline" = 3 days); the branch's main till syncs every few seconds
| to minutes ("red" = no contact for 15 minutes in opening hours). Times are Europe/London.
*/

return [
    // The main till that syncs the shop: online when it synced (hello, push or pull) within this many minutes.
    'sync_online_minutes' => (int) env('TILL_HEALTH_SYNC_ONLINE_MINUTES', 15),

    // A syncing till is offline when it has not synced for this long AND has not validated its licence within
    // `validate_online_hours` (the PC is off or has no internet).
    'sync_offline_hours' => (int) env('TILL_HEALTH_SYNC_OFFLINE_HOURS', 4),

    // Any other till only validates once a day: online within this many hours, offline after `validate_offline_hours`.
    'validate_online_hours' => (int) env('TILL_HEALTH_VALIDATE_ONLINE_HOURS', 26),
    'validate_offline_hours' => (int) env('TILL_HEALTH_VALIDATE_OFFLINE_HOURS', 72),

    // Till clock minus portal time, in seconds, beyond which the till's clock is flagged (either direction).
    'clock_skew_seconds' => (int) env('TILL_HEALTH_CLOCK_SKEW_SECONDS', 300),

    // Sync is failing when the last error (at or after the last push) happened within this many hours.
    'sync_failing_hours' => (int) env('TILL_HEALTH_SYNC_FAILING_HOURS', 24),

    // Sync has stalled when the main till validated within `validate_online_hours` (it is on and online) but has
    // not synced for this many hours, or the queue it reports grows between two check-ins.
    'sync_stalled_hours' => (int) env('TILL_HEALTH_SYNC_STALLED_HOURS', 24),

    // A "Till offline" alert is raised once an offline till has been silent for this many trading hours, and only
    // while the shops are trading (below). It clears itself when the till is back.
    'alert_offline_hours' => (int) env('TILL_HEALTH_ALERT_OFFLINE_HOURS', 4),

    // Trading hours used for the offline alert until shops' own opening hours arrive (module 5.9).
    'trading_hours' => [
        'start' => env('TILL_HEALTH_TRADING_START', '08:00'),
        'end' => env('TILL_HEALTH_TRADING_END', '20:00'),
    ],

    'timezone' => 'Europe/London',

    // How often the scheduler refreshes the health rows (minutes); shown on the screens.
    'refresh_minutes' => 5,
];
