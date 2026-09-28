<?php

/*
|--------------------------------------------------------------------------
| Licensing (modules 1.3 licences, 1.4 token signing, 1.5 licence API)
|--------------------------------------------------------------------------
|
| Contract v1.3.1 (docs/contracts/portal-api-v1.3.1/docs/web-portal-api.md §17.2, §17.17): SSPOS1 tokens
| signed by SsposTokenSigner with `token`, `approvers`, `trusted_keys` and `allow_uncertified` below.
|
| `issuer`, `token_type`, `offline_days` and `check_in_seconds` belong to the old JWS format
| (docs/specs/licence-token-verification.md, superseded); they go when module 1.5 switches to SSPOS1.
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

    // Old JWS format only: "iss" claim written into and required on every licence token.
    'issuer' => env('LICENCE_TOKEN_ISSUER', 'sspos-portal'),

    // JWS header "typ".
    'token_type' => 'sspos-licence+jwt',

    // A till may run this many days without a successful check-in: validUntil = min(iat + offline_days,
    // expiresAt + graceDays).
    'offline_days' => (int) env('LICENCE_OFFLINE_DAYS', 14),

    // How often the till should check in (returned as checkInEverySeconds).
    'check_in_seconds' => (int) env('LICENCE_CHECK_IN_SECONDS', 86400),

    // Module 1.3: plan code used for new tills when their company has no plan of its own. If that plan is
    // missing, archived or inactive, the first active plan (by sort order) is used.
    'default_plan' => env('LICENCE_DEFAULT_PLAN', 'standard'),

    'signing_keys' => [
        // A retired key still verifies (and stays in the JWKS) for this many days, then licence:keys:prune
        // deletes it. Must stay well above offline_days so tokens signed just before a rotation keep working.
        'retired_keep_days' => (int) env('LICENCE_RETIRED_KEY_KEEP_DAYS', 60),
    ],

];
