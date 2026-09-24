# Licence API v1 (draft)

Status: draft, sent to the EPOS team for agreement (2026-09-24). Full shared version with diagrams lives in the
"SSPOS Licence API v1 (draft)" doc. This file is the build reference for module M1.5.

## Rules

- One licence key per register (till). First activation binds it to the PC's `deviceId`.
- Separate from the sync API; works before Cloud sync is on. HTTPS only. No auth header: key + deviceId in body.
- Headers: `X-SSPOS-Licence-Contract: 1`, `X-SSPOS-App-Version`. JSON conventions as the sync contract.
- Rate limit: 10/min per key, 30/min per IP → 429 with `retryAfterSeconds`.
- Key format `SSP-XXXX-XXXX-XXXX-XXXX`: 16 Crockford base32 chars, last one a check character. Stored as hash
  + last 4. Normalised (upper-case, no spaces/dashes) before hashing.

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

## Admin actions that affect the API

Issue key, reset device, suspend, unsuspend, revoke, renew (moves `expiresAt`), change plan/features.
Every action is audit-logged. Same key checking in from two deviceIds raises an admin alert.
