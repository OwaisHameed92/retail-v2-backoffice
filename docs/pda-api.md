# Handheld (PDA) API — phase 1 (for the EPOS Android team)

The new Switch & Save portal offers the legacy portal's PDA API (`/Applications/MAMP/htdocs/retail-backoffice/docs/PDA_APP_API.md`)
under the **same paths, field names and reply shapes** where the new system allows it, so the Android PDA app can
point at the new portal with few changes. Differences are listed in [Differences from the legacy API](#differences-from-the-legacy-api).

- **Base URLs:** UK `https://retail-v2-portal.sspos.co.uk` · Pakistan `https://pak-pos.sspos.co.uk` (same API on both).
- **Format:** JSON (`Content-Type: application/json`, `Accept: application/json`). HTTPS only.
- **Auth:** `POST /api/pda/login` returns a bearer token. Send it on every other call as
  `Authorization: Bearer <token>`. A token in the query string is never read.
- **Shop scoping:** each handheld user belongs to one shop of one business. Everything the app reads or creates is
  for that shop; the app never sends a shop id. Another business's (or shop's) record answers 404.
- **Ids:** every id is a **26-character ULID string** (`"01K5T0Q8C4000000000000P001"`), never an integer. Put the
  ids from the lists straight into create/edit bodies.
- **Money and quantities:** decimal **strings** — prices 2 dp (`"1.45"`), costs and quantities 4 dp (`"0.9800"`,
  `"24.0000"`). Requests may send numbers or strings.
- **Times:** ISO-8601 UTC with `Z` (`"2026-10-20T09:00:00Z"`); dates `YYYY-MM-DD`.
- **Handheld users** are created on the portal by the business owner: **Settings → Handheld (PDA) users** (name,
  email, shop, password, "can change selling prices"). They are not till users and are never sent to a till.

## Errors

Every error has `success: false`, the legacy top-level `code` (UPPER_SNAKE, branch on it) and the portal's API
envelope:

```json
{ "success": false, "code": "PRICE_CHANGE_NOT_ALLOWED", "message": "This handheld account may not change prices. Ask your manager to allow it on the portal.",
  "traceId": "01K7Z3M9Q4H2R8T6V0W1X2Y3Z4", "retryAfterSeconds": null, "rejectedKey": null }
```

Validation errors are **422** `VALIDATION_FAILED` with `errors` (field → messages, using the app's field names, e.g.
`items.0.product_id`). `traceId` is also in the `X-Trace-Id` header: quote it when reporting a problem.

| Status | `code` | When |
|---|---|---|
| 401 | `INVALID_CREDENTIALS` | Wrong email or password (same answer for an unknown email) |
| 401 | `UNAUTHENTICATED` | No token, malformed, unknown or revoked token (logout, password reset, "sign out devices", switched off) |
| 403 | `ACCOUNT_INACTIVE` | The handheld user is switched off on the portal |
| 403 | `SHOP_CLOSED` | The user's shop is closed |
| 403 | `BUSINESS_SUSPENDED` / `BUSINESS_CANCELLED` | The business's account is on hold / closed |
| 403 | `PRICE_CHANGE_NOT_ALLOWED` | Price change by a user without the permission |
| 404 | `NOT_FOUND` | Unknown id, or another shop's / business's record; unknown path |
| 405 | `METHOD_NOT_ALLOWED` | Wrong HTTP method |
| 409 | `TENANT_REQUIRED` | The email + password open accounts in two businesses: send `tenant_id` |
| 409 | `PO_OWNED_BY_TILL` | The shop's till owns the order now (sent/cancelled/received on the till, or the till's own order) |
| 409 | `PO_CANCELLED` | The order is cancelled: it cannot be edited or re-opened |
| 422 | `VALIDATION_FAILED` | Body or query not valid (`errors` lists the fields) |
| 422 | `PRODUCT_ARCHIVED` | Price change on an archived product |
| 429 | `RATE_LIMITED` | Too many requests; wait `retryAfterSeconds` (also `Retry-After`) |
| 501 | `NOT_AVAILABLE_YET` | A phase 2 call (receiving, returns, stock adjustments) |
| 500 / 503 | `SERVER_ERROR` / `SERVER_BUSY` | Our side; retry later |

**Rate limits:** login 10 a minute per email + IP (60 per IP); every other call 240 a minute per token.

---

## 1. Authentication

### `POST /api/pda/login` (public)

```json
{ "email": "stock1@kirkgate.co.uk", "password": "••••••••••", "device_name": "Zebra TC21 #3" }
```
`device_name` is optional (shown to the owner under "devices"). `tenant_id` (the business id) is only needed when
the reply was `409 TENANT_REQUIRED`.

`200`:
```json
{
  "success": true,
  "token": "pda_Q7k2…(52 characters)",
  "token_type": "Bearer",
  "user": { "id": "01K7Z2…", "name": "Stock room 1", "email": "stock1@kirkgate.co.uk", "shop_id": "01K5VB…",
            "shop_name": "Leeds Kirkgate", "shop_code": "LDS", "can_change_prices": false, "last_login_at": "2026-10-20T09:00:00Z" },
  "tenant": { "id": "01K5VA…", "name": "Kirkgate Stores" }
}
```
Store the token; it is shown once (the portal keeps only a hash). Each login makes a new token (one per device);
tokens do not expire on their own — they end at logout, a password reset, "sign out devices" or when the user is
switched off. Use `can_change_prices` to show or hide the price editor.

### `GET /api/pda/me`
Same `user` and `tenant` blocks as the login (no token). Use it to check a stored token. A user moved to another
shop on the portal gets the new `shop_id` here (and every call follows it at once).

### `POST /api/pda/logout`
Revokes the token of this call (other devices stay signed in). `200 { "success": true, "message": "Signed out." }`

---

## 2. Reference lookups

Lists are paginated: `?page=` and `?per_page=` (default 25, max 100). Reply:
`{ "success": true, "data": { "data": [ … ], "current_page": 1, "last_page": 3, "per_page": 25, "total": 61 } }`.

### `GET /api/pda/products?search=&barcode=&per_page=`
The business's active products (the catalogue is shared by all its shops) with **this shop's** price and stock.
`barcode` = exact match on any of the product's barcodes (a scan); `search` = part of the name, SKU or a barcode.
```json
{ "success": true, "data": { "data": [
  { "id": "01K5T0Q8C4000000000000P002", "product_name": "Coca-Cola 500ml", "barcode": "5449000000996", "product_code": "COLA-500",
    "cost": "0.9800", "retail": "1.35", "business_retail": "1.45", "has_shop_price": true, "unit_type": "pcs", "unit_code": "PCS",
    "vat_rate_id": "01K5T0Q8C4000000000000V001", "stock_on_hand": "36.0000", "is_active": true }
], "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 } }
```
- `retail` = the price this shop sells at: its own live shop price if it has one, else the business price
  (`business_retail`). `has_shop_price` says which.
- `barcode` = the primary barcode (else the first). `unit_type`: `pcs` | `kg` | `open`.
- `stock_on_hand` = base units as the shop's till last reported them; `null` when the till has not reported stock
  for this product yet.

### `GET /api/pda/resolve-barcode?barcode=`  (multi-unit)
```json
{ "success": true, "matches": [
  { "product_id": "01K5T0Q8C4000000000000P002", "product_name": "Coca-Cola 500ml",
    "product_unit_id": "01K7Z5…", "selected_unit_id": "01K7Z4…", "selected_unit_code": "CASE", "selected_unit_name": "Case",
    "conversion_to_base": "24.0000", "retail_price": "30.00", "purchase_cost": "18.7200",
    "is_base_unit": false, "allow_decimal": false, "decimal_places": 0, "matched_barcode": "5449000054227", "is_primary": false } ] }
```
The new catalogue has real multi-unit data, mapped like the legacy shape:
- a barcode of an extra unit (CASE, BOX…) → that unit (`product_unit_id` set, its conversion and prices);
- a pack barcode of the base unit (`pack_qty` > 1, no unit) → `product_unit_id: null`, `selected_unit_name: "Pack of 6"`,
  `conversion_to_base: "6.0000"`, prices = 6 × the base unit's;
- any other barcode → the base unit: `product_unit_id: null`, conversion `"1.0000"`, `is_base_unit: true`.

`matches` is an array (a barcode may be on more than one product); `[]` when nothing matches. Prices are this shop's
(its own unit price if set, else the business price).

### `GET /api/pda/products/{id}/units`
The base unit first (`product_unit_id: null`, conversion 1), then the product's extra units, same fields as
above (no `matched_barcode`). `{ "success": true, "data": [ … ] }`.

### `GET /api/pda/suppliers?search=&per_page=`
Active suppliers of the business: `{ id, supplier_name, supplier_code, contact_name, phone_number, email, lead_days }`.

### `PUT /api/pda/products/{id}/price`
Only for a user with **can change selling prices** (else `403 PRICE_CHANGE_NOT_ALLOWED`).
```json
{ "retail": "2.49" }
```
`200`:
```json
{ "success": true, "message": "Price updated — syncing to the tills.",
  "data": { "id": "01K5T0Q8C4000000000000P002", "product_name": "Coca-Cola 500ml", "barcode": "5449000000996",
            "previous_retail": "1.45", "retail": "2.49", "shop_retail": "2.49", "has_shop_price": false, "changed": true, "is_synced": false } }
```
- Changes the **business price** of the base unit (`Product.sellPrice`), exactly as the portal's product form does:
  every shop's till picks it up at its next sync pull. Unit prices (CASE, BOX…) and the cost are not changed.
- **Shop prices:** a shop with its own live price keeps selling at it. The reply's `shop_retail` / `has_shop_price`
  show what this shop actually charges (and the message says so). Changing a shop's own price from the handheld is
  phase 2.
- Price format as the product form: up to 2 decimal places, at most **99,999.99** (UK) / **9,999,999.99** (Pakistan);
  no exponents. Else `422 VALIDATION_FAILED` (`errors.retail`).
- `404` for another business's product, `422 PRODUCT_ARCHIVED` for an archived one.

---

## 3. Purchase orders

Purchase orders made from the handheld are **head-office orders** for the user's shop, the same as orders drafted
on the portal: numbered `HO-<shop code>-000001`, sent to that shop's till at its next sync, and editable here only
while the portal still owns them.

Order fields:
```json
{ "id": "01K7Z6…", "reference": "HO-LDS-000001", "order_no": "HO-000001", "origin": "headOffice", "status": "draft",
  "portal_state": "pending", "editable": true, "locked_reason": null,
  "supplier_id": "01K5T0Q8C4000000000000S001", "supplier_name": "Aire Valley Cash & Carry",
  "expected_delivery_date": "2026-10-25", "notes": "Weekly top-up",
  "net_total": "60.96", "vat_total": "7.49", "gross_total": "68.45",
  "total_ordered": "72.0000", "total_received": "0.0000", "total_returned": null, "total_remaining": "72.0000",
  "sent_at": null, "cancelled_at": null, "cancel_reason": null, "created_at": "2026-10-20T09:00:00Z", "updated_at": "2026-10-20T09:00:00Z",
  "items": [
    { "id": "01K7Z7…", "product_id": "01K5T0Q8C4000000000000P001", "product_name": "Warburtons Toastie White Bread 800g", "barcode": "5010044000701",
      "quantity_ordered": "24.0000", "cases_ordered": 2, "case_qty": 12, "quantity_received": "0.0000", "quantity_returned": null,
      "quantity_remaining": "24.0000", "unit_cost": "0.9800", "vat_percentage": "0.0000", "line_net": "23.52" } ] }
```
- `origin`: `headOffice` (drafted on the portal or the handheld) or `branch` (the till's own order).
- `status`: `draft` (the shop sees it but cannot receive it yet), `sent` (placed: receivable on the till),
  `partReceived`, `received`, `invoiced`, `paid`, `cancelled`. Never match statuses by substring.
- `portal_state`: `pending` = the portal still owns it (editable here), `confirmed` = the shop's till owns it (read only
  here), `cancelled`. `editable` / `locked_reason` say whether and why not.
- Quantities are **base units**: `quantity_ordered` = `cases_ordered` × `case_qty`. `unit_cost` is per base unit, ex VAT.
  `quantity_returned` / `total_returned` are `null` until purchase returns come to the app (phase 2).

### `GET /api/pda/purchase-orders?status=&search=&page=&per_page=`
This shop's orders (head-office and the till's own), newest first, with their items. `status` filters on one status;
`search` on the reference / order number.

### `GET /api/pda/purchase-orders/{id}`
One order. `404` for another shop's or business's order.

### `POST /api/pda/purchase-orders`
```json
{
  "supplier_id": "01K5T0Q8C4000000000000S001",
  "expected_delivery_date": "2026-10-25",
  "notes": "Weekly top-up",
  "status": "draft",
  "items": [
    { "product_id": "01K5T0Q8C4000000000000P001", "quantity_ordered": 2, "unit_cost": "11.76", "conversion_factor": 12 },
    { "product_id": "01K5T0Q8C4000000000000P002", "quantity_ordered": 48, "unit_cost": "0.78" }
  ]
}
```
- `quantity_ordered`: whole units of the line's unit (≥ 1). `conversion_factor`: base units per unit (a case of 12 →
  12; default 1). `unit_cost`: cost of one of those units, ex VAT; the portal stores the cost per base unit
  (`unit_cost` ÷ `conversion_factor`, 4 dp) — or send `base_unit_cost` to give it exactly. No cost → the product's
  cost price.
- `status`: `draft` (default) or `sent` (placed with the supplier). VAT comes from each product's VAT rate; totals are
  worked out by the portal. A product may be on the order once. Up to 500 lines.
- `201 { "success": true, "message": "Created as a draft — the shop’s till gets it at its next sync.", "type": "purchase_order", "data": { …order… } }`

### `PUT /api/pda/purchase-orders/{id}`
Same body as create; `items` replaces the lines (lines left out are removed on the till too). Without `status` the
order keeps its status. `409 PO_OWNED_BY_TILL` once the till owns it, `409 PO_CANCELLED` for a cancelled order.

### `POST /api/pda/purchase-orders/{id}/cancel`
`{ "reason": "Supplier out of stock" }` (optional). Cancels it while the portal owns it; the till pulls the
cancellation. Same 409s as edit (cancel a till-owned order on the till).

---

## Phase 2 (answer `501 NOT_AVAILABLE_YET` today)

In contract v1.4.1 these are **owned by the till**, so the portal cannot write them yet. The routes exist and answer
501 with `code: NOT_AVAILABLE_YET`, so the app can show "Do this on the till for now".

- `POST /api/pda/purchase-orders/{id}/receive`, `GET …/{id}/receipts`, `GET …/{id}/returnable` (receiving goods)
- `GET|POST /api/pda/purchase-returns`, `PUT …/{id}`, `POST …/{id}/cancel` (purchase returns)
- `GET|POST /api/pda/stock-adjustments`, `PUT …/{id}`, `POST …/{id}/cancel` (stock adjustments, opening stock,
  location moves, branch transfers out)
- Stock takes.
- `GET /api/pda/stock-locations`: the new data model has **no stock locations** (only a product's `bin_location`
  text), so this route is not offered (404) until the till contract has them.
- Changing a shop's own price, unit prices and costs from the handheld.

## Differences from the legacy API

| Area | Legacy | New portal |
|---|---|---|
| Ids | integers | ULID strings (26 characters) everywhere: `user.id`, `shop_id`, `product_id`, `supplier_id`, order and line ids |
| Money / quantities | JSON numbers (`1.25`) | decimal strings (`"1.25"`, `"0.9800"`); requests accept numbers or strings |
| Errors | `{success:false, code, message}` | same keys + `traceId`, `retryAfterSeconds`, `rejectedKey`; validation is 422 `VALIDATION_FAILED` with `errors`; 401 is `INVALID_CREDENTIALS` / `UNAUTHENTICATED` |
| Login | email + password | same; optional `device_name`; `tenant_id` only on `409 TENANT_REQUIRED` (emails are unique per business, not globally); `user` adds `shop_name`, `shop_code`, `can_change_prices` |
| Products | `cost`, `retail` numbers | strings; `retail` is the shop's price; adds `business_retail`, `has_shop_price`, `stock_on_hand`, `unit_code`, `vat_rate_id`; `unit_type` is `pcs`/`kg`/`open` (not "Pcs"); archived products are not listed |
| Multi-unit | `product_unit_id` always an id | the base unit has `product_unit_id: null` (it has no unit row); pack barcodes come back as "Pack of N" |
| Price change | allowed for every PDA user | only users with "can change selling prices" (else 403 `PRICE_CHANGE_NOT_ALLOWED`); reply adds `shop_retail`, `has_shop_price`, `changed` |
| Purchase orders | drafts that the POS confirms or rejects; `portal_state` pending/confirmed/rejected | head-office orders; `portal_state` pending/confirmed/cancelled (no "rejected": the till takes the order on, then it is read only here); `status` draft/sent/…; lines are whole units with `conversion_factor`; `quantity_returned` null for now; edit/cancel only while the portal owns the order (409 after) |
| Edit/receive/cancel any shop PO | yes | edit/cancel only head-office orders the portal still owns; receiving is phase 2 |
| Returns, adjustments, receiving, stock locations | available | phase 2 (501), stock locations not offered |
| Users | `php artisan pda:create-user` or the admin | the business owner, on the portal (Settings → Handheld (PDA) users) |
