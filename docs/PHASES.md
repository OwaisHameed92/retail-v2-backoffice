# Phases

Plan v2 (2026-09-28), rewritten after the EPOS team's contract v1.3.1; **current contract v1.4.1** (2026-09-29,
`docs/contracts/portal-api-v1.4.1/`, start at `START-HERE.md` and `docs/web-portal-api/ANSWERS-2026-09-29.md`). The
till is already built against that contract, so **the portal implements it exactly**; where our earlier modules
differ, they are reworked (marked 🔄). Earlier contract folders (v1.1, v1.3.3) were removed when the generator moved on.

Each module is one agent task. Modules in the same wave can run in parallel. Status: `done` · `rework` · `todo` ·
`blocked (reason)`. A module is done only when its Actions + Pest tests (tenant isolation and authorisation
included) pass, the UI follows `docs/BRAND.md`, and `composer check`, `npm run lint`, `npx tsc --noEmit` and
`npm run build` are green.

Totals: **64 modules · 27 done · 37 todo** (2026-09-29, counted from the tables of phases 0–7; 2.9 split into A and
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

## Phase 2: Sync and cloud link — 8/10

Contract: v1.4.1 §1–16, §19, §21 (duplicate/echo/conflict rules + test list 19.4), §20, `docs/web-portal-api/openapi.yaml`.

| # | Module | What | Status |
|---|---|---|---|
| 2.1 | IDs and sync keys | `id_map` (adopt the first till company id, alias later ones, branch = the key's branch, register = the key's register; conflicts → 409 `licence.ids_conflict` + alert) and `IdTranslator` at the edge (ApplySyncChanges; `toTill()` for 2.5 pull); per-branch sync key (`SSK-…`, 160 bits, HMAC + last 4) sent as `apiKey` (+ `hubUrl`) to the main till of a `cloud_sync` licence; admin Sync key panel (generate shown once, send new key to till, revoke; old key 7 days' grace); `AuthenticateSyncKey` for `/api/v1/sync/*`; feature names = the till's 11; `k290bee23` in `.env.example`. **`devices/activate` is not built** (the till never calls it) | done |
| 2.2 | Hello and push | `sync/hello`, `sync/push` (gzip, 5,000 rows, idempotent, ordered, acknowledged, initial mode), branch key auth, to the v1.4.1 `hello-reply` and `error-reply` schemas. Built: contract/header checks, per-key rate limit, per-branch lock (503 `server.busy`), Idempotency-Key replay, 413/422 per §9, initial uploads kept apart by upload id, `sync_branch_status` for 2.7; 5,000-row gzip push ≈ 0.7 s | done |
| 2.3 | Entity store: master data | 145 entities of v1.4.1 via additive migrations (`database/till-schema.json`); an entity the portal does not know yet is kept raw in `till_unknown_rows` (§18.8, §21.1) | done |
| 2.4 | Entity store: transactions | Applier to §19: never twice (ledger + version), never backwards (version, tie by `updatedAt`), never echoed (`hub_hash`, `origin_branch_id`), `baseVersion` ready, `portal_received_at`; §19.4 store tests | done |
| 2.5 | Pull | `GET sync/pull`: per-company counter (`sync_hub_counters`, row lock; after-commit stamp in `HubOwnedRow`, `PublishHubChange` for non-Eloquent writes, rows accepted from tills stamped at the next pull, parents first), hub-owned rows only, company-wide + addressed to the branch, never back to `origin_branch_id` (19.2), paging, till ids (`toTill`), no secrets, gzip reply, `sync_branch_status` last pull; 5,000-row page ≈ 0.6 s. The `sync_conflicts` screen came in 2.9B | done |
| 2.6 | Contract tests | Replay every sample, validate against schemas, pass the §19.4 test list. Built (`docs/contract-tests.md`): `opis/json-schema` (draft 2020-12, cross-file `$ref`) replaces the hand-written subset; `ContractReplyGuard` checks every till reply of every feature test (reply schemas, pull payloads per entity schema, token payloads, error codes and statuses per `error-codes.json`); all 84 sample files schema-validated and mapped to their replaying tests (a guard fails on an unmapped sample; 2.8/Phase 8/deprecated ones marked pending or not applicable); static + runtime error-code check; §21.8 log spy; §19.4 items 1–14 mapped to tests (new HTTP tests for #1–3, #6–7, #10, #14). Contract fixes: licensing errors always carry `details`; framework 403/404/405 on till URLs → 400 `request.invalid`; a decimal with more places than the contract allows → `row.invalid` (never rounded); unknown entities' secret-looking members redacted; a portal-deleted row stays deleted (conflict) | done |
| 2.7 | Till health | Online/offline, versions, last push/validate per branch and till, alerts | todo |
| 2.8 | Local keys and migration | `licence/redeem` (local key reports, 17.6/17.16), `cloud/migrate` + `migrate/complete` + initial push (17.8) | todo |
| 2.9A | v1.4.1 alignment, part A | Contract swap to v1.4.1; entity store 145 schemas (new BranchPrice, PurchaseReturn(+Line), Setting, RolePermission; `local` SyncState/DomainEventRecord/ProcessedCommand acknowledged, never stored; new columns) by additive migrations; keyed Setting/RolePermission rows (ids derived from the payload, settings deny-list never stored); push reply `receivedAt` (after commit, retry = first time); BranchPrice pushed by its own shop only, pulled only by its shop, portal prices always new rows (`SetBranchPrice`); licence `expiresAt` without grace days, `minimumAppVersion` 0.1.0, no 426 on `licence/*`, 426 on `sync/*` only for listed versions; main-till `devices/deactivate` revokes + rotates the key it was sent (`apiKeyRevoked: true`); `X-SSPOS-Store-Protocol` informational | done |
| 2.9B | v1.4.1 alignment, part B | Relay (transfers to `toBranchId`, receipts to `fromBranchId`, customer ledger to the other branches, §10.2; transfer relay for 5.3) and ledger-derived customer balance/points (§10.1); settings + role permissions in the pull (keyed envelope, deny-list, §10.3); head-office purchase orders drafted on the portal and pulled by one shop (§10.6); portal edits of Company/Branch in the pull (§6.1); the `sync_conflicts` screen (review and resolve `hubEditNewer`, `hubVersionNewer`, `branchEditNewer`, `immutableChange`, `tenancyDelete`; `hubChange`). Built: pull feed = hub rows + keyed rows + relayed rows (`hub_version`/`origin_branch_id` on the 5 relayed tables, lines re-queued after a dispatched header / receipt) + head-office drafts + Company/Branch portal edits (`SentToTills`), `PullVisibility`/`PullEnvelopes`; `RecomputeCustomerBalances` after every push chunk (derived columns out of `hub_hash`); `SaveTillSetting`, `SetRolePermission`, `DraftHeadOfficeOrder` (Actions, no UI yet: 4.x/5.2); a conflict re-queues the kept hub row for the shop; tenant `/app/sync/conflicts` (`sync.manage`: owner, manager) with Portal review (filters, side-by-side, keep portal / use shop's / acknowledge, audited) and Shop clashes (read only, `hubChange`) | done |

Waves: 2.6 → 2.7 → 2.8.

## Phase 3: Reporting and dashboards — 0/3

Contract: v1.4.1 `docs/web-portal-api/DASHBOARD.md` (formulas; we build them on **MySQL 8**, not PostgreSQL).

| # | Module | What | Status |
|---|---|---|---|
| 3.1 | Reporting tables | `rpt_*` tables updated idempotently as rows arrive; trading day in Europe/London; refunds subtracted | todo |
| 3.2 | Admin dashboard: trading | Every Admin-panel tile and chart across all businesses, per business and shop | todo |
| 3.3 | Business dashboard | Today / week / month, per shop and total, "last updated N minutes ago" | todo |

## Phase 4: Business panel (customer portal) — 0/10

Contract: v1.4.1 §18.4. Roles: business owner, **shop manager** (one branch only).

| # | Module | Status |
|---|---|---|
| 4.1 | Portal users and roles (incl. branch-scoped shop manager) | todo |
| 4.2 | Products, barcodes, units, departments, categories, CSV import | todo |
| 4.3 | Prices and promotions (incl. per-shop price screen, `BranchPrice`) | todo |
| 4.4 | Customers (ledger-based balance and points, statements, consent) | todo |
| 4.5 | Suppliers, payment types, reasons, staff users/PINs | todo |
| 4.6 | Sales and receipts (refunds, voids) | todo |
| 4.7 | Shops and tills (licence read-only, till status, "Ask for more tills") | todo |
| 4.8 | Reports (sales, refunds, VAT, stock, Z) | todo |
| 4.9 | Shop settings (receipt text, opening hours; per §18.6) | todo |
| 4.10 | My subscription and invoices | todo |

## Phase 5: Operations — 0/10

| # | Module | Status |
|---|---|---|
| 5.1 | Stock (on hand, movements, stock takes, FIFO valuation, expiry) | todo |
| 5.2 | Purchasing (POs, GRNs, supplier invoices, credit notes, purchase returns, payments, rebates); portal-created PO relayed in pull (§10.6) | todo |
| 5.3 | Branch stock transfers (screens; the relay is built in 2.9B) | todo |
| 5.4 | Cash and Z (shifts, Z reports, cash office, card settlement, day lock) | todo |
| 5.5 | Accounts and VAT (expenses, VAT return, journals, fixed assets) | todo |
| 5.6 | Staff (clock events, rota, timesheets, wages) | todo |
| 5.7 | Compliance (age refusals, incidents, training, diary checks, licences, recalls) | todo |
| 5.8 | Newspapers (titles, deliveries, returns, vouchers) | todo |
| 5.9 | Seasonal events and opening hours | todo |
| 5.10 | Pharmacy and parcels (dispensing, medicine classes, parcel carriers) | todo |

## Phase 6: AI — 1/7

| # | Module | Status |
|---|---|---|
| 6.1 | AI foundation (client, tools, preview-then-confirm, metering) | done |
| 6.2 | Portal assistant | todo |
| 6.3 | Morning summary | todo |
| 6.4 | Reorder suggestions | todo |
| 6.5 | Invoice import | todo |
| 6.6 | Anomaly alerts | todo |
| 6.7 | Admin AI | todo |

## Phase 7: Finish and go-live — 0/6

| # | Module | Status |
|---|---|---|
| 7.1 | Apply design system v2 to the business panel and remaining screens | todo |
| 7.2 | Gap analysis (competitors, UK compliance, legacy parity; `docs/research/`) | todo |
| 7.3 | Security review | todo |
| 7.4 | Deploy (MySQL server, HTTPS, queues, scheduler, backups, signing key generated on the server) | todo |
| 7.5 | End-to-end testing with the EPOS team, go-live checklist | todo |
| 7.6 | Scale: partitioning, archiving, sharding, load test with 1,000 synthetic shops (`docs/scaling.md`; groundwork `companies.data_connection` done in 2.1) | todo |

## Phase 8: Later — 0/5

Web orders / click and collect · Online payments · Dealers and resellers · Public marketing site · EPOS update
channel.

---

## Order

Phase 2 (2.6 → 2.7 → 2.8) → 3.1 → Phases 3–6 in waves → 7. Phase 8 later.

Module numbers changed in plan v2: old 3.x (tenant portal) → 4.x, old 4.x (operations) → 5.x, old 5.x (AI) → 6.x.
