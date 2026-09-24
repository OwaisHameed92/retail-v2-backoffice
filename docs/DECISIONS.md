# Decisions

Product and technical decisions already made. Add new ones at the bottom with a date.

## Product (owner, 2026-09-24)

| Topic | Decision |
|---|---|
| Licence | One licence = one till (register), always. No seats, no branch/till limits inside a key. |
| Licence binding | First activation binds the key to the PC's `deviceId`. Moving PC = admin "reset device". |
| Company / branch / till creation | Created on the portal by us; sent to the till (on licence activation). |
| New till or branch later | Added on the portal. |
| Branch-specific prices | Yes. Settable from both portal and till (needs a new till entity, pending EPOS team). |
| Purchase orders | Can be created from the portal too (pending EPOS team). |
| Stock transfer between branches | From portal and till (pending EPOS team). |
| Till settings and role permissions | Controllable from the portal (pending EPOS team: `Setting`, `RolePermission` not synced yet). |
| When tills start sending data | When our portal APIs are ready. |
| Resellers | Not now. |
| Payments from customers | Cash only for now, recorded manually by admin. Online gateway later. |
| Free trial | 7 days, approved manually by us, starts on first activation. |
| Legacy customers | May move over. No data migration: we keep whatever the till sends on its initial sync. |
| Stack | Laravel, built to a much higher standard than the legacy portal. |
| AI | Both: AI features inside the product, and development done with AI agents. |

## Technical (2026-09-24)

| Topic | Decision | Why |
|---|---|---|
| Frontend | Inertia + React + TypeScript + shadcn/ui for both admin and tenant areas | One design system, modern SaaS look |
| Tenancy | Shared database with `company_id` + global scope | Sync writes and reporting across ULID-keyed rows stay simple; legacy DB-per-tenant needed repair tooling |
| Admin users | Separate `admins` table and `admin` guard | Staff and customer logins never mix |
| Licence token | Ed25519-signed JWS, verified offline by the till | See `docs/specs/licence-api-v1.md` |
| Primary keys | ULIDs everywhere; till ULIDs stored as-is | Contract rule: never re-key a till row |

## Waiting on the EPOS team

See `docs/specs/licence-api-v1.md` (open points) and `docs/PHASES.md` (blocked items).

## Tenancy (module 0.4, 2026-09-24)

| Topic | Decision | Why |
|---|---|---|
| Current company | `App\Domain\Tenancy\CurrentCompany`, container-scoped (`#[Scoped]`, flushed per request and per queued job). Set by `company` middleware from the user's active memberships; choice kept in session `current_company_id`, switched via `POST /app/company/switch` | One place to ask "which tenant?"; no leaking between jobs |
| Fail closed | `CompanyScope` throws `MissingCurrentCompany` when a tenant model is queried with no current company, in web, queue and console alike. Creating needs a current company or an explicit `company_id`; writing a row for another company or moving `company_id` throws `CompanyMismatch` | A missing scope must never mean "all tenants" |
| Escape hatch | `Model::withoutCompanyScope()` for super-admin and sync code only. Jobs/commands use `CurrentCompany::runAs($company, fn () => ...)` | Explicit and greppable |
| No membership | Users with no active membership (or only soft-deleted companies) are logged out with "Your account is not linked to a business" | Customer users only exist as company members |
| Tenant roles | owner (all), manager (all but `users.manage`, `billing.view`), accountant (dashboard, sales, reports, billing view), staff (read-only views). Map in `CompanyRole::abilities()`; check with `company.can:<ability>` middleware or `CurrentCompany::can()`. No Gate registered (no provider edit); add one later if policies need it | Simple default map; module 3.1 may make it editable |
| Self-registration | Removed. Owner accounts are created by us when a trial is approved (module 1.6). `/dashboard` moved to `/app` (`app.dashboard`) | Matches the manual trial approval decision |
| Company status | Not enforced at login yet: suspended/cancelled companies can still sign in. To decide in 1.2/1.8 | Billing rules not built yet |

## Shared building blocks (module 0.5, 2026-09-24)

| Topic | Decision | Why |
|---|---|---|
| Audit log | One `audit_logs` table (ULID id, nullable `company_id`, string `actor_type/actor_id` and `subject_type/subject_id`, no FKs, `created_at` only). Written only via `RecordAudit`; the model throws on update/delete. Actor = `admin` guard, then `web`, else explicit or null (system). Not tenant-scoped; tenant screens filter by `company_id` | Audit must outlive the rows it describes and never be edited |
| Secrets in logs | `Redactor` replaces values of keys like password, licence key, API key, token, secret, authorization and any key ending in hash/token/secret/password with `[redacted]`, recursively | Security rule: never store or log secrets |
| ULID validation | Strict upper-case Crockford base32, first char 0-7 (`Ulid::isValid`, `ValidUlid`). Lower-case ids are rejected, not upper-cased | Till ids are stored exactly as received; mixed case would break uniqueness on MySQL vs SQLite |
| Decimals | `MoneyCast` (2 dp) and `QuantityCast` (4 dp) return fixed-scale strings ("1.45", "2.0000"), round half away from zero, throw on non-numeric (incl. "1e3" strings). Maths via `Money` on bcmath (the ext is present; brick/math not added) | Matches till `Money.cs`; never floats |
| API dates | `ApiDate::format` / `UtcDateTimeCast`: ISO-8601 UTC with `Z`, whole seconds; no-offset input is UTC | Contract section 6 |
| API errors | Everything under `api/*` renders `{code, message, traceId, retryAfterSeconds, rejectedKey}`; validation → 400 `request.invalid`, 401 `unauthenticated`, 403 `forbidden`, 404 `not_found`, 405 `method_not_allowed`, 413 `request.too_large`, 429 `rate_limited`, 503 `server.busy`, other 5xx → 500 `server.error`. No exception detail ever, even with APP_DEBUG | Contract section 9 and licence spec |
| Trace id | Per-request ULID from `AssignTraceId` (api group), in `X-Trace-Id`, the error body and the log context. Unmatched api routes still get one from the renderer | Support can match a till's error to our logs |
| Data tables | Server-side only: `TableQuery` (whitelisted `searchable`/`sortable` columns, `perPage` in 10/25/50/100) + React `DataTable` (TanStack Table **v8**, pinned: v9 has a new API shadcn does not use yet). All table state lives in the URL via Inertia `router.get` with `preserveState` | Shareable URLs, no client-side filtering of tenant data |
| Search LIKE | Search terms are not escaped for `%`/`_` | Escape syntax differs between SQLite and MySQL; a wildcard only widens the user's own search |

## Admin area (module 0.3, 2026-09-24)

| Topic | Decision | Why |
|---|---|---|
| Admin roles | `AdminRole` enum: owner, sales, support, accounts. Abilities live in the enum (`AdminRole::can()`): `admins.manage` owner only; `tenants.view` all; `tenants.manage` owner/sales/support; `licences.manage` owner/support; `billing.manage` owner/accounts; `leads.manage` owner/sales. Owner has every ability | One place to read and test the permission map |
| Admin authorisation | Admin user screens use `AdminPolicy` (auto-discovered) via route `->can()`. Later modules add policies that call `$admin->hasAbility('<ability>')`; no Gate string abilities yet (would need a provider line) | Keeps 0.3 inside its own files |
| First admin | Created only with `php artisan admin:create {email} --role=owner` (password typed at a secret prompt, min 12 chars). No seeded or default admin | No default password anywhere |
| Admin login | `/admin/login`, `admin` session guard, 5 failed attempts per email+IP per minute. Inactive admins get the same "credentials do not match" message as a wrong password. Deactivating rotates the remember token and `AdminIsActive` signs out live sessions on the next request | Do not reveal which staff accounts exist |
| Owner safety | Cannot deactivate yourself; cannot deactivate or demote the last active owner (checked in the Actions, in a transaction) | Never lock SSPOS out of its own admin area |
| Admin logout | Invalidates the whole session (also signs out a `web` login in the same browser) | Staff and customer logins should not share a browser session anyway |
| Admin page data | The signed-in admin is shared as the `admin` Inertia prop by `ShareAdminInertiaData` on the admin route group, not in `HandleInertiaRequests` | `auth.user` stays the tenant user |
| Admin password reset | Not built. The `admins` broker and `admin_password_reset_tokens` table exist; an owner can set a new password on the edit screen | Small staff team; owner can reset |

## Token signing (module 1.4, 2026-09-24)

| Topic | Decision | Why |
|---|---|---|
| Crypto library | PHP `sodium` (libsodium) directly, no JWT package. `Ed25519Jws` is framework-free and reproduces the RFC 8037 appendix A.4 vector byte for byte | Fewer dependencies in a security-critical path; proves interop with the till's .NET verifier (NSec/BouncyCastle) |
| Token format | Compact JWS, header exactly `{"alg":"EdDSA","kid","typ":"sspos-licence+jwt"}`, base64url without padding, `JSON_UNESCAPED_SLASHES`. Signer adds `iss` (config), `iat` (Unix seconds), `jti` (ULID) when absent and refuses a foreign `iss` | Matches `docs/specs/licence-api-v1.md`; guide for the EPOS team in `docs/specs/licence-token-verification.md` |
| Verification | Strict: 8 KB cap, three non-empty segments, canonical base64url, JSON objects only, `alg` must be `EdDSA` (no `none`/HS*), exact `typ`, no `crit`, known kid, signature, then `iss`. Claims are decoded only after the signature passes. Exceptions: `MalformedToken`, `UnknownKey`, `InvalidSignature`, `InvalidClaims` (wrong `iss`). Expiry/validUntil/status/device are 1.5's and the till's job | Reject early and by type; the API can map each to an error code |
| validUntil | `ValidUntil::compute(iat, offlineDays, expiresAt, graceDays)`: pure, UTC, days = exact 86,400 s, truncated to whole seconds, negative inputs rejected | One formula shared by 1.5 and its tests |
| Key storage | `licence_signing_keys`: ULID, `kid` unique (`lk<year>-<nn>`, never reused), base64url public key, secret encrypted with APP_KEY (`encrypted` cast, `$hidden`). At most one `is_active`; writes serialised by a cache lock + transaction; if two were ever active the newest signs | Secret at rest is useless without APP_KEY; one signer at a time |
| Retired keys | Rotation retires the old key and **wipes its secret** (a retired key never signs again). It keeps verifying and stays in the JWKS for `licence.signing_keys.retired_keep_days` (60), then `licence:keys:prune` (daily) deletes it. The active key is never pruned | Tokens live at most 14 days, so 60 days covers every token signed before a rotation |
| `generate --force` | Behaves like a rotation (old key retired, still verifies for the keep period). There is no instant-revoke of a key yet | A compromised-key "kill now" path needs a till-side plan too; see open items |
| Secret hygiene | `SigningKey` omits the secret from toArray/JSON/var_dump/print_r and throws on serialize(); `#[SensitiveParameter]` on secret params; commands print kid + public key only; key events audited (`licence_signing_key.generated/rotated/pruned`) with kids only | Security rule: never log or expose secrets |
| APP_KEY | The signing key's secret depends on APP_KEY. Rotating APP_KEY without re-encrypting (or running `licence:keys:rotate` after) makes signing fail | Laravel encrypter; note for ops |

## Plans (module 1.1, 2026-09-24)

| Topic | Decision | Why |
|---|---|---|
| Price model | Two prices per plan, per till: `price_per_till_monthly` and `price_per_till_yearly`, `decimal(12,2)` pounds via `MoneyCast`. Currency is fixed to GBP (column kept for later). Forms accept up to £99,999.99 with at most 2 decimal places (`30`, `29.99`, `£1,200.50`); exponents and 3 dp are rejected, not rounded | One licence = one till; admins never see float noise |
| Trial and grace | `trial_days` (default 7, 0-90) starts on first activation; `trial_grace_days` (default 3, 0-30) = warning days after the trial before the till locks; `grace_days` (default 7, 0-60) = days after a missed renewal before licences are suspended (used by 1.8's auto-suspend job) | Matches the 7-day manual trial decision; values live on the plan so a custom plan can differ |
| Features | `Feature` enum (camelCase): stockControl, purchasing, cashOffice, accounts, staff, customerOrders, newsDeliveries, multiBranch, aiAssistant, aiInsights. Stored as a JSON list in enum order (`AsEnumCollection`); unknown values dropped. The till and portal gate whole areas on these | One place to add a feature; order-stable diffs in the audit log |
| Status | Derived, not stored: **active** (`is_active` + `is_public`), **hidden** (`is_active`, not public), **inactive** (`is_active` off: kept for existing licences, not offered for new ones), **archived** (soft deleted, read-only until restored). The list defaults to all non-archived plans | The brief named Active/Hidden/Archived; `is_active` needed its own state so "stop offering" is not confused with "archive" |
| Codes | `code` is a unique lower-case slug (`^[a-z0-9]+(-[a-z0-9]+)*$`, max 50), unique across archived plans too, editable. Create suggests it from the name | A restored plan can never clash |
| Archive | Soft delete via `ArchivePlan`, blocked with a clear message when `Plan::isInUse()`. `isInUse()` returns false until 1.3 wires it to `licences()->exists()` (all licence statuses) | Plans referenced by licences or invoices must never disappear |
| Duplicate | Copies everything as "<name> (copy)" with code `<code>-copy[-n]`, **inactive and hidden**, then opens the edit form. Archived plans can be copied | A copy is never offered before someone reviews it |
| Edits | No-op saves write nothing (compared on cast values: SQLite returns "30" for "30.00"). `plan.updated` stores only changed keys. Audit actions: `plan.created/updated/archived/restored/duplicated` (duplicate meta: `source_plan_id`, `source_plan_name`) | Activity card shows real changes only |
| Access | `/admin/plans` route group with `can:billing.manage` (owner, accounts). Nav item hidden for other roles | Billing owns pricing |
| Toasts | No shared toast system existed. Plans use a small local `PlanToaster` (`components/admin/plans/plan-toaster.tsx`) fed by a `toast` session flash that plan controllers pass as a page prop | Kept inside the module; see open item below |
| Open item | Promote the toaster to `components/shared` and share `toast` flash from one middleware so every module uses the same toasts | Other modules will need it |

## Emails (module 1.7, 2026-09-24)

| Topic | Decision | Why |
|---|---|---|
| Mailables | `app/Domain/Mail/Mailables/*Mail` extend `BrandedMailable`: `ShouldQueue` + `ShouldBeEncrypted` (queue payloads can hold licence keys or password links), `afterCommit`, 3 tries (backoff 60 s, 5 min), reply-to support. Each takes one plain readonly data object from `app/Domain/Mail/Data` so 1.3/1.6/1.8 call them without new coupling: `Mail::to($owner->email)->queue(new WelcomeTenantMail($data))`. `AdminNewLeadMail` has its own recipient (`sspos.staff_email`): `Mail::queue(new AdminNewLeadMail($data))` | Callers build data, the module owns wording and branding |
| Templates | Markdown mail views in `resources/views/mail`, theme `switch-save` (`resources/views/vendor/mail/html/themes/switch-save.css`, set on every branded mailable and via `MAIL_MARKDOWN_THEME` for framework mail). Custom components `x-mail::licence-keys`, `x-mail::facts`, `x-mail::notice`. Logo is an absolute `config('app.url')` URL, so `APP_URL` must be the public https URL in production. Emails use long dates ("24 September 2026", Europe/London), UK English, sentence case, no exclamation marks. Light only | Laravel inlines the CSS into table layouts that Gmail and Outlook render |
| Registry | `EmailTemplates::MAILABLES` lists every template in lifecycle order. Each mailable declares `templateKey/Label/Description/audience` and a `sample()` with realistic fake data used for previews and test sends | Adding a template is one class + one view + one line |
| Email log | `email_logs` (ULID, nullable `company_id` without FK, `to`, `mailable`, `template`, `subject`, `status` queued/sent/failed, `error`, `message_id`, `sent_at`, `meta`). A branded mailable opens its row as queued when queued (or sent directly); `App\Listeners\RecordEmailLog` (auto-discovered) opens rows for any other mail on `MessageSending`, stamps the row id in an `X-Email-Log-Id` header and marks it sent on `MessageSent`; a send exception or the job's `failed()` hook marks it failed (a later successful retry wins). Never stores bodies; `meta` is scalars only, run through `Redactor`, and mailables only put non-secret facts in it (business name, counts, dates). Lead contact details and licence keys stay out | Support can see what was sent without the log becoming a second copy of secrets |
| Retention | `EmailLog` is `MassPrunable`: rows older than `sspos.email_log_retention_months` (12) are deleted by `model:prune`, scheduled daily in `routes/console.php` | Personal data kept only as long as useful |
| Password emails | Forgot password still sends Laravel's `ResetPassword` notification (broker, throttle and tests unchanged); `User::sendPasswordResetNotification()` registers `ResetPassword::toMailUsing()` so its body is the branded `SetPasswordMail` (reset wording). First-time links for new owners: `SendPasswordSetupLink::handle($user, $businessName, $companyId)` (standard broker token, "set your password" wording). Non-User notifiables get a plain reset message | Branded without replacing the auth flow |
| Admin access | `/admin/emails` (log) and `/admin/emails/templates` (preview + "Send test to me") need `EmailLogPolicy`, which uses the `licences.manage` ability (owner, support) because there is no emails ability. The sidebar item uses the same ability | Customer addresses and account events are support data; avoided editing `AdminRole` |
| Preview | `GET /admin/emails/templates/{key}/preview` renders the sample server-side (HTML or `?format=text`) into a sandboxed iframe with a strict CSP (no scripts), `X-Frame-Options: SAMEORIGIN`, links open in a new tab. Nothing is sent or logged | Exactly what customers get, no risk from the preview |
| Test sends | Only to the signed-in admin's own address, `[Test]` subject prefix, template's fixed recipients skipped, log row `meta.test = true`, audit `email.test_sent`, throttled 10/min | No way to email a customer from the preview screen |
| Open items | (1) Set-password links use the users broker expiry (60 min): 1.6 may want a longer first-time expiry. (2) The forgot-password email is sent in the request (the notification is not queued). (3) Retries mark the row failed then sent; there is no "retrying" state. (4) Admin password resets (not built) would get the plain reset body. (5) Toasts: a small local `EmailToast`, to fold into the shared toaster when one exists | For 1.6, 1.8 and the shared-toast follow-up |

## Password links (owner, 2026-09-24)

| Topic | Decision |
|---|---|
| First-time "set your password" link (welcome) | Valid 7 days. Broker `user_setup`, table `password_setup_tokens`, sent by `SendPasswordSetupLink`; link carries `setup=1` so the page says "Set your password" |
| Forgot-password link | Stays 60 minutes (broker `users`) |

## Tenants (module 1.2, 2026-09-25)

| Topic | Decision | Why |
|---|---|---|
| Portal-made ids | Company, Branch and Register ULIDs are upper case (`HasPortalUlid` → `Ulid::new()`), like till-made ids. Laravel's `HasUlids` default is lower case, which `ValidUlid` rejects | The till receives these ids on licence activation and stores them as-is |
| Branch fields | Mirror the till's Branch: `code` (2–5 capital letters, used in receipt numbers `LDS-01-000482`), name, address, phone, vat_number, `nation` (`england`, `scotland`, `wales`, `northernIreland`), `licensed_hours_json` (text, stored as-is), `is_drs_return_point`, `area_m2` (`decimal(10,2)`, `AreaCast` string). `nextPoNo` is till-owned and not stored | Same field names as the contract; no floats |
| Register fields | `code` two digits `01`–`99`, name, `is_main_till`, `is_active`. `nextSaleNo`/`nextRefundNo` are till-owned and not stored | Counters belong to the till |
| Codes never reused | Unique indexes `(company_id, code)` on branches and `(branch_id, code)` on registers include soft-deleted rows; the actions and form requests check the same | A reused code could repeat receipt numbers |
| Main till invariant | A branch with active tills has exactly one active main till; inactive tills are never main. Kept by `MainTill::normalise()` inside every register action (add, set main, deactivate, reactivate): the first till is main, deactivating the main promotes the active till with the lowest code, a reactivated till only becomes main when no other is active | The main till is the branch's only sync sender |
| Deactivation | Branches and tills are deactivated, not deleted. A deactivated branch keeps its tills' flags (licence module must check `branch.is_active`); tills cannot be added to or reactivated in an inactive branch. A business must keep one active branch (cancel it instead) | Reversible; receipts and history stay linked |
| Company status | `trial → active` (ActivateCompany, also reinstates `cancelled`), `trial/active/overdue → suspended` (SuspendCompany, reason required, previous status kept in `suspended_from_status`), `suspended → previous status` (UnsuspendCompany), `any → cancelled` (CancelCompany, reason required). Reasons live on the company and in the audit log | Suspension is usually temporary (billing) and must restore trials correctly |
| Trial dates | A new trial has no `trial_ends_at` unless the admin sets one: the 7-day trial starts on the first till activation (module 1.5 sets it). Dates entered in admin are the end of that day, Europe/London | Matches the owner's trial decision |
| Status enforcement (replaces the 0.4 "not enforced yet" row) | In the `company` middleware on every /app and settings request: suspended → `app/account-on-hold` page ("Your account is on hold. Please contact Switch & Save support."), no tenant data, only switching to another business allowed, writes redirect to it; cancelled companies are never resolved, so their users are signed out with "This Switch & Save account has been closed…" (or moved to another active business they belong to); overdue keeps full access (1.8 decides banners). Login itself is unchanged: the first /app request after login applies the rule | One enforcement point for login and every later request; no edits to the auth controllers |
| Tenant creation | `CreateTenant` = company + first branch + 1–20 tills ("Till 1…", first is main) + owner, in one transaction. New owners get module 1.7's branded 7-day "set your password" email (`SendPasswordSetupLink`) after commit; an email that already has a login is just added as owner (keeps their password, no email) | Owners may run several businesses with one login |
| Company users | `AddCompanyUser` (creates or attaches; reactivates an inactive membership), `ChangeCompanyUserRole`, `RemoveCompanyUser` (detaches; the user row stays). At least one active owner must remain (checked under lock). Admin "Send set-password email" = `ResendPasswordSetupLink` | Never lock a customer out of their own account |
| Admin authorisation | `CompanyPolicy` (auto-discovered): `tenants.view` for list/detail, `tenants.manage` for every change and "Login as customer". Branch/till/user ids in admin URLs are looked up inside the route's company only (`withoutCompanyScope()->whereBelongsTo($company)`), so another company's id is a 404 | Explicit escape hatch; no cross-company edits via crafted URLs |
| Admin reads across tenants | List counts drop `CompanyScope` inside `withCount` constraints; the detail page loads branches/tills inside `CurrentCompany::runAs($company)` | Documented escape hatches only; the scope itself is unchanged |
| Login as customer | `ImpersonateCompanyUser` logs the chosen active member in on the `web` guard in the same session and stores `impersonation {admin_id, user_id, company_id, started_at}`. Not nestable; not for cancelled companies; audited start and end (actor = the admin). While active: `/admin` redirects to /app (`BlockAdminWhileImpersonating`), the tenant layout shows a banner "Viewing as <company> (<email>) — Return to admin", profile/password changes are 403, and the suspended hold page is skipped. It ends via `POST /admin/impersonation/stop` (outside the blocked group) or automatically if the admin is signed out, deactivated or loses `tenants.manage`. Ending uses `logoutCurrentDevice()` so the customer's remember token is untouched | Support can see exactly what the customer sees without mixing identities |
| Branch switcher | Tenant top bar: "All branches" + active branches of the current company, stored in session `current_branch_id` (`SwitchCurrentBranch`, validated under the company scope), shared as `branches`/`currentBranchId`. `ResolveCurrentBranch::handle($session)` gives later modules the chosen branch (null = all) and drops stale choices; switching company resets it | Later modules filter by one resolver |
| Flash messages | `HandleInertiaRequests` shares `flash.success` / `flash.error` from the session; tenant screens show them with `FlashToaster` (admin tenant pages and the tenant layout) | A shared toast channel for future modules |
| Open items | (1) Logging out from the portal while impersonating ends the whole session (admin too), because the tenant logout controller invalidates the session. (2) Licence and billing tabs are placeholders for 1.3/1.8. (3) Branch `licensed_hours_json` is edited as raw JSON until the till format is documented. (4) Module 1.3 adds `plan_id` and licence checks for inactive branches/tills | For 1.3, 1.5, 1.8 |
