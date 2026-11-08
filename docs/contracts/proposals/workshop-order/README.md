# Contract proposal: the `WorkshopOrder` pull row (`workshop-order`)

**For:** the SSPOS EPOS till team. **From:** the Switch & Save portal team. **Date:** 2026-11-08.
**Builds on:** contract 0.1.85 (`docs/contracts/portal-api-0.1.85`), `WORKSHOP-BIKEBOOK.md` §5 and §6.
**Status:** PROPOSAL. Additive: `X-SSPOS-Contract` stays `1`; the 0.1.85 `WorkshopOrder` entity schema is used as it is.
**Portal side:** built and switched off (`WORKSHOP_CONNECTOR=false`). It is switched on once BikeBook registers SSPOS
and a till release applies this row; until then no till sees any of it (`samples/ownership.json` still says `local`).

## 1. What it is

When a mechanic presses **Send to POS** in BikeBook, BikeBook calls the portal's connector (`POST /api/SalesOrder`).
The portal keeps the order and sends it to the connected shop's till as a `WorkshopOrder` row in that shop's normal
`pull`. The till turns it into its own `CustomerOrder` (same id), holds the parts and shows it under Workshop orders.
Everything after that comes back up as the usual `CustomerOrder` / `CustomerOrderPayment` / `Sale` rows; the portal
then tells BikeBook "paid" (`order.statuschanged`). Direct mode (the till reading BikeBook with its own key) is
untouched.

## 2. The row

- Envelope: `entity` `WorkshopOrder`, `entityId` = the order id (the portal's ULID; also BikeBook's SalesOrder id and
  the id of the till's CustomerOrder), `branchId` = the connected shop (the till's own branch id), `op` `I` for the
  first version and `U` after, `version` = the pull version, `key` = `WorkshopOrder:{id}:{rowVersion}`.
- Payload: **every member of your 0.1.85 `schemas/entities/WorkshopOrder.schema.json`**, so the row is your table's
  row. `schemas/WorkshopOrder.connector.schema.json` (this folder) adds the values the portal fills:

| Member | Portal value |
|---|---|
| `provider` / `source` | `bikebook` / `connector` |
| `status` | `open` or `cancelled` (BikeBook cancelled the job). The portal never sends `collected` |
| `reference` | `WS-{BikeBook job number}`, unique in the shop (a `-2` suffix when needed) |
| `externalOrderNumber`, `externalJobNumber`, `externalJobId`, `externalInvoiceId` | as BikeBook sent them (`externalJobNumber` = the digits of the order number when BikeBook sends no job number) |
| `customerId` | our `Customer` id when BikeBook named one of ours (customers are pulled as usual), else null with the name / phone / e-mail BikeBook sent and `externalCustomerId` |
| `assetName` / `assetSerial` / `externalAssetId` | the bike, when BikeBook sends it (else empty; you can read it from the Public API, Q7) |
| `linesJson` | the lines, `schemas/WorkshopOrderLines.schema.json`: `lineId` (ours, stable across versions), `externalLineId`, `productId` (parts only), `name`, `qty`, `unitPrice` (inc VAT), `vatRateId`, `vatPercentage`, `lineType` (`part` / `labour` / `custom` / `fee`), `lineTotal`, `vatAmount` |
| `discountType` / `discountValue` | `none` / 0 unless BikeBook sends a discount |
| `goodsTotal` | the lines less the discount, inc VAT |
| `paidElsewhere` | 0, or the whole total when BikeBook said the job was paid in BikeBook (`PUT …/status/Complete`, §5.4) |
| `billingType`, `isWarranty`, `isLeasing`, `isMotability` | from BikeBook when sent, else `customer` / false |
| `dueAt`, `notes` | when sent |
| `rowVersion` = `externalVersion` | the portal's version of the order: 1, then +1 on every change |
| `receivedAt` / `createdAt` | when the portal first got the order |
| `cancelledAt` | when BikeBook cancelled it |
| `customerOrderId`, `lockedByRegisterId`, `lockedAt`, `saleId`, `collectedAt` | always null; `isStarted` false, `priceDifference` 0, `jobStatus` / `mechanicName` empty, `isReady` false: **yours** — keep your own values when you apply a newer version |

Sample: `samples/pull-reply.workshop.json` (a new order and a cancelled one).

## 3. Rules for the till

1. Apply a version once: the same `rowVersion` again is a no-op.
2. Until you take money on it or open it on a sale, apply a newer version (lines replaced, totals replaced). After
   that, refuse it and record a `SyncConflict` (entity `WorkshopOrder`, detail the reference), once per version. The
   portal already answers BikeBook's change with 409 `order_locked` as soon as your push shows a payment, a collection,
   a cancellation or a sale on the order.
3. `status: cancelled` before money: drop the order and release the held parts. After money: refuse (as rule 2).
4. Never push `WorkshopOrder`. Push the `CustomerOrder` you make from it with the same `id`, `source` `workshop`,
   `externalProvider` `bikebook`, `externalOrderId` = the WorkshopOrder id, `externalReference` = the job number.
5. One reporter per order: for these orders the **portal** tells BikeBook paid / cancelled / refunded; the till must
   not also record the payment in BikeBook through the Public API. Orders you find yourself in Direct mode stay yours.
6. `paidElsewhere` > 0: show "Already paid in BikeBook" and complete the collection with the "Paid in BikeBook"
   tender (D6). The portal sends no `order.statuschanged` for such an order.

## 4. Setting and licence

- Branch setting `workshop.connector_status` (`pending` / `connected` / `failed` / `disconnected`) is written only by
  the portal and pulled to that shop only, while the connector is switched on. `failed` = still connected but a
  webhook to BikeBook was given up. The portal never writes `workshop.connection_status`.
- Licence feature `workshop_integration`: appended to the token's `features` for a shop with the paid add-on
  (`common.schema.json` `featureName`, open list). Not appended to a trial token whose list is empty (that list means
  "every feature on").

## 5. Files

| File | What |
|---|---|
| `ownership.additions.json` | `WorkshopOrder` local → hub (shop-addressed) |
| `enums.additions.json` | `WorkshopLineType`; the values the portal sends for status and source |
| `schemas/WorkshopOrder.connector.schema.json` | the payload: your entity schema + the portal's values |
| `schemas/WorkshopOrderLines.schema.json` | `linesJson` decoded |
| `samples/pull-reply.workshop.json` | a pull page with a new and a cancelled order |
| `samples/webhook.order-statuschanged.json` | what BikeBook gets when you collect a connector order |

## 6. Questions for the till team

1. Is the `linesJson` line shape above right for your WorkshopOrder table (camelCase, `lineId`)? We copied §5.1.
2. Do you want `rowVersion` = `externalVersion`, or should `externalVersion` stay BikeBook's own version (we have none)?
3. Should a cancelled order come as `op: U` with `status: cancelled` (as now) or `op: D`?
4. A minimum till version: the portal can send the row only to tills at least `WORKSHOP_CONNECTOR_MIN_TILL`. Which
   release will apply it?
