# This shop or every shop — what changed for the portal (2026-09-29, revision 1.5)

**Decisions (SSPOS owner, 2026-09-29).** A price can now be changed **at the shop**, and it must reach the portal
("ma yahan change karunga, sync on hoga to wahan change hojayega"). In a business with more than one shop, a price,
an offer or a standing discount made at a till applies **only to that shop by default** ("by default ussi ki branch
ho"). Applying it to **every shop** needs a manager — the operator's own permission, or a manager's PIN — and is
audited with who allowed it. A business with one shop works as before and never sees the choice.

**"More than one shop"** = the till's licence has feature `multi_branch` **and** `limits.branches` above 1 (a shop's
till only holds its own `Branch` row, so it cannot count the others). One shop with several tills is one shop.

This note lists **only what the sync API sees differently**. No endpoint, envelope, field or status is added or
removed; `X-SSPOS-Contract` stays `1`; the JSON schemas and samples are unchanged. What changes is **who writes some
rows and what they mean** — the portal must accept rows it used to be the only writer of.

## What changed

### 1. `BranchPrice` is now written by the till too (§10.5)

Until today `BranchPrice` was portal-only ("the till never edits or pushes it"). Now:

- **A shop's own price made at the till is a `BranchPrice` row in the push**: `op: "I"`, a new ULID `id` made by
  the till, `branchId` = the shop, `productId`, `productUnitId` (`null` for the base unit, else the unit's row id),
  `price`, `validFromUtc` = the moment it was changed, `validToUtc: null`. Same fields as the rows you send.
- **The price the shop had until then ends at that moment**: every row of that shop, for that product (or unit),
  that was live then gets `validToUtc` = that moment and is pushed as `op: "U"` — **including rows you made**. Rows
  that start later (a price you scheduled for next month) are left alone and still take over when they start.
- **"Every shop" at the till moves the business price instead**: the `Product` row (`sellPrice`) is pushed as
  today, plus the ending (`op: "U"`, `validToUtc` set) of **that shop's** own live `BranchPrice`, so the shop sells
  at the new business price too. The till cannot see other shops' `BranchPrice` rows, so **other shops that have
  their own shop price keep it** — a shop price always beats the business price. If "every shop" should also clear
  other shops' own prices, that is a portal decision (end them on the portal and send the rows down).
- **One-shop business**: a price change is always the business price (`Product.sellPrice`), and a live
  `BranchPrice` of that shop ends as above.
- A multi-UOM unit's price set at the till is the unit's own price (`ProductUnit.sellPriceIncVat`) — the till does
  not write unit-level `BranchPrice` rows from the product form yet; it does for a variant's single unit when a
  variant's price changes at one shop.

**What the portal must do**

- **Accept pushed `BranchPrice` rows** (`I` and `U`) from the till of **that** shop: store them as that shop's
  prices and show them on the portal's "Shop prices" page (a column "Set at: shop / portal" helps). Refuse a row
  whose `branchId` is not the pushing branch's.
- **Ownership is unchanged** (`BranchPrice` stays `"hub"` in `schemas/ownership.json`): if you change a row the
  shop has just changed (e.g. the row the shop just ended) before the shop's change reached you, the till holds your
  row as a `SyncConflict` on its Sync status screen ("by default head office wins") until someone there confirms —
  as for every other portal-owned table. Meanwhile the shop's own new price keeps charging. To avoid the clash,
  change a price by **adding a new row** (a later `validFromUtc`) rather than editing an existing one.
- **Send your later price as a new row** — never rewrite the till's row; the latest `validFromUtc` wins on the till.
- The price-change batch is still branch-owned and read-only to you: its `PriceChangeLine.branchId` is now
  **filled** in a multi-shop business (the shop's id = this shop's price; `null` = the business price at every shop).

### 2. Offers and standing discounts carry their shop (`PromotionRule.branchId`)

`PromotionRule.branchId` has always been in the schema (`null` = every shop) and the till has always honoured it,
but the till never set it. Now:

- An offer or a standing discount (a %/£-off on a department, category, product or customer group) **made at a till
  of a multi-shop business** is pushed with `branchId` = that shop, unless the operator picked "every shop" (then
  `null`, allowed by a manager). A one-shop business still pushes `null`.
- **Standing discounts: one per target per shop.** A shop may have its own discount on "Bakery" beside the
  every-shop one; on the till **the shop's own discount wins** for that target. The portal must **not** merge or
  replace a shop's rule with the every-shop rule for the same target — they are two rows.
- A till lists only its own shop's rules and the every-shop rules; it will not edit another shop's rule, and it
  changes (or starts / stops) an every-shop rule only with a manager's approval — the rule stays every-shop; the
  till never narrows your every-shop rule to one shop.
- Offers imported from a workbook at a till are that shop's (`branchId` = the shop).

**What the portal must do**

- Keep `branchId` exactly as pushed; show it ("All shops" / the shop's name) on the offers list.
- When the portal makes a shop-only offer, send `branchId` = that shop (already supported).

### 3. A new permission key

`business.apply_all_shops` — "apply a price, offer or standing discount at every shop". Seeded to Manager and Owner.
It travels in `RolePermission` like every other key (§10.3); add it to the portal's role editor.

### 4. What the audit log shows (`AuditLog`, branch-owned, read-only to you)

| `entityName` | `action` | Meaning |
|---|---|---|
| `BranchPrice` | `PriceThisShop` | A shop price set at the till. `beforeJson` / `afterJson`: `price`, `productUnitId`; `afterJson` also `everyShop: false`, `source` ("Till", "Edit", "Bulk", "PriceChangeBatch", "Goods-in", "Style price", "Variant"). `entityId` = the product. |
| `BranchPrice` | `PriceEveryShop` | "Every shop" at the till: the business price moved. `afterJson.approvedBy` / `approvedByName` = who allowed it. |
| `PromotionRule` | `Created` / `Updated` / `Activated` / `Deactivated` | As before; `reason` now ends with "— This shop only" or "— Every shop — approved by *name*". |
| (bulk) | `PriceChangeBatch.Approve`, `BulkChangePrice` | `afterJson.parameters.Where` says which, and who approved every shop. |

### 5. `ProductUnit` rows keep their id

Saving a product's units at the till used to delete every `ProductUnit` row (`op: "D"`) and insert new ones with
new ids (`op: "I"`) on each save. Now a unit that is still there is **updated in place** (`op: "U"`, same `id`); only
a removed unit is deleted and only a new unit is inserted. A `BranchPrice` with `productUnitId` (yours or the
shop's) therefore stays linked after the shop saves the product. Nothing to change on the portal, except expecting
far fewer unit deletes and inserts.

## Unchanged

Endpoints, envelopes, `X-SSPOS-Contract: 1`, every JSON schema and sample, `BranchPrice` precedence on the till
(scale label → price-marked pack → live `BranchPrice` → own price), the LAN copy to secondary tills (a price made at
any till of the shop reaches the others within about 20 seconds), and everything in a one-shop business.
