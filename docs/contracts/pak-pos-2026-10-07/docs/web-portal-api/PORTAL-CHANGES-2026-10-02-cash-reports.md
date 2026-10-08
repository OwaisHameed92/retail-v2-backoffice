# SSPOS till — what changes for the portal: Cash & Shift, Reports and till voids (2026-10-02)

For the web-portal developer. These changes are built on the till and ship in the **next release after 0.1.27**;
they are not in a release yet. The contract is still v1 (`X-SSPOS-Contract: 1`): **no** endpoint, envelope, header,
ownership or schema change, no new table or column, no migration, no new tender / payment type, no new ledger
account. What changes is the values some existing rows carry.

## 1. What you must do

| # | Change | What you see | What you must do |
|---|---|---|---|
| 1 | Three new `ExceptionLog.type` values | `LineVoided`, `CartCleared`, `HeldSaleDiscarded` (detail in §2). `type` is a free string in the schema — nothing to migrate. Severity of all three is low. | Accept the values; give them a label if you show exceptions by type ("Line voided", "Sale cleared before payment", "Held sale thrown away"). |
| 2 | `AuditLog` `LineVoided` on every void | Before, the row was only written when the shop had "Ask reason for void" switched on. Now **every** line void writes it; `reason` is `"Not asked"` when the shop does not ask. Expect many more of these rows per shop per day. | Nothing if you already show `LineVoided` (announced with 0.1.15). Check any volume limit or alert you built on audit rows. |
| 3 | New `AuditLog` action `CartCleared` | Entity `Sale`, `entityId` = the open sale's cart id (not a sale id — the sale was never completed). | Show it in the audit view if you have one. |
| 4 | `AuditLog` `Discard` (entity `HeldOrder`) carries more | `beforeJson` gains `HeldBy`, `LineCount`, `Value` beside `Name`, `CustomerId`, `HeldAt`. | Nothing unless you parse `beforeJson` strictly. |
| 5 | Late ledger postings | A `JournalEntry` for a document dated in a **closed or locked** month is now dated the **first day of the first open month**, and its memo ends ` — late posting, dated dd/MM/yyyy` (the document's own date). Before, such a posting was refused and never arrived. | If you match a `JournalEntry` to its source row (sale, expense…) **by date**, match by `refType` + `refId` instead. If you build month figures from `JournalLine`, a late entry counts in the month it was posted into. |

## 2. Field detail

### `ExceptionLog` — the three new types

| Field | `LineVoided` | `CartCleared` | `HeldSaleDiscarded` |
|---|---|---|---|
| When | a line is taken off an open sale before payment (✕, Delete, Undo) | the till's Clear cart throws the whole open sale away (also the clear a clock-out does) | a held sale is thrown away instead of recalled |
| `amount` | the line's value | what the sale was worth | the total the Recall list showed |
| `beforeValue` | — | `"3 lines"` / `"1 line"` | `"3 lines"` / `"1 line"` |
| `afterValue` | `"2 × Beans — <reason>"` (max 200 chars) | — | the name the sale was held under |
| `reasonId` | the picked Void reason, empty when the shop does not ask | — | — |
| `refType` / `refId` | `Sale` / cart id | `Sale` / cart id | `HeldOrder` / held order id |
| `registerId`, `userId`, `at` (UTC) | the till, the cashier, when | same | same |

A cart id is the id of the open basket; it becomes the sale's id only if the sale is completed, so for these rows
there is usually **no** `Sale` row with that id.

### `AuditLog`

| Action | Entity / `entityId` | JSON |
|---|---|---|
| `LineVoided` | `Sale` / cart id | `afterJson`: `ProductId`, `ProductName`, `Quantity`, `Value`, `UserName`, `Reason`, `ReasonId`, `ApprovedBy`. `reason` column = the reason text, `"Not asked"` when the shop does not ask. |
| `CartCleared` (new) | `Sale` / cart id | `beforeJson`: `LineCount`, `Value`, `UserName`, `ApprovedBy` (the manager who gave a PIN over the threshold, else empty). `afterJson` null. |
| `Discard` | `HeldOrder` / held order id | `beforeJson`: `Name`, `CustomerId`, `HeldAt`, **`HeldBy`**, **`LineCount`**, **`Value`**. |

### `JournalEntry` — late posting

- `date` = first day of the first open month (not the document's date); `periodId` = that month.
- `memo` = the usual memo + ` — late posting, dated 30/09/2026`. Example: `Sale BR01-T01-000035 — late posting, dated 30/09/2026`. The memo is cut to keep the mark inside 200 characters.
- `refType` / `refId`, accounts, amounts and VAT rate ids are exactly what they would have been on time.
- Nothing is re-dated after the fact: entries already posted keep their date. (`refType`, `refId`) stays unique.
- The till's ledger poster no longer returns `finance.period_locked` (depreciation, asset disposal, supplier-payment edit and reversal still do when the user picks a date in a closed month).

## 3. Informational — nothing to do

| # | Change | Note |
|---|---|---|
| 6 | Money is whole pence | Open float, paid in / out, safe drop, counterfeit, drawer-swap float, float to a till, petty cash, banking and the declared tenders at close are refused with a third decimal. `CashMovement.amount` and `ShiftTender` figures from this release never carry one; older rows may (e.g. `12.345`). |
| 7 | Voiding a drawer expense after its shift's Z | The reversing `CashMovement` now carries the **open** shift's id on that till (the closed shift and its Z no longer change). With no shift open the void is refused. |
| 8 | Close shift | When notes are counted, the declared cash must equal the count or the close is refused — so `ShiftTender.declared` for cash always equals the count that was typed. |
| 9 | Safe | Petty cash paid out, a float sent to a till and a bank bag bigger than the safe holds are refused unless confirmed — the safe balance no longer goes negative by accident. |
| 10 | Financial periods | A month cannot be closed or locked until its last shop day has passed and no shift from it is open; a locked month cannot be "closed" (which used to take the lock off); the year cannot be closed before it ends. |
| 11 | Z report | `ZReport.totalsJson` is unchanged (same `ZReportDto`, same keys, same figures). Only the **printed** Z changed: it now also prints sales, refunds, discounts, VAT by rate, the cash drawer make-up and the receipt run, counts "sales" as goods only (bottle deposits, charity round-ups and order deposits on their own lines), leaves off tenders nobody used, and a reprint is headed `COPY — reprinted <date time> by <name>`. If you draw your own Z from `totalsJson`, it will not show those extra lines. |
| 12 | Report name | The till shows "Datewise report" as **"Takings summary"**. The key is still `cash.datewise`. |
| 13 | Balance-check exceptions | "Run balance check" now writes each finding to `ExceptionLog` **once per account and day** (`refId` `<account>@yyyyMMdd`) with a worded detail ("Cash in tills (1000): the books say …"); before, every press wrote the same rows again. No new `type`. |
| 14 | Report areas | The till groups the books under one "Finance" area; report keys are unchanged. "Audit trail", and the hub copies of Trial balance and VAT return, are no longer offered in the till's Reports hub (they stay in the catalogue). |
| 15 | Setting text | `till.ask_reason_void`: description reworded; default (false), key, sync policy and ownership unchanged. |

## 4. New error codes (till-side validation; listed because codes are part of the contract)

| Code | Meaning |
|---|---|
| `cash.whole_pence` | "Enter pounds and pence only, e.g. 12.35." |
| `cash.count_mismatch` | the cash declared does not match the note count |
| `cash.declared_negative` | "A counted amount cannot be negative." |
| `cashoffice.over_safe` | more than the safe holds |
| `finance.expense_void_needs_open_shift` | a drawer expense of a closed shift can only be voided with a shift open |
| `finance.period_not_ended` | the month has not ended yet |
| `finance.period_open_shift` | a shift from that month is still open |
| `finance.period_is_locked` | a locked month cannot be closed |
| `finance.year_not_ended` | the financial year has not ended yet |
| `reports.unknown_key` | not a known report |
| `cash.print_report_key` | only the X report and the end of day report print from that key |
| `sale.clear_lines`, `sale.idempotency_key` | bad request to the clear-cart record |

## 5. Status

Merged to the till's main branch on 2026-10-02; the dated lines are in `UPCOMING-CHANGES.md` ("Till voids", "Ledger",
"Cash & Shift", all 2026-10-02). Not in a release yet.

## 6. Coming later (not built — for your planning)

"One home for cash spent" (till task P9-09): the Cash office "Petty cash – Paid out" goes; an expense gains a
"paid from" of till drawer / **safe** / card / bank / owner; a shift's Paid out uses the expense category list and
posts to that category's account instead of suspense. That will change `Reason` types in use, the accounts on
paid-out journals and add a value to the expense payment type. It will get its own note before it ships.
