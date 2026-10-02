<?php

use App\Domain\Ai\Tools\GetCompanyOverview;
use App\Domain\Ai\Tools\Portal\DraftPurchaseOrder;
use App\Domain\Ai\Tools\Portal\FindProducts;
use App\Domain\Ai\Tools\Portal\GetCashVariances;
use App\Domain\Ai\Tools\Portal\GetCustomersOwing;
use App\Domain\Ai\Tools\Portal\GetProductSales;
use App\Domain\Ai\Tools\Portal\GetRefundsAndVoids;
use App\Domain\Ai\Tools\Portal\GetSales;
use App\Domain\Ai\Tools\Portal\GetStaffHours;
use App\Domain\Ai\Tools\Portal\GetStock;
use App\Domain\Ai\Tools\Portal\GetTillHealth;
use App\Domain\Ai\Tools\Portal\GetVatSummary;
use App\Domain\Ai\Tools\Portal\QueueShelfLabels;
use App\Domain\Ai\Tools\Portal\SuggestReorder;
use App\Domain\Ai\Tools\RenameBranch;

/*
|--------------------------------------------------------------------------
| AI (module 6.1)
|--------------------------------------------------------------------------
|
| Claude via the official Anthropic PHP SDK. With no ANTHROPIC_API_KEY every AI feature reports
| "not set up yet" instead of failing. See docs/ai.md.
|
*/

return [

    // Kill switch: false turns every AI feature off at once (they report "switched off").
    'enabled' => (bool) env('AI_ENABLED', true),

    'provider' => 'anthropic',

    'anthropic' => [
        'api_key' => (string) env('ANTHROPIC_API_KEY', ''),
        // Null = the SDK default (https://api.anthropic.com).
        'base_url' => env('ANTHROPIC_BASE_URL'),
        // Seconds. Enforced by the Guzzle transport (the SDK leaves timeouts to the transport).
        'timeout' => (float) env('AI_TIMEOUT', 120),
        'connect_timeout' => (float) env('AI_CONNECT_TIMEOUT', 10),
        // The SDK retries 408/409/429/5xx and connection errors with exponential backoff and honours retry-after.
        'max_retries' => (int) env('AI_MAX_RETRIES', 3),
        'initial_retry_delay' => 0.5,
        'max_retry_delay' => 8.0,
    ],

    /*
    | Model tiers. "default" for reasoning over tool data (the portal assistant), "fast" for cheap, high-volume
    | jobs (classification, summaries). Claude Sonnet 5 and Claude Haiku 4.5 (dated snapshot) by default; set
    | AI_MODEL / AI_FAST_MODEL to change them, and add the new id to `model_options` and `pricing`.
    */
    'models' => [
        'default' => env('AI_MODEL', 'claude-sonnet-5'),
        'fast' => env('AI_FAST_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    /*
    | Request options per model id:
    | - thinking: 'adaptive' or null (Haiku 4.5 has no adaptive thinking).
    | - effort: whether output_config.effort is accepted (it errors on Haiku 4.5).
    | - fallbacks: server-side refusal fallback (`fallbacks: "default"`, beta server-side-fallback-2026-07-01).
    */
    'model_options' => [
        // Sonnet 5: adaptive thinking (display omitted), effort accepted; no server-side `fallbacks: "default"`.
        'claude-sonnet-5' => ['thinking' => 'adaptive', 'effort' => true, 'fallbacks' => false],
        'claude-haiku-4-5-20251001' => ['thinking' => null, 'effort' => false, 'fallbacks' => false],
        'claude-opus-5' => ['thinking' => 'adaptive', 'effort' => true, 'fallbacks' => true],
        'claude-haiku-4-5' => ['thinking' => null, 'effort' => false, 'fallbacks' => false],
    ],

    /*
    | Per-feature settings. `model` is a tier from `models`; `effort` applies only where the model accepts it.
    */
    'features' => [
        'assistant' => ['model' => 'default', 'effort' => 'medium', 'max_tokens' => 16000],
        // Morning summary (6.3): AI_MORNING_SUMMARY_TIER=default to use AI_MODEL instead of the fast (Haiku) tier.
        'morningSummary' => ['model' => env('AI_MORNING_SUMMARY_TIER', 'fast'), 'effort' => null, 'max_tokens' => 4000],
        'reorderSuggestions' => ['model' => 'default', 'effort' => 'medium', 'max_tokens' => 16000],
        'invoiceImport' => ['model' => 'default', 'effort' => 'medium', 'max_tokens' => 16000],
        'anomalyAlerts' => ['model' => 'fast', 'effort' => null, 'max_tokens' => 4000],
        'adminAssistant' => ['model' => 'default', 'effort' => 'medium', 'max_tokens' => 16000],
    ],

    // Agent loop: most model calls in one user turn before we stop and say so.
    'max_steps' => (int) env('AI_MAX_STEPS', 8),

    // Tool results longer than this (characters of JSON) are cut before they are sent.
    'max_tool_result_chars' => 20000,

    // How many earlier messages of a conversation are sent back with a new question.
    'history_messages' => 40,

    // Proposed changes must be confirmed within this many minutes.
    'pending_action_ttl_minutes' => 15,

    // Retention (model:prune, daily): conversations, their messages and old proposals; usage rows (billing).
    'retention_days' => (int) env('AI_RETENTION_DAYS', 90),
    'usage_retention_months' => 24,

    /*
    | Cost. Anthropic list prices in USD per million tokens (5-minute cache writes = 1.25x input,
    | cache reads = 0.1x input). claude-opus-4-8 is listed because refusal fallbacks may be served by it.
    | Stored cost is pounds: tokens x price x usd_to_gbp. Check the rate now and then.
    */
    'usd_to_gbp' => (string) env('AI_USD_TO_GBP', '0.79'),
    'pricing' => [
        'claude-sonnet-5' => ['input' => '2.00', 'output' => '10.00', 'cache_write' => '2.50', 'cache_read' => '0.20'],
        'claude-haiku-4-5-20251001' => ['input' => '1.00', 'output' => '5.00', 'cache_write' => '1.25', 'cache_read' => '0.10'],
        'claude-opus-5' => ['input' => '5.00', 'output' => '25.00', 'cache_write' => '6.25', 'cache_read' => '0.50'],
        'claude-opus-4-8' => ['input' => '5.00', 'output' => '25.00', 'cache_write' => '6.25', 'cache_read' => '0.50'],
        'claude-haiku-4-5' => ['input' => '1.00', 'output' => '5.00', 'cache_write' => '1.25', 'cache_read' => '0.10'],
    ],

    /*
    | Monthly token budgets (input + cache writes + cache reads + output), per calendar month in Europe/London.
    | `plans` overrides the default by plan code, e.g. 'pro' => 5_000_000. 0 = no AI for that plan.
    | The plan must also include the AI feature: assist_questions (assistant), assist_invoice_scan (invoice import) or
    | assist (morning summary, reorder suggestions, anomaly alerts).
    */
    'budgets' => [
        'default_monthly_tokens' => (int) env('AI_MONTHLY_TOKENS', 2_000_000),
        'plans' => [],
        'admin_monthly_tokens' => (int) env('AI_ADMIN_MONTHLY_TOKENS', 5_000_000),
    ],

    // Tool classes offered to the model (filtered per user by audience and ability). Order does not matter:
    // the registry sorts by name so the prompt prefix stays byte-identical for caching.
    'tools' => [
        GetCompanyOverview::class,
        RenameBranch::class,
        // Portal assistant (module 6.2): read tools on the report queries, and one write (a draft order).
        GetSales::class,
        GetProductSales::class,
        GetRefundsAndVoids::class,
        GetStock::class,
        FindProducts::class,
        GetCustomersOwing::class,
        GetCashVariances::class,
        GetStaffHours::class,
        GetVatSummary::class,
        GetTillHealth::class,
        DraftPurchaseOrder::class,
        // Module 6.4: reorder suggestions as draft orders, and shelf labels (both preview-then-confirm).
        SuggestReorder::class,
        QueueShelfLabels::class,
    ],

    // Keys dropped from tool results and prompt data before they are sent (personal data the model does not need:
    // contact details, staff PINs and fob codes, pay rates). Matched ignoring case, `_`, `-` and spaces.
    // Secrets are removed separately by App\Domain\Shared\Support\Redactor.
    'redact_keys' => [
        'email', 'email_address', 'customer_email', 'billing_emails', 'bill_to_emails',
        'phone', 'phone_digits', 'customer_phone', 'mobile', 'telephone',
        'address', 'contact_name', 'postcode', 'dob', 'date_of_birth', 'ni_number',
        'pin', 'pin_hash_version', 'pin_hash_versioned', 'rfid',
        'pay_rate', 'rate_per_hour', 'hourly_rate', 'wage_rate', 'wage_rates',
        'device_id', 'ip', 'last_ip', 'user_agent',
    ],
];
