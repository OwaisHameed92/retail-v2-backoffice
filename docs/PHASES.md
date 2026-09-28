# Phases

Plan v2 (2026-09-28), rewritten after the EPOS team's contract **v1.3.1** (`docs/contracts/portal-api-v1.3.1/`,
start at `START-HERE.md`). The till is already built against that contract, so **the portal implements it exactly**;
where our earlier modules differ, they are reworked (marked 🔄). The v1.1 folder stays only until module 2.3 moves
the generator to v1.3.1.

Each module is one agent task. Modules in the same wave can run in parallel. Status: `done` · `rework` · `todo` ·
`blocked (reason)`. A module is done only when its Actions + Pest tests (tenant isolation and authorisation
included) pass, the UI follows `docs/BRAND.md`, and `composer check`, `npm run lint`, `npx tsc --noEmit` and
`npm run build` are green.

Totals: **65 modules · 14 done · 4 rework · 47 todo.**

---

## Phase 0: Foundation — 5/5

| # | Module | Status |
|---|---|---|
| 0.1 | Project setup, contract fixtures, docs | done |
| 0.2 | Quality tooling (Pint, Larastan 6, Pest, `composer check`, CI) | done |
| 0.3 | Admin area (guard, roles, admin users) | done |
| 0.4 | Tenant area (companies, memberships, fail-closed company scope) | done |
| 0.5 | Shared building blocks (audit log, money, API errors, data table, toaster) | done |

## Phase 1: Onboarding and licensing — 6/11

Contract: v1.3.1 `docs/web-portal-api.md` §17 (read 17.15–17.17 first), `specs/licensing.md`, `licensing/schemas`,
`licensing/samples`.

| # | Module | What | Status |
|---|---|---|---|
| 1.1 | Plans | Price per till, trial days, features | done |
| 1.2 | Tenants | Company → branches → registers, suspend, login as customer | done |
| 1.3 | Licences (admin) | One key per till, renew/reset/reissue/suspend/revoke, alerts, search | done |
| 1.6 | Leads and trial approval | Leads, board, one-click 7-day trial | done |
| 1.7 | Emails | Branded templates, email log, previews | done |
| 1.8 | Cash billing | Invoices + PDF, payments, auto-renew, overdue suspension | done |
| 1.4 | Token signer | `SSPOS1.<payload>.<sig>` tokens (§17.2) via `SsposTokenSigner`/`SsposTokenVerifier` + `LicenceClaims`, kid = `k` + 8 hex of SHA-256(public key), `signerCert` in every token (§17.17, `licence:keys:import-cert`), `licence:keys:handover`; worked examples reproduced byte for byte. Old JWS classes stay until 1.5 switches | done |
| 1.5 | Licence API | `POST licence/activate` (17.15.1), `licence/validate` (17.15.2) per till and `devices/deactivate` (17.7) exactly as contract v1.3.1: headers `X-SSPOS-Contract` (echoed), `X-SSPOS-App-Version` (426), `X-SSPOS-Install-Id`, `Idempotency-Key` replay (24 h); statuses active/expiring/expired/suspended/revoked/released; error codes of `error-codes.json` with `details`; §17.12 rate limits + 5 wrong keys/install/15 min; SSPOS1 tokens with full claims, new token only on change; admin **Release**, install code/id, clock skew, lock state on the licence page; `licence:simulate` speaks the contract. Old check-in API, JWS classes and our draft docs removed; contract tests replay the samples | done |
| 1.11 | Licence form v1.3 | Customer/licence form with every token field (17.16): kind trial/full, validFrom/expiresAt length, `maxRegisters` per branch, `limits.branches`, `multi_branch`, features (till's snake_case names), `company` block; "Till 1 of 3" issuing with limits; installId/installCode, clock skew, lock state; **Release** key; activate-by date; re-sign branch keys on change | todo |
| 1.9 | Admin dashboard | Customers, trials, licences, leads, cash due (sales tiles come in 3.2) | todo |
| 1.10 | Public trial form | Sign-up page → lead | todo |

Waves: **1.4 → 1.5 + 1.11** · then 1.9, 1.10.

## Phase 2: Sync and cloud link — 0/9 (2 rework)

Contract: v1.3.1 §1–16, §19 (duplicate/echo/conflict rules + test list 19.4), §20, `docs/web-portal-api/openapi.yaml`.

| # | Module | What | Status |
|---|---|---|---|
| 2.1 | Activation codes and devices | Per-branch activation code (single use, hashed, expiring) and setup e-mail; `devices/activate` (17.4: ids, hubUrl, branch API key shown once, token, `settingsBootstrap`); `devices/deactivate` / transfer codes | blocked (EPOS answer on ids and one-code onboarding) |
| 2.2 | Hello and push | `sync/hello`, `sync/push` (gzip, 5,000 rows, idempotent, ordered, acknowledged, initial mode), branch key auth | todo |
| 2.3 | Entity store: master data | 🔄 Regenerate for 140 entities (17 new, 11 changed), generator reads v1.3.1, drop v1.1 folder | rework |
| 2.4 | Entity store: transactions | 🔄 Same regeneration + §19 never-twice/never-backwards rules in the applier | rework |
| 2.5 | Pull | Hub version counter, company + branch rows, paging, echo prevention (19.2), conflicts | todo |
| 2.6 | Contract tests | Replay every sample, validate against schemas, pass the §19.4 test list | todo |
| 2.7 | Till health | Online/offline, versions, last push/validate per branch and till, alerts | todo |
| 2.8 | Local keys and migration | `licence/redeem` (local key reports, 17.6/17.16), `cloud/migrate` + `migrate/complete` + initial push (17.8) | todo |
| 2.9 | v1.4 readiness | `receivedAt`, several tills per shop, ledger-derived customer balance, transfer relay, settings/permissions sync | blocked (v1.4 spec) |

Waves: 2.3 + 2.4 → 2.2 → 2.5 → 2.6 + 2.7 · 2.1 and 2.8 when unblocked.

## Phase 3: Reporting and dashboards — 0/3

Contract: v1.3.1 `docs/web-portal-api/DASHBOARD.md` (formulas; we build them on **MySQL 8**, not PostgreSQL).

| # | Module | What | Status |
|---|---|---|---|
| 3.1 | Reporting tables | `rpt_*` tables updated idempotently as rows arrive; trading day in Europe/London; refunds subtracted | todo |
| 3.2 | Admin dashboard: trading | Every Admin-panel tile and chart across all businesses, per business and shop | todo |
| 3.3 | Business dashboard | Today / week / month, per shop and total, "last updated N minutes ago" | todo |

## Phase 4: Business panel (customer portal) — 0/10

Contract: v1.3.1 §18.4. Roles: business owner, **shop manager** (one branch only).

| # | Module | Status |
|---|---|---|
| 4.1 | Portal users and roles (incl. branch-scoped shop manager) | todo |
| 4.2 | Products, barcodes, units, departments, categories, CSV import | todo |
| 4.3 | Prices and promotions (incl. per-shop price screen; till row pending on EPOS) | todo |
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
| 5.2 | Purchasing (POs, GRNs, supplier invoices, credit notes, payments, rebates) | blocked (portal-created PO) |
| 5.3 | Branch stock transfers (entities now in contract; relay in v1.4) | todo |
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

## Phase 7: Finish and go-live — 0/5

| # | Module | Status |
|---|---|---|
| 7.1 | Apply design system v2 to the business panel and remaining screens | todo |
| 7.2 | Gap analysis (competitors, UK compliance, legacy parity; `docs/research/`) | todo |
| 7.3 | Security review | todo |
| 7.4 | Deploy (MySQL server, HTTPS, queues, scheduler, backups, signing key generated on the server) | todo |
| 7.5 | End-to-end testing with the EPOS team, go-live checklist | todo |

## Phase 8: Later — 0/5

Web orders / click and collect · Online payments · Dealers and resellers · Public marketing site · EPOS update
channel.

---

## Order

1. Now: EPOS message and questions (sent by the owner).
1b. **UI v2 (owner priority):** design system v2 (`docs/design/DESIGN-SYSTEM-v2.md`) on the admin shell and every existing admin screen — can run in parallel with 1.4.
2. **1.4 → 1.5 + 1.11**: licensing to the v1.3.1 contract → first real test with the EPOS team.
3. 1.9, 1.10, then **Phase 2** (2.3/2.4 rework first).
4. Phase 3 → Phase 4 → Phase 5 → Phase 6.
5. Phase 7, then Phase 8.

Module numbers changed in plan v2: old 3.x (tenant portal) → 4.x, old 4.x (operations) → 5.x, old 5.x (AI) → 6.x.
