# SSPOS portal pack - 2026-10-06 (till 0.1.52)

What is new since the 0.1.51 pack (same day): our answers to your questions Q1-Q10 are in
docs/web-portal-api/ANSWERS-2026-10-06.md (Roman Urdu, with file names). The contract changes are the last 7
entries of docs/web-portal-api/UPCOMING-CHANGES.md, from "`Customer.pendingPoints` joins `derivedColumns`" down to
"Customer credit move, multi-shop". X-SSPOS-Contract is still 1. No endpoint, envelope or header changed; no field
removed or renamed. openapi.yaml and the Postman collection are unchanged.

Please act on these:
1. New branch-owned table ProductRecallBranchState (schemas/entities/ProductRecallBranchState.schema.json): each
   shop's own close / reopen of a company-wide recall. ProductRecall now has derivedColumns returnedQty, status,
   closedAt, closedByUserId, note - the portal creates recalls and edits only reason / source / batchCode /
   expiryFrom / expiryTo (your Q3).
2. AccountPayDate is relayed to every other branch (samples/ownership.json "relayed", samples/pull-reply.relay.json,
   new sample samples/entities/AccountPayDate.json - your Q9) and gains reminderSetupKey (opaque hash: store it).
3. Customer.pendingPoints is in derivedColumns (your Q1). owed / creditHeld / isAnonymised are worked out on push
   and ignored in a pull (your Q2, web-portal-api.md section 10.1).
4. Settings: customers.reminders_due_on_till (shared); customers.reminders_from_utc (local-only, never pushed).
5. Error codes: new customers.anonymise_credit_held, order.refund_account_no_customer, order.refund_account_over;
   customers.advance_off is gone; sale.account_over_limit also comes from Fix sale corrections.
6. Ledger: in a multi-shop company only the shop with the lowest Branch.id posts the one-time CustomerCreditReclass;
   a shop that posted one in 0.1.51 posts a CustomerCreditReclassReversal once (your Q7). Match by refType + refId.

Also new in docs/web-portal-api.md: section 8 states that a pulled field you leave out keeps the till's value
(null on a required non-text column turns the row down); the table counts are corrected (149 names = 30 hub,
115 branch, 4 local; 147 entity schemas; 21 samples - your Q8).

Then, as needed:
- docs/web-portal-api/PORTAL-CHANGES-2026-10-06.md - everything from till 0.1.27 to 0.1.51 in one place.
- docs/web-portal-api/UPCOMING-CHANGES.md - every change in contract detail.
- docs/web-portal-api.md - the full contract (section 17 licensing, sections 5-10 sync).
- docs/web-portal-api/licensing/, schemas/, samples/, openapi.yaml, SSPOS.postman_collection.json.
- specs/licensing.md - the till's licensing rules.
