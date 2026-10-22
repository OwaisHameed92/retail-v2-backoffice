<?php

// Handheld (PDA) API, phase 1 (docs/pda-api.md). Named limiters (HandheldRateLimits), not the framework `throttle`.
return [
    // `POST /api/pda/login`: attempts per minute for one email from one IP, and for one IP whatever the email.
    'login_per_minute' => (int) env('HANDHELD_LOGIN_PER_MINUTE', 10),
    'login_per_ip_per_minute' => (int) env('HANDHELD_LOGIN_PER_IP_PER_MINUTE', 60),

    // Every other call: requests per minute per token (a scanner can fire a lookup per scan).
    'api_per_minute' => (int) env('HANDHELD_API_PER_MINUTE', 240),

    // A token's `last_used_at` is written at most this often.
    'touch_every_seconds' => 60,

    // Page sizes of the lists (`per_page`).
    'per_page' => 25,
    'max_per_page' => 100,

    // Stock documents drafted from the handheld (stock adjustments, stock takes, goods-in, purchase returns): contract
    // proposal docs/contracts/proposals/pda-stock-drafts. OFF until the till supports it: while off every stock call
    // answers 501 NOT_AVAILABLE_YET and nothing new enters any till's pull. `stock_drafts_min_till`: when set, drafts
    // go only to tills whose X-SSPOS-App-Version is at least this (older tills keep them waiting); empty = every till.
    'stock_drafts' => (bool) env('PDA_STOCK_DRAFTS', false),
    'stock_drafts_min_till' => (string) env('PDA_STOCK_DRAFTS_MIN_TILL', ''),
];
