# Online store

One-click online shop for a retail business (owner 2026-10-23). Two templates for now: Fresh Market (grocery, convenience) and Butcher's Block; the pharmacy template was dropped by the owner on 2026-10-24 (maybe later). Products, prices and stock come from what the tills
already sync. Design mockup (13 screens, three templates):
https://claude.ai/artifact/FdsDJZ2vyhQJw5hfPmUiNz. DECISIONS "Online store, click & collect first".

## Stage S1: click & collect, pay at the shop (this build)

- **Turn on.** The add-on is switched on per business from the admin area. The default price is £20 + VAT a month,
  and it can be changed per business. UK monthly fees go by Direct Debit, so the add-on is added to that business's
  monthly amount. It needs cloud sync, because the products come from the tills. Live only in the UK for now: the
  country flag `onlineStore` is off on PK. The `ONLINE_STORE` switch is off by default.
- **Store builder** (portal → Online store, `store.manage`). Settings:
  - template (`freshMarket` or `butcher`), logo, main colour, banner photo, headline;
  - home sections on or off, in order;
  - the shops customers can collect from, with opening hours, how many minutes it takes to get an order ready, and
    how many orders per slot;
  - a "Taking orders" switch;
  - per product: show online or not, a photo and a short description;
  - the store's address `/{slug}`.

  Publish makes the changes live.
- **Public store** at `https://{ONLINE_STORE_HOST}/{slug}` (without a host: `/store/{slug}` on the app host).
  - Pages: home (the template), department, search, product, basket, checkout, and the order page.
  - The customer chooses the collection shop (only if the business has more than one), then a day and a slot.
  - Guest checkout: name, mobile and email. No customer accounts in S1.
  - Pay at the shop. The shop rings the order up on the till when the customer collects. That till sale takes the
    stock off and records the money, so the online order never writes till data.
- **What is shown.**
  - Shown: active products that are not archived, deleted, merged or a variant parent, and that have a price at that
    shop (the shop's own price, else the business price).
  - Never shown: anything with an age rule, alcohol, tobacco, lottery, knives, banned items, vapes, and pharmacy
    P / POM medicines (GSL only).
  - Stock-tracked products with stock of 0 or less show "Out of stock" and cannot be added.
  - `max_qty_per_sale` is respected.
  - Weighed products are sold in kg steps. The customer sees an estimated price ("final price on the scale"); the
    till weighs and charges at collection.
  - Promotions are taken off by the till at collection. S1 only shows the shelf price.
- **Orders.**
  - Numbers are W-0001 onward, per business.
  - Order stages: new → picking → ready → collected, or cancelled with a reason.
  - Staff work them on the portal → Online orders board.
  - New orders send an email to the business's order address, plus a portal notification.
  - The customer gets emails when the order is received, when it is ready to collect, and if it is cancelled.
  - The order page is opened by a secret link (only its hash is stored).
- **Security.**
  - Public routes are rate limited and have CSRF and a honeypot.
  - A public request resolves the business from the slug and scopes every query to it explicitly. There is no
    logged-in user, so the global scope cannot be relied on.
  - Tests prove that store A never shows or accepts business B's products, shops or orders.

## Later stages

- **S2:** card payment with DNA Payments (hosted payment page, webhook, money to the shop's DNA account) and local
  delivery (area, fee, free-over, slots).
- **S3:** online orders on the till (WebOrder, needs the EPOS contract), promotions online, customer accounts and
  "order again".
- **S4:** own domain, SEO, analytics.

## Data (portal-only tables, ULID keys, `company_id`, `BelongsToCompany`)

| Table | Holds |
|---|---|
| `online_stores` | One per business. Slug (unique across all businesses), template, brand (logo, colour, banner, headline), sections, live / taking orders, order email, add-on price override |
| `online_store_shops` | Collection shops (`branch_id`) with hours, prep minutes, orders per slot |
| `online_store_products` | Per product (`product_id`, the till's id): hidden, photo path, description |
| `online_orders` | Number, shop, status, customer name / mobile / email, slot, item total `decimal(12,2)`, token hash, cancel reason, status times |
| `online_order_lines` | Product id + name / price snapshot, quantity `decimal(14,4)`, line total `decimal(12,2)` |

Till tables (products, prices, stock) are read only and never changed.

## Build waves

| Wave | Modules |
|---|---|
| E1 | OS-1 data, actions, catalogue query, order flow (no UI) |
| E2 | OS-2 public store (Fresh Market) · OS-3 portal store builder + products · OS-4 orders board, emails, notifications |
| E3 | OS-5 Butcher template, mobile polish · OS-6 admin add-on switch + billing line |
