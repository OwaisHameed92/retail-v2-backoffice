# Salon / Barber on the portal (W2: sync acceptance)

The till's Salon / Barber part is built on its `feat/salon-barber` branch and **not released**. W2 makes the ordinary
push and pull accept everything a salon's till will send, so nothing is refused on release day, and records the
words, settings and error codes W3 builds on. No new endpoint or page in W2: the five `sync/salon/*` calls, the booking page,
manage link and queue join are W3a; the iCal feed and the Salon page are W3b (below). Plan: `docs/PHASES.md` "SSPOS 3 verticals". Decision: `docs/DECISIONS.md`
"Salon / Barber sync acceptance (W2)". W1 (pharmacy, BikeBook): `docs/verticals-0.1.85.md`.

## The contract pack

`docs/contracts/salon-barber-2026-11-08/docs/web-portal-api/` is the EPOS salon pack as sent. It is an overlay on the
released 0.1.85 pack: its files win, the rest is read from 0.1.85 (`definitions.php` `overlay`,
`Generator\ContractFiles`; tests `TillFixtures::SALON`, `ContractSchema::SALON`). See the pack's own `README.md`.

## Tables

`php artisan till:entities:generate` (release `salon-2026-11-08`) wrote one additive migration,
`2026_12_15_100000_create_till_salon_2026_11_08_tables.php`: 40 new `salon_*` tables, no column on any existing table
(CashMovement and Consent only gain list values). Hand-written `2026_12_15_100002_add_salon_pull_feed_indexes.php` adds
`(company_id, hub_version)` on the 13 hub-owned tables for the pull feed. Both run on SQLite and MySQL 8. Overrides:
`app/Domain/TillData/definitions-salon.php` (merged into `definitions.php`).

| Ownership | Tables | On the portal |
|---|---|---|
| Hub-owned (13) | SalonServiceDetail, SalonLevel, SalonServiceLevelPrice, SalonServiceAddOn, SalonResource, SalonCommissionPlan, SalonCommissionTier, SalonCommissionCategoryRate, SalonCommissionFixedService, SalonStampCardRule, SalonPackageDefinition, SalonConsultationFormTemplate, SalonOnlineBooking | stored like Product (hub bookkeeping, content hash, conflicts); pulled to salon shops only; SalonResource and SalonOnlineBooking are one shop's (branch scope) |
| Branch-owned (27) | SalonStaffProfile, SalonStaffService, SalonBlock, SalonSaleLineStaff†, SalonLineStaffCorrection†, SalonTip†, SalonTipShare†, SalonTipPayout†, SalonRenterAgreement, SalonRenterStatement, SalonQueueEntry, SalonQueueEntryService, SalonAppointment, SalonAppointmentService, SalonRepeatRule, SalonWaitlistEntry, SalonClientCard, SalonTechnicalRecord, SalonClientPhoto, SalonConsultationAnswer, SalonPatchTest, SalonStampCard, SalonStampEvent†, SalonPackageHolding, SalonPackageUse†, SalonMessageTemplate, SalonMessageOutboxItem | stored, read-only; † append-only = historic from the start (`immutable always`): a later change is kept out and recorded as a conflict |
| Local (1) | SalonClientMedical (health and allergy answers) | never stored: a push of one is acknowledged as skipped (no table, nothing in `till_unknown_rows`, no value logged) |

Column rules as ever: ULID ids as sent, money `decimal(12,2)`, costs and quantities `decimal(14,4)` (SalonSaleLineStaff
`costAmount`, `quantity`), rates `decimal(9,4)` (commission, rent, card-fee and share percentages), UTC `dateTime`,
`*Json` members verbatim in `longText`, notes / message bodies / formulas in `text`. Two reserved MySQL words are
renamed: `SalonLevel.rank` → `level_rank`, `SalonRepeatRule.interval` → `repeat_interval`. Indexes by shop and time
(appointments, blocks, tips, payouts, line staff, queue, outbox, package uses, stamp events), by client (cards,
records, photos, patch tests, stamp cards, holdings) and by sale.

The 34 salon lists of `samples/enums.json` are generated enums (`SalonAppointmentStatus`, `TipSplitMode`…). New values:
`CashMovementType` `tipTaken` / `tipPayout`; `ConsentSource` `import` / `onlineBooking` (worded "Imported from another
salon system" / "Online booking" on the customer page).

## Which shops are salons

`Salon\Support\SalonShops`: the business type is Salon, or a live till setting says so (`vertical.salon` = true or
`setup.business_type` = `Salon`), for the whole business (company scope) or that shop (branch scope). The wizard tile
says "Salon / Barber"; the value stays `Salon`.

## Pull: salon shops only

`Salon\Support\SalonPull`, used by `PullFeed`, `PullVisibility` and `PullEnvelopes`. To a shop that is not a salon:
no Salon* table in its feed at all, no `salon.*` or `vertical.salon` setting, no `salon.*` right, no `salon.*` entry in
a Role's `permissions`, no salon departure. A business with no salon therefore pulls exactly what it pulled before (a
test pulls a grocery before and after the salon next door sends salon rows, settings and rights: byte-identical). To a
salon: the company-wide salon hub rows like Product (in apply order: plan → tiers / rates / levels → service rows →
requests), a shop's own SalonResource to that shop.

## SalonOnlineBooking: who writes what

`Salon\Support\SalonBookingFields` (README §5). The portal writes the request (id, reference, kind, requested start and
stylist, services, client name / phone / e-mail, customerId, notes, answers, terms time, marketing ticks, deposit and
payment reference, expiresAt) and status `pending` / `cancelled` / `expired`. The till writes its answer: appointmentId
(for `kind: new`), decidedAt, decidedByUserId, decisionReason and status `accepted` / `declined`.

- **Pull:** a portal-written request goes to the shop it names, never to another shop, never when it names none.
- **Push:** a till push carrying an answer (`accepted` / `declined`) is always applied, whole, even over a portal change
  made after the till read the request (EntityWriter skips its hub-conflict check for it: "take the till's version").
  It is then the till's (`origin_branch_id`), so it is never sent back to that till. Any other till change follows
  the ordinary hub rules (a newer portal edit wins and the till's change is recorded as a conflict).
- **Portal writes** (`Salon\Concerns\SalonOnlineBookingRules`, for W3's booking page): refused
  (`SalonBookingRefused`) when setting an answer status, the till's members (appointmentId on `kind: new`), no shop or
  another shop, or changing / deleting a request that is no longer pending.

## Money and reports

- **Tips** are never in `SalePayment.amount`: the takings formula is unchanged and SalonTip rows touch no sales figure.
  Cash tips are drawer movements: the shift's cash movements show "Tips taken" (positive; a refunded tip negative) and
  "Tips paid out" (negative), with the till's sign.
- **Package session or stamp reward** = a line discount with `discountSource: promotion`: already counted in the
  discount total, not as a staff or manual discount (no anomaly flag). Nothing to change.
- **Chair rent** (4200, "VAT box 6"): the VAT return helper adds the net of account 4200 (journal entries dated in the
  quarter) to box 6, and the VAT those same entries post to the till chart's box-1 account(s) to box 1
  (`Accounts\Support\ChairRent`); shown as its own source line only when there is chair rent. 2280 / 2290 / 2300 are
  liabilities and only read from the till's journal. `SalonPackageSold` / `SalonPackageRelease` touch no VAT box (the
  value counts once, at the sale).
- **Renter lines:** not changed; see the EPOS questions below.

## Settings, rights, audit, errors

- Till settings page: read-only sections "Salon / Barber", "Salon diary and queue", "Salon messages", "Salon online
  booking" (`app/Domain/ShopSettings/salon.php`, every shared `salon.*` key of the pack) and `vertical.salon` under
  "Shop type", for salon shops only. The local-only and secret keys (`salon.queue.board_*` / `checkin_*`,
  `salon.integrations.meta_access_token`, `salon.integrations.sms_secret`, `salon.online.manage_link_key`) are
  dropped on push and never stored (`SettingSyncPolicy` equals the pack's `settings-local-only.json`).
- The portal never writes `vertical.salon` or any `salon.*` key (`SettingSyncPolicy::TILL_WRITES_ONLY_PREFIXES`).
- Rights: words for the 11 `salon.*` rights (`Salon\Support\SalonRights`), shown in the role editor only when a till
  sent them.
- Audit: words for the 39 salon audit actions (`resources/js/components/app/sales/salon-words.ts`).
- Errors: `Salon\Support\SalonApiErrors` (`salon.not_available` 501 + Retry-After 3600, `salon.not_a_salon` 403,
  `salon.page_too_large` 413, `salon.join_not_found` 404), for W3.

## W3b: staff calendar feed and the Salon page (behind `SALON_ONLINE`)

Switch: `SALON_ONLINE` → config `salon.online` in `config/salon.php` (default false; off in production until the salon
release). `Salon\Support\SalonOnline::enabled()` / `availableFor($company)` (= switch on and `SalonShops::anySalon`).
Decision: `docs/DECISIONS.md` "Salon staff calendar feed and Salon page (W3b)".

### Staff calendar feed (iCal), README §12

- **Address:** `GET /salon/ical/{branchId}/{userId}/{feedToken}.ics` (`routes/salon-ical.php`, no web middleware: no
  session or cookie; `throttle:salon-ical`, `salon.ical_per_minute` = 60 per IP). `userId` is the stylist's till user id.
- **Table** `salon_calendar_feeds` (portal-owned, tenant-scoped, never synced): company, shop, `user_id`, `token_hash`
  (SHA-256) + `token_last4`, `enabled`, `issued_at`, `revoked_at`, `last_fetched_at` (stamped at most every 15 min),
  `created_by_user_id`. One row per stylist and shop.
- **Token** (`Salon\Calendar\FeedToken`): 43-character base64url of 32 random bytes, in the JSON reply only, with the
  `webcal://` form and a server-made QR SVG (`FeedLink`). New address = new hash (old 404s at once); off = hash cleared.
  Audit `salon.calendar_feed_enabled` / `_regenerated` / `_revoked` with shop and stylist, never the token.
- **Content** (`Salon\Calendar\StylistCalendar`, `ICalWriter`): one VEVENT per SalonAppointmentService of that shop
  where the person is stylist or assistant; 7 days back to 60 ahead; no-shows out; cancelled kept 7 days after
  `cancelledAt` as `STATUS:CANCELLED`; `UID` `{appointmentServiceId}@sspos`; `SUMMARY` "Service – FirstName"; times
  UTC `Z`; CRLF, TEXT escaping, 75-octet folding that never splits a UTF-8 character. No phone, e-mail, surname, notes,
  price, deposit or health data. `Cache-Control: private, max-age=300`.
- **404, always the same:** switch off, malformed / unknown / off / renewed token, another shop or stylist, closed shop,
  shop no longer a salon, cancelled business (`Salon\Actions\BuildCalendarFeed`).

### Tenant "Salon" page (`/app/salon`)

- `salon.manage` (owner, manager); menu item and role-matrix row only for a business with a salon shop while the
  switch is on; routes behind `EnsureSalonOnline` (404). A one-shop manager sees and changes only their shop.
- Per salon shop: the till's stylists (SalonStaffProfile, plus anyone whose feed is still on after leaving the till)
  with a "Calendar feed" switch, "Give it a new address" and "Turn the feed off" (confirmed). `POST /app/salon/feeds`
  `{shopId, userId}` (on / new address, JSON with the address shown once), `DELETE /app/salon/feeds/{feed}` (off).
- Today's appointments (Europe/London day, not cancelled or no-show), requests waiting.
- Online booking requests (SalonOnlineBooking), read only: reference, kind, status, time asked for, first name, shop;
  search and status filter.
- **W3a slot:** the "Online booking" card renders the `onlineBooking` prop (`SalonPage`, null today, "Soon" state):
  W3a sets it to `{accepting, lastHeardAt, bookingUrl, queueJoinUrl}` from its heartbeat and booking page.

### Admin

The tenant page shows a read-only "Salon" card (`Salon\Queries\SalonAdminSummary`) for a salon business while the
switch is on: salon shops, stylists, calendar feeds on, online requests this week (from Monday) and waiting.

## Questions for EPOS

- **Q-S1 Renter lines.** README: "Renter lines move out of sales and VAT into 2290 through the ledger." A renter's line
  is an ordinary SaleLine with a `SalonSaleLineStaff` row (`isRenterLine` true). Not decidable from the data: (a) is
  the renter line's VAT in the sale's `SaleVat` rows (it is the renter's supply, not the shop's)? (b) a line shared by
  a renter and an employed assistant (`sharePercent`) — which share leaves sales? (c) should the renter's takings stay
  in shop takings (the shop's card machine and drawer took the money, held on 2290)? Until answered, renter lines
  stay in our sales and VAT figures as sent, like any line.
- **Q-S2 Card tips in the card reconciliation.** In a salon a card `PaymentAttempt.amount` includes the tip. Does
  `ShiftTender.expected` for a card type include card tips? If not, our card reconciliation shows the day's card tips as
  "settled more than taken".
- **Q-S3 Chair rent VAT.** We take box-1 VAT on chair rent from the till chart's box-1 account lines of the entries that
  touch 4200. Confirm the till posts `SalonRenterStatement.rentVat` there (and where the card-fee recharge and its VAT
  go).
- **Q-S4 SalonOnlineBooking `expired` from the till.** README §2 lets both sides mark `expired`; the W2 brief lists only
  `accepted` / `declined` as till answers. A till's `expired` follows the ordinary hub rules (a newer portal change wins).
