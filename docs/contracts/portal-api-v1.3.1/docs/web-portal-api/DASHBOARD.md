# SSPOS web portal — dashboards build spec

**For:** the developer building the SSPOS web portal (Business panel and Admin panel dashboards).
**From:** the SSPOS till team, 2026-09-28. **Contract:** `/api/v1` (document revision 1.3). **Status:** build from this today.
**Read with:** `docs/web-portal-api.md` §2 (company → branch → till), §5–7 (envelope, push), §10 (ownership),
§17.5 / §17.15.2 (licence/validate), §18 (panels), §19 (never twice, never backwards), §20.
Every field named here exists in `schemas/entities/<Entity>.schema.json` (sync) or `licensing/schemas/*` (licensing).
Where this file and a sample disagree, **the sample wins** (samples are generated from the till's code).

The till already has a dashboard and reports. The portal must show **the same numbers** for the same shop and day.
Where the till has a figure, this file gives the till's definition and the code it comes from, so the two can be
compared line by line.

---

## 0. The one-minute version

1. Every push row is an **upsert by `entityId`**, kept only if its `version` is higher than the one you hold.
2. Store the raw rows (typed columns + the full `payload` as JSONB). Build **reporting tables** from them in a
   background job after you reply to the push. Rebuild a whole **(branch, trading day)** at a time — that is what
   makes retries, late rows and refunds safe.
3. A sale counts when `status = "completed"` and `type` is `sale`, `refund`, `exchange` or `deposit`.
   `training` and `quote` never count. `open`, `held`, `voided` never count.
4. **Refunds are their own `Sale` rows with every amount already negative.** Sum signed values; never subtract a
   refund a second time.
5. Money is decimal pounds. Times are UTC. The **trading day is the Europe/London calendar day** of
   `Sale.completedAt`, not its UTC date.
6. `SaleLine`, `SalePayment`, `SaleVat` arrive with empty `branchId` / `registerId` in the envelope. Get the shop
   and till from the parent `Sale` (`saleId`).
7. Show "updated N minutes ago" per shop from your own record of its last push / last contact.

---

## 1. Principles

### 1.1 Ingest: raw rows first, reporting tables second

| Step | What you do | Why |
|---|---|---|
| 1 | Receive the batch (gzip JSON array of `SyncChange`). | §5, §7 |
| 2 | For each row in `seq` order: upsert into the raw table for `entity`, keyed by `entityId`, **only if** `version` > the `row_version` you hold (or you hold nothing). A row with `version` ≤ yours is counted as accepted and ignored. | §7, §19.3. A retried push (same `(entity, entityId, version)`) changes nothing. |
| 3 | Record `(branchId, seq)` as seen; reply `acknowledgedSeq` / `accepted`. Reply within 2 s. | §7, §18.9 |
| 4 | Collect the **touched sale ids**: `entityId` of every `Sale` row + `payload.saleId` of every `SaleLine`, `SalePayment`, `SaleVat` row in the batch. Queue their `(branch_id, trading_day)` as "dirty". | So a child row that arrives in a later batch than its parent still refreshes the right day. |
| 5 | Background job: for each dirty `(branch_id, trading_day)`, **delete and re-insert** that day's rows in every reporting table, from the raw tables, in one transaction. | Idempotent by construction: running it twice gives the same result. No running counters to double-count. |

A UK shop does at most a few thousand sales a day, so rebuilding one shop-day is a few milliseconds in PostgreSQL.
Do **not** keep "add this sale to the running total" counters — a retried or re-ordered batch will double-count
them, and they cannot be repaired without a rebuild anyway.

Keep the whole `payload` JSON beside your typed columns (§18.8). A field the till adds later is already stored.

### 1.2 Money and quantities

- Money is a JSON number in **pounds**, not pence: `5.15` = £5.15 (§6). Parse as decimal, never float.
  PostgreSQL: `numeric(14,2)` for money, `numeric(14,4)` for `qty`, `baseQty`, `costAtSale`, `unitCost`, `qtyDelta`.
- Round only for display, **half away from zero** (the till's rule, `Money`). Sum the stored 2 dp values;
  do not recompute VAT from percentages — use the stored `vatAmount` / `SaleVat.vat`.
- Weighed goods: `qty` is in kg (up to 4 dp); `Product.unitType` is `pcs`, `kg` or `open`.
- **Container deposits** (DRS, `Product.isDepositItem`): `SaleLine.depositAmount` / `Sale.depositTotal`. Never discounted,
  no VAT, so they are in `Sale.total` and in the payments but **not** in `SaleVat`. Sales figures come from
  `SaleVat`; takings from the payments — the difference is the deposits.
- **No cost is normal.** Most shops only enter a sell price, so `costAtSale` is often 0. Margin and profit tiles
  must look clean with zero cost: show "—" or hide the tile, never a warning.

### 1.3 Time: UTC in the database, Europe/London on screen

All date-times on the wire are ISO-8601 UTC (`2026-09-23T09:41:12Z`). A value with no offset is UTC too (§6) —
store it as `timestamptz` interpreted as UTC. Never store local time.

The **trading day** of a sale = the calendar date of `Sale.completedAt` **in the shop's time zone**
(Europe/London for every UK shop; keep a `time_zone` column on your branch table, default `'Europe/London'`).
The trading **hour** = the local hour (0–23) of the same instant. This is exactly what the till does
(`SaleSummaries` converts `completedAt` to local time; `TradingDay.StartUtc/EndUtc` turn a local day into a UTC
window — local midnight to local midnight).

| `Sale.completedAt` (UTC) | UK clock | Local time | Trading day | Hour |
|---|---|---|---|---|
| `2026-09-23T09:41:12Z` | BST (UTC+1) | 23 Sep 10:41 | **2026-09-23** | 10 |
| `2026-09-23T23:30:00Z` | BST (UTC+1) | 24 Sep 00:30 | **2026-09-24** (UTC date says 23rd — wrong) | 0 |
| `2026-12-01T23:30:00Z` | GMT (UTC+0) | 1 Dec 23:30 | **2026-12-01** | 23 |
| `2026-10-25T00:30:00Z` | BST ends 01:00 UTC | 25 Oct 01:30 BST | **2026-10-25** | 1 |
| `2026-10-25T01:30:00Z` | GMT | 25 Oct 01:30 GMT | **2026-10-25** | 1 (second 01:00 hour — same bucket, as on the till) |

BST 2026 runs 29 March 01:00 UTC → 25 October 01:00 UTC. Let the database do it:

```sql
-- trading day and hour of a sale (completed_at is timestamptz)
(completed_at AT TIME ZONE 'Europe/London')::date                    AS trading_day,
extract(hour FROM completed_at AT TIME ZONE 'Europe/London')::int     AS trading_hour
-- the UTC window of one trading day (for queries on raw rows)
completed_at >= (:day::timestamp)       AT TIME ZONE 'Europe/London'
AND completed_at <  (:day::timestamp + interval '1 day') AT TIME ZONE 'Europe/London'
-- "today" for a UK shop
(now() AT TIME ZONE 'Europe/London')::date
```

Store `trading_day` and `trading_hour` on your `sale` row at ingest (they never change once a sale is completed).
The same rule applies to other documents: a shift's day is the local date of `Shift.closedAt`, a Z report's the
local date of `ZReport.periodEnd`, a stock movement's the local date of `StockMovement.at`.

"This week" = Monday to Sunday in local time. "This month" = local calendar month.

### 1.4 Which sales count

`Sale.status` (schema enum): `open | held | completed | voided`.
`Sale.type` (schema enum): `sale | refund | exchange | quote | training | deposit`.

| `type` | Counts in sales, VAT, takings? | Counts as a transaction? | Notes |
|---|---|---|---|
| `sale` | Yes | Yes | Normal sale. |
| `refund` | Yes — amounts are **negative**, so they net off | **No** (counted as a refund) | `originalSaleId` points at the sale refunded (null for a no-receipt refund). |
| `exchange` | Yes — returned lines have negative `qty`, new lines positive | Yes | Its `SaleVat` rows already net the two halves. |
| `deposit` | Yes | Yes | A deposit / part payment against a customer order: a real completed sale of a non-stock line with one out-of-scope `SaleVat` row (`vat` 0). The till counts it; when the order is collected, the collection `sale` pays the pre-paid part with a Voucher-type tender. Show "of which order deposits" if you like; exclude `deposit` only for a "goods sold" view. Not the same thing as `Sale.depositTotal` (container deposits, 1.2). |
| `quote` | **Never** | No | Not a sale. |
| `training` | **Never** | No | Practice mode. Every till report excludes it (`SaleVatSource`, `ShiftTotalsSource`: `Type != Training`). |

And **only `status = "completed"`**. `open` is an unfinished basket, `voided` an abandoned one (only an open sale
can be voided — a completed sale is never changed, it is reversed with a refund). Held baskets are `HeldOrder`
rows, not sales. `completedAt` is null until a sale completes. Also skip any row with `deletedAt` set (see 1.7).

Define this once and use it everywhere:

```sql
CREATE VIEW counted_sale AS
SELECT * FROM sale
WHERE status = 'completed'
  AND type IN ('sale','refund','exchange','deposit')
  AND deleted_at IS NULL
  AND completed_at IS NOT NULL;
```

### 1.5 Refunds

- A refund is a **new `Sale` row** with `type = "refund"`, `status = "completed"`, its own `receiptNumber`, and
  `originalSaleId` = the sale refunded. The original sale **never changes** (it is immutable, §11).
- Every amount on a refund is **already negative**: `Sale.total`, `subtotal`, `vatTotal`, `depositTotal`;
  `SaleLine.qty`, `baseQty`, `goodsTotal`, `lineTotal`, `vatAmount`, `costAtSale`; `SalePayment.amount`;
  `SaleVat.net`, `vat`, `gross` (till code: `RefundRows` — "every quantity and amount negated, so sums over Sale*
  tables reverse naturally").
- So **"net of refunds" = plain SUM over counted sales.** Do not write `sales − refunds` where `refunds` is itself
  a sum of negative rows — that adds them back.
- The **refunds tile** shows refunds as a positive figure: `refund_gross = −SUM(SaleLine.goodsTotal)` over
  **returned lines**. A returned line is a line of a `refund` sale, or a line with `qty < 0` on an `exchange`
  (till: `SaleRowSet.IsReturn`). Refund count = number of `type = "refund"` sales.
- A refund lands on **the day it was made** (its own `completedAt`), not on the original sale's day. Yesterday's
  figures never change because of a refund today. This matches the till.

### 1.6 Child rows: join to the parent `Sale`

`SaleLine`, `SalePayment`, `SaleVat` (and `CustomerOrderPayment`) carry **no branch or till of their own**: the
envelope's `branchId` and `registerId` are `""` (§16). Their payload has `saleId`.

- Shop: the parent `Sale.branchId` (also equal to the request's `X-SSPOS-Branch-Id` header).
- Till: the parent `Sale.registerId`. Staff: the parent `Sale.userId`. Day/hour: the parent's `trading_day` / `trading_hour`.
- Order within a batch is `seq` order and the parent comes first, but a 5,000-row batch boundary can split a sale:
  store orphan children anyway (no foreign key), and let step 4 of 1.1 rebuild the day when the rest arrives.

### 1.7 Soft deletes

`op = "D"` still carries the whole payload with `deletedAt` set (§5). Keep the row, set `deleted_at`, and exclude
it from every figure (`deleted_at IS NULL`). Completed sales, lines, payments and VAT rows are never deleted by the
till — corrections are new rows (refunds). Master data (products, departments…) can be soft-deleted: still show the
name on historical figures (fall back to `SaleLine.name`), just hide it from pickers.

### 1.8 "Last updated N minutes ago"

Keep a `branch_sync_state` row per shop (section 4.7) and set, **with your own clock**:

- `last_contact_at` on every authenticated sync call (`hello`, `push`, `pull`). The main till pulls every
  30 s even when it has nothing to push, so this is the "shop is online" signal.
- `last_push_at` when a push with at least one row is stored; `last_row_at` = the highest envelope `at` stored.

Show per shop: **"Updated 2 min ago"** from `last_contact_at`, and on a stale shop the time of the newest data
("Sales up to 10:41"). Colour: green ≤ 2 min, amber 2–15 min, red > 15 min. A shop that is offline keeps trading
(§20.2) and catches up by itself; its figures for the offline period appear when it reconnects, **on the days they
were made**, so a past day's totals can grow after the fact. That is correct — say "may be incomplete" on any day
whose shop is currently red.

### 1.9 Scoping and ids

- Every query filters by `company_id` from the session, and by `branch_id` for a shop manager (§18.2), in one
  place in your API layer.
- Ids are 26-character ULID **strings** (`text` or `char(26)`), not UUIDs. `number` is unique per till only;
  `(registerId, number)` and `id` are unique (§2.4).

---

## 2. Business panel dashboard (one business, all its shops)

### 2.1 Filters (top of the page, apply to every tile)

| Filter | Values | Applies as |
|---|---|---|
| Shop | All shops / one or more `Branch` | `branch_id = ANY(:branches)` |
| Till | All tills / one or more `Register` of the chosen shops (label `Register.code` – `Register.name`) | `register_id = ANY(:registers)` |
| Date range | Today, Yesterday, This week, Last 7 days, This month, Last month, Custom | trading days `:from … :to` inclusive (1.3) |
| Compare to | Previous period (default), Same period last week, Same period last year, None | see 2.9 |

A shop manager's Shop filter is fixed to their shop. Stock tiles ignore the date range (they are "now").

### 2.2 KPI tiles

All over `counted_sale` (1.4) in the filter's shops, tills and trading days. "Lines" = `SaleLine` of those sales,
"VAT rows" = `SaleVat` of those sales, "payments" = `SalePayment` of those sales with `status` in
`approved | recovered` (`reversed` = undone before the sale completed — never money taken).

| Tile | Formula | Till equivalent |
|---|---|---|
| **Sales (inc VAT)** | `SUM(SaleVat.gross)` | Net + VAT of the till's reports. Excludes container deposits (outside VAT, 1.2); includes order-deposit sales (1.4). |
| **Sales (ex VAT)** — the headline "Net sales" | `SUM(SaleVat.net)` | The till dashboard's "Sales today" (`HourlySales.net` = Σ `SaleVat.net` per sale; refunds net off). |
| **VAT** | `SUM(SaleVat.vat)` | VAT summary report (`SaleVatSource`). |
| **Transactions** | `COUNT(*)` of counted sales with `type <> 'refund'` | Till dashboard `TransactionsToday` (`HourlySales.count`: refunds excluded). |
| **Average basket** | `Net sales ÷ Transactions` (0 → show "—") | Till `AverageBasketToday = NetToday / TransactionsToday` (ex VAT). Label it "ex VAT"; you may add `Sales (inc VAT) ÷ Transactions` beneath. |
| **Refunds** | `−SUM(SaleLine.goodsTotal)` over returned lines (1.5), shown positive; count = `type = 'refund'` sales | Till `SalesDaily.refundNet` is the ex-VAT version: `−SUM(goodsTotal − vatAmount)` over returned lines. |
| **Discounts** | `SUM(SaleLine.lineDiscount)` over non-returned lines. Split: promotions `SUM(promotionDiscount)`, coupons `SUM(couponDiscount)`, manual/staff = `lineDiscount − promotionDiscount − couponDiscount` | Till `SalesDaily.discount` / `.promo`. `lineDiscount` already includes the promotion and coupon shares. |
| **Takings** | `SUM(amount − cashback − changeGiven)` over payments | Till `TenderDaily.amount`. Equals `SUM(Sale.total)` (goods + container deposits). Show "of which container deposits `SUM(Sale.depositTotal)`" when non-zero. |
| **Gross profit** (optional) | `Net sales − SUM(SaleLine.costAtSale)` over all lines (signed) | Till `SalesDaily.profit`. `costAtSale` is ex VAT for the whole line. Hide when every `costAtSale` is 0. |
| **Cash variance** | `SUM(ShiftTender.variance)` for cash tenders of shifts closed in range (2.5) | Close-shift / Z report. |
| **Low stock** | count per 2.6 | Till dashboard alert "Low stock" (`GetLowStockCountAsync`). |
| **Tills online** | `n of m` per 2.8 | Sync status screen. |

### 2.3 Charts

| Chart | Formula | Till equivalent |
|---|---|---|
| **Takings by payment type** (bar or donut) | Group payments by `SalePayment.paymentTypeId`; value `SUM(amount − cashback − changeGiven)`; count `COUNT(*)`; refunds paid out = `−SUM(…)` of payments on refunds and of negative payments on exchanges. Label `PaymentType.name` (fallback `SalePayment.paymentTypeName`). | `TenderDaily.amount`, `.count`, `.refunds`. |
| **Sales by hour** (0–23 bars, today vs compare) | Group counted sales by `trading_hour`; `SUM(SaleVat.net)` and transaction count. | Till dashboard hourly chart (`HourlySales`: `date`, `hour`, `net`, `count`). |
| **Sales by day** (line/bars over the range) | Group by `trading_day`; Net sales, Sales inc VAT, Transactions. | `SalesDaily` / `HourlySales` per `date`. |
| **Sales by shop** / **by till** | Group by `Sale.branchId` / `Sale.registerId`. Label `Branch.name`, `Register.code` + `Register.name`. | — |
| **By department / category** | Lines → `SaleLine.productId` → `Product.departmentId` / `Product.categoryId` → `Department.name` / `Category.name`. Net = `SUM(goodsTotal − vatAmount)` (signed, so refunds net off); qty = `SUM(baseQty)`. Group by the product's **current** department. Unknown product → "Unassigned". | Till "top departments" (`GetTopSellersAsync`: joins `SalesDaily` → current `Product.departmentId`; value = `net − refundNet`). `Department.showInReport` = "breaks the department out on the department sales report". |
| **Top products** (top 5 / 10 / 20) | Group lines by `productId`; qty = `SUM(baseQty)` (signed), net = `SUM(goodsTotal − vatAmount)`, gross = `SUM(goodsTotal)`. Rank by net (default) or qty. Name = `Product.name`, fallback latest `SaleLine.name`. | Till "top products": `net − refundNet`, `qty − refundQty`, ordered by net, top 5. |
| **VAT by rate** (table) | Group VAT rows by `vatRateId`, `code`, `percentage`: `SUM(net)`, `SUM(vat)`, `SUM(gross)`. Order by `code`. | Till VAT summary (`SaleVatSource`: same grouping, completed, non-training, local-day window). |
| **Staff sales** (table) | Group counted sales by `Sale.userId`: Net, Sales inc VAT, Transactions, Refund count/value, Average basket. Name = `User.name`. | Till "Sale by user" report (`SalesDaily.userId`). |

Notes:
- `qty` vs `baseQty`: `baseQty = qty × unitFactor` (a case of 12 sold as 1 is 12 units). Use `baseQty` for units,
  like the till.
- Bag-charge and charity round-up lines (`isBagCharge`, `isCharityRoundUp`) are ordinary lines in every total,
  as on the till; you may hide them from Top products.
- Web / click-and-collect orders (`CustomerOrder`) are **not** sales until collected (then a `Sale` arrives).
  A small "Orders ready to collect" tile = `CustomerOrder` rows with `status = 'ready'`.

### 2.4 Z reports and shifts

| Figure | From |
|---|---|
| Shift list | `Shift`: `registerId`, `userId`, `openedAt`, `closedAt`, `openingFloat`, `status` (`open`/`closed`), `varianceTotal`, `mode`. Day = local date of `closedAt` (open shifts: "open since …"). |
| Per-tender reconciliation | `ShiftTender` (`shiftId`, `paymentTypeId`, `expected`, `declared`, `terminalTotal`, `variance`). `variance = declared − expected`: **negative = short, positive = over**. |
| Z report list | `ZReport`: `registerId`, `sequenceNo` (1, 2, 3… per till), `periodStart`, `periodEnd`, `generatedAt`, `printedAt`, `reprintCount`, `shiftId`. |
| Z report printable view | `ZReport.totalsJson` — a JSON **string** (store as-is) of the till's `ZReportDto`, written with **PascalCase** keys: `Tenders[]` (`PaymentTypeId`, `PaymentTypeName`, `Expected`, `Declared`, `TerminalTotal`, `Variance`, `ExceedsThreshold`), `VarianceTotal`, `HasVarianceWarning`, `VarianceAlertOver`. For tiles use the `ShiftTender` rows instead of parsing it. |
| Sales in a shift | counted sales with `Sale.shiftId` = the shift. |

### 2.5 Cash variance

- **Cash variance (range)** = `SUM(ShiftTender.variance)` where `paymentTypeId` is a cash type
  (`PaymentType.isCash = true`) and the shift's `closedAt` falls in the range. Show red when negative.
- **Shifts over threshold** = shifts whose `ZReport.totalsJson` has `HasVarianceWarning = true` — the till's own
  flag (a tender at or above the shop's alert threshold, saved in the same JSON as `VarianceAlertOver`). Use the
  flag; do not re-derive the threshold.
- Per shift total = `Shift.varianceTotal` (sum of every tender's variance at close; 0 while open).
- Till's expected cash (for reference, `ShiftTotalsSource`): payments of the shift's completed non-training sales,
  `amount − changeGiven` per type, then the cash type adds the drawer ledger (`CashMovement.amount` for types other
  than `sale`/`refund`: float, paid in/out, drops…) and subtracts card cashback. The till has already done this —
  the portal shows `ShiftTender.expected`; do not recompute it.

### 2.6 Stock on hand and low stock

- **Stock on hand** (per shop, per product) = the latest `BranchProduct.qtyOnHand` (highest `version`). Also
  `qtyReserved`, `qtyAvailable` (= on hand − reserved). A product with no `BranchProduct` row has never had stock
  in that shop. Cross-check / movement history: `StockMovement` (`productId`, `type`, `qtyDelta`, `qtyBefore`,
  `qtyAfter`, `at`, `userId`, `refType`, `refId`); the newest movement's `qtyAfter` should equal `qtyOnHand`.
- **Low stock — the till's exact rule** (`DashboardReadQueries.GetLowStockCountAsync`): a `BranchProduct` row of the
  shop where
  - `isActive = true`, and
  - tracked = `BranchProduct.isStockTracked` if not null, else `Product.trackStock`, is true, and
  - `qtyOnHand ≤` reorder point, where reorder point = the first non-null of `BranchProduct.reorderPoint`,
    `BranchProduct.minQty`, `Product.minStockQty`, then the shop's setting `stock.low_stock_threshold`
    (**default 5**; `Setting` rows are not synced yet (§16), so use 5 until they are).
- **Stock value** (optional) = `SUM(qtyOnHand × Product.costPrice)` — hide when costs are zero (1.2).
- Stock movements by type (wastage, damaged, theft, expiry…) over the range = `SUM(-qtyDelta)` grouped by
  `StockMovement.type` (enum `sale|refund|adjustment|received|wastage|transfer|stockTake|damaged|supplierReturn|expiry|theft|openingStock|promotionSample`).

### 2.7 Staff

- Sales per user: 2.3 "Staff sales". Name from `User.name` (portal-owned, company-wide).
- Hours (optional): `ClockEvent` (`userId`, `type` = `in|out|breakStart|breakEnd`, `at`, `branchId`).
  Pair `in`→`out` per user per local day, minus breaks.
- Multi-till caveat until v1.4: see section 7 (second-till sales may carry the main till's user today).

### 2.8 Tills online / offline and app version

| Column | From |
|---|---|
| Shop online | `branch_sync_state.last_contact_at` (1.8). |
| Till list | `Register` rows pushed by the shop (`code`, `name`, `isMainTill`, `isActive`) + the tills your licensing tables know (per-till keys, §17.15). |
| Till last check-in | your record of that till's last `licence/validate` (per till: identified by `installId`; `deviceName`, `appVersion`, `os`). Daily, so it says "alive today", not "online now". |
| App version | the `appVersion` of the till's last `licence/validate`; for the main till also the `X-SSPOS-App-Version` header of its last sync call. |
| Till online | main till: shop online. Other tills: checked in within 26 h (daily check + jitter). Show "not checked in for N days" at ≥ 3 days (the §18.7 e-mail). |

### 2.9 Compare to

| Option | Previous window |
|---|---|
| Previous period | the same number of trading days immediately before `:from` (`:from − n … :from − 1`, n = `:to − :from + 1`) |
| Same period last week | `:from − 7 … :to − 7` (the till shows "yesterday" and "same day last week" for today) |
| Same period last year | the same dates one year earlier (the till's "last year this week" card: the rolling 7 days ending today vs the same dates last year) |

Delta = `(current − previous) ÷ previous`, shown as ±%; previous = 0 → "—". For **Today**, compare up to the same
local time of day (a half-finished day against a whole day always looks bad): read both windows from
`rpt_sales_hourly` with `hour <= extract(hour FROM now() AT TIME ZONE 'Europe/London')`.

---

## 3. Admin panel dashboard (all businesses)

These figures come from the portal's **own** tables (businesses, shops, licence keys, check-ins, sync log), not
from pushed rows. Super admin sees everything; a dealer sees only their businesses (§18.2).

| Tile | Formula |
|---|---|
| **Businesses** | count of your businesses (portal `Company`), split active / suspended. |
| **Shops** | count of shops (portal `Branch`). |
| **Tills** | count of licence keys bound to a PC (per-till licensing, §17.15) — plus, for the branch model, registers reported in `licence/validate` `registers[]` with `isActive = true`. |
| **Licences by status** | per till key, from your licence table (`kind` = `trial` / `full` from the token payload; `expiresAt`; your revoke/suspend/release flags), first match wins: `revoked` → `suspended` → `released` → **expired** (`expiresAt ≤ now`) → **expiring ≤ 7 days** (`now < expiresAt ≤ now + 7 days`) → **trial** (`kind = 'trial'`) → **full** (`kind = 'full'`). Same order as §17.5 step 2 (`status` enum: `active, expiring, expired, revoked, suspended, seatLimit, released`). Also split by `source` (`portal` / `local` — local keys reported by `licence/redeem`, §17.16). |
| **Trials ending** | trial keys with `expiresAt` in the next 3 days (the §18.7 trial-ending e-mails). |
| **Tills online / offline** | shop online = `last_contact_at ≤ 2 min` (1.8); till alive = last `licence/validate` ≤ 26 h; **offline ≥ 3 days** listed by name (§18.7 e-mail). |
| **Last check-in** | per till: time of the last successful `licence/validate`, till clock skew (`tillClockUtc` − your receive time), lock state (`lock.locked`, `lock.reason`). |
| **App versions** | count of tills per `appVersion` (last `licence/validate`); highlight tills below your `minimumAppVersion`. |
| **Sync health per shop** | from `branch_sync_state` + your push log: `last_contact_at`, `last_push_at`, rows received today, last error (HTTP status + `code` you returned, e.g. 422 with `rejectedKey`, and when), `acknowledgedSeq` of the last push; from the last `licence/validate` `diagnostics` when present: `pendingSyncRows`, `lastSyncError`, `databaseSizeMb`. Red = no contact > 15 min in opening hours, a 401/403 in the last day (key rejected — the till stops syncing until the key is changed, §9), or `pendingSyncRows` growing across two check-ins. |
| **Sign-ups this week** | businesses created on the portal from Monday 00:00 Europe/London; and first activations this week (first `licence/activate` / `devices/activate` per till). |
| **Sales across all businesses** (optional) | the Business panel tiles summed over every company — same tables, no company filter. |

---

## 4. Reporting tables

All in PostgreSQL. Raw tables (4.1) are the source of truth; `rpt_*` tables (4.2–4.6) are disposable — you can
drop and rebuild them from raw at any time, which is also how you deploy a changed formula.

### 4.1 Raw tables (one per entity you report on)

Typed columns for what you query + `payload jsonb` + bookkeeping. Example for the four sale tables:

```sql
CREATE TABLE sale (
  id text PRIMARY KEY, company_id text NOT NULL, branch_id text NOT NULL, register_id text NOT NULL,
  shift_id text, user_id text, customer_id text, number bigint, receipt_number text,
  type text NOT NULL, status text NOT NULL, original_sale_id text,
  subtotal numeric(14,2), discount_total numeric(14,2), promo_total numeric(14,2),
  deposit_total numeric(14,2), vat_total numeric(14,2), total numeric(14,2),
  completed_at timestamptz, trading_day date, trading_hour smallint,     -- set at ingest (1.3)
  row_version bigint NOT NULL, deleted_at timestamptz,
  payload jsonb NOT NULL, first_received_at timestamptz NOT NULL, last_received_at timestamptz NOT NULL
);
CREATE INDEX ON sale (company_id, branch_id, trading_day);

CREATE TABLE sale_line (
  id text PRIMARY KEY, company_id text NOT NULL, sale_id text NOT NULL, product_id text, name text,
  qty numeric(14,4), base_qty numeric(14,4), unit_price numeric(14,2),
  line_discount numeric(14,2), promotion_discount numeric(14,2), coupon_discount numeric(14,2),
  vat_rate_id text, vat_percentage numeric(6,2), vat_amount numeric(14,2),
  deposit_amount numeric(14,2), cost_at_sale numeric(14,4), goods_total numeric(14,2), line_total numeric(14,2),
  is_bag_charge boolean, is_charity_round_up boolean,
  row_version bigint NOT NULL, deleted_at timestamptz, payload jsonb NOT NULL, first_received_at timestamptz NOT NULL
);
CREATE INDEX ON sale_line (sale_id);

CREATE TABLE sale_payment (
  id text PRIMARY KEY, company_id text NOT NULL, sale_id text NOT NULL,
  payment_type_id text, payment_type_name text, amount numeric(14,2), cashback numeric(14,2),
  change_given numeric(14,2), status text, is_offline boolean,
  row_version bigint NOT NULL, deleted_at timestamptz, payload jsonb NOT NULL, first_received_at timestamptz NOT NULL
);
CREATE INDEX ON sale_payment (sale_id);

CREATE TABLE sale_vat (
  id text PRIMARY KEY, company_id text NOT NULL, sale_id text NOT NULL,
  vat_rate_id text, code text, percentage numeric(6,2),
  net numeric(14,2), vat numeric(14,2), gross numeric(14,2),
  row_version bigint NOT NULL, deleted_at timestamptz, payload jsonb NOT NULL, first_received_at timestamptz NOT NULL
);
CREATE INDEX ON sale_vat (sale_id);
```

The version-guarded upsert (same shape for every raw table):

```sql
INSERT INTO sale (id, company_id, branch_id, register_id, /* … */ completed_at, trading_day, trading_hour,
                  row_version, deleted_at, payload, first_received_at, last_received_at)
VALUES (:entityId, :companyId, :p_branchId, :p_registerId, /* … */ :completedAt,
        (:completedAt::timestamptz AT TIME ZONE 'Europe/London')::date,
        extract(hour FROM :completedAt::timestamptz AT TIME ZONE 'Europe/London'),
        :version, :deletedAt, :payload, now(), now())
ON CONFLICT (id) DO UPDATE SET
  status = EXCLUDED.status, completed_at = EXCLUDED.completed_at,
  trading_day = EXCLUDED.trading_day, trading_hour = EXCLUDED.trading_hour,
  /* … every typed column … */
  row_version = EXCLUDED.row_version, deleted_at = EXCLUDED.deleted_at,
  payload = EXCLUDED.payload, last_received_at = now()
WHERE sale.row_version < EXCLUDED.row_version;   -- equal or older version: nothing changes (§7, §19.3)
```

Take `branch_id` / `register_id` for a `Sale` from the payload (`payload.branchId`, `payload.registerId`), which
always matches the envelope for till-scoped rows. The same pattern stores `Shift`, `ShiftTender`, `ZReport`,
`BranchProduct`, `StockMovement`, `Register`, `Product`, `Department`, `Category`, `User`, `PaymentType`, `VatRate`,
`CustomerOrder`, `ClockEvent`, `CustomerTransaction`.

### 4.2 `rpt_sales_daily` — one row per shop / till / trading day

| Column | Type | Formula (counted sales of that shop, till, day) |
|---|---|---|
| `company_id`, `branch_id`, `register_id`, `trading_day` | text, text, text, date | **Primary key** `(branch_id, register_id, trading_day)` |
| `gross` | numeric(14,2) | `SUM(SaleVat.gross)` |
| `net` | numeric(14,2) | `SUM(SaleVat.net)` |
| `vat` | numeric(14,2) | `SUM(SaleVat.vat)` |
| `txn_count` | int | sales with `type <> 'refund'` |
| `refund_count` | int | sales with `type = 'refund'` |
| `refund_gross` | numeric(14,2) | `−SUM(goodsTotal)` over returned lines |
| `refund_net` | numeric(14,2) | `−SUM(goodsTotal − vatAmount)` over returned lines |
| `discount`, `promo`, `coupon` | numeric(14,2) | `SUM(lineDiscount)`, `SUM(promotionDiscount)`, `SUM(couponDiscount)` over non-returned lines |
| `cost` | numeric(14,4) | `SUM(costAtSale)` over all lines (signed) |
| `container_deposits` | numeric(14,2) | `SUM(Sale.depositTotal)` |
| `takings` | numeric(14,2) | `SUM(Sale.total)` |
| `rebuilt_at` | timestamptz | when this row was last rebuilt |

### 4.3 `rpt_sales_hourly` — PK `(branch_id, register_id, trading_day, hour)`

`net = SUM(SaleVat.net)`, `gross = SUM(SaleVat.gross)`, `txn_count` (non-refund sales) — grouped by `trading_hour`.
Mirrors the till's `HourlySales` (`net`, `count`).

### 4.4 `rpt_tender_daily` — PK `(branch_id, register_id, trading_day, payment_type_id)`

`payment_type_name` (latest), `amount = SUM(amount − cashback − changeGiven)`, `count = COUNT(*)`,
`refunds = −SUM(amount − cashback − changeGiven)` over payments of refunds and negative payments of exchanges.
Payments with `status = 'reversed'` excluded. Mirrors the till's `TenderDaily` (`amount`, `count`, `refunds`).

### 4.5 `rpt_product_daily` — PK `(branch_id, register_id, trading_day, product_id)`

| Column | Formula |
|---|---|
| `qty` | `SUM(baseQty)` over non-returned lines |
| `refund_qty` | `−SUM(baseQty)` over returned lines |
| `gross` / `net` / `vat` | `SUM(goodsTotal)` / `SUM(goodsTotal − vatAmount)` / `SUM(vatAmount)` over **all** lines (signed — net of refunds) |
| `refund_net` | `−SUM(goodsTotal − vatAmount)` over returned lines |
| `discount`, `promo` | as 4.2, per product |
| `cost` | `SUM(costAtSale)` (signed) |
| `last_name` | latest `SaleLine.name` (fallback label) |

Department and category are **joined at query time** from `product` (the till groups history by the product's
current department). Do not copy them into this table.

### 4.6 `rpt_vat_daily` and `rpt_staff_daily`

- `rpt_vat_daily` — PK `(branch_id, register_id, trading_day, vat_rate_id)`; `code`, `percentage`,
  `net = SUM(SaleVat.net)`, `vat = SUM(SaleVat.vat)`, `gross = SUM(SaleVat.gross)`.
- `rpt_staff_daily` — PK `(branch_id, register_id, trading_day, user_id)`; `gross`, `net`, `txn_count`,
  `refund_count`, `refund_gross` (as 4.2, grouped by `Sale.userId`).

### 4.7 `branch_sync_state` and `sync_push_log` (for freshness and the Admin panel)

```sql
CREATE TABLE branch_sync_state (
  branch_id text PRIMARY KEY, company_id text NOT NULL,
  last_contact_at timestamptz, last_push_at timestamptz, last_pull_at timestamptz,
  last_row_at timestamptz,             -- highest envelope "at" stored
  last_ack_seq bigint, last_app_version text, last_register_id text,
  last_error_at timestamptz, last_error_status int, last_error_code text
);
CREATE TABLE sync_push_log (
  id bigserial PRIMARY KEY, branch_id text NOT NULL, register_id text, received_at timestamptz NOT NULL,
  first_seq bigint, last_seq bigint, rows int, accepted int, acknowledged_seq bigint,
  http_status int, error_code text, rejected_key text, app_version text, duration_ms int
);
CREATE TABLE rpt_dirty_day (branch_id text, trading_day date, PRIMARY KEY (branch_id, trading_day));
```

### 4.8 How a push updates the reporting tables — idempotently

1. Store the batch into the raw tables (4.1). Retried rows hit the `WHERE row_version < EXCLUDED.row_version`
   guard and change nothing.
2. Mark dirty days (in the same transaction as step 1):

```sql
INSERT INTO rpt_dirty_day (branch_id, trading_day)
SELECT DISTINCT s.branch_id, s.trading_day
FROM sale s
WHERE s.id = ANY(:touched_sale_ids)       -- Sale ids + saleId of every child row in the batch
  AND s.trading_day IS NOT NULL
ON CONFLICT DO NOTHING;
```

3. Reply to the till. 4. A background worker takes each dirty day and rebuilds it (one transaction per day):

```sql
BEGIN;
DELETE FROM rpt_sales_daily WHERE branch_id = :b AND trading_day = :d;
INSERT INTO rpt_sales_daily (company_id, branch_id, register_id, trading_day, gross, net, vat,
       txn_count, refund_count, refund_gross, refund_net, discount, promo, coupon, cost, container_deposits, takings, rebuilt_at)
SELECT s.company_id, s.branch_id, s.register_id, s.trading_day,
       COALESCE(SUM(v.gross),0), COALESCE(SUM(v.net),0), COALESCE(SUM(v.vat),0),
       COUNT(*) FILTER (WHERE s.type <> 'refund'),
       COUNT(*) FILTER (WHERE s.type = 'refund'),
       COALESCE(SUM(l.refund_gross),0), COALESCE(SUM(l.refund_net),0),
       COALESCE(SUM(l.discount),0), COALESCE(SUM(l.promo),0), COALESCE(SUM(l.coupon),0),
       COALESCE(SUM(l.cost),0), SUM(s.deposit_total), SUM(s.total), now()
FROM counted_sale s
LEFT JOIN LATERAL (
  SELECT SUM(gross) gross, SUM(net) net, SUM(vat) vat
  FROM sale_vat WHERE sale_id = s.id AND deleted_at IS NULL) v ON true
LEFT JOIN LATERAL (
  SELECT SUM(-goods_total)                FILTER (WHERE ret) refund_gross,
         SUM(-(goods_total - vat_amount)) FILTER (WHERE ret) refund_net,
         SUM(line_discount)               FILTER (WHERE NOT ret) discount,
         SUM(promotion_discount)          FILTER (WHERE NOT ret) promo,
         SUM(coupon_discount)             FILTER (WHERE NOT ret) coupon,
         SUM(cost_at_sale) cost
  FROM (SELECT sl.*, (s.type = 'refund' OR (s.type = 'exchange' AND sl.qty < 0)) AS ret
        FROM sale_line sl WHERE sl.sale_id = s.id AND sl.deleted_at IS NULL) x) l ON true
WHERE s.branch_id = :b AND s.trading_day = :d
GROUP BY s.company_id, s.branch_id, s.register_id, s.trading_day;
-- … same DELETE + INSERT for rpt_sales_hourly, rpt_tender_daily, rpt_product_daily, rpt_vat_daily, rpt_staff_daily
DELETE FROM rpt_dirty_day WHERE branch_id = :b AND trading_day = :d;
COMMIT;
```

Why this is safe in every case:

| Case | What happens |
|---|---|
| Same batch pushed twice (timeout, lost reply) | Raw upserts change nothing; the day is rebuilt to the same numbers. |
| Older `version` arrives after a newer one | Guard ignores it; rebuild gives the same numbers. |
| A `Sale` arrives `open` (v1), then `completed` or `voided` (v2) | v1 was not counted; v2 wins the upsert, marks its day dirty, and the rebuild counts it once (completed) or not at all (voided). |
| Lines arrive in the next batch after their `Sale` | The `Sale` alone counts (with 0 VAT rows) until the lines/VAT rows arrive; their `saleId` marks the same day dirty and the rebuild completes it. |
| **A sale refunded later** (e.g. sold 23 Sep, refunded 25 Sep) | The refund is a new `Sale` (`type = 'refund'`, negative amounts, `originalSaleId` = the 23rd's sale) with `trading_day` 25 Sep. Only 25 Sep is dirty; 23 Sep is untouched. 25 Sep: `net` goes down, `refund_count` + 1, `refund_gross` up, `txn_count` unchanged. |
| Shop offline for a day, then catches up | Every day touched is marked dirty and rebuilt; past days grow to their true totals. |
| Formula change on your side | `TRUNCATE rpt_*`, insert every `(branch_id, trading_day)` from `sale` into `rpt_dirty_day`, let the worker run. |

**Cross-check (optional, recommended):** the till pushes its own daily summaries too — `SalesDaily` (`date`,
`productId`, `registerId`, `userId`, `qty`, `net`, `vat`, `gross`, `cost`, `discount`, `promo`, `refundQty`,
`refundNet`, `profit`), `TenderDaily` (`date`, `registerId`, `paymentTypeId`, `amount`, `count`, `refunds`) and
`HourlySales` (`date`, `hour`, `registerId`, `net`, `count`). Their `date` is already the local trading day. For a
shop and day, `SUM(SalesDaily.net − SalesDaily.refundNet)` must equal your `SUM(rpt_sales_daily.net)` and
`SUM(TenderDaily.amount)` your `SUM(rpt_tender_daily.amount)`. Show a mismatch on the Admin panel's sync health.
Do not build tiles from them: `SalesDaily.vat` and `.gross` are sales-only (refunds are not taken off them), and
they cannot be re-filtered.

---

## 5. PostgreSQL for the core tiles

Parameters: `:company text`, `:branches text[]` (null = all), `:registers text[]` (null = all), `:from date`,
`:to date` (trading days, inclusive). Put the scope filter in one SQL fragment:

```sql
-- SCOPE
company_id = :company
AND (:branches  IS NULL OR branch_id   = ANY(:branches))
AND (:registers IS NULL OR register_id = ANY(:registers))
```

### 5.1 Headline tiles with previous period

```sql
WITH p AS (SELECT :from::date f, :to::date t, (:to::date - :from::date + 1) n),
cur AS (
  SELECT SUM(gross) gross, SUM(net) net, SUM(vat) vat, SUM(txn_count) txns,
         SUM(refund_count) refund_count, SUM(refund_gross) refunds,
         SUM(discount) discounts, SUM(takings) takings
  FROM rpt_sales_daily, p WHERE /* SCOPE */ AND trading_day BETWEEN p.f AND p.t),
prev AS (
  SELECT SUM(net) net, SUM(txn_count) txns, SUM(gross) gross
  FROM rpt_sales_daily, p WHERE /* SCOPE */ AND trading_day BETWEEN p.f - p.n AND p.f - 1)
SELECT cur.*,
       CASE WHEN cur.txns > 0 THEN ROUND(cur.net / cur.txns, 2) END   AS avg_basket_ex_vat,
       CASE WHEN cur.txns > 0 THEN ROUND(cur.gross / cur.txns, 2) END AS avg_basket_inc_vat,
       prev.net AS prev_net,
       CASE WHEN prev.net <> 0 THEN ROUND(100 * (cur.net - prev.net) / prev.net, 1) END AS net_change_pct
FROM cur, prev;
```

### 5.2 Takings by payment type

```sql
SELECT t.payment_type_id, COALESCE(pt.name, MAX(t.payment_type_name)) AS name,
       SUM(t.amount) AS amount, SUM(t.count) AS payments, SUM(t.refunds) AS refunds
FROM rpt_tender_daily t
LEFT JOIN payment_type pt ON pt.id = t.payment_type_id
WHERE /* SCOPE on t */ AND t.trading_day BETWEEN :from AND :to
GROUP BY t.payment_type_id, pt.name
ORDER BY amount DESC;
```

### 5.3 Sales by hour (today, with the same weekday last week)

```sql
WITH today AS (SELECT (now() AT TIME ZONE 'Europe/London')::date d)
SELECT h.hour,
       SUM(h.net)       FILTER (WHERE h.trading_day = today.d)     AS net_today,
       SUM(h.txn_count) FILTER (WHERE h.trading_day = today.d)     AS txns_today,
       SUM(h.net)       FILTER (WHERE h.trading_day = today.d - 7) AS net_last_week
FROM rpt_sales_hourly h, today
WHERE /* SCOPE on h */ AND h.trading_day IN (today.d, today.d - 7)
GROUP BY h.hour ORDER BY h.hour;       -- fill missing hours 0–23 with 0 in the UI
```

### 5.4 Top products and departments

```sql
SELECT r.product_id, COALESCE(p.name, MAX(r.last_name)) AS name,
       COALESCE(d.name, 'Unassigned') AS department,
       SUM(r.qty - r.refund_qty) AS qty, SUM(r.net) AS net, SUM(r.gross) AS gross
FROM rpt_product_daily r
LEFT JOIN product p    ON p.id = r.product_id
LEFT JOIN department d ON d.id = p.department_id
WHERE /* SCOPE on r */ AND r.trading_day BETWEEN :from AND :to
GROUP BY r.product_id, p.name, d.name
ORDER BY net DESC LIMIT 10;
-- departments: same FROM/WHERE, GROUP BY d.id, d.name, ORDER BY SUM(r.net) DESC
```

### 5.5 VAT by rate

```sql
SELECT code, percentage, SUM(net) AS net, SUM(vat) AS vat, SUM(gross) AS gross
FROM rpt_vat_daily
WHERE /* SCOPE */ AND trading_day BETWEEN :from AND :to
GROUP BY code, percentage ORDER BY code;
```

### 5.6 Low stock (the till's rule) and freshness

```sql
SELECT bp.branch_id, COUNT(*) AS low_stock
FROM branch_product bp
JOIN product p ON p.id = bp.product_id
WHERE bp.company_id = :company AND (:branches IS NULL OR bp.branch_id = ANY(:branches))
  AND bp.deleted_at IS NULL AND bp.is_active
  AND COALESCE(bp.is_stock_tracked, p.track_stock)
  AND bp.qty_on_hand <= COALESCE(bp.reorder_point, bp.min_qty, p.min_stock_qty, 5)  -- 5 = stock.low_stock_threshold default
GROUP BY bp.branch_id;

SELECT b.id, b.name, s.last_contact_at, s.last_row_at,
       floor(extract(epoch FROM now() - s.last_contact_at) / 60)::int AS minutes_ago,
       CASE WHEN s.last_contact_at > now() - interval '2 minutes'  THEN 'green'
            WHEN s.last_contact_at > now() - interval '15 minutes' THEN 'amber'
            ELSE 'red' END AS state
FROM branch b LEFT JOIN branch_sync_state s ON s.branch_id = b.id
WHERE b.company_id = :company ORDER BY b.name;
```

### 5.7 Cash variance

```sql
SELECT sh.branch_id, sh.register_id, SUM(st.variance) AS cash_variance, COUNT(DISTINCT sh.id) AS shifts
FROM shift sh
JOIN shift_tender st ON st.shift_id = sh.id AND st.deleted_at IS NULL
JOIN payment_type pt ON pt.id = st.payment_type_id AND pt.is_cash
WHERE /* SCOPE on sh */ AND sh.status = 'closed' AND sh.deleted_at IS NULL
  AND (sh.closed_at AT TIME ZONE 'Europe/London')::date BETWEEN :from AND :to
GROUP BY sh.branch_id, sh.register_id;
```

---

## 6. Worked example — `samples/push-request.json`

Leeds (`branchId …B001`), sent by Leeds till 1 (`registerId …R001`), 9 rows, `seq` 18231–18239.

**What is in it:** one `Sale` (`receiptNumber` `LDS-01-000482`, `type` `sale`, `status` `completed`,
`total` 5.15, `vatTotal` 0.62, `completedAt` `2026-09-23T09:41:12Z`, `userId …A001`), two `SaleLine`, one
`SalePayment`, two `SaleVat`, two `StockMovement`, and one `CustomerOrder` update (`status` `ready`, `version` 2).

| Row | Key figures |
|---|---|
| SaleLine 1 — Warburtons Toastie White Bread 800g (`…P001`) | `qty` 1, `baseQty` 1, `unitPrice` 1.45, `goodsTotal` 1.45, `vatPercentage` 0, `vatAmount` 0.00, `costAtSale` 0.98 |
| SaleLine 2 — Coca-Cola Original Taste 500ml (`…P002`) | `qty` 2, `baseQty` 2, `unitPrice` 1.85, `goodsTotal` 3.70, `vatPercentage` 20, `vatAmount` 0.62, `costAtSale` 0.78 |
| SalePayment | Card (`…T001`), `amount` 5.15, `cashback` 0, `changeGiven` 0, `status` `approved` |
| SaleVat Z | `percentage` 0, `net` 1.45, `vat` 0.00, `gross` 1.45 |
| SaleVat S | `percentage` 20, `net` 3.08, `vat` 0.62, `gross` 3.70 |
| StockMovement | P001 `qtyDelta` −1, `qtyAfter` 22; P002 `qtyDelta` −2, `qtyAfter` 46 |

**Trading day:** `2026-09-23T09:41:12Z` is in BST → 10:41 local → trading day **2026-09-23**, hour **10**.

**Expected dashboard for Leeds, 23 Sep 2026** (this push only):

| Tile | Working | Value |
|---|---|---|
| Sales (inc VAT) | 1.45 + 3.70 | **£5.15** |
| Net sales (ex VAT) | 1.45 + 3.08 | **£4.53** |
| VAT | 0.00 + 0.62 | **£0.62** |
| Transactions | 1 sale, type `sale` | **1** |
| Average basket (ex VAT) | 4.53 ÷ 1 | **£4.53** (inc VAT £5.15) |
| Refunds | no returned lines | **£0.00**, 0 refunds |
| Discounts | `lineDiscount` 0 + 0 | **£0.00** |
| Takings — Card | 5.15 − 0 − 0 | **£5.15**, 1 payment |
| Takings — Cash | none | £0.00 |
| Takings total | = `Sale.total` 5.15 | **£5.15** |
| Sales by hour | hour 10 | net £4.53, 1 transaction |
| Top products by net | Coca-Cola: 3.70 − 0.62 = 3.08, qty 2; Warburtons: 1.45 − 0 = 1.45, qty 1 | 1. Coca-Cola **£3.08** (2), 2. Warburtons **£1.45** (1) |
| VAT by rate | S 20 %: net 3.08 / VAT 0.62 / gross 3.70; Z 0 %: 1.45 / 0.00 / 1.45 | total 4.53 / 0.62 / 5.15 |
| Gross profit (optional) | 4.53 − (0.98 + 0.78) | £2.77 |
| Staff sales | user `…A001` | 1 transaction, net £4.53 |
| Stock on hand | newest `qtyAfter` (a `BranchProduct` row, when it arrives, is authoritative) | P001 **22**, P002 **46** |
| Low stock | P001: `Product.minStockQty` 6 (samples/entities/Product.json) → 22 > 6 → not low; P002: 46 > 5 → not low | **0** |
| Orders ready to collect | `CustomerOrder` `WEB-20260923-0007`, `status` `ready`, `goodsTotal` 10.30 — **not a sale** | 1 |
| Freshness | `last_row_at` = 09:42:12Z (highest `at`); `last_contact_at` = your receive time | "Updated 0 min ago" |

**Push reply:** `{"acknowledgedSeq": 18239, "accepted": 9}` (`samples/push-reply.json`). Push the same batch again:
the reply is identical and **every figure above is unchanged**.

**rpt rows after the rebuild of (Leeds, 2026-09-23):**

| Table | Row |
|---|---|
| `rpt_sales_daily` | (B001, R001, 2026-09-23): gross 5.15, net 4.53, vat 0.62, txn_count 1, refund_count 0, refund_gross 0, discount 0, cost 1.76, container_deposits 0, takings 5.15 |
| `rpt_sales_hourly` | (B001, R001, 2026-09-23, 10): net 4.53, gross 5.15, txn_count 1 |
| `rpt_tender_daily` | (B001, R001, 2026-09-23, T001): amount 5.15, count 1, refunds 0 |
| `rpt_product_daily` | (…, P001): qty 1, gross 1.45, net 1.45, vat 0; (…, P002): qty 2, gross 3.70, net 3.08, vat 0.62 |
| `rpt_vat_daily` | (…, V001 `S` 20): 3.08 / 0.62 / 3.70; (…, V002 `Z` 0): 1.45 / 0.00 / 1.45 |
| `rpt_staff_daily` | (…, A001): gross 5.15, net 4.53, txn_count 1 |

**Add the other two samples** (`push-request.second-till.json`: the same basket on Leeds till 2, `LDS-02-000017`,
09:44:12Z; `push-request.second-branch.json`: the same basket in Bradford, `BFD-01-000233`, 09:46:12Z):

| Scope | Sales inc VAT | Net | VAT | Transactions | Avg basket ex VAT |
|---|---|---|---|---|---|
| Leeds till 1 | 5.15 | 4.53 | 0.62 | 1 | 4.53 |
| Leeds till 2 | 5.15 | 4.53 | 0.62 | 1 | 4.53 |
| **Leeds** | 10.30 | 9.06 | 1.24 | 2 | 4.53 |
| Bradford | 5.15 | 4.53 | 0.62 | 1 | 4.53 |
| **Business** | **15.45** | **13.59** | **1.86** | **3** | **4.53** |

Top products for the business: Coca-Cola qty 6, net £9.24; Warburtons qty 3, net £4.35.

**A later refund (illustration — not in the samples).** On 25 Sep at 14:05 BST Leeds till 1 refunds the two Cokes.
A new `Sale` arrives: `type` `refund`, `status` `completed`, `originalSaleId` = `01K5VB000000000SR001000482`,
`total` −3.70, `vatTotal` −0.62, `completedAt` `2026-09-25T13:05:00Z`; its `SaleLine` `qty` −2, `goodsTotal` −3.70,
`vatAmount` −0.62; `SaleVat` S `net` −3.08, `vat` −0.62, `gross` −3.70; `SalePayment` Card `amount` −3.70.

| Leeds, 25 Sep (only this refund) | Value |
|---|---|
| Sales inc VAT / Net / VAT | −£3.70 / −£3.08 / −£0.62 |
| Transactions | 0 (a refund is not a transaction) |
| Refunds | £3.70, 1 refund (`refund_net` £3.08) |
| Takings — Card | −£3.70 (`refunds` column £3.70) |
| Top products — Coca-Cola | qty 0 sold, refund_qty 2, net −£3.08 |
| **Leeds, 23 Sep** | **unchanged** (£10.30 / £9.06) |

---

## 7. Coming in v1.4 — build so these drop in

| Change | What the till will do | What to build now so nothing breaks |
|---|---|---|
| **Portal receive time** | The push reply gains `receivedAt` (your receive time, UTC). The till stores it and shows "sent to portal at …". | Keep `first_received_at` / `last_received_at` on every raw row (4.1) and return `receivedAt` in your reply as soon as you like — unknown reply fields are ignored by older tills. Show on a sale: **"Created on till 10:41 · received by portal 10:41:15"** (`completedAt` vs `first_received_at`), and on the Admin panel the **sync lag** per shop = `first_received_at − Sale.completedAt` (median, today). |
| **Secondary tills' rows carry their own `registerId`** | Today a sale rung on a second till of a shop can be stored under the **main till's** register, shift and user (found in the till's sync audit, 2026-09-28) — shop totals are right, the per-till and per-user split is not. From v1.4 every row made on till 2 carries till 2's `registerId` (and its own shift and user), exactly as `samples/push-request.second-till.json` already shows. | Key every reporting table by `register_id` and `user_id` now (section 4 does). Show the Till filter and the Staff table for multi-till shops with a small note "per-till figures reliable from app version 3.x (v1.4)" until those shops run it. Do not try to re-attribute old rows. |
| **Customer balance and points from the ledger** | `Customer.balance` / `Customer.points` can lose an update when two shops change the same customer at once. From v1.4 the truth is the ledger: `CustomerTransaction` rows (`customerId`, `type` = `charge|payment|refund|pointsEarn|pointsBurn|pointsAdjust|pointsExpire`, `amount`, `points`, `saleId`, `balanceAfter`, `pointsAfter`, `at`, `branchId`). | Store `CustomerTransaction` as a raw table now. Compute a customer's balance and points as sums over their ledger rows (the sign convention is confirmed in the v1.4 pack; `balanceAfter` / `pointsAfter` on the newest row are a cross-check), and show those — not `Customer.balance`. A later customer-accounts tile ("owed to you", "points outstanding") then needs no migration. |
| **Other v1.4 sync fixes** | `baseVersion` on pushed portal-owned rows (§19.3); `Setting` rows synced (then use the real `stock.low_stock_threshold` instead of 5); date-times always with `Z`. | Treat a date-time without an offset as UTC (§6) today; read the low-stock threshold from a per-shop setting with default 5. |

---

## 8. Checks before you show a real shop

1. Push `samples/push-request.json` → the dashboard for Leeds, 23 Sep 2026 shows exactly section 6's first table.
2. Push it again → identical reply, identical figures.
3. Push the three samples in any order, twice each → section 6's multi-shop table.
4. A sale at 23:30 UTC in summer lands on the **next** local day and in hour 0.
5. A refund on a later day changes only that day; its original day is unchanged.
6. A `training` or `quote` sale, or a `held` / `voided` one, changes nothing.
7. `SUM(rpt_tender_daily.amount)` = `SUM(rpt_sales_daily.takings)` for every shop-day (cashback and change taken off).
8. `SUM(rpt_sales_daily.net)` = `SUM(SalesDaily.net − SalesDaily.refundNet)` from the till's own summaries for
   every shop-day; and `rpt_sales_hourly` net per hour = `HourlySales.net`.
9. A shop with all costs at 0 shows no margin warnings.
10. A business owner cannot see another business's shop by changing an id in the URL.
