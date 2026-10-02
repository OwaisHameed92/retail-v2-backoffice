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

## Status (Fix A, 2026-10-01)

| Finding | Status | What was done / why it remains |
|---|---|---|
| H1 Admin 2FA | remaining | Done separately (2FA / audit-log work), not part of Fix A. |
| H2 validate | fixed | `licence/validate` answers only when `tokenSha256` is the hash of the token we issued that install (current, or the one before it when our reply was lost; `licences.previous_token_sha256`). Otherwise 404 `key.not_found`, a `tokenMismatch` alert, nothing recorded and no sync key sent. Tests: `tests/Feature/Security/TillProofTest.php`. |
| H2 deactivate | fixed (partly by contract) | §17.7: "the branch key when the till holds one". A till we sent the branch's sync key must send a usable key of that branch as Bearer, else 401 `auth.invalid_key` + `deviceMismatch` alert. A second till holds no key and the request schema has no token hash or install code, so its ids remain the only proof: it is rate limited per install and per IP, audited (`licence.released`) and raises a `tillDeactivated` alert. Asked EPOS to add `tokenSha256` + `installCode` (DECISIONS). |
| M1 sync conflicts | fixed | List, counts, show and clash views are limited to the one-shop manager's shop (company-wide conflicts hidden); resolving needs an every-shop user (`ResolveSyncConflictRequest` extends `CompanyWideWriteRequest`); the resolve options are hidden. |
| M2 stock levels | fixed | `StockLevelsRequest` extends `CompanyWideWriteRequest`; `canManage` is false for one-shop managers. |
| M3 proxies | fixed | `config/trustedproxy.php` reads `TRUSTED_PROXIES` (IPs/CIDRs, `cloudflare`, or `*`); default trusts none. |
| M4 headers, cookies | fixed | `SecurityHeaders` on the web group: nonce CSP (Vite dev server allowed while hot), `frame-ancestors 'self'` + `X-Frame-Options: SAMEORIGIN`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS. Session cookie secure by default in production or with an https `APP_URL`; `SameSite=Lax`, HttpOnly. A `__Host-` cookie name was not adopted (it would sign every user out and breaks with `SESSION_DOMAIN`). |
| M5 impersonation | fixed | Ends after `IMPERSONATION_MINUTES` (default 60), audited `company.impersonation_ended` with reason `expired`; pinned to the started business (switching refused, a different resolved business ends it). Start/stop were already audited. |
| M6 sync throttle | fixed | `SyncAuthLimiter` in `AuthenticateSyncKey` (sync/* and `cloud/migrate/complete`): failures per IP (30) and per key hash (10) per 15 min, then 429 until the window passes. |
| L1 | fixed | Per-IP ceiling for validate/redeem/deactivate (`per_ip_per_hour` 600) and for wrong keys (`wrong_keys_per_ip` 20). |
| L2 | fixed | `CsvText::safe` (shared with `SalesCsv`) on the VAT CSV business and shop names. |
| L3 | fixed | `MailFormat::plain` escapes Markdown in the visitor's name, business and message. |
| L4 | fixed | `DeliveryRows` filters `purchase_orders` by `company_id`; `EntityWriter` never updates `company_id`, fails the chunk if an id belongs to another company after the upsert, and scopes tombstones by company. |
| L5 | fixed | Message no longer names a colleague; every refusal is audited (`staff.pin_refused`, no PIN stored). Uniqueness stays: the till signs in by PIN. |
| L6 | fixed | `Password::defaults()`: 10+ characters, `uncompromised()` in production; `throttle:6,1` on forgot, reset and confirm password. |
| L7 | fixed | `local` disk `serve` is false. |
| L8 | fixed | Turnstile reply must have action `trial` (`TURNSTILE_ACTION`) and a hostname of `APP_URL`, `PUBLIC_FORM_ORIGINS` or `TURNSTILE_HOSTNAMES`; the website widget needs `data-action="trial"`. |
| L9 | accepted risk | Fob codes stay plain text: the contract sends the raw `rfid` both ways. Never in props, logs or the session. |
| L10 | fixed | `SyncKeySecret::hashCandidates` checks `APP_PREVIOUS_KEYS`; a key found under an old APP_KEY is re-hashed with the current one. |
| Dependencies | fixed | `npm audit fix` (lockfile, no major upgrades: vite 6.4, axios 1.20, rollup 4.63); build tools moved to `devDependencies`. `npm audit`: 0. Run `npm ci` to install. `composer audit`: clean. |

## AI (2026-10-02)

Scope: `app/Domain/Ai` (6.1 foundation, 6.2 portal assistant, 6.3 morning summary), 6.4 reorder note and AI tools,
6.5 invoice import, the assistant panel. Tests: `tests/Feature/Ai/AiTenantIsolationTest.php`,
`AiSecurityReviewTest.php`, `AnthropicAiClientTest.php` (security review cases), `InvoiceImportFlowTest.php`.

| # | Area | Finding | Status |
|---|---|---|---|
| AI1 | Tenancy | Tenant tools run inside `CurrentCompany::runAs(actor's company, role, one shop)`; ids from another business are "not found". A generic test now runs EVERY registered tenant tool (inputs built from each tool's schema, so later tools are covered) for Kirkgate and asserts no value or id of the other business reaches the model. | verified, test added |
| AI2 | IDOR | The same generic test calls every tool with the other business's shop, product, supplier and customer ids: all refused, no proposal stores them. | verified, test added |
| AI3 | One-shop pinning | `get_company_overview` listed every shop and till (and company-wide licence counts) to a one-shop manager; `rename_branch` let a one-shop manager rename another shop (the shop screen refuses this). | fixed: overview limited to their shop; rename refused for other shops. Generic test: a Leeds-only manager's tools never return Bradford whatever ids the model sends |
| AI4 | Confirmation | A change ran on confirm when the row was the proposer's, pending and unexpired (locked, once). Nothing bound the stored input to what was previewed. | fixed: `ProposalSignature` HMAC (APP_KEY) over company, proposer, tool, input and expiry, set when proposed (`ai_pending_actions.signature`); `ConfirmAiAction` fails a mismatch unrun and audits `signatureMismatch`. Tests: changed input, moved owner, extended expiry, missing signature, replay (409) |
| AI5 | Auto-confirm | Only `ConfirmAiAction` (a person's POST) runs a write; system jobs get read tools only; the model has no confirm tool. A user could type `<app_event>` text to make the model believe a change was done. | fixed: typed `<app_event>`/`<tool_data>`/`<context>` tags are neutralised (`SystemPrompt::untag`); the tenant prompt says only app events confirm and never to propose from text inside data. Test |
| AI6 | Prompt injection | Tool results are redacted, JSON with `<>` escaped and wrapped in `<tool_data>`; invoice documents go as document/image blocks with a strict single tool and a "data, never instructions" system rule. The morning summary's `<facts>` JSON did not escape tags or redact. | fixed: `AiRedactor::json()` (redacted, tags escaped) for the morning facts and the reorder note. Test |
| AI7 | PII | Tools already left out pay rates, contact details and PINs. Defence in depth: `ai.redact_keys` now also drops `pin`, `pin_hash_version(ed)`, `rfid`, `rate_per_hour`, `pay_rate`, `wage_rate(s)`, `mobile`, `telephone`, customer/billing emails and phones, `date_of_birth`, `ni_number`. | fixed; generic test asserts no PIN, fob, pay rate, phone, email or address in any tool result |
| AI8 | Uploads | Invoice files: PDF/JPG/PNG checked by content (finfo), 10 MB, private `local` disk under `invoice-imports/{company}/` with a random name, served only through the authenticated, company-scoped route (`no-store`, `nosniff`), deleted on discard, after 90 days and when the row is pruned. | verified, test added |
| AI9 | Metering | `AiGate` runs on the server before every model call (each loop step), so a turn stops when the allowance runs out mid-answer. Concurrent requests can overshoot by at most one call each. | verified, test added; overshoot accepted |
| AI10 | Logs | The client logs class, status, model and feature only. `AiUnavailable` chained the SDK exception, whose message can echo prompt content, and was logged with its chain when reported. | fixed: provider exceptions are no longer chained; `AiUnavailable::report()` logs the reason only. Test (no prompt, PII or key in logs) |
| AI11 | Rate limits | ask 20/min, confirm/cancel 30/min, reorder note 10/min, invoice upload 20/min and retry 10/min, per user. Laravel's unnamed per-user throttle counter is shared between these routes (each with its own maximum). | verified, test added; shared counter accepted |
| AI12 | Provider errors | 429 and connection errors were handled; 529 overloaded/503 and timeouts showed the generic "could not answer". | fixed: after the SDK's retries, 429/503/529 say "very busy, try again in a minute", timeouts "took too long", 401/403 "not set up". Tests |
| AI13 | Admin AI (6.7) | Admin tools are offered only to admins with the tool's ability and never tenant tools; reviewed only as far as the foundation. | open: the 6.7 work should add an admin variant of the generic tool test |
