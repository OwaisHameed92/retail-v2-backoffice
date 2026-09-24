# Phases

Each module is one agent task. Modules in the same "wave" can run in parallel; a wave starts when the waves it
depends on are done. Status: `todo` · `doing` · `done` · `blocked (reason)`.

Every module is done only when: Actions + Pest tests (incl. tenant isolation and authorisation tests) pass, the
UI matches the layouts and design rules in `CLAUDE.md`, and pint, `php artisan test`, `npm run lint` and
`npm run build` are green.

---

## Phase 0: Foundation

Goal: an empty but solid shell every module builds on.

| # | Module | What | Status |
|---|---|---|---|
| 0.1 | Project setup | Laravel 12 React starter kit, contract fixtures in `docs/contracts`, `CLAUDE.md`, docs | done |
| 0.2 | Quality tooling | Larastan (level 6), Pint, `composer check` (pint + larastan + tests), route files for admin/app/api | done |
| 0.3 | Admin area shell | `admins` table + `admin` guard, admin login, `admin-layout.tsx` (sidebar from the admin mockup), admin roles (owner, sales, support, accounts) | done |
| 0.4 | Tenant area shell | Companies table stub, `company_user` membership, `BelongsToCompany` trait + global scope, `/app` routes, `app-layout.tsx` (sidebar + branch switcher + AI search bar placeholder), tenant roles | done |
| 0.5 | Shared building blocks | Audit log (who, what, before/after), ULID helpers, money cast, API error responder, data-table + filters component, stat card component | done |

Waves: 0.2 · then 0.3, 0.4, 0.5 in parallel.

---

## Phase 1: Onboarding and licensing

Goal: we can create a customer and give each till a working licence key.

| # | Module | What | Depends on | Status |
|---|---|---|---|---|
| 1.1 | Plans | Plan name, price per till per month/year, trial days, grace days, feature flags | 0.3 | done |
| 1.2 | Tenants | Company, branches, registers (ULIDs, same fields as till's Company/Branch/Register), status (trial, active, overdue, suspended, cancelled), admin screens, login-as-customer | 0.3, 0.4 | done |
| 1.3 | Licences | Key generation (format + check char, hash + last4), one per register, statuses, device binding, reset device, suspend/revoke/renew, audit log, admin screens | 1.1, 1.2 | done |
| 1.4 | Token signing | Ed25519 key pair management (`kid`), JWS issue + verify helper, key rotation command | 0.5 | done |
| 1.5 | Licence API | `activate`, `check-in`, `deactivate`, `keys` per `docs/specs/licence-api-v1.md`, rate limits, error body, clone alert | 1.3, 1.4 | done |
| 1.6 | Leads and trial approval | Lead list (new, contacted, approved, rejected), notes, follow-up date, one-click "approve 7-day trial" → tenant + keys + portal owner login + email | 1.2, 1.3, 1.7 | done |
| 1.7 | Emails | Mail templates: welcome + keys, trial reminder (day 5), trial ended, renewal, suspended; email log | 0.5 | done |
| 1.8 | Cash billing | Invoices per tenant, record cash payment → renew licences, overdue list, auto-suspend job after grace | 1.3 | done |
| 1.9 | Admin dashboard | Numbers (tenants, trials, licences, cash due), new leads, trials ending, till health placeholder | 1.2, 1.3, 1.6, 1.8 | todo |
| 1.10 | Public trial form | Simple page: name, business, email, phone, shops, tills, captcha → creates a lead | 1.6 | todo |

Waves: 1.1, 1.2, 1.4, 1.7 · then 1.3 · then 1.5, 1.6, 1.8 · then 1.9, 1.10.

Needs from EPOS team to go live (not to build): agreement on the licence spec, till-side implementation.

---

## Phase 2: Sync API and data store

Goal: tills push their data to us and pull our master data.

| # | Module | What | Depends on | Status |
|---|---|---|---|---|
| 2.1 | Sync keys | Per-branch sync API key (hash + last4), issue/revoke, returned on main-till activation | 1.5 | todo |
| 2.2 | Push endpoint | `POST /api/v1/sync/push`: gzip, ≤5,000 rows, store raw change log, idempotent on `(entity, entityId, version)` and `(branchId, seq)`, `acknowledgedSeq`, error codes | 2.1 | todo |
| 2.3 | Entity store: master data | Tables + upsert mappers for the 28 hub-owned entities (products, barcodes, categories, customers, users, promotions…) | 2.2 | done |
| 2.4 | Entity store: transactions | Tables + mappers for sales, payments, VAT, stock, shifts, Z reports, cash, purchasing, accounts, HR (till-owned, read-only) | 2.2 | done |
| 2.5 | Pull endpoint | Change feed with our own version counter per company, company-wide + branch rows, paging, `hello` | 2.3 | todo |
| 2.6 | Contract tests | Replay every sample, validate every reply against the JSON schemas | 2.2, 2.5 | todo |
| 2.7 | Sync monitoring | Per branch: last push/pull, errors, pending, conflicts; till online/version; admin "till health" page | 2.2, 1.5 | todo |

Waves: 2.1 · 2.2 · 2.3, 2.4 in parallel · 2.5 · 2.6, 2.7.

---

## Phase 3: Tenant portal core

Goal: the customer logs in and manages their catalogue and sees sales.

| # | Module | What | Depends on | Status |
|---|---|---|---|---|
| 3.1 | Portal users | Owner invites managers/accountants, roles + permissions | 0.4, 1.6 | todo |
| 3.2 | Dashboard | Numbers, hourly chart, alerts, top products, branches (per the mockup) | 2.4 | todo |
| 3.3 | Products | Products, barcodes, variants, units, departments, categories, suppliers link, CSV import | 2.3, 2.5 | todo |
| 3.4 | Pricing and promotions | VAT, price changes, promotions, coupons | 2.3, 2.5 | todo |
| 3.5 | Customers | Loyalty, credit limit, balance, statement, GDPR consent | 2.3, 2.4 | todo |
| 3.6 | Sales | Receipt list and detail, refunds, voids | 2.4 | todo |
| 3.7 | Branches and tills | Branch details, tills, licence and sync status | 1.3, 2.7 | todo |
| 3.8 | My subscription | Plan, invoices, licences | 1.8 | todo |
| 3.9 | Reports | Sales, profit, tender, hourly, top products, CSV/PDF export | 2.4 | todo |

Waves: 3.1, 3.7, 3.8 · 3.3, 3.4, 3.5, 3.6 in parallel · 3.2, 3.9.

---

## Phase 4: Operations

| # | Module | What | Status |
|---|---|---|---|
| 4.1 | Stock | Stock on hand per branch, movements, stock takes, FIFO valuation, expiry checks | todo |
| 4.2 | Purchasing | Suppliers, POs, GRNs, supplier invoices, credit notes, payments, standing orders, rebates | blocked (portal-created PO needs EPOS change) |
| 4.3 | Stock transfer | Between branches from portal | blocked (needs new till entity) |
| 4.4 | Branch prices | Per-branch price from portal | blocked (needs new till entity) |
| 4.5 | Cash and Z | Shifts, Z reports, variance, cash office, card settlement, day lock | todo |
| 4.6 | Accounts and VAT | Expenses, VAT return, P&L, journals, fixed assets, recurring bills | todo |
| 4.7 | Staff | Till users and roles, clock events, rota, timesheets | todo |
| 4.8 | Compliance | Age refusals, compliance licences, exception log, audit, recalls | todo |
| 4.9 | Newspapers | Titles, deliveries, returns | todo |
| 4.10 | Seasonal and hours | Seasonal events, opening hours | todo |
| 4.11 | Till settings | Settings and role permissions from portal | blocked (not synced yet) |

---

## Phase 5: AI

| # | Module | What | Status |
|---|---|---|---|
| 5.1 | AI foundation | Claude API client, tool registry over Actions (tenant-scoped), usage metering, preview-then-confirm for writes | done |
| 5.2 | Portal assistant | Ask questions about sales/stock; do changes with confirmation | todo |
| 5.3 | Morning summary | Daily email/WhatsApp digest per company | todo |
| 5.4 | Reorder suggestions | PO drafts from sales and stock | todo |
| 5.5 | Invoice import | Supplier invoice PDF/photo → products, GRN | todo |
| 5.6 | Anomaly alerts | Voids, refunds, cash variance | todo |
| 5.7 | Admin AI | Lead summaries, trial conversion hints, sync error triage | todo |

---

## Phase 6: Later

Web orders (click and collect), EPOS versions + update API, online payments, public marketing site, resellers.
