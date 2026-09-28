# GoCardless billing (module 1.12)

Two ways to sell: **upfront cash** (module 1.8: invoices paid by hand) or **setup fee + Direct Debit**
(GoCardless, monthly or yearly). Decisions: `docs/DECISIONS.md` → "GoCardless billing". Code:
`app/Domain/Billing/GoCardless`.

## Flows

1. **Switch a business to Direct Debit**: admin → tenant → Billing → Direct Debit → *Payment settings* (mode,
   setup fee override, cash/bank or Direct Debit, instalments). The plan's default fee is on the plan form.
2. **Mandate**: *Send setup email* → owners get a signed link (14 days) → our `/direct-debit/{company}/setup`
   opens the GoCardless hosted page → customer authorises → GoCardless sends them to `/direct-debit/{company}/done`
   and sends `billing_requests.fulfilled` + `mandates.*` webhooks.
3. **On the mandate**: setup fee invoice(s) + one-off GoCardless payment each (instalments a month apart), and a
   subscription = live tills × plan price for the cycle + VAT, first charge on the next period start.
4. **Each collection**: GoCardless creates the payment → `payments.created` → we issue the invoice for the next
   unpaid period (due on the charge date, emailed). `confirmed`/`paid_out` → payment recorded (method Direct
   Debit) → invoice paid → tills renewed to the period end.
5. **Problems**: `failed`/`charged_back` → recorded payment reversed, invoice owed again, "Direct Debit failed"
   email (+ reminder after 5 days); billing:run marks it overdue and suspends after 14 days. Mandate lost →
   owners + staff emailed, overdue after 3 days. Trial over without a mandate → suspended after 3 days.
6. **Changes**: tills added/removed, cycle or VAT change → subscription amount updated from the next payment
   (cycle change = new subscription on the same billing day). Daily reconcile fixes anything missed.

## Environment

| Key | Meaning |
|---|---|
| `GOCARDLESS_ACCESS_TOKEN` | Read-write access token. Empty = Direct Debit off. |
| `GOCARDLESS_ENVIRONMENT` | `sandbox` (default) or `live`. |
| `GOCARDLESS_WEBHOOK_SECRET` | Secret of the webhook endpoint (signature check; empty = all webhooks refused). |
| `BILLING_MANDATE_GRACE_DAYS` | Days without a mandate after the trial / after it is lost (default 3). |
| `BILLING_DD_REMINDER_DAYS` | Days after a failure before the reminder email (default 5). |

Webhook URL: `{APP_URL}/webhooks/gocardless` (POST, public, HMAC-signed). Needs the queue worker and the
scheduler (`billing:reconcile-gocardless` 05:30, `billing:run` 06:00).

## Sandbox testing

1. Create a sandbox account at manage-sandbox.gocardless.com → Developers → create an access token (read-write)
   and a webhook endpoint pointing at your public URL + `/webhooks/gocardless` (locally: `ngrok http 8000`).
   Put the token, `GOCARDLESS_ENVIRONMENT=sandbox` and the endpoint secret in `.env`; run `php artisan
   queue:work`.
2. Give the plan a setup fee; on a tenant choose Direct Debit and *Send setup email*; open the link from the
   email log (admin → Emails) and complete the page with sandbox bank details (sort code 200000, account
   55779911).
3. The Billing tab shows the mandate, setup fee payment(s) and subscription. In the GoCardless dashboard use
   *Simulate* (scenario simulators) on a payment: `payment_confirmed`, `payment_failed`, `payment_charged_back`,
   and on the mandate `mandate_failed` / cancel it; watch invoices, licences and emails follow.
4. `php artisan billing:reconcile-gocardless --dry-run` shows any drift; without `--dry-run` it fixes it.
5. Resend a webhook from the dashboard: it is a no-op (stored once). Tests: `php artisan test tests/Feature/Billing/GoCardless`.
