# Partner onboarding API (v1) — Zivaro → retail portal

Owner decision 2026-10-12: when a Zivaro seller records an EPOS sale, the retail portal gets the business without
anyone typing it again. Zivaro (the acquiring reseller platform, `/Applications/MAMP/htdocs/zivaro`) is the first
partner. **UK only** (owner 2026-10-12): `https://retail-v2-portal.sspos.co.uk`. The Pakistan instance has the flow
switched off (country profile `features.partnerApi` false): `/api/partner/v1/*` and the admin partner pages answer
404 and no partner webhook is ever sent.

## Rules

- A request never creates a live business by itself: it lands as a **tenant request** in **Admin → Partner
  requests** with status `pendingReview`. An admin with `tenants.manage` reviews it (may edit every field), then
  **Approve** (creates the business through the normal onboarding: company, shops, tills, licences, owner user,
  billing account with the agreed per-till setup fee) or **Reject** (with a reason).
- Tenant emails follow the email control settings (today all held for the admin).
- Staff hear at once (owner 2026-10-12): a new request, a rejected one sent again, and an approved one the partner
  cancels email `SSPOS_PARTNER_ALERT_EMAILS` (else the lead alert addresses, else `SSPOS_STAFF_EMAIL`; never held), show in the
  admin dashboard's "Needs attention" (admins with `tenants.manage`) and in the menu's "Partner requests" count.
- One request per `externalId`, ever (idempotent): sending the same `externalId` again returns the same request;
  a changed body while `pendingReview` updates it; after approval a changed body is refused (409
  `partner.request_locked`) — edits are made on the portal. A `rejected` request can be sent again: any PUT while
  rejected reopens it as `pendingReview` with that body (audited). `cancelled` is final: a changed body is 409
  `partner.request_closed` (the same body is still 200, unchanged).
- A cancelled deal never suspends a live business automatically: `pendingReview` → `cancelled`; approved → the
  request is flagged "Partner cancelled the deal" for an admin to act on.
- Money: the setup fee payment is recorded on the retail portal as today (cash / card / bank). The partner only
  reads that it was paid.

## Authentication

- `Authorization: Bearer <partner key>` on every call. Keys are created in Admin → Settings → Partners (shown once,
  stored as a hash + last 4, never logged, never in a query string), one or more per partner, revocable.
- HTTPS only. Rate limit 60 requests per minute per key (its own limiter). Errors use the portal's JSON envelope
  `{code, message, traceId, retryAfterSeconds, rejectedKey}` (see **Errors**).
- Every call's outcome is in the portal's audit log (endpoint, externalId, status, error code, key's last 4 — never
  the key or the body).

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
Validation as the admin new-business form (country profile rules for postcode / phone / tax ids; tidied the same way:
upper-case ids and postcodes, `GB` added to a 9-digit UK VAT number, lower-case emails). Required: `seller.name`,
`seller.email`, `business.name`, `owner.name`, `owner.email`, `shops` (1–20, each `name` and `tills` 1–20), `plan`.
Everything else is optional (null or absent); Zivaro sends business phone and shop address as required on its side,
which the portal accepts. `plan` is a plan code (`GET /api/partner/v1/plans` lists the active ones).
`setupFeePerTill` is net of VAT; null = the plan's. `vatApplies` defaults to true.
Reply 201 (created) or 200 (same / updated / reopened): the request (below).

### `GET /api/partner/v1/tenant-requests/{externalId}`
```json
{ "externalId": "zivaro-deal-42", "status": "approved", "tenantId": "01M…", "businessName": "Istanbul Market",
  "tills": 2, "setupFee": { "total": "1800.00", "paid": true, "paidAt": "2026-10-13T10:00:00Z" },
  "tillsActivated": 1, "firstActivatedAt": "2026-10-13T11:00:00Z",
  "adminUrl": "https://retail-v2-portal.sspos.co.uk/admin/tenants/01M…",
  "rejectedReason": null, "cancelledReason": null, "partnerCancelled": false,
  "createdAt": "2026-10-12T09:00:00.000Z", "updatedAt": "2026-10-13T11:00:00.123Z" }
```
`status`: `pendingReview` | `approved` | `rejected` | `cancelled`. Before approval `tenantId`, `setupFee`,
`firstActivatedAt` and `adminUrl` are null and `tillsActivated` is 0. `setupFee.total` is VAT inclusive (the agreed
fee × tills, or the plan's); `paid` is true once every setup fee invoice is paid, or when there is nothing to pay.
`adminUrl` is the business on the portal (`{portal}/admin/tenants/{tenantId}`, staff sign-in). `cancelledReason`:
the partner's cancel reason (also kept on an approved request the partner cancelled: `partnerCancelled: true`), or
"The business was cancelled on the retail portal.". A partner only sees its own requests: another partner's
`externalId` is 404 like an unknown one.

### `POST /api/partner/v1/tenant-requests/{externalId}/cancel`
Body `{ "reason": "Customer pulled out" }` (optional, up to 500 characters). Reply: the request. `pendingReview` →
`cancelled` (+ `tenant.cancelled`); `approved` → stays approved with `partnerCancelled: true` (the live business is
never touched; an admin acts on it); already cancelled, flagged or rejected → unchanged (a repeat is harmless).

### `GET /api/partner/v1/plans`
`[{ "code": "setup-only", "name": "Setup only", "setupFee": "1200.00", "setupFeeMode": "perTill",
"monthlyPerTill": "0.00" }]` (`setupFeeMode` `perBusiness` | `perTill`; `monthlyPerTill` is the plan's monthly
price).

### Errors

Envelope `{code, message, traceId, retryAfterSeconds, rejectedKey}` (+ `details` for validation; `traceId` is also
the `X-Trace-Id` header):

| Status | `code` | When |
|---|---|---|
| 401 | `auth.invalid_key` | No, malformed or unknown Bearer key |
| 401 | `auth.key_revoked` | Revoked key, or the partner is switched off |
| 404 | `partner.request_not_found` | Unknown `externalId` (or another partner's) |
| 409 | `partner.request_locked` | Changed body after approval |
| 409 | `partner.request_closed` | Changed body after cancellation |
| 422 | `request.invalid` | Validation (body, `externalId`, cancel reason) |
| 429 | `rate.limited` | Over 60 a minute per key, or too many wrong keys from one IP (`retryAfterSeconds` + `Retry-After`) |

Validation (422): `retryAfterSeconds` and `rejectedKey` are null, `message` carries the first problem, and
`details.fields` lists every message by dotted body path:

```json
{ "code": "request.invalid", "message": "The request is not valid. The owner.email field is required.",
  "traceId": "01M…", "retryAfterSeconds": null, "rejectedKey": null,
  "details": { "fields": { "owner.email": ["The owner.email field is required."],
                           "shops.0.postcode": ["Enter a UK postcode like LS1 6AB."] } } }
```

## Webhooks (portal → partner)

Configured per partner (URL + signing secret, shown once). POST JSON, retried on non-2xx or no answer with
exponential backoff (1 min, 2, 4 … at most 1 h apart) for 24 h, then given up. Header `X-Partner-Signature:
t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the secret>` (a fresh `t` on each attempt, the same body);
the partner rejects a signature older than 5 minutes. Also `X-Partner-Event-Id` and `X-Partner-Event-Type`. Timeouts:
5 s to connect, 10 s per attempt.

```json
{ "id": "evt_01M…", "type": "tenant.setup_fee_paid", "createdAt": "…",
  "data": { …the same object as GET tenant-requests… } }
```
Types: `tenant.approved`, `tenant.rejected`, `tenant.setup_fee_paid` (every setup fee instalment paid, or nothing
to pay — then straight after `tenant.approved`), `tenant.first_till_activated` (the business's first till key
activated), `tenant.cancelled` (pending request cancelled by the partner, or the business cancelled on the portal).
Each event is made once per request (a rejection once per rejection, as a rejected request can be sent again) and
keeps its `id` on every retry: treat a repeated `id` as already handled. Events exist only for businesses made from
a partner request.

**Ordering.** Webhooks can arrive out of order (retries). Every event has `createdAt`, and its `data.updatedAt`
(ISO-8601 UTC with milliseconds) only moves forward for a request: each change and each event moves it on by at
least 1 ms. Apply an event only if its `data.updatedAt` is not older than the `updatedAt` you already hold (from an
earlier event or a GET/PUT reply); otherwise drop it (a GET gives the current state).

## Zivaro side (summary)

- Sales products get a flag "EPOS (retail portal onboarding)" and a default retail plan code.
- An EPOS deal's form asks for the onboarding fields above; saving a signed deal sends `PUT` (queued, retried);
  the deal shows the request status; cancelling the deal sends `cancel`.
- Webhook receiver verifies the signature and updates the deal; `tenant.setup_fee_paid` marks the deal **live**
  (HQ can still mark it by hand); `tenant.rejected` shows the reason to HQ.
- Config: `RETAIL_PORTAL_URL`, `RETAIL_PORTAL_PARTNER_KEY`, `RETAIL_PORTAL_WEBHOOK_SECRET`.
