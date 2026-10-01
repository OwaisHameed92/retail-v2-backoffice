# Gap analysis (module 7.2, 2026-10-01)

Inputs: PHASES, DECISIONS, competitor research, current routes/pages, legacy routes/menus, web research. Effort: S ≤ 3
days, M ≤ 2 weeks, L > 2 weeks.

## 1. Must-have before go-live (ranked)

| # | Gap | Why it blocks | Who has it | Effort | Module |
|---|---|---|---|---|---|
| 1 | **Two-factor sign-in** (required for admins, optional for owners) | Admins impersonate, issue keys, change billing; no 2FA today | ICRTouch, Clover, Zettle | S | 7.3 |
| 2 | **Audit log viewer** (admin all; owner own business) | Written, never shown; needed for disputes | ICRTouch, Nisa | S | 7.3 |
| 3 | **Legal and GDPR pack**: terms, privacy notice, DPA (we are processor), sub-processors, customer export/erasure, retention | We hold shoppers' data; no terms or privacy link exists | All | M | new 7.7 Privacy |
| 4 | **Close the open EPOS confirmations**: staff `pinHash` format, `shop.trading_hours` format, `quantityPrice` tiers, overtime/holidays | Wrong PIN hash locks staff out of every till | — | S (ours) | 7.5 |
| 5 | **Owner alerts by email**: till offline, sync failing, low stock, cash variance, trial/compliance expiry | In-app only; owners don't log in daily | PayPoint One app, Clover, ICRTouch | M | new 7.8 Notifications (feeds 6.3) |
| 6 | **Shelf-edge labels** queued on price change | Price marking; till has `ShelfLabel`, portal has no screen | PayPoint, ShopMate, Zettle, Clover | M | Catalogue |
| 7 | **Barcode lookup / starter catalogue** | Keying 3–8k SKUs kills onboarding | PayPoint (100k), ShopMate (1M) | M | Catalogue |
| 8 | **Accountant exports**: Xero / QuickBooks / Sage CSV | No hand-off to accountants | ICRTouch, Clover apps | S | Accounts |
| 9 | **Phone install (PWA manifest)** + security headers/CSP | Legacy had it; owners use phones | PayPoint app, Clover | S | 7.1 / 7.3 |
| 10 | **Backups, restore drill, uptime monitor** | None scheduled | — | S | 7.4 |

## 2. Should-have (next 3 months)

1. **Wholesaler price and promo files** (Booker, Bestway, Parfetts, Nisa) — L. Biggest competitive gap.
2. **MTD VAT submission** via HMRC API (MTD Income Tax also started April 2026 for sole traders over £50k) — M.
3. **Scheduled and emailed reports** (daily Z, weekly sales) — S.
4. **Phase 6 AI**: 6.3 morning summary, 6.4 reorder suggestions, 6.6 anomaly alerts — M each.
5. **Portal stock adjustments, wastage and opening stock** (legacy had them; till-owned in contract, ask EPOS) — M.
6. **Handheld app** for stock takes and goods-in (legacy PDA API) — L.
7. **Staff holidays/absence and payroll export** once the contract has them — M.
8. **Gift cards / store credit screen** with liability — S.
9. **Vape duty readiness**: flag unstamped vape stock before 1 April 2027 — S.

## 3. Nice-to-have / differentiators

- Depot ordering and ENOD delivery notes into stock (after feeds) — L.
- "Theft watch": stock vs cash vs sales variance by staff (6.6) — M.
- PayPoint / lottery / Post Office reconciliation (no competitor documents it) — M.
- Supplier invoice import (6.5) and EDI invoice-to-order matching — M.
- Head-office pricing rules by department and shop (margin protection) — M.
- Home news delivery rounds and billing (contract lacks it) — L.
- E-receipts by link/QR, click and collect, online payments, resellers (Phase 8) — L.
- ESL integration — L.

## 4. Legacy parity

| Legacy feature | New | Still needed? |
|---|---|---|
| Vouchers + usage history | Missing (till data stored) | Yes, S (§2.8) |
| PDA users + handheld API | Missing | Yes, later (§2.6) |
| Stock adjustments, opening stock, stock locations from portal | Read-only | Ask EPOS (§2.5) |
| Customer orders + Stripe payment | Missing | Phase 8 |
| Public receipt link + PDF (`/receipt/{token}`) | Missing | Nice (needs till QR) |
| Resellers and reseller orders | Missing | Not now (decision) |
| PWA "Add to Home Screen" | Missing | Yes, S (§1.9) |
| VAT types editor | Rates from till list | No |
| Catalogue, offers, purchasing, customers, reports, registers, permissions | Done, deeper | — |

## 5. Compliance checklist

| Area | Status | Notes |
|---|---|---|
| VAT records, boxes 1–9 | Partial | Helper, CSV, print; no MTD submission |
| Record keeping (6 years) | Partial | Keep sales raw rows through 7.6 archiving |
| Challenge 25 / age rules | Done | Product age rules, refusals log, training |
| Generational tobacco (born 2009+) | Done | `tobaccoGenerational` rule |
| Tobacco & Vapes Act 2026 licensing | Partial | Licences page ready; scheme regulations not yet published |
| Tobacco track and trace (EO / facility IDs) | Missing | Store the IDs per shop (S) |
| Vaping Products Duty (1 Oct 2026; stamps 1 Apr 2027) | Partial | `vape_duty_applies` flag; no stamp check |
| Deposit Return Scheme (1 Oct 2027) | Partial | Deposit item + nation; no returns/claims report |
| HFSS | Done | Flag; small shops exempt |
| Alcohol premises licence / DPS | Done | Compliance licences + expiry |
| Knives, lottery | Done | Product flags |
| GDPR | Partial | Consent read-only; no privacy notice, DPA, SAR/erasure |
| PCI DSS | Done | No card data stored; GoCardless hosted; confirm in 7.3 |
| Accessibility (WCAG 2.2 AA) | Partial | shadcn base; no audit yet (7.1) |
| Security (2FA, headers, audit view) | Missing | §1.1, 1.2, 1.9 |

Sources: [GOV.UK vaping retail](https://www.gov.uk/guidance/handling-wholesale-or-retail-vaping-products-in-the-uk),
[Tobacco and Vapes Act 2026](https://www.legislation.gov.uk/ukpga/2026/18),
[Commons Library DRS](https://commonslibrary.parliament.uk/research-briefings/cbp-10453/).
