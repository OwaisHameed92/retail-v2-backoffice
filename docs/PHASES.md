# Phases

Plan v2 (2026-09-28), rewritten after the EPOS team's contract v1.3.1; **current contract v1.4.1** (2026-09-29,
`docs/contracts/portal-api-v1.4.1/`, start at `START-HERE.md` and `docs/web-portal-api/ANSWERS-2026-09-29.md`). The
till is already built against that contract, so **the portal implements it exactly**; where our earlier modules
differ, they are reworked (marked 🔄). Earlier contract folders (v1.1, v1.3.3) were removed when the generator moved on.

Each module is one agent task. Modules in the same wave can run in parallel. Status: `done` · `rework` · `todo` ·
`blocked (reason)`. A module is done only when its Actions + Pest tests (tenant isolation and authorisation
included) pass, the UI follows `docs/BRAND.md`, and `composer check`, `npm run lint`, `npx tsc --noEmit` and
`npm run build` are green.

Totals: **64 modules · 62 done · 1 deferred (6.7) · 2 waiting for a server (7.4 deploy, 7.5 EPOS end-to-end) · 7.6 load test todo** (2026-10-02, counted from the tables of phases 0–7; 2.9 split into A and
B; Phase 8's five later items are not modules yet).

---

## Phase 0: Foundation — 5/5

| # | Module | Status |
|---|---|---|
| 0.1 | Project setup, contract fixtures, docs | done |
| 0.2 | Quality tooling (Pint, Larastan 6, Pest, `composer check`, CI) | done |
| 0.3 | Admin area (guard, roles, admin users) | done |
| 0.4 | Tenant area (companies, memberships, fail-closed company scope) | done |
| 0.5 | Shared building blocks (audit log, money, API errors, data table, toaster) | done |

## Phase 1: Onboarding and licensing — 13/13

Contract: v1.4.1 `docs/web-portal-api.md` §17 (read 17.15–17.17 first), `specs/licensing.md`, `licensing/schemas`,
`licensing/samples`.

| # | Module | What | Status |
|---|---|---|---|
| 1.1 | Plans | Price per till, trial days, features | done |
| 1.2 | Tenants | Company → branches → registers, suspend, login as customer | done |
| 1.3 | Licences (admin) | One key per till, renew/reset/reissue/suspend/revoke, alerts, search | done |
| 1.6 | Leads and trial approval | Leads, board, one-click 7-day trial | done |
| 1.7 | Emails | Branded templates, email log, previews | done |
| 1.8 | Cash billing | Invoices + PDF, payments, auto-renew, overdue suspension | done |
| 1.4 | Token signer | `SSPOS1.<payload>.<sig>` tokens (§17.2) via `SsposTokenSigner`/`SsposTokenVerifier` + `LicenceClaims`, kid = `k` + 8 hex of SHA-256(public key), `signerCert` in every token (§17.17, `licence:keys:import-cert`), `licence:keys:handover`; worked examples reproduced byte for byte | done |
| 1.5 | Licence API | `POST licence/activate` (17.15.1), `licence/validate` (17.15.2) per till and `devices/deactivate` (17.7) exactly as contract v1.4.1: headers `X-SSPOS-Contract` (echoed), `X-SSPOS-App-Version` (informational: never 426 on `licence/*`), `X-SSPOS-Install-Id`, `Idempotency-Key` replay (24 h); statuses active/expiring/expired/suspended/revoked/released; error codes of `error-codes.json` with `details`; §17.12 rate limits + 5 wrong keys/install/15 min; SSPOS1 tokens with full claims, new token only on change; admin **Release**, install code/id, clock skew, lock state on the licence page; `licence:simulate` speaks the contract. Old check-in API, JWS classes and our draft docs removed; contract tests replay the samples | done |
| 1.11 | Licence form | Customer/licence form with every token field (17.16): kind trial/full, validFrom/expiresAt length, `maxRegisters` per branch, `limits.branches`, `multi_branch`, features (till's snake_case names), `company` block; "Till 1 of 3" issuing with limits; installId/installCode, clock skew, lock state; **Release** key; activate-by date; re-sign branch keys on change. Built: shop details (business type, town, postcode, owner's name, receipt footer) on the company/branch forms; branch licence settings dialog + company branch limits; limits enforced in IssueLicence/AddRegister/AddBranch/Reactivate*; `activate_by` (410 `key.expired`, extend, reissue resets); "Resend key e-mail"; wizard and lead approval carry the form | done |
| 1.9 | Admin dashboard | Customers, trials, licences, leads, cash due (sales tiles come in 3.2) | done |
| 1.10 | Public trial form | Public API `POST /api/v1/public/trial-requests` + hosted `/trial` page → lead (docs/specs/public-trial-api.md) | done |
| 1.12 | GoCardless billing | Upfront cash or setup fee + Direct Debit (monthly/yearly): plan setup fee + per-customer override, instalments; mandate by emailed signed link → GoCardless hosted flow; subscription = live tills × plan price + VAT, kept in step; every GoCardless payment ↔ one invoice, confirmed → RecordPayment (`directDebit`) → licences renewed, failed/charged back → reversal + dunning → overdue/suspension via billing:run; `POST /webhooks/gocardless` (HMAC, idempotent, queued, replayable); daily `billing:reconcile-gocardless`; trial without mandate → grace → suspension; admin Billing tab/overview/plan form (docs/specs/gocardless-billing.md) | done |
| 1.13 | Pricing modes + self-serve Direct Debit | Plan `pricing_mode` perTill/perBranch (per-till columns renamed `price_monthly`/`price_yearly`) + per-company override (billing.manage, audited); invoice lines per till or per branch (branch line renews its tills); DD amount follows tills, branches and pricing; upfront payment recorded in the wizard, lead approval and Billing tab (cash/bank, £0); tenant portal `/app/billing` (billing.view: plan, pricing, upfront, mandate, next collection, invoices + PDF) with "Set up Direct Debit" → GoCardless → return; portal banner with days left; `BILLING_MANDATE_DEADLINE_DAYS` (3) suspension lifted by the mandate, never for £0 recurring; welcome email links to Billing | done |
| 1.14 | Billing flow rules (owner 2026-10-05) | Setup fee = upfront, always by hand (cash/card/bank, instalments), never Direct Debit; plan types setup only / setup + recurring / recurring only; setup-only = 10-year licence topped up; Direct Debit starts after the setup fee; lost mandate = deadline → suspension, lifted by a new mandate; chargeback grace from the reversal; pending collections not overdue; Billing tab "Plan and payments"; `docs/billing-flow.md` | done |
| 1.15 | Billing showcase + tenant purge | "Billing status" card (admin Billing tab and `/app/billing`, one component): plan type, setup fee and how paid, recurring fee and mandate, state in plain words, what happens next with the date, next action (record setup payment / send Direct Debit link / retry Direct Debit / record payment); admin Billing overview "Businesses by billing state" filter; `demo:billing [--scenario=…\|all] [--fresh] [--force]` (demo businesses `is_demo`: never sent to GoCardless, never emailed, own DEMO- document numbers); `tenant:purge {company} [--force]` (dry-run table, confirmation, refuses traded businesses) | done |
| 1.16 | Per-till setup fee + email control (P11, owner 2026-10-07) | Plan `setup_fee_mode` perBusiness (default, as before) / perTill: onboarding fee × tills (editable, 0); a till added later (Add till, Add branch, approving a till request) gets a "Setup fee (added tills)" invoice (editable, 0 waives), paid by hand, the till held on its trial until paid; `setup_fee_covered_tills` grandfathers paid tills, `billing:backfill-setup-fee-coverage`. Admin → Settings → Emails: "Send automatically" per category (invoices, reminders, set password, welcome, owner alerts), held emails with their values, preview, Send / Discard / Send all, business Emails tab (welcome, set-password link), invoice "Email to customer", `emails:auto`; defaults = all automatic | done |
| 1.17 | Change plan (owner 2026-10-07) | Admin → business → Billing → "Change plan" (billing.manage): pick an active plan → plain-words preview (no writes: features gained/lost and shops keeping their own, setup fee for tills not covered — editable, 0 waives —, recurring fee and when it starts, Direct Debit / manual collection, licence terms, invoices made, the email) → confirm. Setup only → recurring: first period invoiced from the change date, paid tills valid to its end, Direct Debit collects it (new mandate deadline when none; `billing_accounts.recurring_starts_on`); recurring → setup only: subscription cancelled, 10-year licence once the setup fee is settled; tokens re-signed; audited `billing.plan_changed`; "Your plan has changed" (reminders category, held when off); GB and PK tests | done |

## Phase 2: Sync and cloud link — 10/10 (complete)

Contract: v1.4.1 §1–16, §19, §21 (duplicate/echo/conflict rules + test list 19.4), §20, `docs/web-portal-api/openapi.yaml`.

| # | Module | What | Status |
|---|---|---|---|
| 2.1 | IDs and sync keys | `id_map` (adopt the first till company id, alias later ones, branch = the key's branch, register = the key's register; conflicts → 409 `licence.ids_conflict` + alert) and `IdTranslator` at the edge (ApplySyncChanges; `toTill()` for 2.5 pull); per-branch sync key (`SSK-…`, 160 bits, HMAC + last 4) sent as `apiKey` (+ `hubUrl`) to the main till of a `cloud_sync` licence; admin Sync key panel (generate shown once, send new key to till, revoke; old key 7 days' grace); `AuthenticateSyncKey` for `/api/v1/sync/*`; feature names = the till's 11; `k290bee23` in `.env.example`. **`devices/activate` is not built** (the till never calls it) | done |
| 2.2 | Hello and push | `sync/hello`, `sync/push` (gzip, 5,000 rows, idempotent, ordered, acknowledged, initial mode), branch key auth, to the v1.4.1 `hello-reply` and `error-reply` schemas. Built: contract/header checks, per-key rate limit, per-branch lock (503 `server.busy`), Idempotency-Key replay, 413/422 per §9, initial uploads kept apart by upload id, `sync_branch_status` for 2.7; 5,000-row gzip push ≈ 0.7 s | done |
| 2.3 | Entity store: master data | 145 entities of v1.4.1 via additive migrations (`database/till-schema.json`); an entity the portal does not know yet is kept raw in `till_unknown_rows` (§18.8, §21.1) | done |
| 2.4 | Entity store: transactions | Applier to §19: never twice (ledger + version), never backwards (version, tie by `updatedAt`), never echoed (`hub_hash`, `origin_branch_id`), `baseVersion` ready, `portal_received_at`; §19.4 store tests | done |
| 2.5 | Pull | `GET sync/pull`: per-company counter (`sync_hub_counters`, row lock; after-commit stamp in `HubOwnedRow`, `PublishHubChange` for non-Eloquent writes, rows accepted from tills stamped at the next pull, parents first), hub-owned rows only, company-wide + addressed to the branch, never back to `origin_branch_id` (19.2), paging, till ids (`toTill`), no secrets, gzip reply, `sync_branch_status` last pull; 5,000-row page ≈ 0.6 s. The `sync_conflicts` screen came in 2.9B | done |
| 2.6 | Contract tests | Replay every sample, validate against schemas, pass the §19.4 test list. Built (`docs/contract-tests.md`): `opis/json-schema` (draft 2020-12, cross-file `$ref`) replaces the hand-written subset; `ContractReplyGuard` checks every till reply of every feature test (reply schemas, pull payloads per entity schema, token payloads, error codes and statuses per `error-codes.json`); all 84 sample files schema-validated and mapped to their replaying tests (a guard fails on an unmapped sample; 2.8/Phase 8/deprecated ones marked pending or not applicable); static + runtime error-code check; §21.8 log spy; §19.4 items 1–14 mapped to tests (new HTTP tests for #1–3, #6–7, #10, #14). Contract fixes: licensing errors always carry `details`; framework 403/404/405 on till URLs → 400 `request.invalid`; a decimal with more places than the contract allows → `row.invalid` (never rounded); unknown entities' secret-looking members redacted; a portal-deleted row stays deleted (conflict) | done |
| 2.7 | Till health | Online/offline, versions, last push/validate per branch and till, alerts. Built: `licences.last_contract_version` + validate `diagnostics` (pendingSyncRows, lastSyncError, databaseSizeMb only); `till_health` / `branch_health` rebuilt every 5 min by `till-health:refresh` (`RefreshTillHealth`, 200 businesses per chunk, fixed query count); states online / stale / offline / not activated and sync healthy / failing / stalled from config/till-health.php; problems offline, old version (< `minimumAppVersion`), sync failing/stalled, clock skew; alerts on the till's licence (`licence_alerts`, 5 automatic types, raised and cleared by the command, "Till offline" only after N silent trading hours; in-app only); admin **Till health** page (all businesses, filters, search, rules card), health on the tenant page (branch strip + till column), licence page card, dashboard tile + "Till sync" system health; tenant dashboard "Shops and tills" (read only, no install ids) | done |
| 2.8 | Local keys and migration | `licence/redeem` (local key reports, 17.6/17.16), `cloud/migrate` + `migrate/complete` + initial push (17.8). Built: redeem = local key report (verify §17.2 steps 1–4, local key register `licenceId → installCode`, 409 `key.used_on_another_install`), a token at a linked till (live, this shop), a portal key at a linked till (applied like activate); migrate with the shop's sync key (or an activated portal key with the dashboard), one PC per shop, ids adopted/aliased (409 `migrate.already_migrated`), licence bound (`LicenceBinder`) with days carried over, `cloud_uploads` progress; initial push only into this shop's open upload (404/409 `migrate.upload_*`); complete counts the ledger per entity (`complete` raises the push cursor to the snapshot, else `incomplete` + `missing`); admin **Cloud link** page + tenant tab, clear a register record (`licences.manage`). All redeem/migrate samples replayed; no licensing endpoint pending. Also answers (b): `isGroupOffer`, 5 more till-only settings, blank `rfid`/`pinHash` never sent, `D` to a shop a row moved away from, `licence.ids_conflict` official, `/login` ⇄ `/` loop fixed. EPOS answers 2026-09-30 applied (`ANSWERS-2026-09-30-portal.md`): keyed `D` always with payload, every-shop → one-shop `D` with each shop's own `branchId`, 409 `migrate.activate_first` (pending code), main-till deactivate without transfer code + next-step message | done |
| 2.9A | v1.4.1 alignment, part A | Contract swap to v1.4.1; entity store 145 schemas (new BranchPrice, PurchaseReturn(+Line), Setting, RolePermission; `local` SyncState/DomainEventRecord/ProcessedCommand acknowledged, never stored; new columns) by additive migrations; keyed Setting/RolePermission rows (ids derived from the payload, settings deny-list never stored); push reply `receivedAt` (after commit, retry = first time); BranchPrice pushed by its own shop only, pulled only by its shop, portal prices always new rows (`SetBranchPrice`); licence `expiresAt` without grace days, `minimumAppVersion` 0.1.0, no 426 on `licence/*`, 426 on `sync/*` only for listed versions; main-till `devices/deactivate` revokes + rotates the key it was sent (`apiKeyRevoked: true`); `X-SSPOS-Store-Protocol` informational | done |
| 2.9B | v1.4.1 alignment, part B | Relay (transfers to `toBranchId`, receipts to `fromBranchId`, customer ledger to the other branches, §10.2; transfer relay for 5.3) and ledger-derived customer balance/points (§10.1); settings + role permissions in the pull (keyed envelope, deny-list, §10.3); head-office purchase orders drafted on the portal and pulled by one shop (§10.6); portal edits of Company/Branch in the pull (§6.1); the `sync_conflicts` screen (review and resolve `hubEditNewer`, `hubVersionNewer`, `branchEditNewer`, `immutableChange`, `tenancyDelete`; `hubChange`). Built: pull feed = hub rows + keyed rows + relayed rows (`hub_version`/`origin_branch_id` on the 5 relayed tables, lines re-queued after a dispatched header / receipt) + head-office drafts + Company/Branch portal edits (`SentToTills`), `PullVisibility`/`PullEnvelopes`; `RecomputeCustomerBalances` after every push chunk (derived columns out of `hub_hash`); `SaveTillSetting`, `SetRolePermission`, `DraftHeadOfficeOrder` (Actions, no UI yet: 4.x/5.2); a conflict re-queues the kept hub row for the shop; tenant `/app/sync/conflicts` (`sync.manage`: owner, manager) with Portal review (filters, side-by-side, keep portal / use shop's / acknowledge, audited) and Shop clashes (read only, `hubChange`) | done |

Waves: 2.6 → 2.7 → 2.8.

## Phase 3: Reporting and dashboards — 3/3 (complete)

Contract: v1.4.1 `docs/web-portal-api/DASHBOARD.md` (formulas; we build them on **MySQL 8**, not PostgreSQL).

| # | Module | What | Status |
|---|---|---|---|
| 3.1 | Reporting tables | `rpt_*` tables updated idempotently as rows arrive; trading day in Europe/London; refunds subtracted. Built (`docs/reporting.md`): `rpt_sales_daily`, `_hourly`, `rpt_tender_daily`, `rpt_product_daily`, `rpt_vat_daily`, `rpt_staff_daily` per company/shop/till/trading day (DASHBOARD.md §4, MySQL 8 + SQLite); `sales.trading_day`/`trading_hour` stamped at ingest (PHP, DST-safe); the apply path marks touched shop-days dirty (old and new day) and a per-business job deletes + rebuilds them from raw rows (tokens, unique, no overlap) + minutely sweep; `reports:rebuild`, `reports:check [--fix]`, `reports:process-dirty`; read side `ReportScope` (tenant / admin) + `SalesReport`, `TenderReport`, `VatReport`, `ProductReport`, `StaffReport` → Data objects; tests: §6 worked example, idempotency, DST, refunds, voids, isolation, incremental == rebuild, fixed query count. Till 0.1.15 pack: order deposits and charity round-ups out of sales (own columns), staff discount apart, tenders one line per name | done |
| 3.2 | Admin dashboard: trading | Every Admin-panel tile and chart across all businesses, per business and shop. Built: **Trading** tab of the admin dashboard (`/admin/trading`, new `trading.view`: owner, support, accounts): presets today/yesterday/7/30 days/this/last month/custom, compare previous period/last week/last year/none (Today up to the same hour), drill-down business → shop in the URL; KPI tiles with change and sparklines (sales inc VAT, net, transactions, average basket, VAT, takings, refunds, discounts, voids, gross profit), sales by day or hour against the compare window, hourly pattern, tender mix, VAT by rate, top businesses and shops / shops / tills, top products; "Updated N min ago"; deferred prop + skeleton, empty states, light/dark, phone; reads `rpt_*` only (~14 grouped queries, fixed count, 60 s cache). `php artisan demo:sales` (real push path, deterministic, `--fresh`, refused in production) | done |
| 3.2b | Full demo data | `php artisan demo:seed [--company=] [--days=60] [--fresh]`: Khan Mini Mart (2 shops) and Singh Family Stores (1 shop) filled through the real push path for every portal page (catalogue, stock, purchasing, transfers, customers, staff time, offers, news, compliance, cash, journals, till health, sales on real products), `rpt_*` rebuilt; idempotent, `--fresh` removes only demo rows, refused in production (docs/reporting.md) | done |
| 3.3 | Business dashboard | Today / week / month, per shop and total, "last updated N minutes ago". Built: tenant `/app` (`reports.view`: owner, manager, accountant; staff see Shops and tills only) with every Business-panel tile and chart of DASHBOARD.md §2 (KPIs with compare and sparklines, cash variance, low stock, tills online, orders ready, sales by day/hour, hourly pattern, tender mix, VAT, sales by shop or till, top products, departments/categories, staff); presets today/yesterday/this week/7 and 30 days/this/last month/custom; shop = portal switcher, till in the URL; one-shop users (`company_user.branch_id`) fixed to their shop; per-shop "Updated N min ago"; empty state before the first sync; deferred + skeleton, light/dark, phone. Shared with 3.2 (`Reporting\Dashboard`, `components/shared/trading`); charts draw today as "so far" | done |

## Phase 4: Business panel (customer portal) — 10/10 (complete)

Contract: v1.4.1 §18.4. Roles: business owner, **shop manager** (one branch only).

| # | Module | Status |
|---|---|---|
| 4.1 | Portal users and roles (incl. branch-scoped shop manager) | done |
| 4.2 | Products, barcodes, units, departments, categories, CSV import | done |
| 4.3 | Prices and promotions (incl. per-shop price screen, `BranchPrice`). EPOS answers 2026-10-01: every offer type incl. `quantityPrice` tiers, days, past-midnight times, style items | done |
| 4.4 | Customers (ledger-based balance and points, statements, consent). Till 0.1.51 pack: owed / credit held, advances in the ledger, current pay dates and reminder state. Till 0.1.52 pack: pay dates relayed to every shop | done |
| 4.5 | Suppliers, payment types, reasons, staff users/PINs. EPOS answers 2026-10-01: till `pbkdf2$…` PIN hash, sent only when set/changed | done |
| 4.6 | Sales and receipts (refunds, voids) | done |
| 4.7 | Shops and tills (licence read-only, till status, "Ask for more tills") | done |
| 4.8 | Reports (sales, refunds, VAT, stock, Z) | done |
| 4.9 | Shop settings (receipt text, opening hours; per §18.6). Till 0.1.51 pack: customer accounts and reminders, shelf labels, product library, shop checks (choice and time types) | done |
| 4.10 | My subscription and invoices | done |

## Phase 5: Operations — 10/10 (complete)

| # | Module | Status |
|---|---|---|
| 5.1 | Stock (on hand, movements, stock takes, FIFO valuation, expiry) | done |
| 5.2 | Purchasing (POs, GRNs, supplier invoices, credit notes, purchase returns, payments, rebates); portal-created PO relayed in pull (§10.6) | done |
| 5.3 | Branch stock transfers (screens; the relay is built in 2.9B). EPOS answers 2026-10-01: till variance figures shown as sent | done |
| 5.4 | Cash and Z (shifts, Z reports, cash office, card settlement, day lock) | done |
| 5.5 | Accounts and VAT (expenses, VAT return, journals, fixed assets) | done |
| 5.6 | Staff (clock events, rota, timesheets, wages). EPOS answers 2026-10-01: till overtime rule (8 h/day), holiday estimate 12.07% | done |
| 5.7 | Compliance (age refusals, incidents, training, diary checks, licences, recalls). EPOS answers 2026-10-06: recalls raised and text-edited on the portal only (close / reopen / returns at a till), returns per shop from stock movements. Till 0.1.52 pack: open / closed per shop (`ProductRecallBranchState`) | done |
| 5.8 | Newspapers (titles, deliveries, returns, vouchers). EPOS answers 2026-10-01: VAT from the linked product, warnings | done |
| 5.9 | Seasonal events and opening hours. EPOS answers 2026-10-01: one company-scope `shop.trading_hours` line (≤ 200) | done |
| 5.10 | Pharmacy and parcels (dispensing, medicine classes, parcel carriers) | done |

## Phase 6: AI — 1/7

| # | Module | Status |
|---|---|---|
| 6.1 | AI foundation (client, tools, preview-then-confirm, metering) | done |
| 6.2 | Portal assistant | done |
| 6.3 | Morning summary | done |
| 6.4 | Reorder suggestions | done |
| 6.5 | Invoice import | done |
| 6.6 | Anomaly alerts | done |
| 6.7 | Admin AI | deferred (owner, 2026-10-01: build when the customer base needs it) |

## Phase 7: Finish and go-live — 1/6

| # | Module | Status |
|---|---|---|
| 7.1 | Apply design system v2 to the business panel and remaining screens | Pass 1 done: one filter location per page (global branch/date bar removed), tenant sidebar regrouped with collapsible groups and ability-hidden items, one breadcrumb trail (PageHeader), sentence-case table headers, shared `ChartLegend` / `ChartTooltipBox`, branded 403/404/500/503 pages, left-aligned select values, token-only colours in account settings, admin "Customers" Soon item removed. Pass 2 done (2026-10-01): light theme by default (dark a user choice), `reference-light-final.webp` look via tokens: white sidebar with the full logo + area label, light top bar with mint wave wash, green active pill, wider search with one-line ⌘K, welcome hero with sun icon (admin and tenant dashboards), admin "Recent activity" and "Business overview" status strip from real data, light auth panel with the full logo, search fields sized to their placeholder. Later pass: per-page spacing/density review, account settings forms onto `FormCard`, admin detail tabs on phones | done |
| 7.1b | Two-factor sign-in and audit log screens | Built: TOTP two-factor (pragmarx/google2fa, inline SVG QR via bacon/bacon-qr-code) required for every admin (set-up after the first password sign-in) and optional for portal users (Settings → Security), or required by the company owner for the whole business; 10 hashed one-time recovery codes shown once (copy/download), "remember this device" 30 days (signed cookie bound to the secret), 5 codes a minute then lockout (logged + audited), replayed codes refused; owner resets another admin's 2FA, support resets a portal user's (tenant page); impersonation needs the admin's own passed 2FA. Audit log: `/admin/audit-log` (owner, support: `audit.view`) and `/app/activity` (tenant `audit.view`, owner by default) with who/business/action/record/date filters, search, keyset paging, detail drawer with before/after diff and streamed CSV (export audited) | done |
| 7.2 | Gap analysis (competitors, UK compliance, legacy parity; `docs/research/`) | done (docs/research/gap-analysis.md; must-haves built: alerts, GDPR, exports, labels, catalogue, 2FA, audit log) |
| 7.3 | Security review | Review done; Fix A (H2, M1–M6, L1–L10, npm audit) done; H1 two-factor sign-in done (with audit log screens) | done |
| 7.4 | Deploy (MySQL server, HTTPS, queues, scheduler, backups, signing key generated on the server) | Kit ready, no server yet: `docs/deploy.md` runbook, `deploy/` (Ubuntu setup, Nginx, PHP-FPM, Supervisor, cron, logrotate, nightly backup, zero-downtime deploy + rollback), `.env.production.example`. Suite also runs on MySQL 8 (`composer test:mysql`, CI `mysql` job) |
| 7.5 | End-to-end testing with the EPOS team, go-live checklist | todo |
| 7.6 | Scale: partitioning, archiving, sharding, load test with 1,000 synthetic shops (`docs/scaling.md`; groundwork `companies.data_connection` done in 2.1) | todo |

## Phase 8: Later — 0/5

Web orders / click and collect · Online payments · Dealers and resellers · Public marketing site · EPOS update
channel.

---

## Order

Phase 2 done (2026-09-30) → 3.1 → Phases 3–6 in waves → 7. Phase 8 later.

Module numbers changed in plan v2: old 3.x (tenant portal) → 4.x, old 4.x (operations) → 5.x, old 5.x (AI) → 6.x.
