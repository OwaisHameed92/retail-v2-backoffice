# Workshop integration — BikeBook (Workshop by Bikebook) — what the portal must build

**Status:** proposal for the portal team, 2026-10-28; **till side built 2026-11-06** (branch `main` line, not released yet) — read **§0a** first: it says what the till now sends, what it does on its own without you (Direct mode), and what is still yours to build. The till side is planned in `tasks/P10-03.md`; this file is the **portal's** half. When a part is built and agreed, fold it into `docs/web-portal-api.md` and add the dated line to `UPCOMING-CHANGES.md`.

**Owner's words (2026-10-28):** a bike-shop customer runs BikeBook for repairs and our SSPOS 3 for the shop. They want
the two joined "properly", like Saledock does it — and better. Everything appears in SSPOS only once the shop has
connected BikeBook; until then nothing changes for any shop.

**What BikeBook is.** A UK workshop system for bike repairs: bookings, jobs, job cards, invoices, mechanics, suppliers.
Mechanics work in BikeBook; money is taken on the shop's POS. BikeBook publishes two things we use:

| BikeBook document | Direction | Who builds it |
|---|---|---|
| **POS connector specification** (`https://developers.bikebook.com/guides/integrating-with-us`) | **BikeBook calls us**: it reads our products, stock, customers, branches, VAT rates; it creates "sales orders" in our POS ("Send to POS"); we tell it when an order is paid | **Portal (this file)** |
| **Workshop Public API v1** (`https://api.bikebook.co.uk/public/v1`, 102 endpoints) | **We call BikeBook** with the shop's own API key: jobs, invoices, payments, customers' bikes, bookings, back orders | **Till** (`tasks/P10-03.md`); the key never reaches the portal |

Why the portal and not the till: BikeBook's servers must reach us over public HTTPS, every 2 minutes, with < 500 ms
answers, day and night. A shop's PC is behind a router and is switched off at night. The portal already holds every
shop's products, stock, customers and VAT rates (they are pushed today), so it only needs a **door shaped for
BikeBook**, plus one new row type down to the till and one webhook back to BikeBook.

---

## 0a. What the till does today (built 2026-11-06) — and what is still yours

**Direct mode works without the portal.** On the shop's main till, with the shop's own BikeBook Public API key, the till
lists BikeBook's unpaid invoices every minute, brings each job onto the till, holds its parts, takes the money on its
normal sale path and tells BikeBook "paid" and "job complete" itself (`docs/workshop/BIKEBOOK-PUBLIC-API.md`). Nothing
here needs the portal, and **the key never reaches the portal**. So the connector below (§1–§9) is the *second* source
of jobs (BikeBook pushing "Send to POS"); it is still wanted, but nothing on the till waits for it.

**What reaches you in `push` from a till whose shop connected BikeBook** (all from the existing row types):

| Row | What is new | Notes |
|---|---|---|
| `CustomerOrder` | `source` (`till` \| `web` \| `workshop`), `externalProvider` (`"bikebook"` or null), `externalOrderId`, `externalReference` (BikeBook job number) | Every existing order is `till`. A workshop order's `reference` is `WS-{job}`; its id equals the till's own workshop order id. Deposits, collection and refunds arrive as the usual `CustomerOrderPayment` / `Sale` rows. |
| `Product` | `workshopExposure` (`auto` \| `always` \| `never`, default `auto`) | Set on the till's product form (§4.1). |
| `Sale` / `SalePayment` | no new columns | A workshop sale may carry a payment whose type is **"Paid in BikeBook"** — money the customer paid in BikeBook (owner's D6). Non-cash, never opens the drawer. |
| `PaymentType` | one new row per company, named **"Paid in BikeBook"**, made when the shop connects (never seeded) | Hidden from the payment screen; the till applies it itself. Matched by name, like "Order deposit". |
| `Account` | ledger account **`1260` "Money held by BikeBook"** (asset), made at connect | What BikeBook holds for the shop until it pays out. Postings balance; accounts stay in SSPOS (D7). |
| `Setting` | branch keys `integration.workshop` and `workshop.*` (provider, connection status, business id/name, product scope + category ids + new-categories flag, workshop category / labour / misc product ids, orders-show-from, ready / message / complete / refund switches, supplier-orders-by) | `workshop.bikebook_api_key` is register-scoped, sealed to the PC and on the local-only list — it never leaves the till. `workshop.connector_status` is **yours** to write (§6); the till only shows it. |
| `Role` / `RolePermission` | permissions `workshop.manage`, `workshop.book`, `workshop.price_override`, `workshop.cancel_hold` | Granted once to Manager (all) and every till role (`book`). |
| `AuditLog` | actions `WorkshopConnected`, `WorkshopDisconnected`, `WorkshopOptionsChanged`, `WorkshopOrderOpened`, `WorkshopHoldCancelled`, `WorkshopProductLinked`, `WorkshopOutboxRetried`, `WorkshopOutboxStopped`, `WorkshopRepairBooked` (entity `WorkshopJob`), `WorkshopExposureChanged` | No keys, no BikeBook bodies in any of them. |

**Local-only for now (never pushed, never pulled):** `WorkshopOrder`, `WorkshopOutboxItem`, `WorkshopCallLog`,
`WorkshopProductLink` (`samples/ownership.json`). When you build the connector, `WorkshopOrder` becomes the pull-only
row of §5 — tell the till team first; the till then moves it from local-only to hub-owned and applies it.

**Licence:** paid feature name **`workshop_integration`** in the key's `features` (§6). Without it the Settings card shows
greyed with "contact your dealer"; nothing else of the module shows.

**Generated contract files changed:** `schemas/entities/CustomerOrder.schema.json`, `schemas/entities/Product.schema.json`,
their `samples/entities/*.json`, `samples/enums.json` (+ `CustomerOrderSource`, `WorkshopExposure`),
`samples/ownership.json`, `samples/settings-local-only.json`. Dated lines in `UPCOMING-CHANGES.md` (2026-11-06).

**Still yours (unchanged from below):** the BikeBook POS connector (§1–§3), the `WorkshopOrder` pull row (§5), the
`order.statuschanged` webhook (§3.8), the admin panel (§8), selling `workshop_integration` (§8), and the joint test list
with BikeBook (§9). Blocked first on BikeBook registering SSPOS as a POS type (§10 Q1). The open questions in §10 stand.

## 0. Big picture

```
                       (1) every 2 min: products, stock        ┌──────────────────────┐
  ┌──────────────┐     (2) every 15 min: customers             │  SSPOS web portal    │
  │  BikeBook    │ ──────────────────────────────────────────► │  BikeBook connector  │
  │  Workshop    │     (3) "Send to POS": create/update/        │  /connectors/bikebook│
  │  (cloud)     │         cancel sales order                   │                      │
  │              │ ◄────────────────────────────────────────── │  (5) webhook          │
  └──────────────┘     order.statuschanged = Complete          └──────────┬───────────┘
         ▲                                                                 │ (4) pull: WorkshopOrder row
         │ Public API (till's own key)                                      ▼  push: CustomerOrder, Sale…
         │ jobs, invoices, payments, bikes, bookings          ┌──────────────────────┐
         └──────────────────────────────────────────────────── │  Main till (shop)    │
                                                               └──────────────────────┘
```

1. BikeBook polls our products + stock (delta by `lastUpdated`).
2. BikeBook polls our customers; it may also create/update a customer in our system.
3. A mechanic presses **Send to POS** (or BikeBook's auto-sync fires) → BikeBook calls `POST/PUT /api/SalesOrder…`.
4. The portal sends that order down to the shop's main till in its normal `pull` as a new **`WorkshopOrder`** row. The
   till turns it into one of its own `CustomerOrder`s (same id), holds the parts' stock and shows it under **Workshop
   orders**. Every change the till makes comes back up in `push` as the usual `CustomerOrder` / `CustomerOrderPayment`
   / `Sale` rows.
5. When the till's `CustomerOrder` becomes `collected` (paid), `cancelled` or a refund is taken, the portal POSTs
   BikeBook's webhook `order.statuschanged` so BikeBook marks the invoice paid and the job completed.

---

## 1. Connection, tenants and credentials

### 1.1 One connection = one branch

- BikeBook's "tenant" = **our branch** (`branchId`). A company with three shops connects each shop separately (each
  shop is its own BikeBook workshop or BikeBook "store").
- Store a `WorkshopConnection` per branch on the portal: `id`, `companyId`, `branchId`, `provider` = `"bikebook"`,
  `status` (`pending`, `connected`, `failed`, `disconnected`), `connectedAt`, `disconnectedAt`, `lastPulledAt` (by
  BikeBook), `lastError`, `defaultStoreId` (= `branchId`), `productScope` (see §4.1), `webhookUrl`, `webhookSecret`
  (encrypted at rest), `createdBy`.
- The till learns the **connector** state from the portal (setting `workshop.connector_status`, written only by you,
  §6) and shows it on its Settings card. The till's own switch-on depends on its own key check
  (`workshop.connection_status`, written only by the till, never pulled) — two keys so neither side overwrites the
  other. The till never holds BikeBook's tokens for this door.

### 1.2 Authorisation — OAuth 2.0 recommended, API key accepted

BikeBook's spec prefers OAuth 2.0 (authorisation code) and accepts a long-lived API key. Build **OAuth** if you can in
the first release; otherwise the **API key** first and OAuth next.

**OAuth 2.0 (authorisation code + refresh):**

| Endpoint | Rule |
|---|---|
| `GET /oauth/authorize` | Standard code flow. The shop owner logs in with their **portal** account, picks the branch, consents. `redirect_uri` = `https://workshop.bikebook.co.uk` (BikeBook gives the exact URI). Reject any other redirect URI. |
| `POST /oauth/token` | Grants `authorization_code` and `refresh_token`. Answer `{ access_token, refresh_token, expires_in, token_type: "Bearer", tenant }` with `tenant` = our `branchId`. `expires_in` 86400 is fine; BikeBook refreshes when < 5 min remain. Refresh tokens long-lived; rotate on use is allowed. |
| Scopes | `offline_access products:read inventory:read customers:read customers:write stores:read taxrates:read salesorders:write webhooks:write`. |
| Revoke | Disconnect in the portal (or on the till, §6) revokes both tokens at once and stops the webhook. |

BikeBook then sends `Authorization: Bearer {access_token}` and `tenant-id: {tenant}` on every call. Refuse a call
whose `tenant-id` is not the branch the token was issued for (403).

**API key (fallback):** the portal admin (or the shop owner in the portal) presses **Create BikeBook key** for one
branch; show it **once**; store only a hash. BikeBook sends it as header `ApiKey: {key}`. **Do not accept the
`?apikey=` query form** that BikeBook's spec also lists — our rule is never a key in a URL (`web-portal-api.md`
§17.11 rule 12, §10.7). Tell BikeBook at registration that we take the header only.

### 1.3 Registering us with BikeBook (external step — owner/portal lead)

BikeBook adds a POS type per vendor. Before go-live, send BikeBook:

- our OAuth client id + secret (or "API key" mode), the `/oauth/authorize` and `/oauth/token` URLs;
- the connector base URL, e.g. `https://<portal-host>/connectors/bikebook/v1`;
- scope list and our rate limits (§7);
- a **sandbox tenant** (a test company + branch on the portal's staging) and a test user;
- our webhook route agreement: BikeBook creates its webhook on us (§3.8) and gives us its receiving URL
  (`https://api.bikebook.co.uk/v2/Webhook/<our-name>`).

BikeBook then runs the joint test list in §9.

---

## 2. Conventions for every connector endpoint

- HTTPS + JSON, **camelCase** (BikeBook's POS spec uses camelCase — `firstName`, `lastUpdated` — unlike its Public API).
- Times ISO-8601 UTC `yyyy-MM-ddTHH:mm:ssZ`. Money: numbers with ≤ 2 dp, **including VAT** unless a field says ex.
- `lastUpdated` delta: return rows whose **portal `updatedAt` or the row's `receivedAt`** is ≥ the given instant. If
  the parameter is missing, return **everything** (initial sync). BikeBook asks with `lastSuccessfulSync − 1 hour`.
- Paging: BikeBook asks ≤ 500 items per page. Use `?page=`/`?pageSize=` or a cursor and answer
  `X-Total-Count` + `Link: <…>; rel="next"` (or a `nextPage` field). BikeBook first lists **ids**, then fetches full
  records in batches of ≤ 500 — make "ids only" cheap (`?fields=id,updated` or a separate light list).
- Speed: single-item GET < 500 ms. Index on `(branchId, updatedAt)` for products, stock and customers.
- Errors (BikeBook's table): 400 permanent (skip item), 401/403 auth (BikeBook marks connection failed), 429 rate
  limit (back-off), 5xx retried 30 s / 2 min / 10 min. Body `{ "error": "invalid_request", "message": "…" }`.
- Every write from BikeBook (`POST`/`PUT`/`DELETE`) is **idempotent by its natural key** (`orderNumber` for an order,
  line id for a line, customer id for a customer). A repeat with the same body answers the same result, no second row.
- Log every call (method, path, branch, status, ms, BikeBook's request id if sent) for the admin panel's sync log
  (§8). Never log tokens, keys or customers' full addresses.

---

## 3. Endpoints BikeBook calls (POS connector spec, with our mapping)

Paths below are **BikeBook's names**; keeping them saves BikeBook work. Prefix with the connector base URL.

### 3.1 `GET /api/Stores` — branches

`Store[]`: `{ "id": <branchId>, "name": <Branch.name>, "address": <Branch.address> }`. One connection = one branch,
so this returns **one** store (the connected branch). The merchant picks it as default.

### 3.2 `GET /api/TaxRates`

`TaxRate[]` from the company's **active** `VatRate` rows (effective today): `{ "id": <VatRate.id>, "name":
<VatRate.name>, "rate": <percentage>, "isDefault": <isDefault> }`. Exactly one `isDefault: true` (the standard rate).
Exempt / out-of-scope rates are listed with `rate: 0` and their own name.

### 3.3 `GET /api/Products?lastUpdated=…` and `GET /api/Product/{id}` — the catalogue

Only products **in scope** (§4.1) and **active** for that branch. A product that leaves the scope (category taken off
the list, product archived, product set to "never") is returned once more with `"enabled": false` (or deleted from
the list and its variants' inventory set to 0 — agree which with BikeBook), never silently dropped, so jobs that
already use it keep working.

Our model → BikeBook's `Product` + `variants[]`:

| BikeBook | Ours |
|---|---|
| `Product.id` | the **variant parent's** `Product.id` when `isVariantParent`; otherwise the product's own `id` |
| `sku` | `Product.sku` (parent's) |
| `name`, `description`, `brand` | `name`, `description`, `brand` |
| `supplier` | name of the product's primary `ProductSupplier`'s supplier, or `""` |
| `category` | the `Category.name` (sub-category name if set, else category) |
| `created`, `updated` | `createdAt`, `max(updatedAt of product, its barcodes, its BranchPrice, its BranchProduct)` |
| `version` | `rowVersion` as text |
| `images[]` | `[ { url } ]` when the portal serves the product picture over HTTPS; else `[]` |
| `variants[]` | one entry per **variant child** (`parentProductId` = this product); a product with no variants returns **one** variant = itself |
| `variants[].id` | the child (or single) product's `id` — **this is the id BikeBook sends back on order lines** |
| `variants[].size` / `colour` | from `variant1..4Name/Value`: the value whose name is "Size" → `size`, "Colour"/"Color" → `colour`; anything else joined into `model` |
| `variants[].barcode` | `primaryBarcode` |
| `variants[].sku` | the variant's `sku` |
| `variants[].weight` | `netMassKg` or `null` |
| `variants[].cost` | `costPrice` (ex VAT) |
| `variants[].sizeOrder` | variant position |

**Service products do not sync** (BikeBook keeps its own detailed services). BikeBook links a service to one of our
products by a **"POS Product ID"** typed on its Create Service page and checked with **Verify** — so
`GET /api/Product/{id}` must answer for **any** active product id of that company, even one outside the scope list
(labour products usually are). The till shows that id with a **Copy** button on the product form.

### 3.4 `GET /api/Inventory?variant={id}` (+ batch) — stock and price

`Inventory[]`: `{ "variant": <productId>, "store": <branchId>, "quantity": <qtyAvailable>, "price": <shelf price inc VAT> }`.

- `quantity` = `BranchProduct.qtyOnHand − qtyReserved` (= `qtyAvailable`), never below 0 in this answer. Untracked
  products (`trackStock` false) answer `quantity: null` (agree with BikeBook) or a large number — ask BikeBook which.
- `price` = the branch's current shelf price: an active `BranchPrice` for the branch if one is valid now, else
  `BranchProduct.priceOverride`, else `Product.sellPrice` (the till's own rule — `Application/Pricing/ShelfPrices`;
  mirror it exactly, inc VAT, 2 dp).
- **Batch (needed):** a shop with 100,000 lines cannot be read one variant per call every 2 minutes. Also answer
  `GET /api/Inventory?lastUpdated=…` (every variant whose stock or price changed) and `POST /api/Inventory/batch`
  `{ "variants": [ids…] }` (≤ 500). Agree with BikeBook that it uses these (spec "Nice-to-have: batch endpoints").
- **Push on change (needed for "reserved" to be honest):** BikeBook's optional `inventory.updated` webhook — send it
  when a pushed `BranchProduct` changes `qtyOnHand`/`qtyReserved`, debounced (≤ 1 per variant per 30 s).

How fresh this is: as fresh as the till's last push (`sync.interval_seconds`, default 30 s). Say so in BikeBook's help.

### 3.5 Customers

| Call | Our behaviour |
|---|---|
| `GET /api/Customer/list?lastUpdated=…` | active, non-anonymised `Customer` rows of the company, delta |
| `GET /api/Customer/{id}` | one customer |
| `POST /api/Customer` | create a `Customer` on the portal (portal-owned master data, §10) and send it down in the branch's `pull` like any portal-made customer. **De-duplicate first**: same phone (UK-normalised, `CustomerLookup` rules) or same e-mail → return the existing one, do not create |
| `PUT /api/Customer` | update name, phone, e-mail; same `pull` path |
| `PUT /api/Customer/{id}/Address` | update `Customer.address` (one address in our model; keep line1, line2, town, postcode joined as the till shows it) |

Mapping: `id` = `Customer.id`; `firstName`/`lastName` = `Customer.name` split at the last space (first = all before);
`emailAddress`, `phone`, `createDate` = `createdAt`, `updateDate` = `updatedAt`, `version` = `rowVersion`,
`addresses[]` = one address parsed from `Customer.address` (postcode = the last UK postcode found).
**Never** expose `balance`, `points`, `creditLimit`, `cardNo`, `dob`, `notes` to BikeBook.
GDPR: a customer anonymised on the till (`anonymisedAt`) is returned once with empty personal fields and then never
again; send BikeBook `customer.updated` for it (§3.8).

### 3.6 Sales orders — the heart of it

BikeBook's **Send to POS** gathers the invoice lines, customer, default store and tax rates, then:

| Call | Our behaviour |
|---|---|
| `POST /api/SalesOrder` (`SalesOrderPostModel`) | create a **`WorkshopOrder`** (§5) for the branch; answer our `SalesOrder` (id = our new ULID) |
| `GET /api/SalesOrder?id=` | current state (from the till's pushed `CustomerOrder` if it exists, else the `WorkshopOrder`) |
| `PUT /api/SalesOrder` (`SalesOrderPutModel`) | replace header/lines → new version of the same `WorkshopOrder` (refused once the till has taken money, §5.3) |
| `DELETE /api/SalesOrder?id=` | cancel → `WorkshopOrder.status = cancelled` |
| `POST /api/SalesOrderLine` / `PUT /api/SalesOrderLine` / `DELETE /api/SalesOrderLine?id=` | change one line → new version of the order's lines (same refusal rule) |
| `PUT /api/SalesOrder/{id}/status/{status}` | accept at least `Saved` (open on the till) and `Complete`; `Complete` from BikeBook means "paid elsewhere" → see §5.4 |

`SalesOrderPostModel` (BikeBook): `orderNumber` (e.g. `"INV#1234 [abcd]"`), `store`, `customer`, `lines[]` with
`variantId` (our product id, or empty for a custom line), `name`, `quantity` (may be decimal), `paidPrice` (unit price
inc VAT), `taxRate` (our `VatRate.id`), `lineType` (`"Custom"`, `"Product"`, …).

Our `SalesOrder` answer: `{ "id", "orderNumber", "status": "Saved" | "Complete" | "Cancelled", "store", "customer",
"total", "paidTotal", "lines": [ { "id", "variantId", "name", "quantity", "paidPrice", "taxRate", "lineType" } ],
"created", "updated" }`.

Validation (400 with a clear `message`): unknown `store` (not the connected branch); unknown `taxRate`; `variantId`
that is not an active product of the company (BikeBook then sends it as a custom line); quantity ≤ 0; price < 0;
more than 200 lines.

### 3.7 Order status we report back

| Till state (pushed `CustomerOrder.status`) | `SalesOrder.status` we answer / send |
|---|---|
| `WorkshopOrder` not yet taken by the till, or `ordered` / `ready` | `Saved` |
| `collected` (balance taken — a real `Sale`) | `Complete` |
| `cancelled` / `expired` | `Cancelled` |

### 3.8 Webhooks — both directions

**BikeBook manages its webhook on us** (spec "Webhook CRUD"): `POST /api/Webhook`, `PUT /api/Webhook`,
`GET /api/Webhook`, `GET /api/Webhook/{id}`, `DELETE /api/Webhook/{id}` with
`{ id, url, secret, subscriptions[], enabled, name }`. Store per connection.

**We send** (POST JSON to that `url`):

| Event | When | Payload |
|---|---|---|
| `order.statuschanged` | a pushed `CustomerOrder` that came from a `WorkshopOrder` changes status to `collected`, `cancelled` or `expired`; also when a refund `CustomerOrderPayment` arrives for it | `{ "id": <order id>, "orderStatus": "Complete" \| "Cancelled", "paidTotal": 0.00, "refundedTotal": 0.00, "saleId": "…", "paidAt": "…Z" }` (the first two fields are BikeBook's; the rest are extras — ask BikeBook to read `paidTotal`) |
| `product.updated` (optional) | a product in scope changed | the `Product` model |
| `customer.updated` (optional) | a customer changed or was anonymised | the `Customer` model |
| `inventory.updated` (needed, §3.4) | stock or price changed | `Inventory[]` |

**One reporter per order — never pay twice in BikeBook.** For an order that came through this connector
(`CustomerOrder.source = workshop`, `externalOrderId` = a `WorkshopOrder` you sent), **you** tell BikeBook (this
webhook) and the till does **not** call BikeBook's Public API to record that payment. Orders the till found itself in
Direct mode are reported by the till and never by you (you will see them with `externalOrderId` = an id you did not
send — ignore them). Deposits and part payments on connector orders: BikeBook's spec has no event for them; send
`paidTotal` on `order.statuschanged` with `orderStatus: "Saved"` only if BikeBook agrees (Q5, Q11) — otherwise BikeBook
learns the money at `Complete`.

Signature: header **`X-Pos-Signature: Base64(HMAC_SHA256(secret, raw_body))`**. BikeBook answers 2xx; on anything else
retry up to 5 times with exponential back-off (1 min, 5 min, 15 min, 1 h, 4 h), then mark the delivery failed and show
it in the admin panel with **Replay**. Deliver at least once; include an `X-Pos-Event-Id` (ULID) so BikeBook can
de-duplicate.

---

## 4. What the shop chooses (comes up from the till as settings)

### 4.1 Which products BikeBook sees

Set on the till (Settings → Workshop integration) and pushed as branch `Setting` rows (normal settings sync):

| Setting key (branch scope) | Values | Portal rule |
|---|---|---|
| `workshop.product_scope` | `all` \| `categories` | `all` = every active product; `categories` = only the list below |
| `workshop.scope_category_ids` | JSON array of `Category.id` | used when `categories`; a sub-category counts if its parent is listed |
| `workshop.new_categories_included` | `true` \| `false` | a category created later joins the list automatically when `true` |
| product field `workshopExposure` (new column on `Product`, §6) | `auto` \| `always` \| `never` | `always` beats the scope, `never` beats it |

The portal must recompute the set when any of these change and answer it from then on (and send `product.updated` /
disabled rows for products that left or joined).

### 4.2 Which system owns the accounts

Owner decision 2026-10-28: **accounts stay in SSPOS** — our ledger posts every workshop sale. No setting. If a shop
also runs BikeBook → Xero, the shop must switch BikeBook's Xero sales sync off, or workshop sales are counted twice;
the till's connect step says so.

---

## 5. New pull row: `WorkshopOrder` (portal → till)

Modelled on the **web order proposal** (`web-portal-api.md` §12) and the head-office purchase order relay (§10.6).
Envelope `entity: "WorkshopOrder"`, `branchId` = the connected branch, `op` `I`/`U`/`D`, `rowVersion` rising.

### 5.1 Payload

```json
{
  "id": "01K8BKB0000000000000000W01",
  "companyId": "…", "branchId": "…",
  "provider": "bikebook",
  "externalOrderNumber": "INV#1234 [abcd]",
  "externalJobNumber": "1042",
  "externalInvoiceId": "inv_01h2…",
  "externalJobId": "job_01h2…",
  "reference": "WS-1042",
  "status": "open",
  "customer": { "customerId": "…", "name": "Ali Khan", "phone": "07700 900123", "email": "ali@example.co.uk" },
  "asset": { "name": "Trek FX3", "serial": "WTU123456", "externalAssetId": "asset_…" },
  "lines": [
    { "externalLineId": "l1", "productId": "…", "name": "Shimano brake pads", "qty": 1, "unitPrice": 12.00,
      "vatRateId": "…", "vatPercentage": 20, "lineType": "part", "lineTotal": 12.00, "vatAmount": 2.00 },
    { "externalLineId": "l2", "productId": null, "name": "Gear tune", "qty": 1, "unitPrice": 45.00,
      "vatRateId": "…", "vatPercentage": 20, "lineType": "labour", "lineTotal": 45.00, "vatAmount": 7.50 }
  ],
  "discount": { "type": "percentage", "value": 10 },
  "money": { "goodsTotal": 51.30, "paidElsewhere": 0.00, "paidElsewhereReference": "" },
  "billing": { "type": "customer", "warranty": false, "leasing": false, "motability": false },
  "dueAt": "2026-10-30",
  "notes": "Customer collects after 5pm",
  "version": 3,
  "sentAt": "2026-10-28T10:15:00Z"
}
```

- `id`: **your** ULID; the till's `CustomerOrder` takes the **same id** (as §12 proposes).
- `reference`: `WS-{BikeBook job number}`; unique in the shop. The till never makes a `WS-` reference itself.
- `lines[].lineType`: `part` (has `productId`), `labour` (no `productId` — the till books it on the shop's chosen
  labour product), `custom` (no `productId` — the till books it on the shop's chosen "workshop misc" product), `fee`.
- `lines[].unitPrice` is inc VAT, ≤ 2 dp; `lineTotal`/`vatAmount` as the till computes them (`VatCalculator`); send
  your values and the till recomputes and flags any penny difference.
- `money.paidElsewhere`: what the customer already paid **in BikeBook** (deposit, payment link). **Open owner question
  (same as §12 rule 5): until a tender for "paid in BikeBook" is designed, send `0.00`** and the till asks BikeBook
  directly (Public API) for the invoice's `paid_amount` — see `tasks/P10-03.md` decision D6 (decided 2026-10-28: the
  till shows it as "Already paid in BikeBook" and books it to a non-cash "Paid in BikeBook" tender).
- `billing`: from BikeBook's `is_warranty_job`, `is_leasing_job`, `is_motability_job`, `billing_type`.
- `status`: `open` | `cancelled`.

### 5.2 Ownership

`WorkshopOrder` is **portal-owned** (add to `HubOwnedEntities` side for this one type, like `hubDrafted` in
`samples/ownership.json`). The till **never pushes** `WorkshopOrder`; it pushes the `CustomerOrder` it made from it
(same id) and everything after. The `CustomerOrder` row gains three columns (§6) so you can join them.

### 5.3 Changes after the shop started

Like §10.6: the portal may send a new version (`U`) of a `WorkshopOrder` until the till has **taken money on it** or
**opened it on a sale**. After that the till **refuses** the change and flags it on its Sync status screen
(`SyncConflict`, entity `WorkshopOrder`, detail `WS-1042`), once per version. Answer BikeBook's `PUT` then with
**409** `{ "error": "order_locked", "message": "The shop has started taking payment for this order. Change it at the
till." }`. A cancel (`status: cancelled`) after money was taken is refused the same way (the shop refunds at the till).

### 5.4 BikeBook says "Complete" itself

If BikeBook calls `PUT /api/SalesOrder/{id}/status/Complete` (paid in full in BikeBook), send the `WorkshopOrder`
again, still `status: "open"`, with `money.paidElsewhere` = the total. Owner decision D6 (2026-10-28): the till shows
"Already paid in BikeBook" and, when the customer collects the bike, completes a £0-to-pay sale whose whole amount is
the non-cash "Paid in BikeBook" tender — so the parts leave stock and the sale is in SSPOS's books (accounts stay in
SSPOS, D7). Do **not** send `order.statuschanged` back for that order (BikeBook already knows).

### 5.5 Idempotent

The same `WorkshopOrder` (same `version`, or same content at a newer version) is a no-op on the till.

---

## 6. Rows and fields that change for the portal

| What | Change | Direction |
|---|---|---|
| `WorkshopOrder` | **new** entity (§5) | portal → till only |
| `CustomerOrder` | **new columns** `source` (`till` \| `web` \| `workshop`), `externalProvider` (`bikebook` or `null`), `externalOrderId` (the `WorkshopOrder.id` or `null`), `externalReference` (BikeBook job/invoice number) | till → portal (branch-owned, read-only to you) |
| `Product` | **new column** `workshopExposure` (`auto` \| `always` \| `never`, default `auto`) | portal-owned master data; the till edits it too (like `BranchPrice` §10.5 — accept it from push) |
| `Setting` rows | new branch keys `workshop.*` (§4) come up from the till; **`workshop.connector_status`** (`pending` / `connected` / `disconnected` / `failed`) is written **only by you** and pulled down; **`workshop.connection_status`** is the till's own (Direct key check) — never write it | per key, as stated |
| `Sale` / `SaleLine` | no new columns; a collection sale for a workshop order carries the `CustomerOrder` link as today | — |
| Secrets | the shop's BikeBook **Public API key** lives only on the main till (`workshop.bikebook_api_key`, register scope, DPAPI-sealed) — it is on the §10.3 deny-list by name (`api_key`) and **never** reaches you | never |
| Licence | new paid feature name **`workshop_integration`** in the licence key's `features` (§17); the portal admin must be able to sell/enable it per shop | portal → till (licence) |
| Enums | `workshopOrderStatus` open, cancelled · `workshopLineType` part, labour, custom, fee · `customerOrderSource` till, web, workshop · `workshopExposure` auto, always, never | add to `samples/enums.json` |

Write the JSON schema + sample for each (`schemas/entities/WorkshopOrder.schema.json`, `samples/entities/WorkshopOrder.json`,
a `samples/pull-reply.workshop.json`), and the contract tests the till generates them from will be added by the till
side (`tests/SSPOS.Infrastructure.Tests/Sync/PortalContract/*`).

---

## 7. Limits we promise BikeBook

- 600 requests / minute per connection; 429 with `Retry-After` beyond it.
- ≤ 500 items per page; ≤ 200 lines per order; request body ≤ 1 MB.
- Single GET < 500 ms p95; list page < 2 s p95.

---

## 8. Portal admin panel (owner/portal lead)

Per company → per branch:

- **Workshop integration** card: provider, status (Connected since …, Failed: reason, Disconnected), last BikeBook
  call, products in scope (count), customers shared (count), open workshop orders (count).
- **Create / revoke key** (API key mode) or **Revoke access** (OAuth).
- **Sync log**: last 1,000 calls (time, call, result, ms) and **webhook deliveries** (time, event, order, status, error)
  with **Replay**. Export CSV. (BikeBook shows the same from its side — staff compare the two when support is needed.)
- Licence: tick `workshop_integration` for the shop.

---

## 9. Joint test list with BikeBook (go-live gate)

1. Connect (OAuth or key) → store list shows the branch; tax rates list has one default.
2. First full product sync of a 100,000-product test shop with scope = 3 categories → only those products, < 15 min.
3. Change a price and a stock count on the till → BikeBook shows it within 3 minutes (push + delta), or at once via
   `inventory.updated`.
4. Create a customer in BikeBook → appears on the till within a minute; same phone twice → no duplicate.
5. Send to POS (parts + labour + custom line) → **Workshop orders** on the till within 30 s; stock for the parts held.
6. Change the job in BikeBook (add a part) → order updated on the till; after the till takes a deposit, a change is
   refused (409) and BikeBook shows the message.
7. Take payment on the till (cash, then card, then split) → BikeBook invoice **Paid**, job **Completed**, within a
   minute.
8. Cancel the job in BikeBook → order gone from the till, stock released.
9. Refund on the till → BikeBook gets `order.statuschanged` with `refundedTotal`.
10. Disconnect → BikeBook calls get 401; the till hides its screens; open orders stay readable.
11. Training mode on the till → nothing reaches the portal or BikeBook (till rule).
12. Kill the portal for 10 minutes during Send to POS → BikeBook retries; one order, not two.

---

## 10. Open questions (ask BikeBook; owner decides the rest)

| # | Question | To |
|---|---|---|
| Q1 | Will you register an extra POS type for SSPOS, and how long does it take? Staging host for us? | BikeBook |
| Q2 | Do you call the batch / delta inventory endpoints and accept `inventory.updated`? | BikeBook |
| Q3 | Untracked products: `quantity: null` or omit? | BikeBook |
| Q4 | Does Send to POS ever carry money already paid in BikeBook (deposit, payment link)? If so, which field? | BikeBook |
| Q5 | Do you read extra fields on `order.statuschanged` (`paidTotal`, `refundedTotal`)? | BikeBook |
| Q6 | Header-only API key OK (no `?apikey=`)? | BikeBook |
| Q7 | Do you send job number, bike name/serial, warranty/leasing flags on the order? If not, the till reads them from your Public API | BikeBook |
| Q8 | How long does your Idempotency-Key live on the Public API (the till may retry after days offline)? | BikeBook |
| Q9 | ~~Money paid in BikeBook~~ — decided 2026-10-28: a non-cash "Paid in BikeBook" tender on the till, labelled as BikeBook's money (§5.4) | Owner ✓ |
| Q10 | Price of `workshop_integration` — owner 2026-10-28: decided later, most probably a **monthly** fee; the portal team sets it up in licensing/billing (the till only needs the feature name in the key) | Owner + portal |
| Q11 | Connector orders: how do we report a deposit / part payment before completion (an `order.statuschanged` with `Saved` + `paidTotal`, or nothing until `Complete`)? And refunds after completion? | BikeBook |
