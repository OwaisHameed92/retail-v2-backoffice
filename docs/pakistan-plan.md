# Pakistan launch plan (country-ready portal)

Started 2026-10-06. Owner decision: one codebase, one instance per country. The Pakistan portal is a second
deployment of this repo with its own server, database, domain, signing key and backups. Nothing is shared at run
time with the UK portal. The country is picked by one setting, `COUNTRY=GB|PK` (default `GB`).

**Rule for every phase: the UK behaves exactly as today.** The whole suite runs with the default `COUNTRY=GB` and must
stay green with no changed expectations. Pakistan gets its own tests (`COUNTRY=PK` set per test).

## UK safety rules (owner, 2026-10-06: live UK tenants are onboarding; the UK must not be affected)

1. **Separate branch.** Pakistan work is done on `pakistan/*` branches in a separate worktree, never straight on
   `main`. `main` is what the UK server runs.
2. **Merge only when proven.** A phase is merged into `main` only after the full suite passes on SQLite **and** MySQL
   with the default `COUNTRY=GB`, with **no existing test expectation changed** (a changed UK expectation means the UK
   changed: stop and fix). Every phase also adds GB "golden" tests that pin today's UK output (money `£1,234.50`,
   London times, VAT wording, postcode and phone rules, Direct Debit billing).
3. **Default is the UK.** A missing or unknown `COUNTRY` is `GB`. The UK server also gets `COUNTRY=GB` written in its
   `.env` explicitly. No database migration may change or move existing UK data; new columns are nullable or have the
   UK value as default.
4. **No UK deploy just for Pakistan.** Pakistan code reaches the UK server only with a normal UK release, after rule 2,
   deployed with `deploy/push-release.sh` (health check and automatic rollback), at a quiet time, then checked on the
   live UK portal (pages, sync, billing).
5. **Small phases.** One phase = one merge = one deploy, so any surprise is small and easy to roll back
   (`deploy/rollback.sh`).

## What is UK-specific today (inventory 2026-10-06)

| Area | Where | Size |
|---|---|---|
| Currency `£` / `GBP` | TS: 42 files, about 20 `Intl.NumberFormat('en-GB', {currency: 'GBP'})` formatters (`shared/trading/format.ts`, `admin/billing/money.ts`, `admin/plans/plan-format.ts`, `app/products/fields.tsx`…). PHP: about 25 files (`Mail/Support/MailFormat`, `Reporting/Reports/ReportCsv`, `Labels/Support/*`, anomaly detectors, requests' messages) | Large |
| Time zone `Europe/London` | PHP: 66 files (consts `TIMEZONE`, `LONDON`, `ZONE`; `config/reporting.php`, `config/till-health.php`). TS: 33 files (`timeZone: 'Europe/London'`) | Large |
| Locale `en-GB` | TS: 85 files (numbers, dates) | Large, mechanical |
| VAT wording | TS: 80 files mention VAT. UK-only: the VAT return (HMRC 9 boxes: `Accounts/Queries/VatReturnHelper`, `Accounts/Data/VatQuarter`, `pages/app/accounts/vat*.tsx`, `components/app/accounts/vat-boxes.tsx`) | Medium |
| Company identifiers | Companies House number, VAT number (`Admin/TenantRules`, `admin/tenants/company-fields.tsx`) | Small |
| Address and phone | UK postcode regex (`Admin/TenantRules`), UK phone examples (`LeadRequest`, `StoreTrialRequest`, `Leads/Support/PhoneDigits`) | Small |
| Billing | Monthly fees are always GoCardless Direct Debit (docs/billing-flow.md). GoCardless: 85 PHP files. An empty token already turns Direct Debit off ("cash only") | Large (behaviour) |
| Emails and legal text | Mail templates, privacy/ICO wording, trial site | Medium |

The till contract has no company currency or country yet (only `exchange_rates` and `sale_payments.currency`). The
till side (FBR, Rs, GST, Urdu receipt, PK installer) is the EPOS team's work, asked for on 2026-10-06.

## Phases

| # | Phase | What | Est. |
|---|---|---|---|
| P0 | **Country foundation** (done 2026-10-06, branch `pakistan/p0-foundation`) | `config/country.php` with a profile per country (GB, PK): currency code and symbol, display decimals, number locale, time zone, tax name (VAT / GST), tax ids (VAT number / NTN, STRN), company id (Companies House / SECP), address rules (postcode required or not, pattern), phone example and pattern, billing collection (`gocardless` / `manual`), feature flags (`vatReturn`, later `fbr`). A `Country` class (PHP) reads it; shared to every Inertia page as `country`. TS `lib/country.ts`: `formatMoney`, `formatNumber`, `formatDate`, `formatDateTime` that read the shared profile. Tests: GB profile equals today's constants; PK profile values | 2 days |
| P1 | **Time zone** (done 2026-10-06, branch `pakistan/p1-timezone`) | Replace the 66 PHP and 33 TS hard-coded `Europe/London` with the profile's zone (`Country::timezone()`, `config('reporting.timezone')` defaulting to it). Trading days, reports (`rpt_*`), till health, AI budgets, schedules | 2 days |
| P2 | **Money and numbers** (done 2026-10-06, branch `pakistan/p2-money`) | Every TS formatter and every `£` goes through `lib/country.ts`; PHP through one `MoneyFormat` (mail, CSV, labels, anomaly text, validation messages) | 3 days |
| P3 | **Tax wording and UK-only features** (done 2026-10-06, branch `pakistan/p3-tax`) | "VAT" → the profile's tax name in labels; the HMRC VAT return shown only where `vatReturn` is on; VAT number / Companies House fields per profile (NTN, STRN, SECP for PK) | 2 days |
| P4 | **Address, phone, forms** (done 2026-10-06, branch `pakistan/p4-address`) | Postcode rule and phone pattern per profile (tenant, supplier, lead, trial forms) | 1 day |
| P5 | **Billing without Direct Debit** (done 2026-10-06, branch `pakistan/p5-billing`) | Profile `collection: manual`: monthly and yearly fees become invoices paid by hand (bank transfer, JazzCash, Easypaisa, cash), recorded by an admin; reminders before and after the due date; the same grace and 7-day suspension. GoCardless untouched for GB. Plans and prices per instance (PKR) | 4–5 days |
| P6 | **Emails and legal text** (done 2026-10-06, branch `pakistan/p6-content`) | Mail templates without UK-only wording; privacy text per country; support contact per instance | 1 day |
| P7 | **Pakistan server** (done 2026-10-07: pak-pos.sspos.co.uk on the same VPS, see docs/deploy.md) | Second VPS, domain, `.env` (`COUNTRY=PK`, `APP_TIMEZONE`), new licence signing key and its certificate from EPOS (public-key handover), Redis, MySQL tuning, nightly and offsite backups (own B2 bucket), no GoCardless | 1 day |
| P9 | **UK sweep** (done 2026-10-07, branch `pakistan/p9-uk-sweep`) | Code sweep and a crawl of every page of a local PK copy for UK-only things a Pakistan user can see; the findings, fixes, owner decisions and EPOS questions are in `docs/pakistan-uk-sweep.md`. Nation hidden on PK, sample places, money and holiday wording, till setting help, AI prompts, mail previews | 1 day |
| P10 | **UK modules** (done 2026-10-07, branch `pakistan/p10-modules`; owner decision on `docs/pakistan-uk-sweep.md` "For the owner to decide") | New profile flags (GB on, PK off; `CountryModules`): deposit return scheme, lottery, alcohol licensing, HFSS, vaping duty, the admin "Load starter set" and pharmacy are hidden on PK (fields, settings, permission row, menu; routes 404). Till data syncs and is stored as before; portal forms keep hidden values. "End in 9" rounds to whole rupees on PK; the accounting export suggests Pakistani tax codes | 1 day |
| P8 | **FBR and till contract** | When EPOS sends the PK contract fields (Sale `fbrInvoiceNumber`, FBR status / QR; Branch `posId`, `ntn`, `strn`; Company `country`, `currency`): store, show on sales and reports, FBR status report | Depends on EPOS |

Order: P0 → P1 → P2 → P3 → P4 → P6 → P5 → P7 → P8. Each phase is its own commit and deploy; the UK portal keeps
running the default profile throughout.

**Not in scope now:** Urdu interface (English is normal for business software in Pakistan; receipts in Urdu are the
till's), provincial sales tax for restaurants (PRA, SRB), a card or wallet payment gateway (later; manual first).

## Owner decisions needed

| Decision | Needed by | Options |
|---|---|---|
| Domain | P7 | **Decided 2026-10-06:** `pak-pos.sspos.co.uk`, on the same VPS as the UK portal (187.124.113.13) for now: its own app folder, database, Redis prefix/DB, queue workers, cron, backups bucket folder and signing key; nothing shared with the UK at run time |
| Money display | Decided 2026-10-06 | Whole rupees `Rs 1,250` (stored values keep 2 decimals) and lakh grouping `1,25,000` (plain numbers too); in the PK profile since P0 |
| Plans and prices in PKR | Before PK go-live | setup fee, monthly per till: entered by the admin on the PK instance (Admin → Plans); nothing seeded (P5) |
| How customers pay | Decided 2026-10-06 | Bank transfer, JazzCash, Easypaisa, cash (PK profile `billing.manualMethods`, built in P5); gateway later |
| Seller company and pay accounts | P5 / P7 | **Decided 2026-10-06:** Pakistan is treated as a Pakistani business, never the UK company. The company is not registered in Pakistan yet: every seller detail is optional (only filled `BILLING_SELLER_*` values show; at minimum the trading name "Switch & Save"; no UK registration, VAT number or address ever on PK). NTN / STRN / SECP number and address are added to `.env` once registered, no code change. Cash first; bank / JazzCash / Easypaisa accounts added later through `.env` (empty = not shown) |
| First kind of shops | P8 | kiryana, mobile, pharmacy, garments (sets the FBR priority) |

## Testing

- Default suite (`COUNTRY=GB`, SQLite and MySQL) unchanged and green after every phase.
- PK tests: profile values, pages render `Rs` and Karachi times, VAT return hidden, manual monthly billing flow.
- Before P7: a full PK smoke run (`COUNTRY=PK php artisan test --group=country-pk`).
