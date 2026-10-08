# SSPOS till 0.1.27 – 0.1.51 — what changes for the portal (2026-10-06)

For the web-portal developer. This note covers everything that changed for the portal **since the last pack you
were given, `portal-pack-0.1.26-2026-10-01.zip`** (built 2026-10-01 21:59, till 0.1.26). It comes with
`portal-pack-0.1.51-2026-10-06.zip`, laid out exactly like the last pack.

**How the cut-off was decided.** The 0.1.26 pack's files are byte-for-byte the repository at commit `0ac78252`
(2026-10-01 21:44, "PUR-22 docs"); its START-HERE listed the `UPCOMING-CHANGES.md` entries down to "Fixed asset
postings (2026-10-01)". Everything below is what came after that: the 42 entries that follow "Fixed asset postings"
in `UPCOMING-CHANGES.md` (from "Customer account payments (2026-10-02)" to "Reminder failure text (2026-10-06)"),
plus the schema / sample / spec changes since that commit. The till versions come from the release tags
(`v0.1.27` … `v0.1.51`); 0.1.51 is the live release (2026-10-06).

**The contract is still v1** (`/api/v1`, `X-SSPOS-Contract: 1`). No endpoint, envelope or header changed; no field was
removed or renamed; `openapi.yaml` and the Postman collection are unchanged. One new table, eight new fields, one
ownership change (a table becomes local), new enum values, one new ledger account and several posting changes.

---

## 1. Summary

Action: **must** = the portal will store wrong figures or refuse rows unless it changes; **should** = shows or
reports better if it changes; **nothing** = for information.

| # | What changed | Type | Till | Portal |
|---|---|---|---|---|
| 1 | New table `AccountPayDate` (pay dates and reminder state) | new table | 0.1.51 | **must** accept and store (branch-owned, read-only) |
| 2 | `CustomerTransaction.type` + `advance`, `advanceRefund` | enum | 0.1.51 | **must** accept; include in the balance sum |
| 3 | `CustomerTransaction.tender`, `registerId`, `shiftId` (nullable strings) | new field | 0.1.51 | **must** accept; should store |
| 4 | `CashMovement.type` + `customerAdvance`, `customerAdvanceRefund` | enum | 0.1.51 | **must** accept; count in expected cash |
| 5 | `Customer.owed`, `Customer.creditHeld` (read-only figures) | new field | 0.1.51 | **must** accept; never treat as truth |
| 6 | `Customer.pendingPoints` (integer) | new field | 0.1.28 | **must** accept; till-held; in `derivedColumns` from 0.1.52 (Q1 answered) |
| 7 | `Customer.earnsPoints` (boolean) | new field | 0.1.32 | **must** accept; missing = `true` |
| 8 | `CustomerOrder.customerId` (string or null) | new field | 0.1.28 | **must** accept; should link to the customer |
| 9 | GL account `2260` "Customer account credit" (Liability), seeded on every chart | ledger | 0.1.51 | **must** accept the `Account` row; should report it |
| 10 | Account payments / Account tender / refunds split between `1100` and `2260` | ledger | 0.1.51 | **should** follow in any ledger report |
| 11 | New `JournalEntry.refType`: `CustomerAdvanceRefund`, `CustomerAccountMove`, `CustomerCreditReclass` | ledger | 0.1.51 | **must** accept if you validate refType |
| 12 | One-time reclass journal (Dr 1100 / Cr 2260) per company and shop | ledger | 0.1.51 | **should** expect it once per shop |
| 13 | New `refType` `CustomerOpening` (customer import opening journal) | ledger | 0.1.28 | **must** accept if you validate refType |
| 14 | Card account payments and card advances are inside `ShiftTender.expected` (card) | behaviour | 0.1.51 | **should** match in any Z you rebuild |
| 15 | 11 company settings `customers.advance_*`, `customers.payment_reminders`, `customers.pay_date_ask_at_till`, `customers.reminders_*` | setting | 0.1.51 | **should** show / store as shared settings |
| 16 | AuditLog `AdvanceRefunded`, `PaymentReminderSent`, `PaymentReminderFailed`; `AccountPaymentCollected` gains `Advance`, `Sales` | audit | 0.1.51 | **should** show in an audit view |
| 17 | `AccountPayDate.lastReminderError` / reminder `Error` are multi-line | behaviour | 0.1.51 | nothing (show as text) |
| 18 | Account payments: a `payment` row may carry `saleId`; one row per sale paid | behaviour | 0.1.29 | **should** age against that sale first |
| 19 | Customers on sales: corrections, exchanges, orders, tender corrections now carry `customerId`; new correction ledger rows | behaviour | 0.1.28 | **should** expect more customer ledger rows |
| 20 | Loyalty rules: `pointsEarn` with empty `saleId`; refund `pointsAdjust` is a share (may be 0) | behaviour | 0.1.28 | nothing (sum as before) |
| 21 | `receiptJson` of customer sales and refunds now fills customer fields | behaviour | 0.1.28 | nothing (store as-is) |
| 22 | AuditLog `AccountPaymentCollected`, `CreditLimitChanged`; `Anonymise` holds no personal data | audit | 0.1.28 | **should** show; nothing to migrate |
| 23 | Settings `customers.loyalty_points_on_tobacco`, `…_on_lottery` | setting | 0.1.28 | **should** store as shared settings |
| 24 | Trading dates in UK shop time (Europe/London) | behaviour | 0.1.28 | nothing (unless a shop PC was on another zone) |
| 25 | `ExceptionLog.type` + `LineVoided`, `CartCleared`, `HeldSaleDiscarded`; `AuditLog LineVoided` on every void; `CartCleared` | enum / audit | 0.1.28 | **should** label them; expect more audit rows |
| 26 | Late postings: entry for a closed month is dated the first open month, memo marked | ledger | 0.1.28 | **must** match journals by `refType`+`refId`, not by date |
| 27 | Cash amounts whole pence; expense void reversal on the open shift; Z `totalsJson` unchanged | behaviour | 0.1.28 | nothing |
| 28 | Compliance rows now updated and soft-deleted (`U` / `D`); many new AuditLog actions; recall action names renamed | behaviour / audit | 0.1.28 | **must** apply `U` / `D` on these tables |
| 29 | Permission keys `analytics.view`, `compliance.record` in role permissions | enum | 0.1.29 | **should** accept as role permissions |
| 30 | `StockLayer` with empty `grnLineId` = a date keyed on the shelf; `ProductRecall` reopen | behaviour | 0.1.29 | **should** not require a goods-in line |
| 31 | Portal address built into every till; dealer-key shops report their key once (`licence/redeem`) | behaviour | 0.1.30 | **must** expect calls from shops you have not seen |
| 32 | `Role` push carries `permissions[]` with `role: null` (push now arrives on fresh installs) | sync fix | 0.1.31 | **must** accept |
| 33 | `grid.layout.*`, `help.tour_dismissed.*` never pushed; Setting envelope always the till's company | sync fix | 0.1.32 | **must** keep refusing such rows from older tills |
| 34 | Labels: `labels.custom_width_mm`, `labels.custom_height_mm`, `labels.gap_mm`; `labels.default_layout` + `Custom` | setting / enum | 0.1.32 | **should** store as shared settings |
| 35 | `devices.second_screen_show_weather` (local only) | setting | 0.1.33 | nothing |
| 36 | Purchasing: close part-received order, order lines in units, return undo, invoice edit/delete, new audit actions, `labels.ask_after_goods_in` | behaviour / setting / audit | 0.1.33 | **should** expect `Received` orders short of qty and `U` / `D` on invoices |
| 37 | Push answered 200 with nothing stored is a failed run on the till | sync fix | 0.1.34 | **must** acknowledge correctly (§7) |
| 38 | Licence key mandatory, no built-in trial; `cloud/migrate` `localTrialEndsAt` always null | behaviour | 0.1.35 | **must** send every sign-up a key |
| 39 | Product pictures: `Product.imagePath` set by the till (local path), extra `Product` pushes; `catalogue.auto_product_images` | behaviour / setting | 0.1.35 | **should** not treat `imagePath` as a URL; expect bursts |
| 40 | `EventSubscription` becomes local (never pushed) | ownership | 0.1.38 | **must** stop expecting it; may drop stored rows |
| 41 | Push never sends a blank `companyId`; refused rows re-sent with the same id and version | sync fix | 0.1.42 | **must** accept the re-send |
| 42 | `compliance.shop_checks_ask_at_shift_open` | setting | 0.1.45 | nothing beyond storing it |
| 43 | Training mode no longer pushes practice data | sync fix | 0.1.45 | **should** remove practice rows already received (list from the till) |
| 44 | Purchase order from a file: bulk lines, created products, `PriceHistory.source` "OrderImport", AuditLog `OrderImportProductsCreated` | behaviour / audit | 0.1.46 | **should** accept bursts; nothing else |
| 45 | `PrinterProfile.driverKey` `escpos` on a `Label` profile | enum | 0.1.48 | nothing (accept the value) |
| 46 | Product library settings `library.lookup_enabled`, `library.share_new_products`, `library.url` (shared); `library.api_key` (local) | setting | 0.1.28 | nothing beyond storing them |
| 47 | Error codes (till-side, never sent to the portal) — list in §9 | error code | 0.1.28 – 0.1.51 | nothing |

Counted by main type (47 rows): 1 new table · 5 new-field rows (8 fields: `tender`, `registerId`, `shiftId`,
`owed`, `creditHeld`, `pendingPoints`, `earnsPoints`, `customerId`) · 1 ownership change · 5 enum / value rows ·
6 ledger rows · 6 setting rows (22 new shared keys, 5 new local keys, 2 deny-list prefixes) · 2 audit rows (more
audit actions sit in rows 25, 28, 36, 44) · 5 sync fixes · 15 behaviour rows · 1 error-code row (48 till codes).

---

## 2. Customers and accounts

### 2.1 Customer advance (till 0.1.51)

A customer may now pay in **more than they owe**; the shop holds the rest as credit and takes their next account
sales from it. Off by default (`customers.advance_enabled` = false) — nothing changes in a shop until it is turned on,
**except** the new ledger account `2260` and the one-time reclass journal (2.4), which arrive from every till on its
first start of 0.1.51.

**Balance can now be negative** = credit held. Your balance sum over `CustomerTransaction.amount` (§10.1) already
handles it: an advance is a negative row.

#### `CustomerTransaction` — new type values and three new fields

`samples/enums.json` → `CustomerTransactionType` and `schemas/entities/CustomerTransaction.schema.json`:

| Value (on the wire) | Till's number | `amount` | When |
|---|---|---|---|
| `advance` | 8 | **negative** (credit held grows) | Collect payment with nothing owed, or the part of a payment beyond what was owed |
| `advanceRefund` | 9 | **positive** (credit held shrinks) | Refund advance: credit handed back as cash or card |

Enums travel as camelCase strings, never numbers (§6) — the numbers 8 / 9 in `UPCOMING-CHANGES.md` are the till's
internal values only.

New fields (migration `CustomerTransactionTender`), all `string` or `null`, always present:

| Field | Meaning |
|---|---|
| `tender` | `"Cash"` or `"Card"` (capitalised free text, not a camelCase enum). Set on `payment`, `advance` and `advanceRefund` rows written by Collect payment / Refund advance; `null` on every other and every older row. |
| `registerId` | the till that took the money, same rows; else `null` |
| `shiftId` | the shift open on that till, same rows; else `null` (no shift open → `null`) |

Sample (`samples/entities/CustomerTransaction.json` — an account sale, so the new fields are `null`):

```json
{
  "customerId": "01K5T0Q8C4000000000000K001",
  "type": "charge",
  "amount": 8.40,
  "points": 0,
  "saleId": "01K5VB000000000SR001000484",
  "balanceAfter": 8.40,
  "pointsAfter": 240,
  "userId": "01K5T0Q8C4000000000000A001",
  "note": "Account sale",
  "at": "2026-09-23T09:43:12Z",
  "tender": null,
  "registerId": null,
  "shiftId": null,
  "branchId": "01K5T0Q8C4000000000000B001",
  "id": "01K5VB0000000000000CT00481",
  "companyId": "01K5T0Q8C4000000000000C001"
}
```

The same shape for a card advance of £20.00 at a till (illustrative values; `note` defaults to "Card advance" when
the cashier types no reference):

```json
{ "type": "advance", "amount": -20.00, "points": 0, "saleId": "", "note": "Card advance",
  "tender": "Card", "registerId": "<register id>", "shiftId": "<shift id>" }
```

A payment of £50 against £30 owed writes **one `payment` row per sale paid (−30 in total) and one `advance` row
(−20)**, all with the same `at`, `tender`, `registerId`, `shiftId`.

**Portal:** accept both values; keep summing `amount` for the balance; store the three new fields and use
`tender` / `shiftId` if you rebuild a shift's takings.

#### `CashMovement` — two new type values

`schemas/entities/CashMovement.schema.json` → `type` gains `customerAdvance` (till value 17, `amount` positive, cash
taken in as an advance) and `customerAdvanceRefund` (18, `amount` negative, cash handed back). Both count in the
shift's expected cash, like `accountPayment`. A **cash** payment over what is owed writes two rows: `accountPayment`
for the part that clears the debt and `customerAdvance` for the rest. Card money writes no `CashMovement` (as before).

#### `ShiftTender.expected` for card

Card account payments and card advances in / out are now **inside** the shift's expected card total (a Z's
expected card figure includes them). Before 0.1.51 card account payments were not. No schema change. **Portal:** if
you rebuild expected card from `SalePayment` alone, add the card `CustomerTransaction` rows of that `shiftId`
(`payment` and `advance` add, `advanceRefund` subtracts).

#### `Customer` — `owed` and `creditHeld`

Two read-only figures, worked out from `balance` like `isAnonymised` (`schemas/entities/Customer.schema.json`, required,
`number`):

- `owed` = `balance` when above 0, else 0
- `creditHeld` = −`balance` when below 0, else 0

They are as stale as `balance` (a balance-only change does not push the `Customer` row, §10.1). **Portal:** work both
out from your own ledger sum; never store them as the truth. See question Q2 about sending them down.

### 2.2 Ledger for customer accounts (till 0.1.51)

New account (seeded on every chart; added to existing shops at start-up; pushed as an `Account` row, op `I`):

| Code | Name | Type |
|---|---|---|
| `2260` | Customer account credit | Liability |

As with `2240` / `2250` in 0.1.15, a company with several shops receives one `2260` row per shop (same `code`,
different `id`): store by `id`, group by `code` for a company chart.

Posting rules (accounts: `1100` Trade debtors, `1200` Cash in tills, `1240` Card clearing, `2260` Customer account credit):

| Event | `refType` / `refId` | Lines |
|---|---|---|
| Account payment (cash / card) | `CustomerPayment` / the `payment` row id | Dr 1200 or 1240, Cr **1100** |
| Advance (part beyond what is owed) | `CustomerPayment` / the `advance` row id | Dr 1200 or 1240, Cr **2260** |
| Sale paid with the Account tender | the sale's usual entry | Dr **2260** up to the credit held, Dr **1100** for the rest |
| Refund to the account | the refund's usual entry | mirrors the sale (Cr 2260 for the part that goes back to credit) |
| Advance refunded | **`CustomerAdvanceRefund`** (new) / the `advanceRefund` row id | Dr 2260, Cr 1200 or 1240 |
| Opening credit balance (customer import) | `CustomerOpening` / the `opening` row id | Cr **2260** (was 1100) |
| Fix sale → Wrong customer, when the two customers' owed / credit split differs | **`CustomerAccountMove`** (new) / the new customer's row id | Dr/Cr 1100 ↔ 2260 for the difference |
| One-time upgrade (2.4) | **`CustomerCreditReclass`** (new) / `"<companyId>:<branchId>"` | Dr 1100, Cr 2260 |
| Points earned on a customer correction | `LoyaltyEarn` / `"<saleId>:<customerId>"` | as before |

The sale event inside the till gains `accountCredit` (the part of Account tenders run against credit). Domain events
are local, so **this field never reaches the portal** — the split is visible only in the journal lines.

### 2.3 Pay dates and payment reminders (till 0.1.51)

Off by default (`customers.payment_reminders` = false). When on, the till asks "When will they pay?" on a sale that
leaves money owed, lists due accounts under Collections, and sends reminders by the shop's own WhatsApp or e-mail
(from the till — never through the portal).

#### New table `AccountPayDate` — branch-owned

`schemas/entities/AccountPayDate.schema.json`; `samples/ownership.json` → `"AccountPayDate": "branch"`. Read-only to
the portal; pushed like any other branch row. **There is no sample file for it** — the shape below is from the
schema.

| Field | Type | Meaning |
|---|---|---|
| `customerId` | string | the customer |
| `saleId` | string | the account sale this promise is for; **empty string** = the whole account |
| `dueAt` | date-time (UTC) | when the customer said they will pay |
| `note` | string | free text, up to 500 |
| `userId` | string | who set it |
| `replacedAt` | date-time or null | set when a newer pay date took this one's place; a new pay date never edits an old one, it adds a row and stamps the old one (op `U`) |
| `reminderSentAt` | date-time or null | the last reminder that went out |
| `reminderChannel` | enum `none` / `whatsApp` / `email` | how the last one went |
| `reminderAttempts` | integer | failed tries **since the last send** (0 after a send) |
| `lastReminderAt` | date-time or null | the last try, sent or not |
| `lastReminderError` | string | why the last try failed; empty when it sent. **Multi-line** from 2026-10-06: a short reason on the first line ("WhatsApp not set up", "WhatsApp and Email not set up", "no mobile number", …), then one "Channel: detail." line per channel |
| `isCurrent` | boolean | `replacedAt` is null (worked out, not stored) |
| `lastTryFailed` | boolean | `lastReminderError` is not empty (worked out, not stored) |
| + `branchId`, `id`, `companyId`, `createdAt`, `updatedAt`, `rowVersion`, `deletedAt`, `isDeleted`, `domainEvents` | | as every branch row |

Whether anything is still owed is **not** stored here — work it out from the ledger, as the till does.

**Portal:** store; optionally show a customer's current pay date (`isCurrent`) and reminder state.

#### Reminder audit rows

Every try writes one `AuditLog` row: `action` `PaymentReminderSent` or `PaymentReminderFailed`, `entityName`
`"Customer"`, `entityId` = customer id, `userId` empty for automatic sends, `afterJson`:

```json
{ "PayDateId": "<earliest pay date id>", "PayDateIds": ["<id>", "<id>"], "Channel": "WhatsApp",
  "Amount": 42.50, "Error": "", "ByHand": false }
```

`Channel` here is `"WhatsApp"` / `"Email"` / `"None"` (PascalCase inside the audit JSON, unlike the camelCase
`reminderChannel` field). The WhatsApp overdue reminder text is now the full sentence (before 0.1.51 the bare text
`account_overdue` was sent) — the till sends it, the portal sees only the audit row.

### 2.4 One-time upgrade journal (till 0.1.51)

On its first start of 0.1.51 each till posts **one** journal per company and shop that moves credit balances already
held before this version off Trade debtors: `refType` `CustomerCreditReclass`, `refId` `"<companyId>:<branchId>"`,
Dr 1100 / Cr 2260, memo ending "(one-time upgrade)". The register marker `sync.customer_credit_moved_utc` that
records it is local and never pushed. **Portal:** expect at most one per shop; nothing to do except include it in
ledger reports.

### 2.5 Customer settings (company scope, shared both ways — `samples/settings-local-only.json` `sharedKeys`)

| Key | Type | Default | Till |
|---|---|---|---|
| `customers.advance_enabled` | bool | false | 0.1.51 |
| `customers.advance_refund_needs_pin` | bool | true | 0.1.51 |
| `customers.advance_print_slip` | bool | true | 0.1.51 |
| `customers.payment_reminders` | bool | false | 0.1.51 |
| `customers.pay_date_ask_at_till` | bool | true | 0.1.51 |
| `customers.reminders_auto_send` | bool | true | 0.1.51 |
| `customers.reminders_send_by` | choice: `WhatsApp, then email` / `WhatsApp only` / `Email only` | `WhatsApp, then email` | 0.1.51 |
| `customers.reminders_quiet_from` | time `HH:mm` (shop time) | `21:00` | 0.1.51 |
| `customers.reminders_quiet_until` | time `HH:mm` (shop time) | `08:00` | 0.1.51 |
| `customers.reminders_repeat` | choice: `Never` / `1 day` / `3 days` / `7 days` | `Never` | 0.1.51 |
| `customers.reminders_message` | text ≤ 500, placeholders `{name}` `{amount}` `{when}` `{shop}` | "Hi {name}, a reminder that {amount} on your account is due {when}. Thank you — {shop}" | 0.1.51 |
| `customers.loyalty_points_on_tobacco` | bool | false | 0.1.28 |
| `customers.loyalty_points_on_lottery` | bool | false | 0.1.28 |

### 2.6 AuditLog actions for customers

| Action | Entity / id | JSON | Till |
|---|---|---|---|
| `AccountPaymentCollected` | `Customer` / customer id | before `{Balance}`; after `{Balance, Amount, Tender, ApprovedBy}` — from 0.1.51 also `Advance` (the part held as credit) and `Sales` (how many sales it paid) | 0.1.28 (+0.1.51) |
| `CreditLimitChanged` | `Customer` / customer id | before `{CreditLimit}`; after `{CreditLimit, ApprovedBy}` | 0.1.28 |
| `Anonymise` | `Customer` / customer id | `beforeJson` no longer carries the erased name / phone / e-mail — a fixed description instead | 0.1.28 |
| `AdvanceRefunded` | `Customer` / customer id | balance before / after, tender, who approved | 0.1.51 |
| `PaymentReminderSent` / `PaymentReminderFailed` | `Customer` / customer id | see 2.3 | 0.1.51 |

### 2.7 Customer rows and links that arrive differently (till 0.1.28 – 0.1.32)

- **`Customer.pendingPoints`** (integer, required, 0 on old rows; 0.1.28, migration
  `CustomerPendingPointsAndOrderCustomer`): points a Not Paid (account) sale held back until the account is paid —
  never spendable, not in `points`. A payment releases its share into `points` (a `pointsEarn` row with an empty
  `saleId`, note "Points earned on account payment").
  From till 0.1.52 it is in `derivedColumns` (`samples/ownership.json`): never written on the till from a pull,
  never the truth in a push.
- **`Customer.earnsPoints`** (boolean, required; 0.1.32): `false` = "Collects points" not ticked — their sales write
  no `pointsEarn` row and no loyalty posting; points already held can still be spent. Existing customers `true`; a
  customer added on the form `false` until ticked; an import `true`. **A row that arrives without the field is read as
  `true`** — when you send a `Customer` down, include it; never default it to `false`.
- **`CustomerOrder.customerId`** (string or null; 0.1.28): the customer record an order was taken for; null for a
  walk-in order. The order's sales (`Sale.type` `deposit`, the collection `sale`, a cancel `refund`) carry the same
  `customerId`, so their `CustomerTransaction` rows follow.
- **Sales that now carry the customer** (they had `customerId` null): the re-sale of a "Tender corrected" pair; an
  exchange (`Sale.type` `exchange`, its Account / Loyalty points pay-back writes `refund` / `pointsAdjust` rows for
  that customer); a refund after a wrong-customer correction names the customer the newest correction names.
- **Wrong-customer corrections**: a `CustomerCorrection` row now comes with `CustomerTransaction` rows on both
  customers, all with the sale's `saleId` — `refund` (negative) on the previous customer and `charge` on the new one
  for what the sale put on account; `pointsEarn` negative / positive for the points it earned.
- **Account payments against sales** (0.1.29): a `payment` row may carry `saleId` = the account sale the cashier
  picked; empty still means "clears the oldest charge first". A payment taken against several sales arrives as one
  `payment` row per sale, all with the same `at` and `userId`; still one `CashMovement` `accountPayment` for the cash
  total and one `CustomerPayment` journal per `payment` row. **Portal:** when ageing a balance, put a payment with a
  `saleId` against that sale's charge first, then the oldest.
- **Refunds and points**: a refund's reversing `pointsAdjust` is now a share of what the original sale earned (it was
  a formula on the refund total), so it can be 0.
- **Amounts** on account payments are always rounded to the penny.
- **Customer import** (0.1.28): a new customer's `opening` row now has a journal entry — `refType` `CustomerOpening`,
  `refId` = that row's id: Dr 1100, Cr 2230 (opening points at redemption value), balance to 3900; reversed signs for
  a customer in credit (from 0.1.51 the credit side goes to 2260, see 2.2). Customers imported earlier have none.
- **`Sale.receiptJson`** of a sale or refund with a customer now fills `customerName`, `pointsEarned`, `pointsBalance`
  and (when part went on / back to account) `accountBalance` — fields that existed and were always empty. A points
  tender's `pointsBalance` is now the balance after the whole sale / refund. Store `receiptJson` as-is.

---

## 3. Cash, shift, ledger and reports (till 0.1.28)

The detail is in **`PORTAL-CHANGES-2026-10-02-cash-reports.md`** (written 2026-10-02, after the last pack, so it is
included in this pack). In short:

- **Late postings — must:** a journal entry for a document dated in a closed or locked month is posted into the first
  open month (`date` = its first day, `periodId` = that month) and its `memo` ends " — late posting, dated dd/MM/yyyy".
  Match a `JournalEntry` to its source by `refType` + `refId`, never by date. The ledger poster no longer returns
  `finance.period_locked`.
- **Till voids:** `ExceptionLog.type` gains `LineVoided`, `CartCleared`, `HeldSaleDiscarded` (free string, low
  severity); `AuditLog` `LineVoided` is now written for every line void (`reason` "Not asked" when the shop does not
  ask; `afterJson` gains `ReasonId`, `ApprovedBy`); new `AuditLog` `CartCleared` (`Sale` / cart id); `Discard`
  (`HeldOrder`) `beforeJson` gains `HeldBy`, `LineCount`, `Value`. Expect many more audit rows.
- **Cash:** amounts are whole pence from this release; a drawer expense voided after its shift's Z reverses on the
  open shift; balance-check `ExceptionLog` rows are written once per account and day (`refId` `<account>@yyyyMMdd`).
  `ZReport.totalsJson` is unchanged (only the printed Z changed). Report "Datewise" is shown as "Takings summary"
  (key `cash.datewise` unchanged).
- **Shop time:** every trading date (`SalesDaily.date`, `TenderDaily.date`, hourly buckets, `JournalEntry` dates,
  Z / report windows) is taken from UK time (Europe/London), not the PC's zone. A PC already on UK time sends exactly
  what it sent before.

---

## 4. Compliance, permissions and stock dates

- **Compliance rows are now updated and soft-deleted** (0.1.28): the change log pushes `U` and `D` (`deletedAt`) for
  `ComplianceLicence`, `TrainingRecord`, `IncidentReport`, `DiaryCheckDefinition`, `DiaryCheckRecord` (void),
  `TemperatureUnit`. **Portal must** apply updates and deletes on these tables (they were insert-only).
  `AgeRefusal` rows can be added from the back office with no sale or product id.
- **New AuditLog actions** (0.1.28): `LicenceUpdated` / `LicenceRenewed` / `LicenceRemoved`; `TrainingUpdated` /
  `TrainingRemoved`; `IncidentUpdated` / `IncidentRemoved`; `DiaryCheckAdded` / `DiaryCheckUpdated` /
  `DiaryCheckRemoved` / `DiaryCheckSwitchedOn` / `DiaryCheckSwitchedOff` / `DiaryRecordVoided`; `TemperatureUnitAdded`
  / `TemperatureUnitUpdated` / `TemperatureUnitRemoved` / `TemperatureUnitSwitchedOn` / `TemperatureUnitSwitchedOff`;
  `AllergensChanged` (`ProductAllergenMatrix`); `RefusalAdded`. **Renamed** for `ProductRecall`: `Raise` →
  `RecallRaised`, `ReturnToSupplier` → `RecallStockReturned`, `Close` → `RecallClosed` (afterJson names the product
  and the recall reference). 0.1.29: `RecallReopened`; `StockLayer` `ShelfDateAdded` / `ShelfDateChanged` /
  `ShelfDateRemoved` (JSON Product, Date dd/MM/yyyy, Quantity).
- **`ProductRecall`** (0.1.28 / 0.1.29): `reference` keeps REC-yyyyMMdd-HHmm but in UK shop time and may end "-2",
  "-3"; `note` = what was done when closed; a closed recall can go back to `Open` with `closedAt`, `closedByUserId`,
  `note` cleared. See question Q3.
- **`StockLayer`** (0.1.29): a row with an empty `grnLineId` is a date keyed on the shelf — no goods-in line,
  `unitCost` = the product's cost then (0 when the shop keeps no costs); can be soft-deleted. No stock movement, no
  journal.
- **Alert wording** changed for ComplianceLicenceExpiry, TrainingExpiry, ComplianceCheckMissed,
  FoodSafetyTemperatureOutOfRange, LicenceExpiry ("SSPOS subscription expires in N days").
- **Permission keys** in `RolePermission` / `Role.permissions[]` (0.1.29): `analytics.view` (Owner/Admin, Manager;
  granted once to Manager-level roles) and `compliance.record` (Cashier, Supervisor, Manager, Owner and any role with
  `settings.view`; back-filled once).
- **Setting** `compliance.shop_checks_ask_at_shift_open` (branch, bool, default false; 0.1.45): the Shop checks card
  opens by itself at shift open. Same `DiaryCheckRecord` rows as before.

---

## 5. Sync and licensing

| Change | Till | What the portal sees / must do |
|---|---|---|
| **Portal address built in** (`https://retail-v2-portal.sspos.co.uk`) | 0.1.30 | A fresh install redeems an e-mailed `SSP-…` key with nothing typed (`licence/activate`); portal-licensed tills check in daily (`licence/validate`); **every shop on a dealer key (`SSPOS1.…`) reports it once** (`licence/redeem`, §17.16) — expect calls from shops you have never seen. Only 409 `key.used_on_another_install` locks a till. Sync starts only with a sync key or an `apiKey` in your reply. |
| **`Role` rows in a push** | 0.1.31 | `permissions[]` items carry `roleId`, `permissionKey`, `role` — `role` is **always `null`**. Up to 0.1.30 a till with a Role waiting could not build a push at all (no `sync/push` after `sync/hello`). The same permissions also go as `RolePermission` keyed rows (§10.3). |
| **Per-user settings not pushed** | 0.1.32 | `grid.layout.*` and `help.tour_dismissed.*` join the §10.3 deny-list (`samples/settings-local-only.json` `localOnlyPrefixes`); the envelope `companyId` of every `Setting` row is the till's own company. Older tills sent them as `company` settings with a **user** id as company — keep refusing those; do not link that id to the company. |
| **Push answered 200 with nothing stored** | 0.1.34 | A reply whose `acknowledgedSeq` is below the batch's first `seq` is a failed run on the till (its own code `sync.push_not_acknowledged`, never sent): back-off 1 → 2 → 5 → 15 min. The row is not skipped — it holds the shop's queue until fixed. |
| **Licence key mandatory** | 0.1.35 | No built-in trial; a till with no genuine key is locked. Every sign-up must be sent a key (a trial key for a trial). `cloud/migrate` `localTrialEndsAt` is always `null` (field kept). A key whose start date has not come reports `lock.reason` `other`. A main till with no key also refuses to take extra tills (till-side only). `specs/licensing.md` §9 is new. |
| **`EventSubscription` is local** | 0.1.38 | `samples/ownership.json` now `"local"`; never pushed, not in the `cloud/migrate` history, refused in a pull. It went up with a blank `companyId` and was refused 422 `row.invalid`, holding the queue. Stop expecting it; stored rows can be dropped. The schema file stays. |
| **`companyId` never blank** | 0.1.42 | A row saved with no company (seen: `TopSellerTile`; possible: `FifoStockLayer`, `Category`) goes up with the till's company in the envelope and payload; rows refused 422 "companyId must be a ULID" are **re-sent under the same `entityId` and version** — accept them even if you remember the refusal. |
| **Training mode no longer pushes practice data** | 0.1.45 | Up to 0.1.44 practice sales, payments, shifts, stock and ledger rows went up as real rows (`Sale.type` "Sale", nothing marks them). From 0.1.45 sync reads only the shop's real database. Rows already received stay until removed: ask for the list per shop (the till has a tool that lists them). A change pulled during a training session was applied to the training file only — re-send from the till's last live acknowledgement if your hub does not re-send acknowledged changes. |

---

## 6. Labels, printing and the second screen

- **Own label size** (0.1.32): shared branch settings `labels.custom_width_mm` (number 20–110, default 50),
  `labels.custom_height_mm` (15–150, default 30), `labels.gap_mm` (0–10, default 2; 0 = continuous roll).
  `labels.default_layout` may now hold `Custom` (beside `Small`, `Medium`, `Large`, `ShelfEdge`, `A4Sheet`).
- **Labels on a receipt printer** (0.1.48): `PrinterProfile.driverKey` `escpos` can now be on a profile whose `role` is
  `Label` (until now `zpl`, `epl`, `tspl` or `gdi`). Its `paperWidthMm` is the roll (58 or 80); `hasCutter` says
  whether each label is cut. No new field.
- **`labels.ask_after_goods_in`** (branch, bool, default false; 0.1.33): offer labels after a delivery.
- **Second-screen weather** (0.1.33): `devices.second_screen_show_weather` (bool, default true) is local only, never
  pushed. The till calls api.postcodes.io and api.met.no itself, not the portal.

---

## 7. Catalogue and purchasing

- **Purchasing** (0.1.33; no schema change):
  - `PurchaseOrder.status` can go `partReceived` → `received` by Close order — a `received` order may have lines with
    `receivedQty` below `orderedUnits`. A part-received order can no longer be cancelled.
  - `PurchaseOrderLine`: till orders save `orderedCases` 0 and the whole quantity in `orderedUnits`; `unitCostSnapshot`
    may be typed; `position` is max + 1.
  - `GoodsReceiptLine.expectedQty` is 0 on a line received without an order.
  - `PurchaseReturn.purchaseOrderId` is also set on a return started from an order by hand; a return can go `sent` →
    `cancelled` (Undo send: positive `SupplierReturn` stock movements, a `Reversal` journal, AuditLog
    `UndoSendPurchaseReturn`).
  - `ProductSupplier` rows are created when an unlinked product is ordered (`caseQty` 1, `caseCost` = unit cost);
    AuditLog `Unlink` on `ProductSupplier`.
  - `Supplier.orderMethod` is `phone` for a supplier created without an e-mail; blank optional text arrives `null` on
    update.
  - `SupplierInvoice` can be updated and soft-deleted while not approved (nothing is posted before approval);
    `number` may be "(no number) dd/MM"; a disputed invoice can go back to its earlier status;
    `SupplierInvoiceLine.vatRateId` is filled on typed lines; a typed line may be negative.
  - AuditLog `ClosePurchaseOrder` (same shape as Send / Cancel). Text changes in `StockMovement.note`,
    `SupplierCreditNoteLine.description` and the return's journal memo.
- **Product pictures** (0.1.35): the till fills `Product.imagePath` a few seconds after a product is created — a
  **local file path** on the main till, not a URL; a second `Product` push follows most creates. "Find pictures" on
  the Products list fills it for every product without one — about one `Product` push a second while it runs
  (thousands on a big catalogue). New company setting `catalogue.auto_product_images` (bool, default true).
- **Purchase order filled from a file** (0.1.46): hundreds of `PurchaseOrderLine` rows in one save; a product the
  sheet names that the shop did not have is created (ordinary `Product` + `ProductBarcode` push, opening `PriceHistory`
  with `source` `"OrderImport"` — a new value of that free-text field beside "Create" and "QuickAdd"; sell price 0.00
  when the sheet gave none). One AuditLog row per import: `action` `OrderImportProductsCreated`, `entityName`
  `PurchaseOrder`.
- **Product library** (0.1.28): new settings `library.lookup_enabled` (branch, bool, true), `library.share_new_products`
  (branch, bool, true), `library.url` (company, text, blank) — shared; `library.api_key` (secret, never pushed). The
  till talks to the shared barcode library directly, not to the portal.

---

## 8. Local-only settings added (never pushed, never applied from a pull)

`devices.second_screen_show_weather`, `library.api_key`, `server.joined_branch_id`, `server.joined_shop_name`,
`server.main_till_address` (till-to-till link, 0.1.35), prefixes `grid.layout.` and `help.tour_dismissed.`; register
marker `sync.customer_credit_moved_utc`. All listed in `samples/settings-local-only.json`.

---

## 9. Error codes (till-side validation — never sent to the portal; listed because codes are part of the contract)

| Till | Codes |
|---|---|
| 0.1.28 | `customers.payment_tender`, `customers.anonymised`, `customers.credit_limit`, `loyalty.default_customer`, `corrections.tender_needs_customer`, `corrections.already_corrected`, `corrections.customer_not_found`, `corrections.same_customer`, `corrections.account_needs_customer`, `exchange.pay_back`, `sale.clear_lines`, `sale.idempotency_key`, `cash.whole_pence`, `cash.count_mismatch`, `cash.declared_negative`, `cashoffice.over_safe`, `finance.expense_void_needs_open_shift`, `cash.print_report_key`, `reports.unknown_key`, `finance.period_not_ended`, `finance.period_open_shift`, `finance.period_is_locked`, `finance.year_not_ended` |
| 0.1.33 | `purchasing.po_part_received`, `purchasing.po_cannot_close`, `purchasing.po_line_whole`, `purchasing.po_product_archived`, `purchasing.po_product_banned`, `purchasing.po_line_unit_cost`, `goods_in.whole_units`, `goods_in.date_future`, `purchase_return.order_not_found`, `purchase_return.order_supplier`, `purchase_return.order_nothing_received`, `purchase_return.over_order`, `purchase_return.whole_units`, `purchase_return.date_future`, `purchase_return.not_in_stock`, `purchase_return.credit_number_used`, `purchase_return.undo_not_sent` |
| 0.1.34 | `sync.push_not_acknowledged` (the till's own status, never on the wire) |
| 0.1.51 | `customers.payment_over_balance`, `customers.advance_off`, `customers.advance_refund_over_credit`, `customers.advance_refund_pin`, `customers.advance_refund_amount`, `customers.advance_refund_tender`, `sale.account_over_limit` |

---

## 10. Schema and sample files changed since the last pack

| File | Change |
|---|---|
| `schemas/entities/AccountPayDate.schema.json` | **new** |
| `schemas/entities/CashMovement.schema.json` | `type` + `customerAdvance`, `customerAdvanceRefund` |
| `schemas/entities/Customer.schema.json` | + `pendingPoints`, `earnsPoints`, `owed`, `creditHeld` (all required) |
| `schemas/entities/CustomerOrder.schema.json` | + `customerId` (string or null, required) |
| `schemas/entities/CustomerTransaction.schema.json` | `type` + `advance`, `advanceRefund`; + `tender`, `registerId`, `shiftId` (string or null, required) |
| `samples/enums.json` | `CustomerTransactionType` + `advance`, `advanceRefund` |
| `samples/ownership.json` | + `"AccountPayDate": "branch"`; `EventSubscription` `"branch"` → `"local"` |
| `samples/settings-local-only.json` | deny-list prefixes `grid.layout.`, `help.tour_dismissed.`; local keys and shared keys listed in §2.5, §4, §6, §7, §8 |
| `samples/entities/Customer.json`, `CustomerOrder.json`, `CustomerTransaction.json`, `push-request.json`, `pull-reply.json`, `pull-reply.relay.json` | new fields added |
| `docs/web-portal-api.md` | §10.3 deny-list (per-user prefixes), §13 `EventSubscription` local |
| `SIMPLE-SETUP.md` | the built-in portal address |
| `specs/licensing.md` | §9 licence key mandatory, no built-in trial; main till with no key takes no tills |
| `PORTAL-CHANGES-2026-10-02-cash-reports.md` | new (not in the last pack) |
| `UPCOMING-CHANGES.md` | 42 new entries (all summarised above) |

Unchanged: `openapi.yaml`, `SSPOS.postman_collection.json`, `SSPOS.postman_environment.json`, everything under
`licensing/`, the other `schemas/*.schema.json`.

---

## 11. Ask the till team

**Q1–Q10 answered (till team, 2026-10-06): see `ANSWERS-2026-10-06.md`.** Q3 (recall) and Q7 (credit move) also led to till changes in 0.1.52 — UPCOMING-CHANGES.

| # | Question |
|---|---|
| Q1 | ~~`Customer.pendingPoints` not in `derivedColumns`~~ **Answered (till team, 2026-10-06):** from till 0.1.52 `pendingPoints` is in `derivedColumns` like `balance` / `points` — the till never writes it from a pull and a change to it alone does not push the row. Store what a push says if you like, but never as the truth; sending it down is ignored. |
| Q2 | `Customer.owed` / `creditHeld` are worked out from `balance` on the till. Confirm the till ignores them in a pull (so the portal may send 0), and whether they should be listed under `derivedColumns`. |
| Q3 | `ProductRecall` is hub-owned, but close / reopen / note edits are made at the till (open since 2026-10-02 in `UPCOMING-CHANGES.md`). Which side wins when the portal also edits a recall? |
| Q4 | `SupplierInvoice` update / soft-delete before approval "writes AuditLog rows" — which `action` names? |
| Q5 | `AccountPayDate.saleId` is an empty string (not null) for "whole account" — confirm it stays that way. |
| Q6 | `CustomerTransaction.tender` is `"Cash"` / `"Card"` (capitalised free text, not a camelCase enum). Will other values appear (e.g. a voucher), and will it stay free text? |
| Q7 | The one-time `CustomerCreditReclass` journal — what `date` is it posted under (the start-up day?), and is it skipped when a shop has no customer in credit? |
| Q8 | `docs/web-portal-api.md` §13 says "everything else, 111 tables" for branch-owned; `samples/ownership.json` lists 114 `"branch"` entries. The file is the truth (§10) — please correct the text. |
| Q9 | No `samples/entities/AccountPayDate.json` sample yet — can one be generated with the other samples? |
| Q10 | Product library settings (`library.*`) are shared both ways: is the portal expected to manage `library.url` per company, or just store it? |
