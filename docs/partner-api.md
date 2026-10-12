# Partner onboarding API (v1) — Zivaro → retail portal

Owner decision 2026-10-12: when a Zivaro seller records an EPOS sale, the retail portal gets the business without
anyone typing it again. Zivaro (the acquiring reseller platform, `/Applications/MAMP/htdocs/zivaro`) is the first
partner. UK portal only for now (`https://retail-v2-portal.sspos.co.uk`); the Pakistan portal has its own partner
keys if it is ever used.

## Rules

- A request never creates a live business by itself: it lands as a **tenant request** in **Admin → Partner
  requests** with status `pendingReview`. An admin with `tenants.manage` reviews it (may edit every field), then
  **Approve** (creates the business through the normal onboarding: company, shops, tills, licences, owner user,
  billing account with the agreed per-till setup fee) or **Reject** (with a reason).
- Tenant emails follow the email control settings (today all held for the admin).
- One request per `externalId`, ever (idempotent): sending the same `externalId` again returns the same request;
  a changed body while `pendingReview` updates it; after approval a changed body is refused (409
  `partner.request_locked`) — edits are made on the portal.
- A cancelled deal never suspends a live business automatically: `pendingReview` → `cancelled`; approved → the
  request is flagged "Partner cancelled the deal" for an admin to act on.
- Money: the setup fee payment is recorded on the retail portal as today (cash / card / bank). The partner only
  reads that it was paid.

## Authentication

- `Authorization: Bearer <partner key>` on every call. Keys are created in Admin → Settings → Partners (shown once,
  stored as a hash + last 4, never logged, never in a query string), one or more per partner, revocable.
- HTTPS only. Rate limit 60 requests per minute per key. Errors use the portal's JSON envelope
  `{code, message, traceId, retryAfterSeconds, rejectedKey}`.

## Endpoints

### `PUT /api/partner/v1/tenant-requests/{externalId}`
Create or update (while `pendingReview`). `externalId`: the partner's own id, 1–64 chars `[A-Za-z0-9._:-]`
(Zivaro uses `zivaro-deal-{id}`).

```json
{
  "seller": { "name": "Ali Khan", "email": "ali@switchandsave.co.uk" },
  "business": { "name": "Istanbul Market", "legalName": "Istanbul Market Ltd", "companyNumber": null,
                "vatNumber": null, "phone": "0121 000 0000", "email": "sandra@example.co.uk" },
  "owner": { "name": "Sandra Smith", "email": "sandra@example.co.uk", "phone": "07700 900123" },
  "shops": [ { "name": "Birmingham", "address": "1 High Street", "town": "Birmingham", "postcode": "B1 1AA",
               "tills": 2 } ],
  "plan": "setup-only",
  "setupFeePerTill": "750.00",
  "vatApplies": true,
  "salePrice": "1500.00",
  "signedOn": "2026-10-12",
  "notes": "Two tills, card machine from DNA"
}
```
Validation as the admin new-business form (country profile rules for postcode / phone / tax ids). `plan` is a plan
code (`GET /api/partner/v1/plans` lists the active ones). `setupFeePerTill` is net of VAT; null = the plan's.
Reply 201 (created) or 200 (same / updated): the request (below).

### `GET /api/partner/v1/tenant-requests/{externalId}`
```json
{ "externalId": "zivaro-deal-42", "status": "approved", "tenantId": "01M…", "businessName": "Istanbul Market",
  "tills": 2, "setupFee": { "total": "1800.00", "paid": true, "paidAt": "2026-10-13T10:00:00Z" },
  "tillsActivated": 1, "firstActivatedAt": "2026-10-13T11:00:00Z", "rejectedReason": null,
  "createdAt": "…", "updatedAt": "…" }
```
`status`: `pendingReview` | `approved` | `rejected` | `cancelled`.

### `POST /api/partner/v1/tenant-requests/{externalId}/cancel`
Body `{ "reason": "Customer pulled out" }`. Reply: the request.

### `GET /api/partner/v1/plans`
`[{ "code": "setup-only", "name": "Setup only", "setupFee": "1200.00", "setupFeeMode": "perTill",
"monthlyPerTill": "0.00" }]`

## Webhooks (portal → partner)

Configured per partner (URL + signing secret, shown once). POST JSON, retried with backoff for 24 h on non-2xx.
Header `X-Partner-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the secret>`; the partner
rejects a signature older than 5 minutes.

```json
{ "id": "evt_01M…", "type": "tenant.setup_fee_paid", "createdAt": "…",
  "data": { …the same object as GET tenant-requests… } }
```
Types: `tenant.approved`, `tenant.rejected`, `tenant.setup_fee_paid`, `tenant.first_till_activated`,
`tenant.cancelled`. Each event is sent once (idempotent `id`).

## Zivaro side (summary)

- Sales products get a flag "EPOS (retail portal onboarding)" and a default retail plan code.
- An EPOS deal's form asks for the onboarding fields above; saving a signed deal sends `PUT` (queued, retried);
  the deal shows the request status; cancelling the deal sends `cancel`.
- Webhook receiver verifies the signature and updates the deal; `tenant.setup_fee_paid` marks the deal **live**
  (HQ can still mark it by hand); `tenant.rejected` shows the reason to HQ.
- Config: `RETAIL_PORTAL_URL`, `RETAIL_PORTAL_PARTNER_KEY`, `RETAIL_PORTAL_WEBHOOK_SECRET`.
