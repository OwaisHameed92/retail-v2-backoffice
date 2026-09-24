# Licence API v1 (draft)

Status: draft, sent to the EPOS team for agreement (2026-09-24). Full shared version with diagrams lives in the
"SSPOS Licence API v1 (draft)" doc. This file is the build reference for module M1.5. **Built in module 1.5**:
see "As built (module 1.5)" at the end for status codes, exact behaviour and the sample files.

## Rules

- One licence key per register (till). First activation binds it to the PC's `deviceId`.
- Separate from the sync API; works before Cloud sync is on. HTTPS only. No auth header: key + deviceId in body.
- Headers: `X-SSPOS-Licence-Contract: 1`, `X-SSPOS-App-Version`. JSON conventions as the sync contract.
- Rate limit: 10/min per key, 30/min per IP → 429 with `retryAfterSeconds`.
- Key format `SSP-XXXX-XXXX-XXXX-XXXX`: 16 Crockford base32 chars, last one a check character. Stored as hash
  + last 4. Normalised (upper-case, no spaces/dashes) before hashing. Full rules and test vectors: "Key format" below.

## Endpoints

| Method + path | Body | Reply |
|---|---|---|
| `POST /api/v1/licence/activate` | `licenceKey, deviceId, deviceName, appVersion, os, requestedAt` | `licence, token, company, branch, register, sync, checkInEverySeconds, serverTimeUtc` |
| `POST /api/v1/licence/check-in` | `licenceKey, deviceId, appVersion, tokenId, lastSaleAt, requestedAt` | `licence, token, message, checkInEverySeconds, serverTimeUtc` |
| `POST /api/v1/licence/deactivate` | `licenceKey, deviceId` | `{ released: true }` |
| `GET /api/v1/licence/keys` | — | JWKS `{ keys: [{kid, kty:"OKP", crv:"Ed25519", x, use:"sig"}] }` |

- Activate by the already-bound device returns the same reply (reinstall). Activate by another device → 409.
- `licence` object: `id, keyLast4, status, plan, features[], activatedAt, trialEndsAt, expiresAt, graceDays, deviceId`.
- `company`/`branch`/`register`: same ids and field names as the sync entities (Company, Branch, Register).
- `sync` (proposal): `{ hubUrl, apiKey }` for a main or single till, else `null`.
- Check-in on suspended/expired/revoked returns **200** with that status and a signed token.

## Token

JWS compact, header `{alg:"EdDSA", kid, typ:"sspos-licence+jwt"}`. Claims:
`iss:"sspos-portal", jti, lic, keyLast4, companyId, branchId, registerId, deviceId, status, plan, features,
expiresAt, graceDays, iat, validUntil`.

`validUntil = min(issuedAt + 14 days, expiresAt + graceDays)`. Private key never leaves the portal; rotate by
adding a new `kid`.

## Statuses

`issued` (never activated) → `trial` | `active` → `grace` → `expired`; admin: `suspended`, `revoked`.
Trial: 7 days from first activation, 3 grace days. Paid: 7 grace days.

## Errors (non-2xx body `{code, message, traceId, retryAfterSeconds, rejectedKey}`)

| Status | code |
|---|---|
| 400 | `request.invalid` |
| 404 | `licence.not_found` |
| 409 | `licence.bound_to_other_device` (activate) |
| 403 | `licence.device_mismatch` (check-in from non-bound device) |
| 403 | `licence.revoked` |
| 403 | `licence.not_activatable` (activate on expired/suspended) |
| 409 | `contract.unsupported` |
| 429 | `rate_limited` |

## Key format (built in module 1.3)

The till can check a typed key offline before calling `activate`, so a typo gets "check the key" at once
instead of a round trip and a 404.

**Alphabet** (Crockford base32, 32 symbols, no I, L, O, U), value = position:

```
0123456789ABCDEFGHJKMNPQRSTVWXYZ
```

`0`=0 … `9`=9, `A`=10, `B`=11, … `H`=17, `J`=18, `K`=19, `M`=20, `N`=21, `P`=22, `Q`=23, `R`=24, `S`=25, `T`=26,
`V`=27, `W`=28, `X`=29, `Y`=30, `Z`=31.

**Shape:** `SSP-` + 4 groups of 4 = 16 characters. Characters 1–15 are random (15 random bytes, low 5 bits of
each); character 16 is the check character.

**Normalising what a person typed** (do this before checking or sending):

1. Upper-case.
2. Remove spaces, tabs, `-`, `_` and `.`.
3. Map `O` → `0`, `I` → `1`, `L` → `1`.
4. If the result is 19 characters and starts with `SSP`, drop the `SSP`.
5. It must now be exactly 16 alphabet characters (a `U` or any other symbol is invalid).

**Check character: Luhn mod 32** (the "Luhn mod N" algorithm with N = 32):

```text
function checkCharacter(payload15):            // the first 15 characters
    factor = 2; sum = 0
    for i from 14 down to 0:                   // right to left
        addend = factor * value(payload15[i])
        sum   += (addend div 32) + (addend mod 32)
        factor = (factor == 2) ? 1 : 2
    return ALPHABET[(32 - (sum mod 32)) mod 32]

function isValid(body16):                      // normalised 16 characters
    factor = 1; sum = 0
    for i from 15 down to 0:                   // the check character is not doubled
        addend = factor * value(body16[i])
        sum   += (addend div 32) + (addend mod 32)
        factor = (factor == 2) ? 1 : 2
    return sum mod 32 == 0
```

It catches every single wrong character and every swap of two neighbours except `0Z` ↔ `Z0`.

Worked example, payload `7K2Q9DMF3XRAP8T` (right to left, factor starts at 2):

| Char | T | 8 | P | A | R | X | 3 | F | M | D | 9 | Q | 2 | K | 7 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Value | 26 | 8 | 22 | 10 | 24 | 29 | 3 | 15 | 20 | 13 | 9 | 23 | 2 | 19 | 7 |
| Factor | 2 | 1 | 2 | 1 | 2 | 1 | 2 | 1 | 2 | 1 | 2 | 1 | 2 | 1 | 2 |
| Addend (div + mod) | 21 | 8 | 13 | 10 | 17 | 29 | 6 | 15 | 9 | 13 | 18 | 23 | 4 | 19 | 14 |

Sum = 219, 219 mod 32 = 27, (32 − 27) mod 32 = 5 → check character `5` → key `SSP-7K2Q-9DMF-3XRA-P8T5`.

**Test vectors** (also in the portal's tests, `tests/Unit/Licensing/LicenceKeyTest.php`):

| First 15 | Check | Key | Valid? |
|---|---|---|---|
| `7K2Q9DMF3XRAP8T` | `5` | `SSP-7K2Q-9DMF-3XRA-P8T5` | yes |
| `4HWCJ6ZB81MEQV5` | `H` | `SSP-4HWC-J6ZB-81ME-QV5H` | yes |
| `2NRXT7KP5G0ADYF` | `6` | `SSP-2NRX-T7KP-5G0A-DYF6` | yes |
| `123456789ABCDEF` | `8` | `SSP-1234-5678-9ABC-DEF8` | yes |
| `0123456789ABCDE` | `Z` | `SSP-0123-4567-89AB-CDEZ` | yes |
| `000000000000000` | `0` | `SSP-0000-0000-0000-0000` | yes |
| | | `SSP-7K2Q-9DMF-3XRA-P8T6` | no (check character) |
| | | `SSP-7K2Q-9DMF-3XRA-8PT5` | no (swapped neighbours) |
| | | `SSP-7K2Q-9DMF-3XRA-P8TU` | no (`U` is not in the alphabet) |
| | | `ssp 7k2q 9dmf 3xra p8t5` | yes (normalises to the first row) |
| | | `SSP-OI23-4567-89AB-CDEZ` | yes (`O`→`0`, `I`→`1`: the fifth row) |

**Portal storage:** `key_hash` = HMAC-SHA256 (hex) of the normalised 16 characters, keyed with the portal's
APP_KEY (previous APP_KEYs are also tried, so a key rotation does not lose licences); `key_last4` = the last 4
characters. The plain key exists only in the reply that created it and in the welcome / "new licence key"
emails. Admin screens show `SSP-••••-••••-••••-P8T5`.

## Statuses as the portal computes them (module 1.3)

`App\Domain\Licensing\LicenceState::for($licence, $now)` is what `activate` / `check-in` must report:

1. stored `revoked` → `revoked`; stored `suspended` → `suspended` (staff reason);
2. company suspended, cancelled or deleted → `suspended`;
3. branch or till deactivated (or deleted) → `suspended`;
4. otherwise the dates: never activated → `issued`; paid (`expiresAt` set) → `active` until `expiresAt`, then
   `grace` for `graceDays`, then `expired`; trial → `trial` until `trialEndsAt`, then `grace` (the plan's trial
   grace days), then `expired`.

`graceDays` in the reply is the grace that applies now (trial grace during a trial, paid grace once renewed).
`expiresAt` in the token is the paid expiry, or the trial end while on trial. The reply's `features` are the
licence's own copy of its plan's features (changed only by "Change plan").

## Admin actions that affect the API

Issue key, reset device, suspend, unsuspend, revoke, renew (moves `expiresAt`), change plan/features.
Every action is audit-logged. Same key checking in from two deviceIds raises an admin alert.

What each one means for the API (built in module 1.3, `app/Domain/Licensing/Actions`):

| Admin action | Effect the API must honour |
|---|---|
| Issue (`IssueLicence`) | New licence, status `issued`, no device. First `activate` binds the PC and starts the trial. |
| Reissue key (`ReissueKey`) | New key; the old key is gone (`licence.not_found`). Device binding cleared. Status and dates kept. |
| Reset device (`ResetDevice`) | Same key, device binding cleared: the next `activate` from any PC binds it. |
| Suspend / unsuspend | `suspended` with the staff reason; unsuspend returns to what the dates say. |
| Revoke (`RevokeLicence`) | Final. `activate` → 403 `licence.revoked`; check-in → 200 with status `revoked`. |
| Renew (`RenewLicence`) | Sets `expiresAt` (end of day, Europe/London) and paid `graceDays`; trial becomes `active`. |
| Change plan | New `plan` and `features` at the next check-in. |
| Till deactivated (tenant screen) | Licence suspended with reason "Till deactivated"; reactivating the till lifts it. |

## As built (module 1.5)

Portal code: `app/Http/Controllers/Api/LicenceApiController.php` (thin) → `app/Domain/Licensing/Api/`
(`ActivateLicence`, `CheckInLicence`, `DeactivateLicence`). Samples made from real replies:
`docs/specs/licence-api-samples/` (regenerated by `tests/Feature/Licensing/Api/LicenceApiSamplesTest.php`, which
fails when a reply's shape drifts from them).

### Request rules

- JSON body only (`Content-Type: application/json`). **Any query string is refused** with 400 `request.invalid`,
  so a key can never end up in a URL or an access log.
- `X-SSPOS-Licence-Contract: 1` on every endpoint, `keys` included. Missing or another value → 409
  `contract.unsupported`. Replies echo `X-SSPOS-Licence-Contract: 1`, carry `X-Trace-Id` and
  `Cache-Control: no-store, private`.
- `X-SSPOS-App-Version` is recorded as the till's app version (the body's `appVersion` is the fallback).
- Field limits: `licenceKey` string ≤ 64, `deviceId` string ≤ 191 (required), `deviceName` ≤ 191, `appVersion` ≤ 50,
  `os` ≤ 100, `tokenId` ≤ 64, `requestedAt` / `lastSaleAt` ISO-8601. `requestedAt`, `tokenId` and `lastSaleAt`
  are accepted for logs and later use; the portal clock decides.
- The key is normalised as in "Key format". A key that fails the format or check character gets the same 404
  `licence.not_found` as an unknown key (the till should catch typos offline first).
- Rate limits: 30 requests/minute per IP on all four endpoints, and 10/minute per key (every spelling of one key
  shares a bucket). Every request counts, errors included. Over → 429 `rate_limited`, `retryAfterSeconds` and a
  `Retry-After` header.

### Status codes

| Endpoint | 200 | Errors |
|---|---|---|
| `activate` | First activation, rebind after "Reset PC" or `deactivate`, or reinstall on the bound PC | 400 `request.invalid`; 404 `licence.not_found`; 409 `licence.bound_to_other_device`; 403 `licence.revoked`; 403 `licence.not_activatable`; 409 `contract.unsupported`; 429 `rate_limited` |
| `check-in` | Bound PC, **any status** (trial, active, grace, expired, suspended, revoked): status in the signed token | 400; 404; 403 `licence.device_mismatch`; 409 `contract.unsupported`; 429 |
| `deactivate` | Bound PC (binding cleared), or nothing bound (idempotent) | 400; 404; 403 `licence.device_mismatch` (another PC); 403 `licence.revoked`; 409; 429 |
| `keys` | JWKS | 409 `contract.unsupported`; 429 |

### Behaviour

- **First activation** (`activatedAt` null): `activatedAt` = now. A trial gets `trialEndsAt` = now + plan
  `trial_days` (7) and `graceDays` = plan trial grace (3). A licence renewed before its first activation starts
  `active` with the paid grace (7) and no trial. A trial company with no trial end yet takes this date as its
  trial end.
- **Binding** (not bound: first activation, after "Reset PC" or after `deactivate`): only when the licence can
  trade now (trial, active or grace). Suspended (by staff, business, branch or till) or expired → 403
  `licence.not_activatable` with the reason in `message`. Dates are never restarted by a rebind.
- **Reinstall** (activate from the bound PC): 200 with the full reply **whatever the status**, so a reinstalled
  till gets its company/branch/till rows and a signed token saying e.g. `suspended`. (The spec's
  `licence.not_activatable` applies only when a PC would be newly bound.)
- **Another PC**: activate → 409 `licence.bound_to_other_device`; check-in → 403 `licence.device_mismatch`. Both
  raise an admin alert. After "Reset PC" (nothing bound) the old PC's check-in gets 403 with the message "This till
  needs to be activated again…" and no alert.
- **Reissued key**: the old key → 404 `licence.not_found` like any unknown key. If the PC that was bound when the key
  was replaced keeps using it, staff get a `reissuedKeyUsed` alert.
- **Revoked**: activate and deactivate → 403 `licence.revoked`; check-in → 200 with status `revoked`.
- `message` (check-in): trial → "Free trial until 1 October 2026."; active → `null`; grace → when the till stops;
  expired / suspended / revoked → the reason (the staff suspension reason included) + "Please contact Switch &
  Save support.". Dates in messages are UK dates (Europe/London); every date field is UTC `Z`.

### Reply details

- `licence.status` and the token's `status` are the **effective** status (licence + business + branch + till).
- `licence.expiresAt` = paid expiry (null during a trial; `trialEndsAt` has the trial end). The token's
  `expiresAt` = paid expiry, or the trial end while on trial. `graceDays` = the grace that applies now.
- Token claims, in order: `iss, iat, jti, lic, keyLast4, companyId, branchId, registerId, deviceId, status, plan,
  features, expiresAt, graceDays, validUntil`. `iat` is Unix seconds; `expiresAt` and `validUntil` are ISO-8601
  UTC strings. `validUntil = min(iat + 14 days, expiresAt + graceDays)` (exact 24 h days).
- `company`, `branch`, `register`: the till's entity field names with the envelope fields (`id, companyId,
  createdAt, updatedAt, rowVersion, deletedAt, isDeleted, domainEvents`). Nulls are written. Till-owned counters
  (`nextPoNo`, `nextSaleNo`, `nextRefundNo`) are **not sent**: the till keeps its own. `rowVersion` is `0`: the
  sync pull feed (module 2.5) sends the real versions later and always wins. `areaM2` is a number or null.
- `sync` is always present and `null` until module 2.1 (sync keys) fills `{hubUrl, apiKey}` for the main till.
- `checkInEverySeconds` = 86400 (config `licence.check_in_seconds`).

### Samples (`docs/specs/licence-api-samples/`)

Signed with the public RFC 8037 test key under the fake kid `lk-test-01` (TEST ONLY; same key as
`licence-token-verification.md`), so the tokens verify with `keys-reply.json`. Key `SSP-7K2Q-9DMF-3XRA-P8T5` is a
spec test vector.

| File | What |
|---|---|
| `activate-request.json` / `activate-reply.json` | First activation, 7-day trial |
| `check-in-request.json` / `check-in-reply.json` | Next day, trading (trial) |
| `check-in-reply.suspended.json` | Suspended by staff: 200, `status: suspended`, reason in `message` |
| `check-in-reply.grace.json` | Trial ended, in its 3 grace days |
| `deactivate-request.json` / `deactivate-reply.json` | Release from the bound PC |
| `keys-reply.json` | JWKS |
| `error-request-invalid.json` | 400, missing `deviceId` |
| `error-licence-not-found.json` | 404, unknown key |
| `error-licence-bound-to-other-device.json` | 409, activate from a second PC |
| `error-licence-device-mismatch.json` | 403, check-in from a second PC |
| `error-licence-not-activatable.json` | 403, business suspended, activate on an unbound licence |
| `error-licence-revoked.json` | 403, activate on a revoked licence |
| `error-contract-unsupported.json` | 409, `X-SSPOS-Licence-Contract: 2` |
| `error-rate-limited.json` | 429, 11th request in a minute for one key |

### Try it

`php artisan licence:simulate SSP-XXXX-XXXX-XXXX-XXXX --action=activate|check-in|deactivate --device=DEMO-PC-1
[--name=FRONT-TILL] [--url=https://portal.example]` calls the real endpoints (default `APP_URL`), prints the reply,
verifies the token with the public JWKS like a till (header, kid, signature, `iss`, `deviceId`, `validUntil`) and
says what the till would do: trade, trade with a grace banner, or lock.

### Open points for the EPOS team

1. Confirm the status codes above, especially: reinstall on the bound PC answers 200 whatever the status; check-in
   answers 403 `licence.device_mismatch` (not 409) when the PC is not bound; deactivate on a revoked licence is 403.
2. The activate reply has no `message`; a till reinstalled on a suspended licence should check in right after to get
   the reason (or we add `message` to activate: say which you prefer).
3. `company`/`branch`/`register` leave out the till-owned counters and send `rowVersion: 0`. Please make sure the
   till's apply does not reset its counters and treats these rows as the first copy.
4. String fields that are empty on the portal are sent as `null` (the schemas say `string`).
5. `deviceId`: please send a stable, per-install-independent machine id (the same after a reinstall), ≤ 191 chars.
