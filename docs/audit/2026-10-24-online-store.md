# Online store audit, 2026-10-24

Scope: the online store as it is on `main` at `409abb7` (OS-1 to OS-7). Read-only on code. Covered: the public store
(`routes/store.php`, `app/Http/Controllers/Store`, `app/Http/Support/Store`, `resources/js/pages/store`,
`resources/js/components/store`, `resources/views/store.blade.php`), `app/Domain/OnlineStore`, portal (builder, shops,
products, orders board), admin add-on and plans, billing hooks (`CompanyPricing`, `InvoiceLineBuilder`), mails, and
`tests/Feature/OnlineStore` (282 tests, all passing).

How it was checked: code reading; the app on a copy of the dev SQLite database (add-on and cloud sync switched on in
the copy only) at 1280 px, 375 px and 360 px; one test order placed; a second instance with `ONLINE_STORE_HOST` set
to check host routing; text contrast measured in the browser; queries per page counted; the owner-approved mockup
(`store-mockup/project/*.dc.html`) compared screen by screen.

Areas: `public-look` (OS-8), `checkout` (OS-9), `emails` (OS-10), `portal`, `admin`, `billing`, `security`, `a11y`,
`perf`, `seo`, `missing-feature`.

What is already good: every public query is scoped to the store's business explicitly, and isolation tests cover
products, shops, orders and signed links across businesses. Prices and names are read again on the server at order
time. Money uses bcmath strings. Slot capacity and order numbers are taken under a row lock with a unique-index retry.
Slots are built on the shop's wall clock, so they stay right across the clock change (checked on 25 Oct 2026). The
CSP uses a nonce, the referrer policy keeps signed order URLs off other sites, uploads are type-checked and get random
names, store-provided text is escaped by React, the default palettes pass contrast, and there is no horizontal scroll
at 360 px.

## Summary

| # | Severity | Area | Finding |
|---|---|---|---|
| OSA-1 | high | security, checkout | Anyone can place unlimited fake orders: they fill every slot and send emails to any address in the shop's name |
| OSA-2 | high | missing-feature, public-look, checkout | No trader details, privacy notice, terms or contact page (UK legal minimum for selling online) |
| OSA-3 | high | missing-feature, public-look | No allergen or mandatory food information before purchase |
| OSA-4 | high | security, checkout | Products under an open recall are still sold online |
| OSA-5 | high | portal, admin | When the add-on goes off, the Online orders board 404s and open orders are stranded |
| OSA-6 | medium | checkout, emails | Customer order links die when the store is taken offline or its address changes |
| OSA-7 | medium | security, perf | Portal data (user email, business status, abilities) is embedded in public store pages |
| OSA-8 | medium | security | With `ONLINE_STORE_HOST` set, `/admin/login` and the portal answer on the shop's domain |
| OSA-9 | medium | seo, security | Every page is `index, follow`, including order pages with customer details |
| OSA-10 | medium | checkout, a11y | The confirmation page opens scrolled to the bottom, so "your order is in" is never seen |
| OSA-11 | medium | public-look | On phones, product names overflow onto the "Each" line |
| OSA-12 | medium | checkout | Quantity is not limited by stock on hand (1 in stock, 99 orderable) |
| OSA-13 | medium | missing-feature, portal | No closed days or holidays: customers can book Christmas Day |
| OSA-14 | medium | emails, portal | With an empty order email, the owner's sign-in email gets the orders and is the reply-to on customer emails |
| OSA-15 | medium | perf, security | Uploads are served as uploaded: up to 4 MB, 8000 px, with EXIF/GPS kept |
| OSA-16 | medium | a11y, public-look | A light brand colour makes text unreadable (measured 1.63:1) |
| OSA-17 | medium | seo | No SSR, meta description, link previews, canonical, sitemap or structured data |
| OSA-18 | medium | missing-feature, checkout, portal | No order notes, no substitution choice, and staff cannot change lines |
| OSA-19 | medium | public-look | Letter tiles instead of photos, and no pack size or unit price (mockup shows both) |
| OSA-20 | medium | a11y | Page changes are not announced and focus is not moved; checkout errors do not take focus |
| OSA-21 | medium | portal, missing-feature | No "not collected" outcome, no undo, no reminder; ready orders wait forever |
| OSA-22 | medium | security | Customer personal data is kept forever; the order link never expires and shows mobile and email |
| OSA-23 | low | checkout | Product availability windows (`availability_from/to`, seasonal) are ignored |
| OSA-24 | low | checkout | A price change between basket and order is not shown to the customer |
| OSA-25 | low | security | Public slot data shows `taken` and `capacity` (the shop's order volume) |
| OSA-26 | low | checkout | "Mobile" accepts any 7–20 digits or symbols |
| OSA-27 | low | security | Unused `token_hash` code; the docs describe a different order-link design |
| OSA-28 | low | perf | About 20–25 queries per public page, mostly the same checks repeated |
| OSA-29 | low | public-look | "About us" is fixed template copy that the shop cannot edit |
| OSA-30 | low | billing | The add-on invoice line is recognised by a guess (no licence and no branch) |
| OSA-31 | low | missing-feature | HFSS placement, deposit return and medicine pack limits are not considered |
| OSA-32 | low | portal | One permission covers both building the store and picking orders |
| OSA-33 | low | checkout | Changing shop at checkout reloads the page and clears the typed details |
| OSA-34 | low | public-look | On phones, the shop can only be changed at checkout |
| OSA-35 | low | portal | `builder.tsx` is 367 lines (rule: about 300) |

Counts: critical 0, high 5, medium 17, low 13.

## Findings

### OSA-1 · high · security, checkout: unlimited fake orders fill every slot and send mail in the shop's name

- **Where:** `routes/store.php` (`checkout.place`), `app/Domain/OnlineStore/Support/StoreRateLimits.php`,
  `app/Http/Requests/Store/PlaceStoreOrderRequest.php`, `PlaceCollectOrder::customer()`.
- **What is wrong:** placing an order needs only a session, a CSRF token and an empty honeypot. The only limit is 6
  orders a minute per IP per store. There is no CAPTCHA (Turnstile is already allowed in the CSP), no check that the
  email or mobile is real, no cap per email, mobile or store, and no cap per day. Each order:
  - takes a place in a slot (default 5 per slot);
  - emails "We've got your order" to whatever address was typed, in the shop's name;
  - emails the shop and adds a bell entry for every staff member.
- **Why it matters:** one IP fills a shop's whole bookable week (about 7 days × 16 slots × 5) in about 90 minutes.
  Real customers then see "no collection times", the board fills with junk, and the shop's name is used to spam third
  parties. That hurts mail reputation for every tenant on the shared sender.
- **Fix:**
  - Turnstile on checkout, invisible and shown only above a risk score.
  - At most 3 open orders per email and per mobile per store, and a per-store hourly cap (config).
  - Fake orders should not hold slots until they are confirmed. Either ask the customer to confirm by email link
    first, or let staff "cancel as spam" in bulk, which frees the slots and sends no email.
  - Log and alert on bursts.

### OSA-2 · high · missing-feature, public-look, checkout: no trader details, privacy notice, terms or contact page

- **Where:** `resources/js/components/store/store-footer.tsx`, `resources/js/pages/store/checkout.tsx` (the 189-line
  file; the only legal text is "By placing the order you agree to collect it…").
- **What is wrong:**
  - The store shows no legal or trading name, registered office, company number, VAT number or contact email.
  - There is no privacy notice where name, mobile and email are collected.
  - There are no terms, no cancellation and returns policy, and no cookie information.
  - The mockup footer has Privacy and Terms links and the mockup checkout says "you agree to the shop's terms". Neither
    is built.
- **Why it matters:** the Electronic Commerce Regulations 2002 (reg. 6), the Consumer Contracts Regulations 2013
  (pre-contract information, cancellation rights for non-perishable goods) and UK GDPR Art. 13 apply to every shop
  using this. A real UK shop expects them out of the box.
- **Fix:**
  - New store settings for the legal name, address, company number, VAT number and contact email. Fill them by default
    from the business record (`companies.legal_name`, address, VAT).
  - Generated pages `/{slug}/privacy`, `/{slug}/terms` and `/{slug}/contact`, from Switch & Save templates the shop can
    add to.
  - Footer links to those pages.
  - At checkout, a short privacy line with a link, and "By placing the order you agree to the terms" with a link.
  - Trader details in the order emails.

### OSA-3 · high · missing-feature, public-look: no allergen or mandatory food information

- **Where:** product page `resources/js/pages/store/product.tsx` (only a free-text "About this product"),
  `online_store_products` (only `description`), `OnlineCatalogueQuery::select()`.
- **What is wrong:** food is sold at a distance with no ingredients, allergens, net quantity or storage information.
  The demo store sells a "BLT Sandwich", which is likely prepacked for direct sale (Natasha's Law), with nothing about
  allergens.
- **Why it matters:** retained Reg. (EU) 1169/2011 Art. 14 requires the mandatory food information, allergens
  included, to be available before the purchase is concluded when food is sold at a distance. The penalties and the
  health risk fall on the shop. Customers with allergies cannot use the store.
- **Fix:**
  - Structured online fields per product: ingredients, the 14 allergens as checkboxes, a "may contain" note, net
    quantity, and storage and use.
  - A "Food information" block on the product page, and an allergen filter.
  - Until those exist: on every food product page, "Ask the shop for allergen information before ordering", with the
    phone number. Also let the shop hide products with no food information.

### OSA-4 · high · security, checkout: products under an open recall are still sold online

- **Where:** `app/Domain/OnlineStore/Support/OnlineCatalogueQuery.php` `rules()` (no rule reads `product_recalls`).
- **What is wrong:** the till syncs `product_recalls` (status, scope, batch), but the catalogue never checks them.
  A recalled product stays shown and orderable.
- **Why it matters:** food safety. An FSA recall is exactly when a product must not be offered. Online it can be
  ordered for days before someone notices.
- **Fix:** add a `recalled` rule: hide any product with an open, not deleted recall (`status` open or active,
  `closed_at` null) for the business, with the reason "Under recall" in `REASONS` so the portal products page explains
  it. Add a test.

### OSA-5 · high · portal, admin: switching the add-on off strands open orders

- **Where:** `app/Http/Middleware/EnsureOnlineStoreOn.php:22` (wraps `app/online-orders/*`),
  `OnlineStoreSwitch::availableFor`, `SetOnlineStoreAddOn`,
  `resources/js/components/admin/billing/online-store-addon-card.tsx`.
- **What is wrong:** the Online orders board, an order's page and the pick list are behind the same middleware as the
  builder. Any of these makes them all 404, even with orders still new, picking or ready:
  - the admin switches the add-on off;
  - the last till loses cloud sync;
  - the business moves to a setup-only plan.
- **Why it matters:** staff can no longer see what to pick or hand over, mark orders collected, or cancel them. Cancel
  is how the customer gets told. Customers turn up for orders nobody can find. The admin card does not warn about this.
- **Fix:**
  - Gate the orders routes on "the business has an online store row with orders", not on `availableFor`. They should
    stay read and work while any order is open.
  - In the admin card, show "N open orders" and ask for confirmation before switching off.
  - Optionally cancel open orders automatically with a reason, so customers are emailed.

### OSA-6 · medium · checkout, emails: order links die when the store goes offline or changes address

- **Where:** `FindStoreBySlug.php:28` (`is_live` required for every store route, the order page included),
  `StoreLinks::orderPage()`, the "Store address" field in the builder.
- **What is wrong:**
  - "Take offline" makes every order page 404, while open orders are still being worked. The builder's dialog says
    only that "orders stay on the board".
  - The signature covers the slug, so changing the store address breaks every link already emailed.
  - The old slug can be claimed by another business at once, so printed QR codes and shared links then open someone
    else's shop.
- **Why it matters:** customers lose their order page, and the "View your order" button in every email breaks.
- **Fix:**
  - Resolve the order page with a resolver that also accepts a store that is offline, but not one whose add-on is off.
  - Sign the order id only (for example `/o/{order}` on the store host), or keep `online_store_slug_history` and
    redirect old slugs with a 301.
  - Hold a released slug for 90 days.

### OSA-7 · medium · security, perf: portal props are embedded in public store pages

- **Where:** `app/Http/Middleware/HandleInertiaRequests.php:60-100`, which runs on `routes/store.php` through the `web`
  group. Also `StorePages::share`.
- **What is wrong:** every store page's `data-page` carries the portal's shared props:
  - `auth.user`, with id, name, email, `email_verified_at` and `two_factor_confirmed_at`;
  - `company` (with its status, for example `overdue`), `companies`, `abilities` (39 for an owner),
    `onlineOrdersNew`, `billingNotice`, `impersonation`;
  - the random `quote` and `country`.

  This was checked by signing in as `test@example.com` and opening `/store/khan-mini-mart`. Anonymous visitors still
  get `name`, `quote`, `twoFactorEnabled` and `country`.
- **Why it matters:** a shared or kiosk computer, screenshots, or any future page cache would expose a business's
  internal state on a public page. The extra props also run the portal's queries on every public request from a
  signed-in user. A public page should carry nothing it does not need.
- **Fix:** give the store its own Inertia middleware (or have `HandleInertiaRequests::share()` return only `errors` and
  `flash` when the root view is `store`), and remove `HandleInertiaRequests` from the store route group.

### OSA-8 · medium · security: portal and admin answer on the shop's domain

- **Where:** `bootstrap/app.php` (`web`, `admin` and `app` route files have no domain), `routes/store.php`.
- **What is wrong:** with `ONLINE_STORE_HOST=shop.localhost`:
  - `GET /admin/login` returns 200 on the shop host;
  - `/app/online-orders` redirects to `http://shop.localhost/login`, which is the store's 404;
  - `/up` answers there too.
- **Why it matters:** the admin sign-in sits on the customer-facing domain (phishing look-alike, and a larger attack
  surface on a host that may later be put behind a CDN). Portal links on that host are broken.
- **Fix:** put `Route::domain(parse_url(config('app.url'), PHP_URL_HOST))` on the web, admin and app groups when
  `online_store.host` is set, or add middleware that 404s anything except store routes on the store host. Add a test.

### OSA-9 · medium · seo, security: every page is indexable, including order pages

- **Where:** `resources/views/store.blade.php:21` (`<meta name="robots" content="index, follow">` on every page).
- **What is wrong:** the order page shows the customer's name, mobile and email. The basket, checkout, search with any
  `q`, error pages and paginated filters are all indexable too.
- **Why it matters:** an order link that ends up in a crawler's reach (forwarded email, analytics, a browser extension)
  can be indexed with personal data. Indexing search and filter pages wastes the crawl and creates thin duplicates.
- **Fix:**
  - `noindex, nofollow` (and an `X-Robots-Tag` header) on order, basket, checkout and error pages, and on search and
    department pages with filters.
  - Keep `index` on home, departments (page 1, no filters) and products.

### OSA-10 · medium · checkout, a11y: the confirmation opens scrolled to the bottom

- **Where:** `resources/js/pages/store/checkout.tsx:50` (`form.post(urls.place, { preserveScroll: true })`).
- **What is wrong:** the redirect to the order page keeps the checkout's scroll position. At 375 px the confirmation
  opened at `scrollY` 1866, on the footer, and the "Thanks …, your order is in" hero was off-screen. Focus stays on
  `body`.
- **Why it matters:** the most important moment of the purchase is never seen. Customers may think the order failed
  and order again.
- **Fix:**
  - Use `preserveScroll: (page) => page.component === 'store/checkout'` (keep the position only when validation fails).
  - On the order page, move focus to the `h1` (`tabIndex={-1}`) when `justPlaced`.

### OSA-11 · medium · public-look: product names overflow on phones

- **Where:** `resources/js/components/store/product-card.tsx:47` (`-my-2.5 line-clamp-2 py-2.5`).
- **What is wrong:** the padding that enlarges the tap target also shows part of the clamped third line. At 375 px,
  "Aunt Bessie's Roast Potatoes 700g" draws "700g" over the "Each" line below.
- **Why it matters:** it looks broken on the most-used screen size, on every listing.
- **Fix:** put the clamp on an inner `<span className="line-clamp-2">` and keep the padding on the link. Or drop the
  negative margin and get the 44 px target from `min-h-11` on the link.

### OSA-12 · medium · checkout: quantity is not limited by stock on hand

- **Where:** `OnlineCatalogueQuery::item()` (`orderable` is `qty > 0`), `OrderLineRules::checkQuantity`,
  `BasketRules::limit`.
- **What is wrong:** with 1 in stock a customer can order 99. Nothing is reserved, so two customers can both order
  the last unit.
- **Why it matters:** staff find out only while picking and must call or cancel, which gives a poor first impression.
  `docs/online-store.md` promises stock from the tills.
- **Fix:**
  - For tracked products, cap the quantity at `floor(shop_qty − open online quantity)`.
  - Optionally keep a small buffer setting per shop ("keep N back").
  - Notice text: "Only 3 left at Leeds".

### OSA-13 · medium · missing-feature, portal: no closed days or holidays

- **Where:** `OnlineStoreShop::hoursOn`, `CollectionSlots`, `SaveStoreShop` (hours by weekday only).
- **What is wrong:** there is no way to close a single date (bank holidays, Christmas, a staff shortage) or to change
  the hours for one day. The only tool is "Taking orders", which closes everything.
- **Why it matters:** customers will book slots on 25 December and on Eid.
- **Fix:** add `online_store_closures` (shop, date, optional special hours, reason). Skip those dates in
  `CollectionDays` and `CollectionSlots` and show "Closed: Christmas Day". Add a "Closed days" list on the Collection
  shops page.

### OSA-14 · medium · emails, portal: the owner's sign-in email is used without saying so

- **Where:** `OnlineOrderMessages::shopAddress()` (`order_email`, else the owner's sign-in email, else the business
  email), used as the recipient of new-order mail and as the `replyTo` of customer emails. The builder's help text
  (`builder.tsx:283`) says "Leave empty to rely on the board and portal notifications only."
- **What is wrong:** the help text is the opposite of what happens. With the field empty, the owner's personal login
  address receives the orders and is given to every customer as the reply-to address.
- **Why it matters:** this is a privacy and expectation problem. It exposes a credential identifier (the login email)
  to the public.
- **Fix:**
  - Use the business contact email (`companies.email`) as the fallback. Never use a user's sign-in email as the
    reply-to.
  - Change the help text to name the exact address the orders will go to.

### OSA-15 · medium · perf, security: uploads are served exactly as uploaded

- **Where:** `app/Domain/OnlineStore/Support/StoreImages.php:72` (`storeAs` of the original),
  `product-photo.tsx` and the banner `<img>` tags (no `width`, `height` or `srcset`).
- **What is wrong:** logos, banners and product photos are kept as uploaded: up to 4 MB and 8000 × 8000 px, with
  EXIF (often GPS from the owner's phone). Grids load the full image for a tile about 170 px wide.
- **Why it matters:**
  - On mobile data, 24 tiles of 2–4 MB each is a page of 50 MB or more.
  - There is layout shift.
  - The location of the photo is leaked.
- **Fix:**
  - On upload, re-encode to WebP with Intervention Image or GD, which also strips metadata.
  - Make 3 sizes (240, 640, 1600 px) and serve them with `srcset`/`sizes`, `width`/`height` and
    `fetchpriority="high"` on the hero.

### OSA-16 · medium · a11y, public-look: a light brand colour makes text unreadable

- **Where:** `text-brand` on white or paper in `home-sections.tsx:25,215,256`, `search.tsx:26`, `product.tsx:120`
  and `butcher-sections.tsx:58`. The builder's colour picker does not check contrast.
- **What is wrong:** `onColour()` fixes text placed on the brand colour, but brand-coloured text on white is not
  checked. With `#F5C518`, the measured contrast for "See all", "All products", the phone link and the about facts was
  1.47–1.63:1 (needs 4.5:1, or 3:1 for large text).
- **Why it matters:** this fails WCAG 1.4.3, and shops do pick yellow and orange.
- **Fix:**
  - Work out a `--color-brand-text` on the server: the brand colour darkened until it reaches 4.5:1 on white and
    paper. Use it for every `text-brand`.
  - In the builder, warn when the chosen colour fails.

### OSA-17 · medium · seo: missing basics for search and link previews

- **Where:** `resources/views/store.blade.php`, no SSR (`config/inertia.php` SSR bundle commented out).
- **What is wrong:**
  - The server HTML `<title>` is the store name on every page (checked on a product page). Product titles exist only
    after JavaScript runs.
  - There is no meta description, no Open Graph or Twitter tags (WhatsApp and Facebook previews show nothing useful),
    no canonical, no sitemap and no JSON-LD (`LocalBusiness` with address and hours, `Product` with price and
    availability).
- **Why it matters:** local shops share their link on WhatsApp and Facebook, and product pages will not rank. The
  roadmap puts SEO in S4, but the OG and title basics are cheap and expected at launch.
- **Fix:**
  - Pass `title`, `description` and `image` per page from the controllers into the Blade view (server side, not
    `<Head>`).
  - Add OG tags with the banner or product photo.
  - Add `/{slug}/sitemap.xml` (home, departments, products) and `LocalBusiness` JSON-LD on home.
  - Consider Inertia SSR for the store bundle only.

### OSA-18 · medium · missing-feature, checkout, portal: no order notes, substitutions or line changes

- **Where:** `PlaceStoreOrderRequest`, `online_orders` (no notes column), `ChangeOnlineOrderStatus` (whole-order
  moves only).
- **What is wrong:**
  - The customer cannot say "no onions, slice thin" or "allow substitutions". The mockup basket has "Allow
    substitutions".
  - Staff cannot remove an out-of-stock line or change a weighed line's weight; they can only cancel the whole order.
- **Why it matters:** these are the most common real-world needs, especially for the butcher template ("cut to
  order"). Cancelling a 20-item order over one missing item loses the sale.
- **Fix:**
  - Add `customer_note` (up to 500 characters) and `substitutions_ok` to checkout and the order.
  - Let staff remove a line or change a quantity (audited; the customer is emailed "we changed your order"), with the
    total recalculated.

### OSA-19 · medium · public-look: letter tiles instead of photos, and no pack size or unit price

- **Where:** `StorePresenter::photos` (online photos only), `product-photo.tsx`, `product-card.tsx`.
- **What is wrong:**
  - With no online photos, all 472 demo products show a coloured letter tile. Photos can only be added one product at
    a time.
  - Cards and product pages show "Each" but no pack size or unit price. The mockup has "5 kg bag · £1.60 per kg".
  - There are no badges, no "Open now" and no opening hours in the footer (all in the mockup).
- **Why it matters:** a grid of letters does not look like a professional shop, and the gap to the owner-approved
  mockup is large.
- **Fix:**
  - Bulk photo upload, matched by barcode or file name.
  - Optionally a licensed GTIN image source.
  - Show size or unit from `net_mass_kg`, `volume_ml` and `unit_code`, and a unit price where one can be worked out.
  - "Open now · closes 17:00" in the header and footer.

### OSA-20 · medium · a11y: page changes are not announced and focus is not moved

- **Where:** the store pages (Inertia client navigation), `StoreLayout`, `checkout.tsx`.
- **What is wrong:**
  - After a client-side visit, focus stays on the clicked link (or `body`), and the new page title and heading are not
    announced.
  - On a failed checkout, focus is not moved to the first invalid field or to an error summary.
  - The day and slot `radiogroup`s use buttons without arrow-key handling (each one is a tab stop).
- **Why it matters:** screen-reader users cannot tell that the page changed or that the order failed (WCAG 2.4.3,
  3.3.1, 4.1.3).
- **Fix:**
  - On `router.on('navigate')`, move focus to `#main` and announce `document.title` through a polite live region.
  - On `onError`, focus the first field with an error.
  - Use real radio inputs, as `ShopChoice` already does, or add roving tabindex.

### OSA-21 · medium · portal, missing-feature: no "not collected" outcome, no undo, no reminder

- **Where:** `OnlineOrderStatus::next()`.
- **What is wrong:**
  - "Collected" and "Cancelled" are final, with no undo for a mistaken tap.
  - There is no "not collected" outcome and no automatic step after the slot passes.
  - The customer gets no reminder before the slot and nothing when the shop gives up.
  - Ready orders stay in "Ready" indefinitely.
  - New orders appear on the board only at its 30-second refresh, with no sound.
- **Why it matters:** these are daily operations for a shop. Chilled stock held for no-shows needs a rule.
- **Fix:**
  - Add a "Not collected" final state (with an email).
  - Allow undo within 10 minutes (audited).
  - Optionally move ready orders to "not collected" automatically N hours after the slot.
  - Add an optional chime on the board when new orders arrive.

### OSA-22 · medium · security: personal data kept forever and an order link that never expires

- **Where:** `online_orders` (name, mobile, email), `StoreLinks::orderPage` (signed with no expiry),
  `pages/store/order.tsx` ("Placed … by {name}, {mobile}"). There is no pruning job (nothing in `routes/console.php`).
- **What is wrong:**
  - Customer details are kept indefinitely.
  - The emailed link opens the order, with mobile and email, for ever, for anyone it is forwarded to.
- **Why it matters:** UK GDPR storage limitation, and the shop (controller) needs Switch & Save (processor) to support
  a retention period.
- **Fix:**
  - A scheduled job that anonymises customer fields N months after collection or cancellation (config, default 24).
  - Expire order links 60 days after the slot.
  - Mask the mobile and email on the public page (`07••• •••123`).

### OSA-23 · low · checkout: product availability windows are ignored

- **Where:** `OnlineCatalogueQuery::rules()`.
- **What is wrong:** `products.availability_from`, `availability_to` and `availability_seasonal_event_id` are not
  read. A product the till sells only in a window or season is offered online all year.
- **Fix:** confirm the meaning in the EPOS contract and add an `unavailable` rule.

### OSA-24 · low · checkout: a price change is not shown to the customer

- **Where:** `PriceBasket::handle`, `PlaceBasketOrder`.
- **What is wrong:** notices cover only removed lines and quantity changes. If the till syncs a new price between the
  checkout view and "Place order", the order is placed at the new price and the button's total was wrong.
- **Fix:** keep the last shown price per line in the basket and refuse the order with "The price of X changed to £Y"
  when it differs.

### OSA-25 · low · security: public slot data shows order volume

- **Where:** `CollectionSlot::toArray()` (`taken`, `capacity`) in the checkout props.
- **What is wrong:** anyone can read how many orders each slot has, which is a business's trading volume.
- **Fix:** send only `available`.

### OSA-26 · low · checkout: "Mobile" accepts almost anything

- **Where:** `PlaceCollectOrder::customer()` with the GB phone pattern `^[0-9+()\s-]{7,20}$`.
- **What is wrong:** "1111111" passes. The field is labelled Mobile, and the shop relies on it to call the customer.
- **Fix:** use a UK mobile pattern (`^(?:\+44\s?7|07)\d{3}\s?\d{6}$`, spaces allowed) with a clear message, or label
  the field "Phone".

### OSA-27 · low · security: unused token code and a docs mismatch

- **Where:** `PlaceCollectOrder.php:71,130` (`random_bytes` token and `token_hash`), `PlacedOrder::token`,
  `docs/online-store.md` ("only its hash is stored").
- **What is wrong:** the order page actually uses a non-expiring HMAC-signed URL tied to `APP_KEY`; the token is
  never used. Rotating `APP_KEY` silently breaks every link already emailed. The 403 page says "This link has
  expired" for any bad signature.
- **Fix:** pick one design. Either use the hashed token in the URL (which survives a key rotation, can expire, and
  works with OSA-6), or remove the token. Update the docs.

### OSA-28 · low · perf: about 20–25 queries per public page

- **Where:** `FindStoreBySlug::handle` (company, licences, plan on every request), `StorePages::store` (department
  counts with a correlated price subquery on every page), `CollectionSlots::taken` (`Company::findOrFail` per day, up
  to 7 times per `next()`).
- **What is wrong:** measured on 609 products: home about 23 queries, department about 25, checkout about 23. Fine
  now, but the correlated price and stock subqueries run once per row on catalogues of 20,000 products. Search is
  `LIKE '%term%'`, and `_` is not escaped.
- **Fix:**
  - Cache the availability check and the department counts per store for 60 s (cleared on sync and save).
  - Pass the company into `CollectionSlots`.
  - Escape `_`.
  - Plan a full-text index later.

### OSA-29 · low · public-look: "About us" cannot be edited

- **Where:** `home-sections.tsx:237`, `theme.ts` `aboutLead`.
- **What is wrong:** the builder offers an "About us" section described as "A short note about your business", but
  there is no field for it. Every store shows the same template sentence.
- **Fix:** add an `about` text (up to 600 characters) to the builder, with the template copy as the placeholder.

### OSA-30 · low · billing: the add-on line is recognised by a guess

- **Where:** `InvoiceLineBuilder::isOnlineStoreLine()` (`licence_id === null && branch_id === null`).
- **What is wrong:** any future line with no licence and no branch (setup fee, PDA, hardware) will be counted as the
  online store in plan-change previews.
- **Fix:** add an explicit `kind` (`licence`, `branch`, `onlineStore`) to the line array and invoice lines.

### OSA-31 · low · missing-feature: HFSS, deposit return and medicine pack limits

- **What is wrong:**
  - Featured products, related products and the basket can promote HFSS products (`is_hfss`). The online location
    rules apply to businesses with 50 or more staff.
  - `is_deposit_item`/`deposit_amount` (DRS from Oct 2027) are not added to the price.
  - GSL painkillers rely only on `max_qty_per_sale` being set.
- **Fix:**
  - A per-business "HFSS rules apply" flag that keeps HFSS products out of featured and related.
  - Show the deposit on the product and add it to the line.
  - Default the online limit to 2 packs for products in a pain-relief category when `max_qty_per_sale` is empty.

### OSA-32 · low · portal: one permission covers the store and the orders

- **Where:** `routes/app.php:484,494` (`company.can:store.manage` on both).
- **What is wrong:** shop-floor pickers need `store.manage` to work orders, which also lets them change collection
  hours and slots for their shop.
- **Fix:** split into `orders.work` (board, pick list, status) and `store.manage` (builder, shops, products).

### OSA-33 · low · checkout: changing shop clears the typed details

- **Where:** `checkout-parts.tsx` `ShopChoice` (`router.post(chooseUrl, …)` without `preserveState`).
- **What is wrong:** the page remounts after the redirect, so the name, mobile and email already typed are lost.
- **Fix:** `preserveState: true`, or reload only `shop`, `days`, `slots` and `lines`.

### OSA-34 · low · public-look: on phones, the shop can only be changed at checkout

- **Where:** `store-header.tsx` (the shop chooser is `lg:flex` only).
- **What is wrong:** a multi-shop customer browses the first shop's prices and stock (by name) until checkout.
- **Fix:** show a compact "Collect from Leeds · change" row under the header on phones when `shopCount > 1`.

### OSA-35 · low · portal: `builder.tsx` is over the file-size rule

- **Where:** `resources/js/pages/app/online-store/builder.tsx`.
- **What is wrong:** 367 lines, against the CLAUDE.md rule of about 300.
- **Fix:** move the brand and sections cards into components under `resources/js/components/app/online-store/`.

## Routing

- **OS-8 (public look):** OSA-11, 15, 16, 17, 19, 29, 34, plus the footer and pages parts of OSA-2 and 3.
- **OS-9 (checkout, delivery, payments):** OSA-1, 10, 12, 18, 20, 24, 25, 26, 33, plus the checkout notice in OSA-2.
  Card payments will make OSA-1 and OSA-12 more urgent.
- **OS-10 (emails, SMTP):** OSA-14, OSA-6 (links), OSA-22 (link expiry), plus trader details in the emails (OSA-2).
- **Not in a wave yet:**
  - OSA-4 and OSA-23 (catalogue rules);
  - OSA-5, 13, 21, 32 (portal and admin);
  - OSA-7, 8, 9, 27 (security);
  - OSA-28 (perf);
  - OSA-30 (billing);
  - OSA-31 and OSA-35.
