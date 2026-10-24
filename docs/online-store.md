# Online store

One-click online shop for a retail business (owner 2026-10-23). Two templates for now (Fresh Market, Butcher's Block). Products, prices and stock come from what the tills
already sync. Design mockup (13 screens; the Fresh Market and Butcher templates are built):
https://claude.ai/artifact/FdsDJZ2vyhQJw5hfPmUiNz. DECISIONS "Online store, click & collect first".

## Stage S1: click & collect, pay at the shop (this build)

- **Turn on.** The admin switches the add-on on per business and picks the template. Only businesses on a monthly
  plan (cloud sync + Direct Debit) can have it; a setup-only business moves to monthly first. The default price is
  £20 + VAT a month, changeable per business, billed as its own "Online store" line on the same monthly invoice. Live only in the UK for now: the
  country flag `onlineStore` is off on PK. The `ONLINE_STORE` switch is off by default.
- **Part of plans (OS-7, owner option B).** A monthly/yearly plan can include the online store (`plans.includes_online_store`,
  its own `online_store_monthly_price`, blank = £20). A new business on such a plan gets the add-on switched on with
  the template chosen in the wizard (partner onboarding: Fresh Market); Change plan switches it on (template asked when
  the business has no store) or off (also an add-on switched on by hand) with the plan, and the preview says so. Price
  order: the business's own > the plan's > the default. Still one "Online store" line per business on the same invoice
  and Direct Debit. The Billing tab card stays as the exception path. Refused while `ONLINE_STORE` is off.
- **Templates** (owner 2026-10-24): two light looks, `freshMarket` (grocery, convenience) and `butcher` (Butcher's
  Block). The Care Pharmacy template was dropped for now (a migration moves any `pharmacy` store to Fresh Market; an
  unknown stored value reads as Fresh Market). Switch & Save sets the template when switching the add-on on; the
  business cannot change it. Each template has its own fonts, colours, default brand colour (forest green, oxblood)
  and default banner photo; the store's own colour and banner win. All pages are shared; only the home sections'
  look changes (`resources/js/components/store/theme.ts`, `butcher-sections.tsx`).
- **Store builder** (portal → Online store, `store.manage`). Only once Switch & Save has switched the store on
  (before that it says "not switched on yet"). Settings:
  - the look, read only ("set by Switch & Save"); logo, main colour, banner photo, headline;
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
- **Emails (OS-10, portal → Online store → Emails).** Customer emails (received, ready, cancelled, and any later
  "customer" online store email) are the shop's own:
  - From name: the store's sender name, else the business name; never "Switch & Save" (refused and ignored).
    Reply-To: the store's reply-to, else the order email, else the business email. Never a user's sign-in email
    (OSA-14); the new-order email to the shop goes to the order email, else the business email, else nobody.
  - Look: the store's logo and brand colour (header bar, buttons, links; a readable text colour is worked out),
    shop name, address and phone in the footer, and a small "Online store by Switch & Save" line. Plain-text part too.
    Previews on the Emails tab.
  - Own SMTP server (optional): host, port (25, 465, 587, 2525), TLS / SSL / none, username, password, From address.
    The password is encrypted at rest, never sent to the browser ("saved, ending ••1234"), blank keeps it. The host
    must be public (SSRF guard, checked again and pinned at send time). Used only after a test email gets through
    ("Send a test email", 5 per 10 minutes, to the signed-in user). If it fails at send time, the reason is shown on
    the Emails tab and the email goes through the platform mailer in the shop's name.
  - The new-order email to the shop stays on the platform mailer and the Switch & Save look.
- **Security.**
  - Public routes are rate limited and have CSRF and a honeypot.
  - A public request resolves the business from the slug and scopes every query to it explicitly. There is no
    logged-in user, so the global scope cannot be relied on.
  - Tests prove that store A never shows or accepts business B's products, shops or orders.

## OS-8: the public store's look (2026-10-24)

- **Colours.** The store's one colour (or the template's) becomes a full set of colours on the server
  (`BrandPalette`): buttons, links, prices, chips, focus rings, header strip, footer, phone bar and toasts. Text is
  always readable (WCAG AA): a light colour gets dark text, and links and prices use a darker shade. Fresh Market keeps
  its lime highlight with a green or grey colour, else a light shade of the store's own colour; the butcher keeps tan.
  The builder says when a colour is light.
- **Banners** (`online_store_banners`): up to 6, each with a photo, headline, short text and a button to a department,
  a product, a search or an https address; on or off, in order. They take turns at the top of the home page (pause on
  hover or focus, pause button, arrows, dots, swipe; no auto-advance with reduced motion). The old single banner and
  headline moved into the first banner.
- **Featured products:** best sellers (quantity sold in the last 30 days at the collection shops, from
  `rpt_product_daily`; newest until there are sales), hand-picked (up to 24, in order) or newest.
- **Optional home rows** (off until turned on in "Home page sections"): "On offer" (till offers live today for everyone,
  all day, at the shop; the offer shows on every product card, and the till takes it off at collection) and "Rows by
  department" (chosen departments, else the first three). "About us" has its own text.
- **Photos:** the online photo, else the master catalogue picture of one of the product's barcodes, else a branded
  placeholder with the department's icon. Department tiles use a product photo from the department, else an icon.
  Cards show pack size and price per kg or litre from the till's weight and volume.
- **Food information:** "Ingredients & allergens" per product (Products page), else the tills' allergen data, shown on
  the product page before ordering with "check the label, ask the shop".
- **Search engines and sharing:** title, description, Open Graph, canonical and JSON-LD written on the server;
  `noindex` (meta and `X-Robots-Tag`) on basket, checkout, order, error, search and filtered pages; `/{slug}/sitemap.xml`
  and `/{slug}/robots.txt`. Store pages carry only the store's own shared props, never the portal's.

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
| `online_store_products` | Per product (`product_id`, the till's id): hidden, photo path, description, ingredients and allergens |
| `online_store_banners` | OS-8: home banners (photo, headline, text, button link, on/off, order) |
| `online_orders` | Number, shop, status, customer name / mobile / email, slot, item total `decimal(12,2)`, token hash, cancel reason, status times |
| `online_order_lines` | Product id + name / price snapshot, quantity `decimal(14,4)`, line total `decimal(12,2)` |
| `online_store_mail_settings` | OS-10: per store, sender name, reply-to, own SMTP server (password encrypted + last 4), verified time, last error |

Till tables (products, prices, stock) are read only and never changed.

## Build waves

| Wave | Modules |
|---|---|
| E1 | OS-1 data, actions, catalogue query, order flow (no UI) |
| E2 | OS-2 public store (Fresh Market) · OS-3 portal store builder + products · OS-4 orders board, emails, notifications |
| E3 | OS-5 Butcher template, read-only look in the builder, mobile polish · OS-6 admin add-on switch + billing line |
