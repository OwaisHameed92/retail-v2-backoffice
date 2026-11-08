# Salon / Barber on the portal (W2: sync acceptance)

The till's Salon / Barber part is built on its `feat/salon-barber` branch and **not released**. W2 makes the ordinary
push and pull accept everything a salon's till will send, so nothing is refused on release day, and records the
words, settings and error codes W3 builds on. W2 added no endpoint or page; the five `sync/salon/*` calls, the booking page,
manage link and queue join are W3a (below), the iCal feed W3b. Plan: `docs/PHASES.md` "SSPOS 3 verticals". Decision: `docs/DECISIONS.md`
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

## W3a: the salon calls and the public pages (behind `SALON_ONLINE`, off)

Salon README §3–§11. Everything below is behind the switch `SALON_ONLINE` (`config/salon_online.php`), **default off and
off in production** until the owner says so. Off: every `sync/salon/*` call answers `501 salon.not_available` with
`Retry-After: 3600` (after the sync key check, before the body is read) and every public page is a 404. Decisions:
`docs/DECISIONS.md` "Salon online booking and queue join (W3a)". The iCal feed and the tenant "Salon" page are W3b.

### The five calls (`routes/api.php`, `SalonOnlineController`)

Same auth, `X-SSPOS-*` headers and error body as `sync/*`, then `GuardSalonOnline`: the switch (501), 60 salon calls a
minute per key (`salon_online.per_minute`, 429 `rate.limited`), and only a salon shop's main (or single) till (403
`salon.not_a_salon`; `SalonShops` + `registers.is_main_till`).

| Call | Action | Notes |
|---|---|---|
| `PUT sync/salon/booking-page` | `StoreBookingPage` | gzip or plain, ≤ 8 MB unzipped (413 `salon.page_too_large`); checked by `BookingPageReader` (422 `request.invalid`, `rejectedKey` = path); whole page per shop in `salon_online_shops.page` without the key; `manage_key` encrypted; `stored` / `unchanged` (same content or older `generatedAt`); replies `bookingUrl`, `queueJoinUrl`, `manageUrl` |
| `PUT sync/salon/heartbeat` | `RecordSalonHeartbeat` | `last_heard_at` = our clock; flags (`accepting`, `queueJoinOpen`, `pendingRequests`, `offlineAfterMin`) only from a newer `at`; `{result: ok}` |
| `GET sync/salon/queue-joins?max=20` | `QueueJoinsForTill` | unanswered joins of the shop, oldest first, `max` 1–100, `hasMore`; joins older than 15 minutes are dropped first |
| `POST sync/salon/queue-joins/{joinId}/answer` | `AnswerQueueJoin` | `joined` / `refused`, kept whole; the same body again → 200; unknown, another shop's or dropped → 404 `salon.join_not_found` (`rejectedKey` = id); body `joinId` must match |
| `PUT sync/salon/queue` | `StoreQueueState` | today's phone joiners' places; newest `at` kept (`stored` / `unchanged`); no names or phones in it |

Tests: `tests/Feature/Salon/SalonOnlineApiTest.php`, `SalonQueueJoinTest.php`; every reply is also checked against the
pack's schemas and codes by `ContractReplyGuard` (salon folder added to its proposal lists).

### Public pages (`routes/salon.php`, `resources/js/pages/salon-public/*`)

`ResolveSalonShop` finds the shop by slug (404 when the switch is off, the address is unknown, no page is published,
the shop or business is closed, or the shop is no longer a salon). The shop's name, phone and addresses come from the
page and the branch; never the manage-link key. Inertia pages share only the minimal props (as the online store).

| Address | Page | What it does |
|---|---|---|
| `/book/{slug}` (`?src=`, `?embed=1`) | `book` | services by category with options (per-stylist price and minutes), add-ons, client-group filter, stylists + Any (+ any female / male when the team has both), day / time picker from `/book/{slug}/times`, details (name, phone, e-mail, the shop's questions, patch test when needed, terms, three marketing boxes never pre-ticked). Offline: "Request this time — the shop will confirm"; closed: the shop's phone |
| `POST /book/{slug}` | (JSON) | `RequestOnlineBooking`: re-checks the time against the published ranges, links / creates the Customer first, writes the pending `SalonOnlineBooking` (`OnlineRequestWriter`), records `src`; 201 `{redirect}` |
| `/book/{slug}/r/{requestId}` | `request` | polls every 5 s: pending (never "booked"), accepted (with the manage link), declined (the till's reason), withdrawn; "Withdraw my request" while pending |
| `/book/{slug}/m/{appointmentId}.{token}` | `manage` | `ManagedAppointment`: token checked, the appointment as pushed; move (`/times`, `/move`) and cancel (`/cancel`) until `changeWindowHours` before, else "Please ring the shop"; marketing choices (`/preferences`) any time. Each a new request (`RequestAppointmentChange`) |
| `/book/{slug}/queue` | `queue` | `JoinSalonQueue` with the desk's rules; offline / paused: "please ask at the desk" |
| `/book/{slug}/queue/{joinId}` | `queue-position` | polls every 20 s: sent, waiting (ticket, place, minutes), called ("Please come to the desk now"), in service, done, refused (the till's message), dropped |

Free times: `Availability\FreeTimes` + `PageMenu` + `LondonClock` (Europe/London, the clock-change hour never offered),
holds from `Online\SalonBookingHolds`. Public writes are JSON without session or CSRF (they work in another site's
frame), rate limited per visitor and shop (`SalonPublicRateLimits`), each with the form's own key (a double tap is one).
`embed=1` lets any site frame the booking page and its request page only (`SecurityHeaders::FRAME_ANY_SITE`).

Tests: `SalonFreeTimesTest` (one stylist, Any, basket grid, notice, DST days, holds), `SalonBookingFlowTest` (request →
pull → accept / decline, offline, closed, withdraw, double tap, customer match, `src`), `SalonManageLinkTest`,
`SalonQueueJoinTest`, `SalonPublicPagesTest` (switch off 404, framing, rate limits, nothing in the log, the pack's
checklist §17 as far as the portal can prove it).

### Before a real salon is connected (README §17)

Proven by tests: 1 (501 until on; push / pull carry on), 2 (stored, addresses, `unchanged`), 3–4 (request pulled; the
till's accept / decline shown; a clash stays pending on the till), 5 (withdraw → `cancelled`; after an answer refused),
6 (offline → request only, answered when back), 7 (token, window, preferences), 8 (join → ticket, twice → one, refusal
shown, called), 9 (a grocery's key → 403; no salon row in its pull). Needs a real till: auto-accept within a minute, the
till's `SyncConflict` on a late withdrawal, Consent rows written on accept, the till's own texts. 10 (iCal) is W3b.

### Points to watch

- `request.invalid` is 422 on the salon calls (salon README) but 400 elsewhere in the contract.
- The pack's own sample ids (`CU001`) are not valid ULIDs; the portal's push validator refuses such ids.
- The shop's own questions may invite health answers ("Any allergies?"); the page asks clients to keep medical details
  for the shop. The answer is stored only on the request row the contract defines.

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
