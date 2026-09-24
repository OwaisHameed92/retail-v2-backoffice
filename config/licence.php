<?php

/*
|--------------------------------------------------------------------------
| Licensing (modules 1.3 licences, 1.4 token signing, 1.5 licence API)
|--------------------------------------------------------------------------
|
| See docs/specs/licence-api-v1.md and docs/specs/licence-token-verification.md. The issuer and token type
| are baked into the till's verifier: changing them breaks every installed till.
|
*/

return [

    // "iss" claim written into and required on every licence token.
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
