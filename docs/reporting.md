# Reporting tables (module 3.1)

The `rpt_*` tables that the admin trading dashboard (3.2), the business dashboard (3.3) and the reports (4.8) read.
Contract: v1.4.1 `docs/web-portal-api/DASHBOARD.md` (§1 rules, §2 tiles, §4 tables, §6 worked example). Built for
**MySQL 8 and SQLite**: no LATERAL, no `FILTER`, no time-zone functions in SQL.

Code: `app/Domain/Reporting` (`ReportTables` lists every table's keys and columns in one place).

## Tables

Every row starts with `company_id, branch_id, trading_day, register_id`, then its own key; the primary key is that
whole tuple (so it contains `trading_day`, ready for monthly RANGE partitions, see scaling.md). Index
`(company_id, trading_day)` on each; `rpt_sales_daily` also `(trading_day)` for the admin's all-business totals.
Money `decimal(12,2)`, quantities and costs `decimal(14,4)`, signed (refunds net off).

| Table | Key | Columns (formula over counted sales of the shop, till and day) |
|---|---|---|
| `rpt_sales_daily` | till | `gross`/`net`/`vat` = Σ SaleVat less order-deposit and charity lines; `txn_count` (type ≠ refund, deposit), `refund_count`; `refund_gross`/`refund_net` = −Σ returned lines' goodsTotal / (goodsTotal − vatAmount); `discount`/`promo`/`coupon` = Σ non-returned lines; `cost` = Σ costAtSale (all lines); `staff_discount` = Σ non-returned lines with `discountSource` staff (lineDiscount − promo − coupon); `container_deposits` = Σ Sale.depositTotal; `order_deposits` / `charity` = Σ goodsTotal of `ORDER-DEPOSIT` / `CHARITY-ROUNDUP` lines; `takings` = Σ Sale.total; `void_count`/`void_total` (voided baskets) |
| `rpt_sales_hourly` | till, local `hour` | `net`, `gross` (SaleVat), `txn_count` |
| `rpt_tender_daily` | till, `payment_type_id` | `amount` = Σ (amount − cashback − changeGiven), `count`, `refunds` = −Σ of refund payments and negative exchange payments; newest `payment_type_name`. Approved and recovered payments only. Read: one line per name (payment types are made per shop) |
| `rpt_product_daily` | till, `product_id` | `qty`/`refund_qty` (baseQty, sold / returned), `gross`/`net`/`vat` (all lines, signed), `refund_net`, `discount`, `promo`, `cost`, `last_name` (newest line name). Department / category joined when read |
| `rpt_vat_daily` | till, `vat_rate_id`, `percentage` | `code`, `net`, `vat`, `gross` |
| `rpt_staff_daily` | till, `user_id` | `gross`, `net`, `txn_count`, `refund_count`, `refund_gross`, `void_count` |
| `rpt_dirty_days` | company, shop, day | `token` (new on every mark), `marked_at` |

**Counted sale** (§1.4): `status = completed`, `completed_at` set, type `sale|refund|exchange|deposit`, not deleted.
Training, quotes, open and held baskets never count.
**Not sales** (till 0.1.15): order-deposit lines (`productId` `ORDER-DEPOSIT`, held on 2240 until collection sells the goods)
and charity round-ups (`CHARITY-ROUNDUP` or `isCharityRoundUp`, held on 2250) are taken off every SaleVat figure and
kept out of product, refund and staff figures; they are summed on their own and stay in takings. All-zero hourly and VAT rows are dropped. **Returned line**: a line of a refund, or qty < 0 on an exchange.
**Refunds** are their own sales with negative amounts: every figure is a signed sum; only the refund columns are
negated to show them positive, and a refund lands on its own day.

**Voids.** A voided sale was never completed (`completedAt` null), so it lands on the local day of its `updatedAt`
(when it was voided) and counts in `void_count` / `void_total` only, in no other figure.

## Trading day

`sales.trading_day` / `trading_hour` (added by `2026_10_12_100000_create_reporting_tables.php`, hand-written, not in
till-schema.json) = the Europe/London date and hour of `completed_at` (voided: `updated_at`), worked out in PHP
(`TradingDay`, `SaleDayStamper`) when the sale is stored. 23:30Z on a summer day is the next day, hour 0; both 01:00
hours of the October change are hour 1. `config/reporting.php` `timezone` (`REPORTS_TIMEZONE`).

## How rows get in (idempotent)

1. `ChunkApplier` (in each 500-change transaction of `ApplySyncChanges`) calls `ReportDayTracker`:
   - before the writes: the stored (shop, day) of every sale the chunk touches (Sale ids, children's `saleId`, a
     child deleted without payload: its stored `sale_id`);
   - after the writes: stamps the trading day of the sales written; for every **applied** Sale / SaleLine /
     SalePayment / SaleVat change, marks the old and the new (shop, day) dirty (`rpt_dirty_days`, fresh token).
   Duplicates, stale and echoed rows mark nothing; a replay marks nothing.
2. After the batch, `ProcessDirtyReportDaysJob` is dispatched once per business (after commit;
   `ShouldBeUniqueUntilProcessing` + `WithoutOverlapping` per business; `REPORTS_QUEUE`).
3. The job (`ProcessDirtyReportDays`) takes the oldest dirty days and, per shop, 7 days at a time
   (`days_per_rebuild`), **deletes and re-inserts** those days' rows in every `rpt_*` table (`RebuildReportDays`),
   then removes only the tokens it read (a day marked again meanwhile stays queued).
4. `reports:process-dirty` (every minute) re-queues businesses whose days waited more than 2 minutes (lost or
   failed job).

A rebuild is 6 grouped queries (`SaleFacts`, sums in whole pence so SQLite REAL never drifts) plus one delete and
one bulk insert per table, whatever the number of sales (a test proves the query count is fixed). Replays,
duplicates, out-of-order children, an open → completed sale, an edit of a completed sale (kept out as an immutable
conflict), a soft delete and late refunds cannot double-count: a day is always recomputed from the raw rows.

## Commands

```bash
php artisan reports:rebuild [--company=ID ...] [--from=2026-09-01] [--to=2026-09-30]   # full rebuild, chunked
php artisan reports:check   [--company=ID ...] [--from=…] [--to=…] [--fix]              # incremental == rebuild?
php artisan reports:process-dirty [--now]                                              # the sweep
```

`reports:rebuild` first stamps the trading day of sales stored before 3.1 (**run it once after deploying 3.1**),
then rebuilds every (shop, day) that has sales or report rows. `reports:check` works out each shop-day in memory
and compares it with the stored rows column by column; exit 1 on any difference or unstamped sale. Both are
idempotent and safe while tills push. A formula change = deploy, then `reports:rebuild`.

## Reading (for 3.2, 3.3, 4.8)

```php
$scope = ReportScope::tenant('2026-09-01', '2026-09-30', $branchIds, $registerIds);  // current company, fail closed
$scope = ReportScope::admin(null, $from, $to);                                       // super admin: all businesses

app(SalesReport::class)->totals($scope);                              // SalesTotals: KPI tiles (§2.2)
app(SalesReport::class)->compare($scope, $scope->previousPeriod());   // ->changePercent('net'); sameLastWeek(), sameLastYear()
app(SalesReport::class)->byDay($scope); ->byHour($scope, $upToHour); ->upToHour($scope, TradingDay::currentHour());
app(SalesReport::class)->byBranch($scope); ->byRegister($scope); ->byCompany($adminScope);
app(TenderReport::class)->byPaymentType($scope);
app(VatReport::class)->byRate($scope);
app(ProductReport::class)->top($scope, 10, 'net'); ->byDepartment($scope); ->byCategory($scope);
app(StaffReport::class)->byUser($scope);
app(AdminTradingReport::class)->topCompanies($adminScope, 10); ->topBranches($adminScope, 10); ->activity($adminScope);  // 3.2, admin only
```

Every method returns readonly Data objects (`app/Domain/Reporting/Data`) with money as fixed-scale strings. Averages
and gross profit are null when the screen should show "—" (no transactions / no costs). Names are joined at read
time (shop, till "code – name", payment type, product, department, category, till user) with a fallback. The rpt
models (`RptSalesDaily`…) use `BelongsToCompany` and refuse saves. The shop manager's one-shop restriction
(`CurrentCompany::restrictedBranchId()`, module 3.3) is passed in `branchIds` by the caller.

Not here (read from the raw tables, DASHBOARD.md): stock on hand and low stock (§2.6, "now"), Z reports, shifts and
cash variance (§2.4–2.5), tills online (§2.8). The optional cross-check against the till's own `SalesDaily` /
`TenderDaily` (§4.8) is not built yet.

## Demo sales (module 3.2)

```bash
php artisan demo:sales [--company=<id or exact name>] [--days=60] [--fresh]   # never in production
```

Fills the demo tenants (Khan Mini Mart, Patel News and Booze) with till-shaped sales through `ApplySyncChanges` (ledger
stream `demo-sales`), then rebuilds their `rpt_*` rows. Repeatable (same ids and seqs per shop and date); `--fresh`
first removes only the rows of the `demo-sales` stream. Code: `Reporting\Actions\GenerateDemoSales`,
`Reporting\Demo\*`.
