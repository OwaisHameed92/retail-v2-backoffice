# Contract proposal: stock documents drafted on the portal (`pda-stock-drafts`)

**For:** the SSPOS EPOS till team. **From:** the Switch & Save portal team. **Date:** 2026-10-22.
**Builds on:** contract v1.4.1 (`docs/contracts/portal-api-v1.4.1`), §10.6 head-office purchase orders, §19.
**Status:** PROPOSAL. Additive only: `X-SSPOS-Contract` stays `1`; nothing in v1.4.1 is removed or renamed.
**Portal side:** built and switched off (`PDA_STOCK_DRAFTS=false`). It is switched on the day a till release
supports this proposal; until then no till sees any of it.

## 1. Why

The Android handheld (PDA) app lets a shop worker scan a product and fix its stock on the spot: adjust it (+/−,
damaged, opening stock), count a shelf, book a delivery in against a purchase order, and return goods to the
supplier. In v1.4.1 the rows these make (`StockMovement`, `StockTake`, `GoodsReceipt`, `PurchaseReturn`) are
**branch-owned**: only the till writes them. The portal must never write stock itself: two writers of stock would
double-book it, and only the till knows its stock at the moment a change lands.

So the handheld cannot work today (the portal answers `501`). This proposal lets the portal **draft** these
documents for one shop, exactly as it already drafts a head-office purchase order (§10.6). The till pulls the
draft, applies it as its own work against its own stock, and pushes the outcome. The till stays the only writer
of stock.

## 2. The idea in six lines

1. The portal drafts a document for **one shop** (row id = a ULID the portal makes, `rowVersion` 0).
2. It goes down **only in that shop's pull**, like a head-office order (`hubDrafted`, envelope `branchId` = the shop).
3. The till **applies it when it pulls it**, in one local transaction, against its stock at that moment.
4. From the moment the portal has sent it, the document is **the shop's**: the portal never sends it again changed.
5. The till pushes **the same row** with the outcome: confirmed (with what it booked) or rejected (with a reason),
   plus its usual rows (`StockMovement`, the order's `receivedQty`…). That push is the shop's own change.
6. The portal reads the outcome from that push and shows it on the handheld. Nothing is echoed back (§19.2).

This is the §10.6 mechanism the till already has (`hubDrafted`, apply a pulled branch-table row for this shop
only, refuse changes once the shop owns it, `SyncConflict`), applied to four documents.

## 3. The four documents

| Handheld screen | Contract entity | New or reused | Outcome pushed as |
|---|---|---|---|
| Adjust stock (+/−, opening stock, "scan and correct the count") | **`StockAdjustmentDraft`** | **new** table, branch-owned, `hubDrafted` | the same row: `status` `confirmed` / `rejected` |
| Stock take (shelf count, many products) | `StockTake` + `StockTakeLine` | reused, now `hubDrafted` (`origin: headOffice`) | the same rows: `approved` / `cancelled` |
| Goods in against a purchase order (partial deliveries) | `GoodsReceipt` + `GoodsReceiptLine` | reused, now `hubDrafted` (`origin: headOffice`) | the same rows: `posted` / `cancelled` |
| Return to supplier (optionally against an order line) | `PurchaseReturn` + `PurchaseReturnLine` | reused, now `hubDrafted` (`origin: headOffice`) | the same rows: `sent` / `cancelled` |

### Why a new entity for adjustments, and reuse for the others

- **`StockAdjustmentDraft` is new** because nothing in v1.4.1 fits. `StockMovement` is the till's append-only stock
  ledger: it needs `qtyBefore` / `qtyAfter`, known only when the till applies it; it has no "refused" state; and a
  pulled `StockMovement` would make the portal a second writer of the ledger. A `stockCount` ("there are 34 on the
  shelf") cannot be a movement at all until the till works the difference out. A small request row the till turns
  into its own `StockMovement` keeps the ledger single-writer. Reusing `StockTake` with one line for an adjustment
  was considered and dropped: +/− and opening stock (with a cost) are not counts.
- **`StockTake`, `GoodsReceipt` and `PurchaseReturn` are reused**, because they already carry exactly what the
  handheld captures (counted quantities; delivery note and quantities per order line; returned quantities, costs,
  reasons), the till already has their screens and posting logic, and their own status is the outcome
  (`approved` / `posted` / `sent`, or `cancelled`). The only additions are `origin` (as `PurchaseOrder.origin`) and,
  where missing, `cancelReason` (as `PurchaseOrder.cancelReason` and `GoodsReceipt.cancelReason`). A separate
  `GoodsReceiptDraft` or `StockTakeDraft` would duplicate these tables and the till's posting code.

## 4. Ownership (`ownership.additions.json`)

Additive to `samples/ownership.json`:

- `entities`: `"StockAdjustmentDraft": "branch"` (new table; the other tables are already `"branch"`).
- `hubDrafted` (rule text in the file): `StockAdjustmentDraft`, `StockTake`, `StockTakeLine`, `GoodsReceipt`,
  `GoodsReceiptLine`, `PurchaseReturn`, `PurchaseReturnLine`, next to `PurchaseOrder` / `PurchaseOrderLine`.
- `relayed`, `derivedColumns`: unchanged. The till's outcome members (§6) are "never read from a pull", the same
  wording as `PurchaseOrderLine.receivedQty` in §10.6.

**Which shop pulls it:** the shop in the row's `branchId` (envelope `branchId` = that shop), and only that shop.
Another shop never receives it; the shop that applied it never gets it back.

**Enums** (`enums.additions.json`): `StockAdjustmentDraftType` (`stockIncrease`, `stockDecrease`, `openingStock`,
`stockCount`), `StockAdjustmentDraftStatus` (`pending`, `confirmed`, `rejected`), `StockAdjustmentReason` (`damaged`,
`expired`, `theft`, `countCorrection`, `other`), `StockTakeOrigin`, `GoodsReceiptOrigin`, `PurchaseReturnOrigin`
(`branch`, `headOffice`; the till's own rows are `branch`, the default when the member is missing).

## 5. What the portal sends (pull)

Schemas: `schemas/*.schema.json` (2020-12; the reused entities `allOf` the v1.4.1 schema plus the new members and
the values a draft must have). Sample: `samples/pull-reply.stock-drafts.json` (one pull of shop B001 with one of
each). Every member is written, nulls too (§6). Parents first: header, then its lines straight after it (§8).

### 5.1 `StockAdjustmentDraft` (one product, one shop)

| Member | Portal sends |
|---|---|
| `productId` | the product (hub-owned, already on the till) |
| `type` | `stockIncrease` (+), `stockDecrease` (−), `openingStock` (+, first stock with a cost), `stockCount` (counted) |
| `quantity` | base units, up to 4 dp. Increase / decrease / opening: the change, > 0. **`stockCount`: what is on the shelf (≥ 0), not a change** — the till books `quantity − its stock at apply` |
| `unitCost` | per base unit, ex VAT, 4 dp: for `stockIncrease` / `openingStock` (the product's cost when the operator gave none); `null` otherwise |
| `reason` | `damaged` / `expired` / `theft` / `countCorrection` / `other`; always set for `stockDecrease`, else may be `null` |
| `note` | free text from the operator, `""` when none (≤ 255) |
| `productUnitId`, `enteredQuantity`, `conversionToBase` | display only: what was typed ("2 × Case of 12" → `quantity` 24, unit, 2, 12); `null` for the base unit. The stock maths uses `quantity` only |
| `enteredAt`, `enteredBy` | when and by whom it was entered (`"Stock room 1 (handheld)"`): our users are not till users |
| `status` | `pending` |
| `confirmedAt`, `rejectedAt`, `rejectReason`, `qtyBefore`, `qtyDelta`, `qtyAfter`, `stockMovementId` | `null`. **Never read from a pull**: the till's outcome (§6) |
| `branchId`, `id`, `companyId`, `createdAt`, `updatedAt`, `rowVersion` (0), `deletedAt`, `isDeleted`, `domainEvents` | as every row |

### 5.2 `StockTake` + `StockTakeLine` (`origin: headOffice`)

Header: `origin` `headOffice`, `status` **`review`** (counting is done; the counts come with it), `reference` = our
per-shop number `HS-{branchCode}-{000001}` (unique per shop among head-office stock takes), `name`, `note`, `scope`
`productList`, `scopeId` `""`, `isBlind` false, `countUncountedAsZero` false (only the listed products change),
`startedAt` = when it was counted, `startedByUserId` / `approvedByUserId` `""`, `approvedAt` / `cancelledAt` /
`cancelReason` `null`, thresholds 0, `isHighValueCount` / `isClosed` false.

Lines: `stockTakeId`, `productId`, `productName`, `countedQty` (base units, ≥ 0), `isCounted` true, `countCount` 1,
`countedAt`, `countedByUserId` `""`, `sectionId` `""`, `note` `""`, `recountRequired` false. **`snapshotQty`,
`varianceQty`, `varianceCost`, `unitCost` are sent as 0 and never read from a pull**: the till fills them when it
applies the count (§6). One line per product; up to 500 lines.

### 5.3 `GoodsReceipt` + `GoodsReceiptLine` (`origin: headOffice`), one delivery against an order

Header: `origin` `headOffice`, `status` **`draft`**, `purchaseOrderId` (the shop's order: a head-office order or
the shop's own), `supplierId` / `supplierName` (the order's), `deliveryNoteNumber` (the supplier's note, `""` when
none), `receivedDate` (date the goods arrived), `receivedByUserId` `""`, `note`, amounts worked out from the lines
(net, VAT, gross; 2 dp), `postedAt` / `cancelledAt` / `cancelReason` `null`.

Lines: `goodsReceiptId`, `purchaseOrderLineId` (always set), `productId` = `expectedProductId` (the order line's),
**`receivedQty` = what arrived in this delivery** (base units, a delta: partial deliveries are several receipts),
`expectedQty` = what the portal saw outstanding on the line (display only; the till rechecks), `damagedQty` 0,
`caseQty` / `unitCost` / `vatRateId` / `vatPercentage` from the order line, `isShortOrOver` false, `note` `""`,
`expiryDate` / `batchNo` `null`. Only lines with a quantity are sent.

### 5.4 `PurchaseReturn` + `PurchaseReturnLine` (`origin: headOffice`)

Header: `origin` `headOffice`, `status` **`draft`**, `number` / `reference` = our per-shop number
`HR-{branchCode}-{000001}` (unique per shop among head-office returns), `supplierId` / `supplierName`,
`purchaseOrderId` (or `null` for a plain supplier return), `goodsReceiptId` `null`, `returnDate`, `note` = the
reason given, amounts from the lines, `createdByUserId` `""`, `creditNoteNumber` `""`, every sent / credited /
cancelled member `null`, `cancelReason` `null`.

Lines: `purchaseReturnId`, `productId`, `productName`, `qty` (base units, > 0), `unitCost` (ex VAT, 4 dp),
`vatRateId`, `vatPercentage`, `reason` (`damaged` / `wrong` / `expired` / `recalled` / `other`), `note` (free text),
`fromStock` true, `goodsReceiptLineId` `null`, **`purchaseOrderLineId`** (new, nullable: the order line returned
against), `lineNet` / `lineVat`.

## 6. What the till does with a draft (apply, outcome, push)

On pull, for a row with `origin: headOffice` (or a `StockAdjustmentDraft`) addressed to this shop:

1. **New id:** validate (§8), then apply it in one local transaction and record the outcome on the same row.
   Apply it **when pulled**, without a person (open question Q1).
2. **Id already held, same content** (a repeated page, or the same version again): nothing (§19.1).
3. **Id already held, different content** (should never happen: the portal sends a draft once): refuse it, keep
   the till's row and record a `SyncConflict` naming the document, once per version — as a changed head-office
   order after the shop sent it (§10.6).

| Document | Confirmed (applied) | Rejected |
|---|---|---|
| `StockAdjustmentDraft` | one `StockMovement` (§7), then the row: `status` `confirmed`, `confirmedAt`, `qtyBefore` / `qtyDelta` / `qtyAfter` (base units), `stockMovementId` | `status` `rejected`, `rejectedAt`, `rejectReason` (text shown to the operator); no movement |
| `StockTake` | `snapshotQty` = the till's stock **at apply**, `varianceQty` = counted − snapshot, `varianceCost`, `unitCost` per line; one `StockMovement` per line with a variance (`type` `stockTake`, `refType` `StockTake`, `refId`, `refLineId` = the line); header `status` `approved`, `approvedAt` | `status` `cancelled`, `cancelledAt`, **`cancelReason`** (new member) |
| `GoodsReceipt` | posted as the till's normal goods-in: `status` `posted`, `postedAt`; its `received` movements; the order line's `receivedQty` and the order's `status` (`partReceived` / `received`) as today | `status` `cancelled`, `cancelledAt`, `cancelReason`; nothing booked |
| `PurchaseReturn` | booked as the till's normal return: `status` `sent`, `sentAt`, `supplierReturn` movements (`fromStock`) | `status` `cancelled`, `cancelledAt`, **`cancelReason`** (new member) |

Then the till **pushes** these rows as the shop's own changes (ChangeLog entries, `rowVersion` > 0), exactly as when
the shop sends or receives a head-office order. Sample: `samples/push-request.stock-drafts-outcome.json`.

- The pulled row itself is written **without** a ChangeLog entry (§19.2); only the outcome is. So an applied draft
  comes up once, with its outcome; it is never echoed unchanged.
- After the outcome the row is the shop's like any other: later changes at the till (a credited return, a
  manager re-opening a count) push as usual.
- The portal never sends the row again; it stops sending a draft once the till pushed it.

## 7. Stock movement of an adjustment

| `type` / `reason` | `StockMovement.type` | `qtyDelta` |
|---|---|---|
| `stockIncrease` | `adjustment` | `+quantity` |
| `stockDecrease` + `damaged` / `expired` / `theft` | `damaged` / `expiry` / `theft` | `−quantity` |
| `stockDecrease` + `countCorrection` / `other` | `adjustment` | `−quantity` |
| `openingStock` | `openingStock` | `+quantity` (with `unitCost`) |
| `stockCount` | `stockTake` | `quantity − qtyBefore` (no movement when 0: confirmed, `qtyDelta` 0, `stockMovementId` null) |

`refType` `StockAdjustmentDraft`, `refId` = the draft id, `refLineId` `""`, `unitCost` = the draft's (else the
product's cost), `note` = the draft's note, `at` = when the till applied it.

## 8. What the till validates (reject with a reason; never a 4xx on the pull)

| Check | All four | Adjustment | Stock take | Goods receipt | Return |
|---|---|---|---|---|---|
| Addressed to this shop (`branchId`, envelope `branchId`) | ✓ | | | | |
| Product exists, not deleted, not archived (`isActive`, `archivedAt`) | ✓ (every line) | | | | |
| Product tracks stock (`trackStock`) | ✓ | | | | |
| Quantities ≥ 0 with ≤ 4 dp; > 0 where §5 says so | ✓ | | | | |
| Stock may go below zero | | follow `negativeStockMode` (Q2) | | | Q2 |
| Order exists, is this shop's, is `sent` / `partReceived`, line belongs to it | | | | ✓ | if given |
| `receivedQty` ≤ ordered − received on the line **at apply** | | | | ✓ (Q6) | |
| Returned ≤ received − already returned on the order line | | | | | ✓ if given |
| Supplier exists | | | | ✓ | ✓ |

A rejected draft is still acknowledged in the pull (the cursor moves); the outcome push is what tells the portal.

## 9. Conflict and sync rules (§19)

- **Never twice:** a draft is keyed by its `id` on both sides. The portal also keeps the handheld's
  `client_reference` (a UUID, unique per shop) so a repeated handheld request never makes a second draft; the till
  never sees it. The same row pulled twice is one document and is applied once.
- **Never echoed:** the pulled row is written without a ChangeLog entry; only the outcome (a real change by the
  shop) is pushed. The portal never sends a draft back to the shop after its push.
- **Never backwards:** the portal changes a draft only before it first sent it (it then sends the newest version
  once, with a higher pull `version`); after that it never sends it again. The till keeps the highest `rowVersion`
  of its outcome as for any branch row.
- **Clash:** a changed draft for an id the till already applied is refused and flagged (§6 step 3), never applied.
- **Order lines:** while a handheld delivery is waiting for the till, the portal refuses changes to that
  head-office order (it would change lines under the delivery). After the till posts it, the order is the shop's
  (§10.6 as today).

## 10. What the till team builds (checklist)

1. Table `StockAdjustmentDraft` (§5.1), branch-owned, in `SyncOwnershipMap` + `hubDrafted`; generated schema and
   sample.
2. New members: `StockTake.origin`, `StockTake.cancelReason`, `GoodsReceipt.origin`, `PurchaseReturn.origin`,
   `PurchaseReturn.cancelReason`, `PurchaseReturnLine.purchaseOrderLineId`; enums of §4.
3. `hubDrafted` apply for the seven tables, reusing the head-office order path: accept only rows for this shop with
   `origin: headOffice` (any `StockAdjustmentDraft`), lines after their header (park otherwise, §8), refuse a
   change to an id already applied (`SyncConflict`).
4. Apply at pull (§6, §7): adjustment → `StockMovement`; stock take → approve with variance at apply; goods receipt
   → post; return → book as `sent`. One transaction per document; validation of §8 → rejected with a reason.
5. Push the outcome rows (§6) as the shop's own changes; the pulled rows never.
6. Screens: show "From head office / handheld" on these documents (as head-office orders); keys that would change
   a drafted document's lines are greyed, as for head-office orders.
7. Tell us the first till version that has it (we send drafts only to tills at or above it: Q12).

## 11. Open questions for the till team

- **Q1 — apply without a person?** We propose: applied at pull, no manager step. For stock takes the till has
  approval thresholds (`approvalOverValue`, recount thresholds): should a drafted count above them stay in `review`
  for a manager at the till (the portal then shows "waiting at the till")? Same question for returns: `draft` →
  `sent` at once, or a person sends it?
- **Q2 — negative stock:** a decrease (or return) that takes stock below zero: follow the product's
  `negativeStockMode` (`block` → reject), or always apply (the handheld saw the goods)?
- **Q3 — `stockCount` with no difference:** confirm with `qtyDelta` 0 and no movement (our proposal), or write a
  zero movement for the audit trail?
- **Q4 — user ids:** `startedByUserId`, `approvedByUserId`, `receivedByUserId`, `createdByUserId`,
  `StockMovement.userId` are not-null strings. The portal sends `""` (as `sentByUserId` on head-office orders) and
  `enteredBy` text on adjustments. Is `""` fine, or do you want a fixed "head office" system user id?
- **Q5 — numbers:** `HS-` (stock takes) and `HR-` (returns) are our per-shop numbers, like `HO-`. Do your unique
  indexes on `StockTake.reference` / `PurchaseReturn.number` allow a separate head-office number space, or do you
  prefer to number them yourselves on apply (we would then show your number after the push)?
- **Q6 — over-receipt at apply:** reject (our proposal; the portal already refuses with `OVER_RECEIPT` when it can
  see it) or book it as an over-delivery (`isShortOrOver`)?
- **Q7 — reject reasons:** free text is enough for the handheld. Do you also want an enum code (e.g.
  `productArchived`, `notStockTracked`, `overReceipt`) as a separate member?
- **Q8 — adjustment reasons:** we propose the fixed `StockAdjustmentReason` list. Would you rather we send a
  `reasonId` from the business's `Reason` rows (hub-owned) instead of, or as well as, the code?
- **Q9 — stock take variance basis:** the handheld wants the variance against the till's stock **when it applies
  the count** (sales between the count and the sync are not lost). Can the till set `snapshotQty` at apply for a
  drafted stock take, instead of at the start of counting?
- **Q10 — return against an order line:** we add `PurchaseReturnLine.purchaseOrderLineId`. Would you rather have
  `goodsReceiptLineId` (we would have to pick a receipt line)?
- **Q11 — locked days:** should a draft dated in a locked day (`DayLock`) or closed financial period be rejected,
  or booked today?
- **Q12 — capability:** which till version first supports this, and should the till say so in `hello` (e.g. a
  capability list) rather than us comparing `X-SSPOS-App-Version`? The portal can already hold drafts back from a
  till below a minimum version.
