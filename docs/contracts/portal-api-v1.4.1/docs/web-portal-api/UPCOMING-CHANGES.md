Revision 1.5 (2026-09-29): prices, offers and standing discounts made at a shop — this shop or every shop.
Only what the sync API sees differently: `SHOP-OR-EVERY-SHOP.md` (in this folder). Not yet folded into
`docs/web-portal-api.md`.

Purchase returns (2026-09-29): two new branch-owned tables, `PurchaseReturn` and `PurchaseReturnLine` (schemas in
`schemas/entities/`), pushed like `GoodsReceipt`/`SupplierCreditNote` — read-only to the portal. A goods-in with
damaged units opens one (`GoodsReceiptId` set); `Status` Draft → Sent → Credited (or Cancelled); `Reference` =
"PR-{branch code}-00012". Recording the supplier's credit also pushes the usual `SupplierCreditNote` row
(`Reason` "Purchase return PR-…").

Clock events (2026-09-30): `ClockEvent` gains `registerId` (string or null) — the till the clock was pressed on, so
the Z report's "Sales by cashier" shows who worked each till's shift. Null on older rows and back-office corrections.
Pushed as before; read-only to the portal.

Licensing (2026-09-30, portal decisions confirmed by the till; to fold into `docs/web-portal-api.md` §17.7, §17.8,
§17.12 — that file is being edited elsewhere, so the text lives here until then):

- **409 `migrate.activate_first` on `cloud/migrate`** (new; `licensing/samples/error-codes.json`,
  `error.migrate-activate-first.409.json`, openapi E409, Postman "409 activate first"). The install has no licence
  activated on the portal yet. `details: null`. The till: **Connect** saves nothing and tells the owner to enter the
  licence key first (Settings → Licence → Enter key, i.e. `licence/activate`) and then press Connect again with the
  **same** sync key; the **background move** (a key typed with no internet) keeps the key, backs off 1 → 2 → 5 →
  15 minutes and tries again, and Sync status says to enter the licence key. The key is not spent — do not count it
  as used. The till's own text wins over `message` for this code.
- **`devices/deactivate` of the main till — option (b).** `transferCode: null`, `transferCodeExpiresAt: null`
  (usually `apiKeyRevoked: true`); the **same licence key** is activated again on the new PC with
  `licence/activate` (release its binding to the old `installId` when you answer 200). Put the next step in
  `messages[]` (sample `licensing/samples/deactivate-reply.main-till.same-key.json`, openapi example
  `mainTillSameKey`): the till shows "This till has been deactivated." followed by each due message as
  "title: text" straight after Deactivate. No message → the till says "To use this licence on the new PC, enter the
  same licence key there (Settings → Licence → Enter key)." A null `transferCode` is a normal success, never an
  error; a non-null one still works (shown once with Copy). §17.7 "Transfer to a new PC" becomes: old PC → Backup →
  Deactivate; new PC → install → restore → Enter key (the same licence key) → sync resumes from the till's cursor.

Release 0.1.15 (2026-09-30, in 0.1.15 — v0.1.14..v0.1.15). No endpoint, envelope, header or ownership change;
`X-SSPOS-Contract` stays `1`. One migration (`ClockEventRegister`). What the portal sees differently:

- **`ClockEvent.registerId`** — see "Clock events" above (schema updated).
- **New ledger accounts** (seeded on first start of 0.1.15, then pushed as `Account` rows, op `I`): `2240`
  "Customer deposits held" and `2250` "Charity collections held", both `Liability`. `Account` is hub-owned, but the
  ids are made on each shop's till: a company with several shops receives one pair per shop (same `code`,
  different `id`). Store by `id` as always; group by `code` wherever you show one chart for the company.
- **New payment types** (seeded the same way, `PaymentType` op `I`): **"Order deposit"** (position 6) and
  **"Loyalty points"** (position 7), both `showOnRefund: false`, `showOnCustomerPayment: false` — not keys on the
  payment card. Same per-shop id note as the accounts; match by `name` for company-wide views.
- **`SalePayment` rows with the new tenders.** Loyalty points spent: `paymentTypeName` "Loyalty points",
  `amount`/`appliedAmount` = pounds, `providerRef` = the customer's id; a refund carries the negative amount. The
  points move as `CustomerTransaction` `pointsBurn` (negative `points`, `saleId` set, note "Points spent on sale …")
  and, on a refund, `pointsAdjust` (positive, "Points returned by refund …"). No points are earned on the part paid
  with points. Order deposit applied at collection: `paymentTypeName` "Order deposit" (before 0.1.15 the paid part
  was applied as a Voucher tender). `TenderKind` (till-internal: `Deposit` = 5, `Points` = 6) is **not** in any
  pushed row — the portal only sees the payment type.
- **`CashMovement.type` gains `accountPayment`** (enum value 16, schema regenerated): cash a customer paid off
  their account at the till; it counts in the shift's expected cash. Card account payments write none.
- **`CashMovement` with no shift**: sale cash taken with no shift open is now written with `shiftId: ""`
  and later updated (op `U`) with the next shift's id; the shift gets an `AuditLog` `AdoptNoShiftCash`
  (`entityName` "Shift").
- **Ledger (`JournalEntry` / `JournalLine`), every change from 0.1.15 on — old entries are not re-posted:**
  - Refunds: before 0.1.15 a refund was journalled like a sale (sales, VAT and cash went **up**); now it debits
    sales and VAT output and credits the tenders. Refunds also post at once (before, a refund waited for the next
    sale). An Account refund now goes back on the customer (`Sale.customerId` is set on refunds of a customer's
    sale; a no-receipt Account refund needs a customer).
  - Exempt and out-of-scope sales post to `4030` / `4040` (before, every 0% line went to `4020` zero rated).
  - Customer-order deposits and part-payments (sale line `productId` "ORDER-DEPOSIT"): Cr `2240`, not sales;
    collection releases it (Dr `2240`) and books the goods at their own VAT rates; a cancelled order's deposit
    refund debits `2240`.
  - Charity round-up (line `productId` "CHARITY-ROUNDUP"): Cr `2250`, never income.
  - Loyalty points tender: Dr `2230` on the sale, Cr `2230` on its refund. Points expiry (Settings "Points expire
    after (months)" now takes effect): `CustomerTransaction` `pointsExpire` + journal `refType` "LoyaltyExpire"
    (Dr `2230` / Cr `6900`).
  - 5p cash rounding: up to 2p per cash sale or refund to `6100` Cash over/short (before, `9999` Suspense).
- **Refund documents** (`Sale` with negative totals, its `SaleLine`s and `SalePayment`s): a part refund of a
  split-tender sale now comes back card first, then gift voucher, then account, cash last (was pro rata over
  every tender); an exchange's pay-back defaults to the same order. A part refund of a multi-buy line is priced so
  the items kept still get the deal (4 tins on 3 for £2 at £2.95: one back = £0.95); the refund line's unit price
  is the price it comes back at, and part refunds of one line never add up to more than the line took.
- **Discounts:** a staff-purchase discount's share on each line is `SaleLine.discountSource: "staff"` (was
  `"manual"`).
- **DRS:** no deposit is charged (`depositTotal` 0, nothing on `2220`) while the DRS scheme setting is off.
- **Gift voucher sold by card:** the `StoreCreditVoucher` is issued only after the card payment is approved;
  its `issuedSaleId` is the card payment's cart id.
- **New `AuditLog` actions:** `NoSale` (`entityName` "Drawer", `entityId` = register id; plus an `ExceptionLog`
  `NoSale` row) and `LineVoided` (`entityName` "Sale", `entityId` = the cart id — not a sale id; product, quantity,
  value, reason in `afterJson`, only when "Ask reason for void" is on).
- **Setting defaults** (only matter where no row exists): `till.ask_reason_void` false (was true), `till.key_click_sound`
  false (was true), `till.cart_product_count_mode` "Units" (was "Lines"). No new setting keys; `SettingSyncPolicy` and `SyncOwnershipMap`
  unchanged.
