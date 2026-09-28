<?php

/*
|--------------------------------------------------------------------------
| Licensing (modules 1.3 licences, 1.4 token signing, 1.5 licence API)
|--------------------------------------------------------------------------
|
| Contract v1.3.1 (docs/contracts/portal-api-v1.3.1/docs/web-portal-api.md §17.2, §17.15, §17.17): SSPOS1 tokens
| signed by SsposTokenSigner with `token`, `approvers`, `trusted_keys` and `allow_uncertified` below; the per-till
| licence API (`licence/activate`, `licence/validate`, `devices/deactivate`) reads `api` and `till_features`.
|
*/

/**
 * "kid:base64urlPublicKey,kid2:…" from an env var into [kid => key].
 *
 * @return array<string, string>
 */
$keyList = static function (?string $value): array {
    $keys = [];

    foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $pair) {
        [$kid, $key] = array_pad(explode(':', $pair, 2), 2, '');

        if ($kid !== '' && $key !== '') {
            $keys[trim($kid)] = trim($key);
        }
    }

    return $keys;
};

return [

    // SSPOS1 licence token payload defaults (§17.2).
    'token' => [
        // Shown on the till's licence screen (payload `issuer`, max 80 characters).
        'issuer' => env('LICENCE_ISSUER', 'SSPOS Portal'),

        // Payload `onlineCheck` for cloud licences. The policy travels in each token.
        'online_check' => [
            'required' => true,
            'interval_hours' => (int) env('LICENCE_ONLINE_CHECK_HOURS', 24),
            'grace_days' => (int) env('LICENCE_ONLINE_CHECK_GRACE_DAYS', 14),
        ],
    ],

    // Approvers whose signer certificates we accept (§17.17): kid => base64url raw Ed25519 public key. Today
    // one: the owner's key generator. LICENCE_APPROVERS="k1234abcd:<43 chars>".
    'approvers' => $keyList(env('LICENCE_APPROVERS')),

    // The owner's licence-generator public keys, to verify local tokens in redeem/migrate (§17.2 "Keys"):
    // kid => base64url raw public key. LICENCE_TRUSTED_KEYS="k1234abcd:<43 chars>,…". We never sign with them.
    'trusted_keys' => $keyList(env('LICENCE_TRUSTED_KEYS')),

    // TEST VECTORS ONLY: the approver from licensing/samples/signer-certificate.worked-example.json. Its
    // private seed is public, so it is never read by application code; tests copy it into `approvers`.
    'documentation_test_approvers' => [
        'k76b44696' => 'Pk-8h6RROQQEGjtudGbtnlnHM9JmBQac5JttMIBxMZU',
    ],

    // Sign with a key that has no signer certificate yet (logs a warning; tills refuse such tokens). Only
    // for local development and tests.
    'allow_uncertified' => (bool) env('LICENCE_ALLOW_UNCERTIFIED', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

    // "contact" in the public-key hand-over (licence:keys:handover).
    'handover_contact' => env('LICENCE_HANDOVER_CONTACT', ''),

    // Licence API (module 1.5, contract §17.15).
    'api' => [
        // `expiring` instead of `active` when the token's expiresAt is this close (§17.5 step 2: 7 days).
        'expiring_days' => (int) env('LICENCE_EXPIRING_DAYS', 7),

        // nextCheckAfterSeconds: while trading, and while locked (expired, suspended, revoked, released).
        'next_check_seconds' => (int) env('LICENCE_NEXT_CHECK_SECONDS', 86400),
        'next_check_locked_seconds' => (int) env('LICENCE_NEXT_CHECK_LOCKED_SECONDS', 3600),

        // Tills below this X-SSPOS-App-Version get 426 app.update_required (null = any version).
        'minimum_app_version' => env('LICENCE_MINIMUM_APP_VERSION'),

        // Idempotency-Key replies are kept this long (§17.11 rule 5: at least 24 hours).
        'idempotency_hours' => 24,

        // §17.12 suggested limits. Wrong keys: per install id.
        'rate_limits' => [
            'activate_per_ip_per_hour' => 10,
            'validate_per_install_per_hour' => 60,
            'deactivate_per_install_per_hour' => 20,
            'wrong_keys_per_install' => 5,
            'wrong_keys_window_seconds' => 900,
        ],
    ],

    // Our plan features (App\Domain\Plans\Enums\Feature) → the till's feature names in the token (§17.2,
    // `^[a-z0-9]+([._-][a-z0-9]+)*$`, compared exactly). Only `multi_branch` is confirmed by the EPOS team
    // (src/SSPOS.Application/Ports/Feature.cs); the rest are our snake_case names until they confirm theirs.
    // A feature missing here is left out of the token.
    'till_features' => [
        'stockControl' => 'stock_control',
        'purchasing' => 'purchasing',
        'cashOffice' => 'cash_office',
        'accounts' => 'accounts',
        'staff' => 'staff',
        'customerOrders' => 'customer_orders',
        'newsDeliveries' => 'news_deliveries',
        'multiBranch' => 'multi_branch',
        'aiAssistant' => 'ai_assistant',
        'aiInsights' => 'ai_insights',
    ],

    // Module 1.11: an unused key must be activated within this many days of being issued (or reissued), else
    // licence/activate answers 410 key.expired. Staff can extend it or reissue the key.
    'activate_by_days' => (int) env('LICENCE_ACTIVATE_BY_DAYS', 30),

    // Module 1.3: plan code used for new tills when their company has no plan of its own. If that plan is
    // missing, archived or inactive, the first active plan (by sort order) is used.
    'default_plan' => env('LICENCE_DEFAULT_PLAN', 'standard'),

    'signing_keys' => [
        // A retired key still verifies for this many days, then licence:keys:prune deletes it. Keep it well
        // above the tokens' online-check grace so tokens signed just before a rotation keep verifying here.
        'retired_keep_days' => (int) env('LICENCE_RETIRED_KEY_KEEP_DAYS', 60),
    ],

];
