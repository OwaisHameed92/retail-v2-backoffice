# Security review (2026-10-01, read-only)

Scope: all routes (web, auth, settings, app, admin, api, webhooks), tenancy, till APIs, webhooks, trial API,
impersonation, sessions/headers, dependencies. Route audit (`route:list`): every `/app` route has
`auth:web + verified + company`, and all but dashboard/switchers have `company.can:`. Every `/admin` route has
`auth:admin`, and all but dashboard/logout/impersonation-stop/email-keys have `can:` (email-keys checks in its
FormRequest). Secrets are HMAC/PBKDF2-hashed with last4, errors use the contract envelope, the gzip body is
inflated in streamed chunks with a cap, GoCardless uses `hash_equals` with event-id dedupe, and queued mails are
encrypted. **No Critical findings.**

## High

**H1. No 2FA for super admins.** `app/Http/Controllers/Admin/Auth/LoginController.php:19` accepts a password
only. An admin can impersonate any tenant, issue licence and sync keys, and change billing. *Fix:* make TOTP and
recovery codes mandatory on the `admin` guard. Also require a fresh password confirmation before impersonation
and key generation.

**H2. Till `licence/validate` and `devices/deactivate` authenticate by identifiers only.**
`app/Domain/Licensing/Api/ValidateLicence.php:49-75` checks `licenceId` + `installId`. `tokenSha256` is
required but is only used to decide whether to reissue, never compared with the stored `token_sha256`, although
the contract §17.15.2 says the till is identified by all three. When a rotation is pending or no key was ever
issued, `SyncKeyDelivery.php:92-101` hands the branch's **sync key** (`apiKey`) to the caller.
`DeactivateDevice.php:48` releases a licence and revokes/rotates the sync key given only `registerId` +
`installId`. *Impact:* anyone who learns two ULIDs (an ex-staff member, a leaked screenshot or log) gets full
sync read/write for the branch, or can knock a till offline. *Fix:* require `tokenSha256` to match the current
(or immediately previous) token hash before recording or delivering anything. Require a token hash or branch key
on deactivate.

## Medium

**M1. A one-shop manager bypasses the branch restriction on sync conflicts.**
`SyncConflictController.php:29-46`, `SyncConflictList.php:37` (optional branch filter, `restrictedBranchId()`
never applied), `ResolveSyncConflictRequest.php:14` (`authorize` returns true). A branch-locked manager sees every
shop's conflicts and clashes, and can resolve `useTill`, which overwrites company-wide hub rows. *Fix:* force
`branch_id = restrictedBranchId()` in the list, show and clash views. Make resolve extend `CompanyWideWriteRequest`,
or check the conflict's branch.

**M2. Stock levels are a company-wide write without the one-shop guard.**
`app/Http/Requests/App/Stock/StockLevelsRequest.php:17` returns true, so a branch-locked manager changes a
product's min/max/reorder for every shop. *Fix:* extend `CompanyWideWriteRequest`.

**M3. Proxy trust is not configured.** `bootstrap/app.php:32` has no `trustProxies`. Behind Cloudflare or a load
balancer, every `$request->ip()` bucket becomes one shared bucket: activate and migrate (10/h), trial (5/h),
logins. One client can then block all till activations. Setting `*` later without care makes the IP spoofable
via `X-Forwarded-For`. *Fix:* trust only the real proxy CIDRs (Cloudflare list), and add a test.

**M4. Missing security headers and cookie hardening.** There is no global CSP, HSTS, `X-Frame-Options` /
`frame-ancestors` (the admin UI can be clickjacked), `Referrer-Policy` or `nosniff`. `config/session.php:172`
`secure` defaults to null. *Fix:* add header middleware on the web group, set `SESSION_SECURE_COOKIE=true` in
production, and consider a `__Host-` cookie name.

**M5. Impersonation is not time-limited or pinned.** `Impersonation.php:50` stores `started_at` but never checks
it, and does not compare `company_id` with the current company. The admin can use `company/switch` to reach the
user's other businesses. *Fix:* expire after N minutes (for example 30), and reject or stop the session when the
current company differs from `company_id`.

**M6. The sync API has no throttle before authentication.** `GuardSyncRequest` (per-key limit) runs after
`AuthenticateSyncKey`, so failed bearer attempts are unlimited. Keys have 160 bits of entropy, so this is a
DB/CPU denial-of-service risk, not a guessing risk. *Fix:* add a per-IP limiter on failed auth for `api/v1/sync/*`
and `cloud/migrate/complete`.

## Low

- **L1. Rate-limit buckets keyed on a caller-chosen `X-SSPOS-Install-Id`.** `ThrottleLicenceApi.php:45` (validate,
  deactivate, redeem) and `WrongKeyLimiter` both key on it. Activate still has the per-IP limit. Add a per-IP ceiling.
- **L2. CSV formula injection.** The VAT CSV writes business and shop names unescaped
  (`VatReturnHelper.php:86-87`, `AccountsController.php:113`). Reuse the apostrophe-prefix helper from `SalesCsv`.
- **L3. Markdown injection in staff email.** The public trial `message`, `contactName` and `businessName` are
  rendered as Markdown in the new-lead email (`resources/views/mail/admin-new-lead.blade.php`), so links are
  clickable (phishing of staff). Escape Markdown or put the text in a code span.
- **L4. Missing company scope in some lookups.** `DeliveryRows.php:51` looks up `purchase_orders` by till-supplied
  ids with no `company_id` filter. The `EntityWriter` upsert can also race-overwrite `company_id`, because the
  check happens before the write. Add `company_id` to the lookup and to the upsert condition.
- **L5. Staff PIN oracle.** `StaffGuards.php:31-40` answers "PIN already used", so a manager can learn which PINs
  colleagues hold (10/min throttle). Consider a softer message and an audit entry.
- **L6. Weak defaults on customer passwords.** `Password::defaults()` is never configured (min 8, no
  `uncompromised()`). `confirm-password` and `forgot-password` (`routes/auth.php:15,34`) have no route throttle.
- **L7. Unused signed storage routes.** `config/filesystems.php:36` sets `'serve' => true`, which registers signed
  `GET/PUT storage/{path}`. Nothing uses them, so disable it.
- **L8. Turnstile check is incomplete.** It does not verify `hostname`/`action` (`Turnstile.php`).
- **L9. Fob codes stored in plain text.** The contract needs the raw `rfid`; it is hidden from props. Record it
  as an accepted risk.
- **L10. Sync-key hashes cannot survive APP_KEY rotation.** `SyncKeySecret::hash` uses only the current `app.key`,
  so rotating APP_KEY silently breaks every sync key. Mirror `LicenceKey::hashCandidates`.

## Dependencies

- `composer audit`: clean.
- `npm audit --omit=dev`: 15 (2 critical, 8 high), mostly build tooling (vite, rollup, shell-quote, form-data)
  listed under `dependencies`. `axios` (high) ships to the browser via Inertia.
- *Fix:* `npm audit fix`, bump vite/Inertia, and move build tools to `devDependencies`.
