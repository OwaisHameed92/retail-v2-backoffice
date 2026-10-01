<?php

/*
|--------------------------------------------------------------------------
| Browser security headers and support sessions (security review 7.3, M4 and M5)
|--------------------------------------------------------------------------
|
| SecurityHeaders (web group) sends X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy,
| a Content-Security-Policy on HTML pages (with a per-request nonce for Vite, Ziggy and the theme script) and HSTS
| on HTTPS requests. Trusted proxies live in config/trustedproxy.php (TRUSTED_PROXIES).
|
*/

return [

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),

        // Send Content-Security-Policy-Report-Only instead (try a policy change without breaking pages).
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),

        // Extra origins allowed for scripts, frames and connections (the Turnstile widget on /trial).
        'script_hosts' => ['https://challenges.cloudflare.com'],
        'frame_hosts' => ['https://challenges.cloudflare.com'],
        'connect_hosts' => ['https://challenges.cloudflare.com'],

        // Web fonts (resources/views/app.blade.php).
        'font_hosts' => ['https://fonts.bunny.net'],
    ],

    'hsts' => [
        // Seconds; 0 turns HSTS off. Only sent on HTTPS requests (behind a proxy: set TRUSTED_PROXIES).
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),
    ],

    // Two-factor sign-in. Off for now (owner, 2026-10-01): the screens and data stay, nothing is asked or enforced.
    // Turn on with TWO_FACTOR_ENABLED=true (tests run with it on, see phpunit.xml).
    'two_factor' => [
        'enabled' => (bool) env('TWO_FACTOR_ENABLED', false),
    ],

    // Admin "Login as customer" (M5): the support session ends by itself after this many minutes.
    'impersonation_minutes' => max(1, (int) env('IMPERSONATION_MINUTES', 60)),

];
