# SSPOS portal pack - 2026-10-06 (till 0.1.51)

What is new since the 0.1.26 pack (2026-10-01 21:59): read docs/web-portal-api/PORTAL-CHANGES-2026-10-06.md first.
It covers tills 0.1.27 to 0.1.51 in one place: a summary table (what changed, type, must / should / nothing),
then one section per area, the schema files changed, and questions for the till team.
The same changes in contract detail are the 42 entries at the end of docs/web-portal-api/UPCOMING-CHANGES.md,
from "Customer account payments (2026-10-02)" down to "Reminder failure text (2026-10-06)".
X-SSPOS-Contract is still 1. No endpoint, envelope or header changed; no field removed or renamed.
openapi.yaml and the Postman collection are unchanged.

Please act on these first:
1. New branch-owned table AccountPayDate (schemas/entities/AccountPayDate.schema.json).
2. CustomerTransaction: type values advance / advanceRefund; new nullable fields tender, registerId, shiftId.
   A customer's balance can now be negative (= credit held). Customer gains pendingPoints, earnsPoints, owed,
   creditHeld; CustomerOrder gains customerId.
3. CashMovement.type values customerAdvance / customerAdvanceRefund. Card account money is now inside the shift's
   expected card total.
4. Ledger: new account 2260 Customer account credit; account payments, Account tender and refunds split between
   1100 and 2260; new refTypes CustomerAdvanceRefund, CustomerAccountMove, CustomerCreditReclass, CustomerOpening;
   one one-time reclass journal per shop on first start of 0.1.51. Late postings are dated in the first open month:
   match journals by refType + refId, never by date.
5. EventSubscription is now local (never pushed). Compliance tables now send U and D. A push never sends a blank
   companyId (rows refused for it are re-sent with the same id and version). Training mode no longer pushes
   practice data. Licence key mandatory (no built-in trial); the portal address is built into every till.

Then, as needed:
- docs/web-portal-api/PORTAL-CHANGES-2026-10-02-cash-reports.md - Cash & Shift, Reports and till voids in detail
  (written after the last pack).
- docs/web-portal-api/UPCOMING-CHANGES.md - every change in contract detail (not yet folded into the spec).
- docs/web-portal-api.md - the full contract (section 17 licensing, sections 5-10 sync).
- docs/web-portal-api/licensing/, schemas/, samples/, openapi.yaml, SSPOS.postman_collection.json.
- specs/licensing.md - the till's licensing rules (new section 9: licence key mandatory).
