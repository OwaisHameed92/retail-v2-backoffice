# SSPOS till 0.1.15 — what changes for the portal (2026-09-30)

For the web-portal developer. The contract is still v1 (`X-SSPOS-Contract: 1`): no endpoint, envelope, header or
ownership change. The detail for each item is in `UPCOMING-CHANGES.md`; schemas and samples in this folder are
updated.

## Your two licensing points — confirmed

| What you did | What the till does (0.1.15 + this change) | Files |
|---|---|---|
| **409 `migrate.activate_first`** on `cloud/migrate` | **Connect:** saves nothing and tells the owner to activate the licence key first (Settings → Licence → Enter key = `licence/activate`), then press Connect again with the **same** sync key. **Background move** (a key typed with no internet): keeps the key, backs off, tries again later; Sync status says to enter the licence key. The till uses its own en-GB text for this code. Treat the sync key as **not used**. | `licensing/samples/error-codes.json`, `error.migrate-activate-first.409.json`, openapi E409, Postman "409 activate first" |
| **Main-till deactivate, option (b):** `transferCode: null`, same key on the new PC, next step in `messages[]` | A null `transferCode` is a normal success, never an error. Straight after Deactivate the till shows "This till has been deactivated." plus each due message as "title: text". No message → "To use this licence on the new PC, enter the same licence key there (Settings → Licence → Enter key)." A non-null code still works (shown once, Copy). | `licensing/samples/deactivate-reply.main-till.same-key.json`, `deactivate-reply.schema.json`, openapi example `mainTillSameKey`, Postman |

**You must:** for option (b), release the key's binding to the old install when you answer the deactivate with
200, so `licence/activate` from the new PC gets 200 (not 409 `key.already_used`). Put the next step in
`messages[]` (e.g. "Activate this same licence key on the new PC").

## Release 0.1.15 — changes in pushed data

| # | Change | What you see | What you must do |
|---|---|---|---|
| 1 | `ClockEvent.registerId` | New field (string or null): the till the clock was pressed on. Null on older rows. | Add the column. |
| 2 | New accounts `2240` Customer deposits held, `2250` Charity collections held | Two `Account` rows (op `I`, `Liability`) from each shop's till on first start. Ids made on the till: several shops = one pair each, same `code`. | Store by `id`; group by `code` for a company-wide chart. |
| 3 | New payment types "Order deposit", "Loyalty points" | Two `PaymentType` rows (op `I`), positions 6 and 7, not on refund / customer payment. Same per-shop id note. | Store by `id`; group by `name` for company-wide lists. |
| 4 | Loyalty points as a tender | `SalePayment` "Loyalty points", `providerRef` = customer id; `CustomerTransaction` `pointsBurn` (sale) / `pointsAdjust` (refund puts points back); journal Dr/Cr `2230`. No points earned on the part paid with points. | Show "Loyalty points" as a tender. Balances still come from `CustomerTransaction`. |
| 5 | Order deposits | Deposit document (line `ORDER-DEPOSIT`) → Cr `2240`, not sales. Collection pays with the "Order deposit" type (was "Voucher") → Dr `2240`. | Leave deposits out of sales figures; show "Order deposit" as a tender. |
| 6 | New enum values | `CashMovement.type`: `accountPayment` (16) = cash paid off a customer account at the till. `TenderKind` `Deposit` (5) / `Points` (6) is till-internal and never pushed. | Accept `accountPayment`. |
| 7 | Refund postings fixed | From 0.1.15 a refund's journal takes sales, VAT and cash **off** (before, refunds were journalled like sales). Refunds post at once. Entries made before 0.1.15 are **not** re-posted. | Nothing if you show the till's journals. If you build figures from `JournalLine`, be aware older refund entries have the wrong direction. |
| 8 | Refund order and price | Part refund of a split sale: card first, then gift voucher, account, cash last (was pro rata). Exchange pay-back: same order. Multi-buy part refunds keep the deal fair; parts never exceed what the line took. Account refunds go back on the customer (`Sale.customerId` set on refunds). | Nothing — informational. |
| 9 | Exempt / out-of-scope sales | Post to `4030` / `4040` (were all `4020` zero rated). Charity round-up (line `CHARITY-ROUNDUP`) → `2250`, never income. | Nothing if you show the till's journals. |
| 10 | Staff discount source | Staff-purchase discount on each line: `SaleLine.discountSource: "staff"` (was `"manual"`). | Show "staff" apart from "manual" in discount reports. |
| 11 | Cash rounding (5p) | Up to 2p per cash sale/refund posts to `6100` Cash over/short (was `9999` Suspense). | Nothing — informational. |
| 12 | Points expiry now happens | `CustomerTransaction` `pointsExpire`; journal `refType` "LoyaltyExpire" (Dr `2230` / Cr `6900`). | Nothing — informational. |
| 13 | No-shift cash | `CashMovement` with `shiftId: ""`, later `U` with the next shift's id; `AuditLog` `AdoptNoShiftCash`. | Accept an empty `shiftId` that fills in later. |
| 14 | DRS | No deposit charged while the DRS scheme setting is off. | Nothing — informational. |
| 15 | Gift voucher by card | Voucher issued only after the card is approved; `issuedSaleId` = the card payment's cart id. | Nothing — informational. |
| 16 | New audit actions | `AuditLog` `NoSale` ("Drawer") + `ExceptionLog` `NoSale`; `LineVoided` ("Sale", `entityId` = cart id, not a sale id). | Show them in the audit view if you have one. |
| 17 | Setting defaults | `till.ask_reason_void` false, `till.key_click_sound` false, `till.cart_product_count_mode` "Units". No new keys; sync policy and ownership unchanged. | Nothing unless you show default values. |
