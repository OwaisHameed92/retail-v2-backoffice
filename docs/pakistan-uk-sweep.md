# Pakistan P9: UK-specific things a Pakistan user can see

Started 2026-10-07 after the owner opened the live Pakistan portal (`COUNTRY=PK`) and still saw UK-only things, such as
the shop form's "Nation" select (England, Scotland, Wales, Northern Ireland). Rule as for every Pakistan phase: the UK
portal is live, so GB output stays byte-identical (golden tests in `tests/Feature/Shared/CountryUkSweepTest.php`).

## How the list was made

1. **Code sweep.** Every user-visible string in `resources/js`, `resources/views` and `app` (labels, help, placeholders,
   examples, validation messages, mail, PDF and CSV text, AI prompts and tool descriptions, sample and demo text, select
   options and enum labels) grepped for: England, Scotland, Wales, Northern Ireland, Nation, UK, United Kingdom,
   British, Britain, GB, HMRC, MTD, Making Tax Digital, Companies House, ICO, GDPR, council, NHS, National Insurance,
   PAYE, Royal Mail, Evri, DPD, Parcelforce, PayPoint, Payzone, lottery, Challenge 25, PASS, Natasha's Law, FSA, Food
   Standards, Trading Standards, BBFC, DVLA, Ofcom, sort code, bank holiday, pounds, pence, "p" suffixes, £, GBP, en-GB,
   Europe/London, postcode, county, UK towns (Leeds, Bradford, London, Manchester, Birmingham…), +44, 07700, 0113,
   .co.uk, VAT outside `taxText`, minimum unit pricing, Sunday trading, excise and duty, deposit return (DRS), HFSS,
   National Minimum / Living Wage, personal and premises licences, tobacco track and trace. Strings already routed
   through the P0–P6 helpers (`taxText`, `ukOnly`, `LocalText`, `keepsUkStyles`, `ContactRules`…) were checked and left.
2. **Page crawl.** A local Pakistan copy in the worktree: scratch SQLite file (`DB_DATABASE` outside the repo),
   `COUNTRY=PK`, migrated, `db:seed` and `demo:seed` (data: the demo businesses), a local test admin; the PHP built-in
   server on 127.0.0.1:8791. Each page was loaded in a browser (rendered text) and its Inertia props were read, then both
   scanned for the terms above: **143 portal and settings pages, 37 admin and guest pages, 20 email previews, 13 CSV
   exports** (every GET route of `php artisan route:list` with sample ids; `/app/sales/export` timed out on the demo
   volume, `/app/accounts/vat` is a 404 on PK since P3). The crawl was run again after the fixes.

## Result

**74 hits** (one hit = one UK thing at one place in the code; the same text on many pages counts once):
**48 fixed** behind the country profile, **16 modules or features listed for an owner decision**, **10 instance or
data items** that are not code. GB is unchanged in every case.

### Fixed (GB unchanged, PK neutral or local)

| # | Where a PK user saw it | UK text | Source | Fix |
|---|---|---|---|---|
| 1 | Admin → New business, branch dialog | "Nation" select: England, Scotland, Wales, Northern Ireland; hint "Sets deposit return and licensing rules on the till." | `components/admin/tenants/branch-fields.tsx`, `Tenancy/Enums/Nation.php`, `Admin/TenantController` | New profile key `nations` (GB the four, PK none). `Nation::options()` returns the profile's; the field is hidden when there are none. The column keeps its default (see EPOS questions) |
| 2 | Admin → Leads → Approve trial | "Nation" column and select per shop | `components/admin/leads/approve-trial-dialog.tsx`, `Leads/LeadController` | Hidden when `nations` is empty (own grid without the column) |
| 3 | Admin → business page, branch card | "12 Main Boulevard · England" | `components/admin/tenants/branch-card.tsx`, `Tenancy/Data/TenantData` | `nationLabel` null on PK (`Nation::shownLabel()`), not shown |
| 4 | Portal → Shops → a shop | "Code LDS · England" | `pages/app/shops/show.tsx`, `Shops/Queries/ShopDetail` | `nation` null on PK, not shown |
| 5 | AI assistant (company overview tool) | `nation: england` sent to the model | `Ai/Tools/GetCompanyOverview` | Omitted where the profile has no nations |
| 6 | Branch dialog hint | "Usually the town or street, e.g. Leeds." | `branch-fields.tsx` | `localPlaces()` → "e.g. Lahore" (new profile key `samplePlaces`) |
| 7 | Branch dialog hint | "receipt numbers like LDS-01-000482" | `branch-fields.tsx` | `localPlaces()` → "LHR-01-000482" |
| 8 | Admin branch / business validation | "Enter the branch name, for example Leeds." | `StoreBranchRequest`, `StoreTenantRequest` | `LocalText::places()` → "for example Lahore." |
| 9 | Every portal page, top bar | "Ask anything, e.g. top sellers in Leeds" | `components/app-sidebar-header.tsx` | `localPlaces()` → "in Lahore" |
| 10 | AI assistant panel | "e.g. Top sellers in Leeds last week" | `components/app/assistant/assistant-panel.tsx` | `localPlaces()` |
| 11 | Sign-in, trial, reset pages: preview card | "Leeds · Till 1", "Leeds · Till 2", "Bradford · Till 1" | `components/auth/auth-preview.tsx` | `localPlaces()` → Lahore, Karachi |
| 12 | Same preview card | "GST return ready" (the VAT return is UK-only) | `auth-preview.tsx` | "GST report ready" off GB |
| 13 | Products → product form footer | "Prices are in pounds." | `pages/app/products/form.tsx` | `currencyName()` → "rupees" |
| 14 | Offers → offer form footer | "Prices are in pounds." | `pages/app/promotions/form.tsx` | `currencyName()` |
| 15–24 | Validation messages (product prices, customer credit limit, supplier minimum order, staff hourly rate, news cover price, offer amount off and deal price, every-shop and shop price, till price action) | "Enter an amount in pounds, like 1.25." and 9 alike | `SaveProductRequest`, `CustomerRequest`, `SupplierRequest`, `StaffRequest`, `NewsTitleRequest`, `PromotionRequest` (2), `EveryShopPriceRequest`, `ShopPriceRequest`, `TillData/Actions/SetBranchPrice` | `LocalText::currency()` → "in rupees" |
| 25 | Calendar → Hours | "bank holidays and closures from the tills", "Bank holidays and closures are set on the till.", "Special days (bank holidays, …)" | `pages/app/calendar/hours.tsx` | `publicHolidays()` → "public holidays" |
| 26 | Calendar → Special days | "Bank holidays, closures and changed hours…", "When a till marks a bank holiday…" | `pages/app/calendar/special-days.tsx` | `publicHolidays()` |
| 27 | Calendar → Events, event page | event kind "Bank holiday" | `components/app/calendar/calendar-page.tsx` (`eventKindLabel`), `events.tsx`, `event.tsx` | "Public holiday" off GB |
| 28 | Admin → lead "Contacted" dialog | placeholder "…wants to start after the bank holiday." | `components/admin/leads/lead-dialogs.tsx` | `publicHolidays()` |
| 29 | Staff → Timesheets | "National Minimum and Living Wage by age…" | `pages/app/staff/time/timesheets.tsx` | "Minimum wage rates by age…" off GB |
| 30 | Compliance → Recalls → Raise a recall | source options "Food Standards Agency", "Trading Standards" | `components/app/compliance/recall-dialog.tsx` | Off GB: Food authority, Manufacturer, Supplier, Head office (a stored source is still listed) |
| 31 | Compliance → Recalls, empty state | "…when a supplier or the Food Standards Agency withdraws a product." | `pages/app/compliance/recalls.tsx` | "…or the food authority…" |
| 32 | Product form → rules | "Knife or blade: Challenge 25 and the refusals log." | `components/app/products/product-form-rules.tsx` | "Age check and the refusals log." |
| 33 | Pharmacy → Dispensing | "NHS charge paid" (pill, stat card, filter) | `components/app/pharmacy/format.tsx` (`chargeLabel`), `pages/app/pharmacy/dispensing.tsx` | "Prescription charge paid" off GB |
| 34 | Pharmacy → Dispensing | "…NHS charges paid, exemptions and private prescriptions." | `dispensing.tsx` | "…charges paid…" |
| 35 | Settings → Till settings | "Type prices in pence" | `ShopSettings/catalogue.php`, `Support/SettingCatalogue` | "Type prices without the decimal point" ("Rs 1.50" help) |
| 36 | Settings → Till settings | "Round cash to 5p", "…to the nearest 5p." | same | "Round cash totals", "…to the nearest 0.05." |
| 37 | Settings → Till settings | "Challenge 25: usually 25." | same | "Usually 25." |
| 38 | Settings → Till settings | "Staff record each refused sale, as Trading Standards expect." | same | "Staff record each refused sale." |
| 39 | Users → permissions matrix | "Set up the Direct Debit" | `PortalUsers/Support/RoleMatrix` | "Manage the subscription" where fees are paid by hand |
| 40 | Accounts → Chart of accounts | column "GST box" with HMRC's VAT return box numbers | `pages/app/accounts/chart.tsx` | Column shown only where the VAT return exists (`hasVatReturn()`) |
| 41 | Products → add from catalogue | "Round up to end in 9p" | `components/app/catalogue/pricing-fields.tsx` | "Round up so the decimals end in 9" (see owner list 15) |
| 42 | AI tools (assistant) | "Trading days to read (UK dates)" | `Ai/Support/Portal/ToolWindow` | `LocalText::region()` → "(Pakistan dates)" |
| 43 | AI reorder note | prompt "You help a UK convenience-store owner…" (never localised) | `Purchasing/Actions/SummariseReorderSuggestions` | Through `PromptCountry::localise()`, new phrase "a UK convenience-store owner" |
| 44 | AI anomaly explanation | prompt "…a UK shop business…" (never localised) | `Anomalies/Actions/ExplainAnomaly` | Through `PromptCountry::localise()` |
| 45 | Admin → Emails → previews and template list | sample shops "Leeds", "Bradford", "LDS", "Leeds Road" in 8 samples (till request, owner alert, alert resolved, new lead, invitation, anomaly alert, owner digest, morning summary) | `Mail/Mailables/*Mail::sample()`, `Mail/Support/MorningSummaryMail` | `LocalText::places()` |
| 46 | Admin → Emails → Welcome preview | "Set up your Direct Debit … through GoCardless" | `WelcomeTenantMail::sample()` | No Direct Debit section where fees are paid by hand (as the real email since P5) |
| 47 | Admin → Emails → New till request preview | "…a second counter in for the lottery." | `AdminTillRequestMail::sample()` | Neutral message off GB |
| 48 | Invoice, customer statement, privacy export and label sheet PDFs | `<html lang="en-GB">` | `resources/views/{billing,customers,privacy,labels}/*.blade.php` | The profile's `dateLocale()` ("en-PK") |

### For the owner to decide (kept as they are; till features)

These are UK legal concepts built into the till's data model (contract fields and enums). They are not hidden or removed:
hiding a module is the owner's call. UK wording was neutralised where it was trivial (above).

| # | Module or feature | What is UK-specific | Where | Recommendation |
|---|---|---|---|---|
| 1 | Deposit return scheme (DRS) | Product "Deposit return item", payment type "Deposit return", receipt setting "Bottle deposit lines", branch "Deposit return point", sale lines "Container deposit (DRS)" | product form rules, payment types, till settings, branch dialog, sale receipt | **Hide on PK** (no DRS in Pakistan); till fields stay at their defaults |
| 2 | Age-restricted sales rules | Till `AgeRule` labels: "Tobacco (born on or after 1 Jan 2009 refused)" (the UK generational ban), Lottery 18, Nicotine and vapes 18, Knives 18, Fireworks 18, Solvents 18, Energy drinks 16, Paracetamol 16; Challenge 25 prompt | product form, catalogue, compliance → age checks, till settings | **Needs a PK version** from EPOS (Pakistani age rules); until then keep |
| 3 | Lottery | Product flag "Lottery", age rule, loyalty "Points on lottery", compliance licence "National Lottery retailer agreement" (data) | product form, till settings | **Hide on PK** (no UK-style shop lottery) |
| 4 | Alcohol licensing | "Licensing hours and alcohol duty reports", staff "Personal licence holder", branch "Licensed hours" | product form, staff form, branch dialog | **Hide on PK** |
| 5 | HFSS and vaping duty | "High fat, sugar or salt" (HFSS promotion rules), "Allowed on HFSS food", "Vaping duty applies" | product form rules, offer form | **Hide on PK** |
| 6 | Pharmacy dispensing | NHS England prescription model: charges, exemptions (HC2 low income, war pension, prepayment certificate, "Free in this nation"…), GSL / P / POM classes | Pharmacy pages | **Needs a PK version** (DRAP schedules); hide on PK until then |
| 7 | Newspapers and magazines | UK wholesaler model (sale or return, vouchers, zero-rated papers) | News pages | **Keep** (sale-or-return magazines and papers exist in Pakistan; wording is generic since P6) |
| 8 | HMRC VAT return | 9-box helper, "Amount (£)", MTD | Accounts → VAT return | **Keep hidden on PK** (404 since P3); FBR sales tax summary comes with P8 |
| 9 | Accounting export defaults | Xero / QuickBooks / Sage UK tax codes ("No VAT", "20% (VAT on Income)") | Accounts → Export | **Needs a PK version** (default tax codes for Pakistani accountants) |
| 10 | Master catalogue starter set | "Adds about 600 UK convenience products from the demo catalogue" (UK brands and barcodes) | Admin → Catalogue | **Hide "Load starter set" on PK** or build a PK set |
| 11 | Minimum wage bands | UK National Minimum / Living Wage bands held by the till | Staff → Timesheets | **Keep** (wording neutral now; rates are the till's data) |
| 12 | Compliance licence types | UK licence names come from the tills' data | Compliance → Licences | **Keep** (data) |
| 13 | Calendar event kinds | "School holiday", seasonal events with a UK `nation` | Calendar | **Keep** (till data; "Bank holiday" reads "Public holiday" on PK) |
| 14 | Till keypad in pence / cash rounding to 5p | Behaviour assumes pence | Till settings | **EPOS question** (wording neutral now) |
| 15 | Catalogue price rule "end in 9" | Rounds the pence to end in 9 (1.29); on PK prices show in whole rupees | Add from catalogue | **Owner**: PK probably wants whole rupees ending in 9 (a behaviour change) |
| 16 | Supplier payment methods | "Direct Debit" as a supplier payment method (till data) | Purchasing → Payments | **Keep** (generic bank term) |

### Not code: instance settings and data

| # | What | Where | Note |
|---|---|---|---|
| 1 | `support@switchandsave.co.uk`, `switchandsave.co.uk/download` in every mail footer and the welcome email | `config/sspos.php` defaults | The PK server's `.env` must set `SSPOS_SUPPORT_EMAIL`, `SSPOS_STAFF_EMAIL`, `SSPOS_WEBSITE_URL`, `SSPOS_EPOS_DOWNLOAD_URL` (P6) |
| 2 | Demo businesses: shops Leeds / Bradford, UK addresses, suppliers (Booker, Bestway, 0113 numbers, .co.uk emails), customers (07700 phones), offers named "2 for £3", "counterfeit £20 note", recalls "FSA-PRIN-…", "Challenge 25" training, "National Lottery" licences, UK newspapers, "Gordon's London Dry Gin", ledger accounts "VAT on sales" | `database/seeders`, `app/Domain/Demo` (`demo:seed`, `db:seed`) | Local and demo only (refused in production); never on the PK server. A PK demo set would be new work |
| 3 | Leads with UK towns and postcodes | `LeadSeeder` | Local only |
| 4 | Plans in GBP ("Pounds sterling (GBP)") | `PlanSeeder` | Local only; PK plans are created in PKR by the admin (P5) |
| 5 | Product, supplier, customer and shop names, ledger account names, licence types, wage band labels, news titles | Till data | Out of scope (the till's data as sent) |
| 6 | Mail sample products (Walkers, Warburtons, Hovis) | admin email previews | Sample text only (admin), kept |
| 7 | Accounting tax codes "No VAT" on the export page | `ExportDefaults` | Kept by P3 (accounting-package codes) |
| 8 | Report CSV "Time (Pakistan)" | audit and activity exports | Already local (P6) |
| 9 | `Branch.nation = "england"` stored for PK shops | `branches.nation` default | See EPOS question 1 |
| 10 | Staff `preferred_culture` default `en-GB` | `Staff/Actions/SaveStaffMember` | Till field, not shown; EPOS question 4 |

## EPOS questions

1. **Branch `nation` on a Pakistani shop.** The contract has `Branch.nation` as a required string; the portal's values are
   `england`, `scotland`, `wales`, `northernIreland` and the column defaults to `england`. On PK the portal now hides the
   field and keeps the default, so a Pakistani till receives `england` exactly as before (no non-contract value is
   sent). Does `england` switch on UK-only rules on a PK till (DRS, licensing, VAT)? Should a PK shop send an empty
   string, `pakistan`, or a province (`punjab`, `sindh`, `khyberPakhtunkhwa`, `balochistan`, `islamabadCapitalTerritory`,
   `gilgitBaltistan`, `azadJammuAndKashmir`)? Once answered, the PK profile's `nations` lists them and the field shows.
2. **Cash rounding and keypad pence** (`payments.round_cash_to_5p`, `till.keypad_price_in_pence`) with whole rupees.
3. **Age rules for Pakistan** (`AgeRule`: the UK generational tobacco ban, lottery, energy drinks, paracetamol).
4. **Staff `preferred_culture`** default for PK (`en-PK`, `ur-PK`?).
5. **Public holidays** (`EventKind.bankHoliday`, `SeasonalEvent.nation`) for Pakistan.
6. **Pharmacy dispensing** exemptions and charge statuses for Pakistan.

## Tests

`tests/Feature/Shared/CountryUkSweepTest.php`: GB golden (the four nations and their labels on the admin and portal
pages and in the AI overview, every rerouted message, till setting, permission label, AI prompt and tool description,
mail samples, `lang="en-GB"`) and PK (`country-pk`: nation hidden and the till value kept, wording, mail previews free of
UK terms, and a crawl of 38 main portal and 6 admin pages failing on a deny-list of UK terms in their props).
