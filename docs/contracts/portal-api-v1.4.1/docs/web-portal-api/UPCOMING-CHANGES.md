Revision 1.5 (2026-09-29): prices, offers and standing discounts made at a shop — this shop or every shop.
Only what the sync API sees differently: `SHOP-OR-EVERY-SHOP.md` (in this folder). Not yet folded into
`docs/web-portal-api.md`.

Purchase returns (2026-09-29): two new branch-owned tables, `PurchaseReturn` and `PurchaseReturnLine` (schemas in
`schemas/entities/`), pushed like `GoodsReceipt`/`SupplierCreditNote` — read-only to the portal. A goods-in with
damaged units opens one (`GoodsReceiptId` set); `Status` Draft → Sent → Credited (or Cancelled); `Reference` =
"PR-{branch code}-00012". Recording the supplier's credit also pushes the usual `SupplierCreditNote` row
(`Reason` "Purchase return PR-…").
