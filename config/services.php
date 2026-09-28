<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Cloudflare Turnstile for the public trial form (module 1.10). The site key is public (shown on /trial); the
    // secret is server-side only.
    'turnstile' => [
        'site_key' => (string) env('TURNSTILE_SITE_KEY', ''),
        'secret' => (string) env('TURNSTILE_SECRET', ''),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    // GoCardless Direct Debit (module 1.12). Empty token = Direct Debit off (billing works as cash only). The
    // webhook secret signs POST /webhooks/gocardless. Environment: sandbox or live.
    'gocardless' => [
        'access_token' => (string) env('GOCARDLESS_ACCESS_TOKEN', ''),
        'environment' => env('GOCARDLESS_ENVIRONMENT', 'sandbox') === 'live' ? 'live' : 'sandbox',
        'webhook_secret' => (string) env('GOCARDLESS_WEBHOOK_SECRET', ''),
    ],

];
