# Billing flow (owner rules, 2026-10-05)

How customers pay, and what happens when they do not. Code: `app/Domain/Billing`. Decisions: `docs/DECISIONS.md`
→ "Billing flow rules". GoCardless set-up: `docs/specs/gocardless-billing.md`.

## The two kinds of money

| Money | How it is paid | Who records it |
|---|---|---|
| **Setup fee (upfront)** | Cash, card (our card machine) or bank transfer. Once, or in monthly instalments. **Never by Direct Debit.** | An admin with `billing.manage` (owner or accounts). |
| **Monthly or yearly fee** | **Always by GoCardless Direct Debit.** | GoCardless. We record each payment automatically. |

"Setup fee" and "upfront payment" are the same thing: the one-time amount paid at the start.

An admin can still record a monthly payment by hand as an exception (Billing tab → invoice → Record payment).

## Plan types

The admin picks one on the plan form (Admin → Plans).

| Plan type | Setup fee | Recurring fee | The licence |
|---|---|---|---|
| **Setup fee only** | Yes | None | Trial until the setup fee is paid in full. Then a full licence for 10 years, topped up automatically. It does not expire. |
| **Setup fee + monthly** (or yearly) | Yes | Direct Debit | Trial until paid. Then valid up to the end of the last period the Direct Debit paid. |
| **Monthly only** (or yearly) | £0 | Direct Debit | Same as above, without the setup fee step. |

A business can have its own setup fee, instalments and prices (Billing tab → Payment settings, Change pricing).

## Normal journeys

### Setup fee only

| When | Customer | Admin | Till |
|---|---|---|---|
| Onboarded | Welcome email. | Records the setup fee if it was paid there and then (wizard or trial approval). | Trial starts when the till is first activated. |
| Setup fee paid (admin records it) | Gets the paid invoice by email as the receipt. | Billing tab: "Setup fee: £X — Paid". | Next check-in: **active**, licence end date 10 years ahead. |
| Every day (billing:run) | Nothing. | Nothing. | New tills get the full licence. When less than 9 years are left it is topped up to 10 years. |

Paid in instalments: each paid instalment keeps the tills paid up to the due date of the next one. The last one
gives the 10-year licence.

### Setup fee + monthly (or monthly only)

| When | Customer | Admin | Till |
|---|---|---|---|
| Onboarded | Welcome email with a link to set up the Direct Debit. Portal banner: "Set up your Direct Debit — N days left". | Records the setup fee if paid. | Trial. |
| Direct Debit set up | GoCardless confirms by email. | Billing tab: "Mandate: Active". | Trial continues. |
| Setup fee recorded | Paid invoice by email. | Billing tab: "Setup fee: Paid". | No change yet. |
| Mandate **and** setup fee in place | — | Subscription starts. Billing tab: "Monthly: £Y by Direct Debit — next collection <date>". | If the trial already ended, it is moved to the first collection date, so the till unlocks now. |
| Each collection | Invoice by email: "collected by Direct Debit on <date>". | Payment appears under Direct Debit payments. | When the money is confirmed (a few working days): **active** to the end of that period. |

The Direct Debit does not start until the setup fee (or its first instalment) is paid.

## When something goes wrong

| Problem | Customer | Admin | Till | How it ends |
|---|---|---|---|---|
| **No Direct Debit** within 3 days of onboarding (`BILLING_MANDATE_DEADLINE_DAYS`) | Banner every day. One reminder email when less than 2 days are left. Then "Account suspended" email with the setup link. | Billing tab warning, then the business shows **Suspended** ("No Direct Debit set up"). | **Suspended** at its next check-in. | The owner sets up the Direct Debit: the suspension lifts **at once**. Money taken by hand does not lift it. |
| **Direct Debit cancelled** by the customer (or failed, expired, blocked) | "Your Direct Debit has stopped" email with a new link. Reminder before the deadline. Then suspended. | Copy of the email. Billing tab: "Direct Debit stopped". Subscription paused. | Keeps working for 3 days (`BILLING_MANDATE_GRACE_DAYS`), then **suspended**. | A new mandate: suspension lifted at once, subscription moved to the new mandate. |
| **Payment failed** | "Direct Debit failed" email, a reminder after 5 days. | Copy of the email. Invoice shows **Overdue** the next day. | Paid date stays. After it passes: **grace** for the plan's payment grace days (7), then **expired**. After 7 days unpaid (`BILLING_SUSPEND_AFTER_DAYS`, the same as the payment grace — owner 2026-10-05): **suspended**. | GoCardless retries (if Success+ retries are on in GoCardless), the next collection pays the oldest unpaid invoice, or the admin records a payment. Paid: **active** again at once. |
| **Charged back** (bank reversed a paid payment) | "Payment reversed" email. | Copy. The invoice is owed again. | The paid date is **not** shortened. Suspended only 7 days **after the chargeback**, not after the old due date. | Same as a failed payment. |
| **Payment late** (still being collected) | Nothing. | The invoice is not marked overdue while GoCardless is still collecting it. | No change. | Confirmed: paid. Failed: see above. |
| **Setup fee unpaid** | Portal: "Setup fee: Unpaid — £X to pay by cash, card or bank transfer". | Billing tab: "Setup fee: £X — Unpaid", button "Record setup fee payment". | Trial until it ends (plus trial grace), then **expired**. No Direct Debit is collected. | The admin records it: the till unlocks at its next check-in. |

## What the admin can always do

All of these are audited.

| Action | Where |
|---|---|
| Record a setup fee payment (cash, card, bank transfer) | Billing tab → Record setup fee payment |
| Change or waive the setup fee (set it to £0) before it is invoiced | Billing tab → Payment settings |
| Void a setup fee invoice (waives it) | Invoice → Void |
| Record a monthly payment by hand (exception) | Invoice → Record payment |
| Extend a licence | Licence → Renew |
| Lift a suspension | Tenant → Unsuspend |
| Send the Direct Debit setup email again | Billing tab → Send setup email |

## What the till is told

The till learns everything from `licence/validate`, at its next check-in.

| Status | Means |
|---|---|
| `trial` | Free trial, before any payment. |
| `active` | Paid (setup-only: paid in full; recurring: within a paid period). |
| `grace` | Past the end date, still trading for the grace days. |
| `expired` | Locked: the trial or the paid period ran out. |
| `suspended` | Locked by us: no Direct Debit, or an invoice unpaid past the grace. |

A paid end date is never moved back. Only an admin can shorten it, on purpose.

## Daily jobs

| Time | Job | Does |
|---|---|---|
| 05:30 | `billing:reconcile-gocardless` | Replays failed webhooks, fixes any drift with GoCardless. |
| 06:00 | `billing:run` | Setup-only licences, overdue invoices, suspensions, trial emails, Direct Debit reminders and deadlines. |

Both can run twice without harm.

## See it

`php artisan demo:billing` makes **DEMO – Setup + monthly** (setup fee £1,440 paid by bank transfer, Direct Debit
active, last month's £14.40 collected, next collection shown). Open it from Admin → Billing ("Businesses by billing
state") or Tenants: the **Billing status** card on its Billing tab says where it stands and what happens next.

More cases: `php artisan demo:billing --scenario=<case>` (or `all`):

| `--scenario` | Business | Shows |
|---|---|---|
| `setup-monthly` (default) | DEMO – Setup + monthly | All paid, next Direct Debit date |
| `setup-only-paid` | DEMO – Setup only, paid | Paid by card, licence for 10 years, nothing more to pay |
| `setup-only-unpaid` | DEMO – Setup only, on trial | Trial — N days left, the day the tills lock |
| `waiting-for-dd` | DEMO – Waiting for Direct Debit | Setup paid in cash, no mandate, reminder sent, lock date |
| `payment-failed` | DEMO – Direct Debit failed | Monthly only, payment failed 4 days ago, locks in 3 days |
| `instalments` | DEMO – Setup fee in instalments | 1 of 2 instalments paid, next due next month |

Demo businesses are safe on the live server: nothing is ever sent to GoCardless for them and they never get an email
(logged as "Not sent (demo)"). `--fresh` removes every demo business (and only those) first. In production add
`--force`. To remove any one business for good: `php artisan tenant:purge "<name or id>"` (shows what goes, asks first).


## Pakistan (manual collection)

A Pakistan instance (`COUNTRY=PK`, profile `billing.collection` = `manual`; phase P5) has **no Direct Debit at all**:
no mandate, no setup deadline, no "Set up Direct Debit" banner or email, and GoCardless is never called (whatever
token is set). Everything above about Direct Debit applies to the UK only; the UK is unchanged.

| Money | How it is paid | Who records it |
|---|---|---|
| **Setup fee (upfront)** | Bank transfer, JazzCash, Easypaisa or cash. Once, or in monthly instalments. | An admin with `billing.manage`. |
| **Monthly or yearly fee** | **An invoice for each period, paid by hand**: bank transfer, JazzCash, Easypaisa or cash. | An admin (Invoice → Record payment), with the transaction id as the reference. |

Plans and prices are per instance: the admin creates PKR plans (Admin → Plans). Amounts show in whole rupees
("Rs 2,500"); a setup fee up to Rs 99,99,99,999 is accepted.

### Normal journey (setup fee + monthly, or monthly only)

| When | Customer | Admin | Till |
|---|---|---|---|
| Onboarded | Welcome email (no Direct Debit section). | Records the setup fee if it was paid there and then. | Trial. |
| Setup fee recorded | Paid invoice by email. | Billing tab: "Setup fee: Paid". | If the trial is already over (or ends within 7 days), the first monthly invoice is issued at once and the trial runs to its due date, so the till unlocks now. |
| 7 days before the tills run out (`BILLING_GENERATE_DAYS_BEFORE`) | Invoice by email, with how to pay (bank account, JazzCash, Easypaisa) and the invoice number to quote. | The invoice is issued automatically (no draft). | No change. |
| 3 days before the due date | Reminder email "due on …". | — | — |
| Due date (`BILLING_MANUAL_DUE_DAYS` = 7 days after issue, so normally the period start) | Reminder email "due today". | — | Paid tills run to the end of the last paid period. |
| Paid (admin records it: bank transfer, JazzCash, Easypaisa, cash) | "Licences renewed" email. | Invoice paid. | **Active** to the end of that period at the next check-in. |

The monthly invoices do not start until the setup fee (or its first instalment) is paid, as the UK Direct Debit does.
A monthly-only plan starts at once (its first invoice comes 7 days before the trial ends). A setup-only plan is the
same as in the UK.

### When it is not paid

| Day after the due date | Customer | Admin | Till |
|---|---|---|---|
| 1 | — | Invoice **Overdue**, business overdue. | Paid date passed: **grace** for the plan's payment grace days, then **expired**. |
| 3 (`BILLING_MANUAL_REMIND_AFTER_DAYS`) | "Invoice … is overdue" email with the day the account is suspended. | — | — |
| 8 (unpaid more than `BILLING_SUSPEND_AFTER_DAYS` = 7, as in the UK) | "Account suspended" email, with how to pay by hand. | Business **Suspended** ("Invoice … unpaid"). | **Suspended** at its next check-in. |
| Paid | "Account active again" email. | Suspension lifted at once. | **Active** at its next check-in. |

### How to pay (per instance, empty = hidden)

`BILLING_PAY_BANK_NAME`, `BILLING_PAY_BANK_ACCOUNT_TITLE`, `BILLING_PAY_BANK_IBAN` (shown as "Meezan Bank", "Account
title …", "IBAN …"), `BILLING_PAY_JAZZCASH`, `BILLING_PAY_EASYPAISA`. They appear on every invoice (PDF and email), the
reminders and the portal page My subscription → **How to pay** (amount due, next invoice, the accounts). The UK
`BILLING_BANK_*` (sort code) lines are not used.

### Daily job

`billing:run` (06:00 Karachi): setup-only licences, period invoices (issued and emailed), overdue, suspensions, trial
emails and the payment reminders. `billing:reconcile-gocardless` does nothing. `demo:billing` (the UK Direct Debit
showcase) refuses to run.

### Our seller details (owner 2026-10-06)

The Pakistan instance never shows the UK company. Only the `BILLING_SELLER_*` values set in its `.env` appear on
invoices and emails: at least the trading name "Switch & Save". No "Registered in …" line without a company number;
the NTN (`BILLING_SELLER_NTN` or `BILLING_VAT_NUMBER`) and STRN (`BILLING_SELLER_STRN`) appear, labelled, once set.
