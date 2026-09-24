# UK convenience EPOS backoffice features: competitor research (set B)

Collected 2026-09-24 by a research sub-agent of the (paused) gap analysis. Input for the end-of-plan gap analysis —
not yet turned into modules. Items marked "snippet" come from search summaries, not opened pages.

## A. UK convenience features a generic POS lacks

1. **Wholesaler price file feeds**: daily price/description updates by barcode from Booker, Nisa, Bestway (Best-one),
   Parfetts, Costcutter; retailer picks up to 3 files, a national barcode file fills gaps (ShopMate >1M products,
   PayPoint >100k SKUs); retailer can override any price.
2. **Promotion period feeds**: symbol-group/wholesaler promotions load automatically each promo cycle.
3. **Price change → shelf label workflow**: price file change on an in-stock line flags the shelf strip; batch
   label printing from handheld/till, or push to ESL.
4. **Electronic ordering to the depot** from till, handheld or app using the retailer's wholesaler account number.
5. **ENOD / electronic delivery notes** posting straight into stock.
6. **Newspapers and magazines**: daily publication barcode/price file with SOR flag, issue-number recognition,
   automatic return quantities, credit and waste tracking.
7. **Home news delivery (HND)**: rounds sheets, stops/starts, holidays, vouchers, weekly/fortnightly/monthly billing,
   emailed bills, credit control, customer web portal with card/DD, Smiths News and Menzies delivery notes,
   driver app.
8. **Services in one basket**: PayPoint bill pay, Collect+ parcels, top-ups; service sales reported separately;
   lottery split national vs instant.
9. **Age-restricted flags** per item/category with minimum age and till prompt.
10. **Shop Saves / customer accounts** (pay on account).
11. **Head office controls**: pricing rules by commodity and store to protect margin; analysis by store, group,
    estate; pushed range additions (substitutions, presells, allocations).
12. **ESL integration** (Hanshow live with Nisa and Co-op).
13. **Handheld workflows**: gap scanning, stock takes, wastage, goods-in checks, labels, queue-busting, price/stock
    check at the cash and carry.
14. **Margin and POR reporting** by supplier, days of stock, PMP awareness (PMP inferred from trade press).

## B. Per vendor (condensed)

- **ICRTouch TouchOffice Web / TOWeb+ / TouchStock**: 150–200+ reports (HTML/PDF/CSV, scheduled email); real-time
  drill-down dashboard (estate → site → employee); remote product and till keyboard editing; scheduled price changes
  per site or estate; mix and match, set menus, vouchers; POs, stock takes, adjustments, wastage, returns, deliveries,
  inter-site transfers, variance; time and attendance, payroll export; loyalty and customer credit accounts; audit
  journal with CCTV overlay; Xero/Sage/QuickBooks; bulk import; handheld stock app. No wholesaler/news/PayPoint.
- **Clover**: item list with bulk edits, low-stock email alerts, labels, multi-location catalogue; age-restricted
  item types with minimum age; rotas, leave, permissions; most retail depth via App Market (POs, counts, labels,
  weight barcodes, bundles, house accounts, daily email report, accounting sync).
- **Zettle**: product library (variants, VAT, cost, units, open price), Excel/CSV import (2,000 rows), per-product
  low-stock limit, label printing, simple staff roles, sales reports, invoices. No suppliers/POs/multi-site.
- **PayPoint One (main UK convenience benchmark)**: tiers ~£10–£31/week; 100k+ SKU product file; Booker link (daily
  prices, monthly promos, app ordering, ENOD into stock); Nisa link; one basket with bill pay/Collect+/card services;
  news via PaperRound (counter news file, SOR, returns, credits, up to 30 Shop Saves accounts); reporting by cash
  profit/volume/value/rate of sale; mobile app (live sales, voids/refunds as they happen, price and promo changes,
  book stock in, cash and carry checks); label printing app; cloud backup.
- **Nisa Evolution / Multisite**: head office app with alerts, automated pricing strategies by commodity and store,
  central prices/promos, estate reports, head office → branch messages, range allocations, per-user/system/device
  rights, per-SKU history (price, promo, stock, waste, sales, audit), PLOF updates, ESL.
- **Booker / ShopMate (RDP)**: up to 3 wholesaler files + 1M barcode file, automatic promos per period, label prompt on
  price change, depot ordering and ENOD, BackOffice + HeadOffice (3–5+ stores), local loyalty, competitor
  benchmarking, handhelds and ESL.
- **Spar/BWG (Prosper, Henderson EDGEPoS)**: gift cards, queue-busting, fuel + shop, exception reporting, promo
  performance, scheduled reports, points loyalty, savings club; head office comparison, auto ordering, age checks,
  labels, coupons, ESL, fuel integration, app kit, audit trails.
- **Specialists**: PaperRound and Reposs (HND); myEPOS (inner/outer barcodes, pack sizes, issue numbers, lottery
  split, supplier email ordering, branch transfers); epos.co.uk ("theft watch" stock vs cash vs physical); Epos Direct
  (FIFO/expiry alerts, PDAs); Image Retail (2M barcode DB, counter webcam, shelf life); Intelligent Retail
  (replenishment, days of stock, supplier contribution); Epos Now (age prompts with ID scanner, auto POs).

## C. Not found / design opportunities

Post Office counter reconciliation; lottery and PayPoint end-of-day reconciliation; planogram downloads; EDI invoice
matching against orders; Challenge 25 refusal logs (our till already has `AgeRefusal`). No vendor documents these —
treat as opportunities, not verified features.

## D. Sources

Opened: icrtouch.com (touchoffice-web, touchoffice-web-plus, touchstock, convenience-grocery); icrsystems.co.uk;
nisalocally.co.uk (multisite launch, evolution of EPoS); paypointbusiness.com (Booker link, Nisa link, PaperRound news,
EPoS Pro + app); slrmag.co.uk/paypoint-epos-6; sales.paperround.net/main/features; shop.reposs.com (home,
home-news-delivery); imageretailsolutions.co.uk; shopmate.co.uk (home, booker-brp, parfetts, best-one);
partnerdiscovery.worldpay.com (RDP); retail-systems.com (Appleby Westward SPAR); henderson.technology (products,
epos-faqs); uk.clover.com (retail inventory, manage your business, apps guide PDF); docs.clover.com (age-restricted
items); gb.zettle.com; zettle.com help (inventory, product library, my staff); intelligentretail.com blog;
epos.co.uk convenience; mhouse.uk guide; eposdirect.co.uk; myepos.com convenience; hanshow.com ESL; madic-uk.com.
Failed: booker.co.uk free EPOS (403), acs.org.uk (403), premierepos.co.uk, conveniencestore.co.uk (2 articles),
Play Store PayPoint listing, la.clover.com multi-location help, zettle inventory management page.
