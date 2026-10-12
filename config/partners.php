<?php

/*
 * Partner onboarding API v1 (docs/partner-api.md): Zivaro → retail portal.
 */
return [
    // Requests per minute per partner key (own limiter, never shared with the till or public throttles).
    'rate_limit_per_minute' => (int) env('PARTNER_API_RATE_LIMIT', 60),

    // Failed Bearer checks before a lockout (per IP and per presented key), and the lockout window.
    'failed_auth' => [
        'per_ip' => (int) env('PARTNER_API_FAILED_AUTH_PER_IP', 30),
        'per_key' => (int) env('PARTNER_API_FAILED_AUTH_PER_KEY', 10),
        'window_seconds' => 900,
    ],

    // Last use of a key is written at most this often.
    'touch_every_seconds' => 60,

    'webhooks' => [
        // HTTP timeouts per attempt; the job's own timeout stays below the queue's retry_after (90 s).
        'connect_timeout' => 5,
        'timeout' => 10,
        'job_timeout' => 30,
        // Retried with exponential backoff (first retry after `first_retry_seconds`, doubling, at most
        // `max_retry_seconds` apart) until `give_up_after_hours` after the event was made.
        'first_retry_seconds' => 60,
        'max_retry_seconds' => 3600,
        'give_up_after_hours' => 24,
        // A claimed event is left alone this long by other workers.
        'lock_seconds' => 60,
    ],
];
