<?php

/*
| Module 3.1 (Reporting tables). The `rpt_*` tables are rebuilt from the raw till rows one (shop, trading day) at a
| time: see docs/reporting.md and contract v1.4.1 DASHBOARD.md §1.3, §4.
*/

return [
    // The shops' time zone. The trading day of a sale is the calendar date of `completedAt` here (DASHBOARD.md §1.3).
    'timezone' => env('REPORTS_TIMEZONE', 'Europe/London'),

    // Queue for the per-business rebuild job dispatched after a push (null = the default queue).
    'queue' => env('REPORTS_QUEUE'),

    // Trading days of one shop rebuilt together (one set of grouped queries and one transaction).
    'days_per_rebuild' => (int) env('REPORTS_DAYS_PER_REBUILD', 7),

    // Dirty days a rebuild job takes per pass, and passes per job (the scheduled sweep picks up the rest).
    'dirty_batch' => (int) env('REPORTS_DIRTY_BATCH', 500),
    'dirty_passes' => (int) env('REPORTS_DIRTY_PASSES', 20),

    // The minutely sweep re-queues days marked dirty more than this many minutes ago (a lost or failed job).
    'sweep_after_minutes' => (int) env('REPORTS_SWEEP_AFTER_MINUTES', 2),
];
