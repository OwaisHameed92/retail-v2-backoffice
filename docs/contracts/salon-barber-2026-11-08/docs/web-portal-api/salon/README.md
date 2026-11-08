# Salon / Barber online booking and queue join — contract (till side)

**For:** the web portal team. **From:** the SSPOS EPOS till team. **Date:** 2026-11-07.
**Builds on:** contract v1.4.1 (`docs/web-portal-api.md`: §3 transport, §5–§8 push / pull, §9 errors, §10 ownership,
§17.12 rate limits, §19 never twice / never echoed / never backwards) and the product-pictures pack
(`docs/web-portal-api/product-images/`, same call style).
**Status:** PROPOSAL, till side built (branch `salon/cloud`, not released; `tasks/P11-01.md` §I). Additive only:
`X-SSPOS-Contract` stays `1`; no v1.4.1 endpoint, entity, member or code changes. Five new calls under `sync/salon/`,
error codes in `error-codes.additions.json`, schemas in `schemas/`, samples in `samples/` (generated from the till's own
types by `tests/SSPOS.Infrastructure.Tests/Sync/PortalContract/PortalSalonPack.cs`).
**Until the portal switches it on**, answer every `sync/salon/*` call `501 salon.not_available` with `Retry-After: 3600`
(after the key check, before the body is read). The till then waits an hour and asks again; nothing else changes.

Only shops whose type is **Salon / Barber** ever call these, and only from their **main (or single) till**, with cloud
sync on. Every other shop type sends exactly what it sent before; a grocery never calls `sync/salon/*`.

---

## 1. The idea in ten lines

1. **The till is the diary of record.** The portal never writes an appointment.
2. The till **publishes the booking page** (`PUT sync/salon/booking-page`): the page's rules, the services and stylists
   clients may book, and the **free start times of the next N days** worked out by the till's one availability engine.
   The portal shows the page from that, even while the till is off.
3. A client books → the portal writes a **`SalonOnlineBooking` row** (`status: pending`) for that shop and sends it in
   the shop's **pull**, like any portal-owned row. It holds the requested time as *pending* on the page until
   `expiresAt`.
4. The till **answers on the row**: `accepted` (with the `appointmentId` it made) or `declined` (with a reason), and
   pushes it back in the normal **push** (plus the new `SalonAppointment` rows). The portal confirms to the client.
5. The till accepts **automatically** when the shop has "Accept online bookings automatically" on **and** the time is
   still free; otherwise the request waits for the front desk. **A clash is never booked through automatically.**
6. The till sends a **heartbeat** every minute (`PUT sync/salon/heartbeat`). No heartbeat for the page's
   `offlineAfterMin` → the page goes to **"request only"** (§6).
7. Once the till has answered a request, **the portal's later changes to that row are refused and flagged** for the staff
   (§7). No silent merge.
8. The client's **manage link** (no password) moves, cancels or changes marketing choices by sending **new** requests
   (`kind: reschedule | cancel | preferences`) — §8.
9. **Queue join from a phone**: the door QR opens the portal's join page; the till collects joins every 5 seconds
   (`GET sync/salon/queue-joins`), puts each in the queue exactly as the desk would and answers it
   (`POST …/answer`), and pushes the phone joiners' places (`PUT sync/salon/queue`) whenever they move.
10. Marketing ticks on a request become the client's **`Consent` rows** (source `onlineBooking`) when the till accepts it.

## 2. Sequence

```
Client            Portal                                   Till (main)
  |  pick time  ->  |  shows times from booking-page          |
  |  Book        -> |  SalonOnlineBooking pending, expiresAt  |
  |                 |  holds the time as "pending"            |
  |                 |  ---- pull (≤ 30 s) ------------------> |  auto-accept? time still free?
  |                 |                                         |   yes -> SalonAppointment + row accepted
  |                 |                                         |   no  -> row stays pending (desk decides)
  |                 |  <--- push: SalonOnlineBooking accepted,|
  |                 |        SalonAppointment(+Service) rows  |
  |  <- confirmed   |  release the hold; booked               |
  |                 |  <--- booking-page (≈ 20–60 s later)    |  diary changed -> page republished
```

Request `status` moves only this way:

| From | To | Who | When |
|---|---|---|---|
| (new) | `pending` | portal | the client books (or uses the manage link) |
| `pending` | `cancelled` | portal | the client withdraws before the till answered |
| `pending` | `expired` | portal or till | `expiresAt` passed with no answer (both may mark it; same meaning) |
| `pending` | `accepted` | **till only** | appointment made / moved / cancelled, or marketing choices taken |
| `pending` | `declined` | **till only** | the desk declined it (`decisionReason` says why, in client words) |

`accepted`, `declined` (and `cancelled`, `expired`) are final. A request the till accepts **after** `expiresAt` is still
accepted — the till is the diary of record: confirm it to the client.

## 3. Common rules for the five calls

| | |
|---|---|
| Auth | `Authorization: Bearer <sync key>` and every §3 identity header (`X-SSPOS-Company-Id`, `X-SSPOS-Branch-Id`, `X-SSPOS-Register-Id`, `X-SSPOS-Contract: 1`, `X-SSPOS-App-Version`). Same 401 / 403 / 409 / 426 answers as `sync/hello`. Never a key in the address |
| Who calls | The shop's main (or single) till, only in a Salon / Barber shop with cloud sync on, only while no history upload is open |
| Scope | The key's shop (branch). A call for another shop's data is `404` like an unknown one |
| Bodies | JSON in the sync's own shape (`SyncJson`): camelCase names, enums as camelCase text, UTC times `…Z`, dates `yyyy-MM-dd`, money as numbers with at most 2 dp. The booking page is sent with `Content-Encoding: gzip`; the others plain |
| Errors | §9 body `{code, message, traceId, retryAfterSeconds, rejectedKey}`; codes in `error-codes.additions.json` |
| Waits | `429 rate.limited` / `503 server.busy`: the till waits `Retry-After` (60 s if none) before its next salon call. `501 salon.not_available`: an hour (or `Retry-After` if longer). Any other failure: a minute. **Push and pull never wait for a salon call** |
| Rate | At most: one heartbeat a minute, one booking page per diary change (settled 20 s) and at least every 15 minutes, one queue-joins read every 5 s while phone join is on, one queue push per change (at least every minute while a phone joiner waits). Suggest a salon limit of 60 calls a minute per key |
| Idempotent | Every call can be repeated safely: a page or queue push replaces the last one (keep the newest `generatedAt` / `at`); a heartbeat just updates "last heard"; an answer to a join already answered with the same body is `200` |

## 4. The calls

### 4.1 `PUT /api/v1/sync/salon/booking-page` — the page and its free times

- **Body:** `schemas/booking-page.schema.json`, sample `samples/booking-page.json`, gzipped. Sent whole every time.
- **`rules`:** `isOpen` (false = show the page closed), `slotIntervalMin`, `minNoticeMin`, `daysAhead`, `bufferMin`,
  `changeWindowHours` (§8), `holdMin` (set `expiresAt = createdAt + holdMin`), `offlineAfterMin` (§6), `autoAccept`
  (say "confirmed in a minute" vs "the shop will confirm"), `askPatchTest`, `questions[]`, `terms`, `depositNote`,
  `queueJoinOpen`.
- **`services[]`:** only services the shop marked "Book online" (never an add-on-only one), menu order: `productId`,
  `name` (online name), `description`, `categoryName`, `clientGroup` (`men` | `women` | `kids` | `everyone` — filter
  when the client says who it is for), `priceIsFrom`, `needsPatchTest`, `depositAmount` (0 = none; deposits are taken
  in the shop until online payment exists), `addOns[]` (`productId`, `name`, `price`, `extraMin`), and `options[]`:
  **what can be booked** — the service itself (`label` empty) or each hair length / type variant (`label` "Long"…).
  Each option has its own `productId` (the one a request books), `price`, `durationMin` and `byStylist[]` — every online
  stylist who does it at **their** price and minutes (the stylist's own, else their level's, else the service's).
- **`stylists[]`:** only stylists the shop lets clients pick online: `userId`, `firstName` (first name only), `levelName`,
  `group` (`female` | `male` | empty, for a "female / male stylist only" wish). Offer **"Any stylist"** too.
- **`availability`:** §10.
- **`manageLink`:** `{keyId, key}` — §8. A secret: store it like the sync key, never show or log it.
- **`timeZone`** is always `Europe/London`; every date and `HH:mm` in the body is UK shop time.
- **Reply 200** (`schemas/booking-page-reply.schema.json`, sample `samples/booking-page-reply.json`): `result`
  (`stored` | `unchanged`) and **your** addresses: `bookingUrl` (the public page), `queueJoinUrl` (the page the door QR
  opens), `manageUrl` (the start of a manage link, ending `/`). The till keeps them, shows them on its Online booking
  tab with QR codes and "Book" links (§9), and puts the manage link in its messages. Send the same addresses every time;
  send a new one only if the page really moved.
- **Errors:** `413 salon.page_too_large` (over 8 MB unzipped — the till shows it on its Status tab and the shop lowers
  "Book up to (days ahead)"), `422 request.invalid` (`rejectedKey` = the JSON path). A typical barber's page is a few
  kilobytes zipped; a 40-service, 10-stylist salon 28 days ahead is well under 1 MB unzipped.

### 4.2 `PUT /api/v1/sync/salon/heartbeat` — "still here"

- **Body:** `schemas/heartbeat.schema.json`, sample `samples/heartbeat.json`: `at` (till clock, UTC), `accepting` (the
  page is on), `queueJoinOpen` (phone join on, queue on and not paused), `pendingRequests`, `pageGeneratedAt` (the last
  page you stored — if yours is older, expect a new one soon), `offlineAfterMin`.
- Every minute while the page or phone join is on. When the shop switches both off, **one** last heartbeat with
  `accepting: false, queueJoinOpen: false`: show the page closed.
- **Reply 200** `{ "result": "ok" }`. Record `lastHeardAt = now` (your clock).

### 4.3 `GET /api/v1/sync/salon/queue-joins?max=20` — phone joins waiting for the till

- **Reply 200** (`schemas/queue-joins-reply.schema.json`, sample `samples/queue-joins-reply.json`):
  `{ joins: [ {joinId, firstName, phone, serviceIds[], stylistUserId, note, joinedAt} ], hasMore }`, oldest first, only
  joins **not yet answered**. `joinId` is **your ULID** (26 characters, Crockford) — the till uses it as the queue
  ticket's id. A join you list again (the till's answer was lost) is answered again with the same ticket; it never makes a
  second ticket.
- Rules the till applies (exactly the desk's Add): first name required (≤ 60), phone digits / `+ ( ) -` / spaces (≤ 30),
  note ≤ 200, at most 10 services, the stylist must be one who takes clients, every service must be on the menu; the
  queue must be on, not paused, not full. Validate the same on your page so most clients never see a refusal.
- Stop listing a join after `answer`; drop one nobody answered within 15 minutes and tell the client to ask at the desk.

### 4.4 `POST /api/v1/sync/salon/queue-joins/{joinId}/answer`

- **Body:** `schemas/queue-join-answer.schema.json`, samples `samples/queue-join-answer.joined.json` and
  `…refused.json`: `status` `joined` (with `ticket` "A004", `position` 1 = next, `minutesToWait`, `estimatedStartAt`)
  or `refused` (with `code` and `message` — the message is already in the client's words, show it as it is).
- **Reply 200** `{ "result": "stored" }`; `404 salon.join_not_found` for a join you do not have (the till moves on).

### 4.5 `PUT /api/v1/sync/salon/queue` — "my position"

- **Body:** `schemas/queue.schema.json`, sample `samples/queue.json`: `at`, `tradingDate`, `isOpen`, `waiting` (everyone
  waiting, desk and phone), `walkInMinutesToWait` (for someone joining now; null = nobody on now can take them — say
  "ask at the desk"), and `entries[]` for **today's phone joiners only**: `joinId`, `ticket`, `status` (`waiting`,
  `called`, `inService`, `done`, `left`, `noShow`), `position` (0 when not waiting), `minutesToWait`,
  `estimatedStartAt`. No names, no phone numbers.
- Sent after every change that moves a phone joiner and at least every minute while one waits. Keep the newest `at`.
  The client's "my position" page reads this (poll it every 15–30 s, or push it to the page).
- `called` = "Please come to the desk now". The till also texts "you're 2nd / you're next" itself when they left a phone.

## 5. Online booking requests — the `SalonOnlineBooking` row

Schema `../schemas/entities/SalonOnlineBooking.schema.json`; samples `samples/online-request.new.json` (as you send it),
`samples/online-request.accepted.json` (as the till pushes it back), `samples/online-request.preferences.json`.
Ownership: **hub-owned** (you create it), one shop's (`branchId` set; envelope `branchId` the same).

| Field | Who writes | Meaning |
|---|---|---|
| `id` | portal | your ULID; never reused |
| `reference` | portal | your reference shown to the client, e.g. `WEB-000123` (≤ 30) |
| `kind` | portal | `new`, `reschedule`, `cancel`, `preferences` (§8) |
| `appointmentId` | portal for `reschedule` / `cancel` / `preferences`; **till** for `new` | the appointment to change; for `new` the one the till made |
| `requestedStartsAt` | portal | UTC start of the first service |
| `requestedUserId` | portal | the stylist picked; null = any |
| `servicesJson` | portal | `[{"productId":"…","userId":"…"}]` in order: options' `productId`s, then add-ons; `userId` optional per line (null = the request's stylist, else any) |
| `clientFirstName`, `clientLastName`, `clientPhone`, `clientEmail` | portal | the client |
| `customerId` | portal | the client's `Customer` (hub-owned: create or match it on your side and **send the `Customer` row first**; a request naming a customer the till has not got waits for it). Needed for consent (§11) |
| `notes` | portal | the client's note (≤ 500) |
| `answersJson` | portal | `[{"question":"…","answer":"…"}]`, including the patch-test question |
| `termsAcceptedAt` | portal | when the client accepted `rules.terms` |
| `marketingSms`, `marketingEmail`, `marketingWhatsApp` | portal | explicit ticks only (unticked boxes, never pre-ticked) |
| `depositPaid`, `paymentReference` | portal | what the client paid online for this booking (≥ 0.00, 2 dp) and your payment's reference (≤ 100); `0.00` / empty while the page takes no payment. A paid deposit needs `customerId` (§5a) |
| `expiresAt` | portal | `createdAt + rules.holdMin` |
| `status` | portal: `pending`, `cancelled`, `expired`; **till**: `accepted`, `declined`, `expired` | §2 |
| `decidedAt`, `decidedByUserId`, `decisionReason` | **till** | when / who (null = automatically) / why declined |

- **Pull:** send the row in the shop's pull like any hub-owned row (§8 of the main contract). Send a newer version to
  withdraw it (`status: cancelled`) or to correct it **while it is `pending`**.
- **Push:** the till's answer comes back as a `U` of the same row with a higher `rowVersion` (and the new
  `SalonAppointment` + `SalonAppointmentService` rows, branch-owned, with `onlineBookingId` = the request's id). **Take
  the till's version.** Never send it back changed.
- **Idempotent:** the same row twice (same version, or same content at a newer version) changes nothing. Re-sending the
  till's own answer back to it is no change and no clash.
- **What the till refuses** (skipped, logged; §7 for flags): `status` `accepted` / `declined` from you; any change to a
  row the till has answered; a row naming another shop; any salon row at all in a shop that is not a salon.

### 5a. A deposit paid online (contract, 2026-11-07)

The payment itself happens on your side (your payment provider; the till never sees a card). Send the request with
`depositPaid` > 0, `paymentReference` and `customerId` (the `Customer` row first, as for any request). When the till
accepts a `new` request it lands the deposit exactly as one taken at the desk: a `CustomerTransaction` of type `advance`
for that customer (`amount` = −depositPaid, `tender` `"Online"`, `registerId` / `shiftId` null — it is in no till's drawer
or card total), the customer's `balance` down by it (held as their credit; the till's payment offers it against the bill),
a journal entry `refType` `CustomerPayment` (Dr 1210 Card clearing, Cr 2260 Customer account credit), the appointment's
`depositAmount` = depositPaid and `depositSaleId` = that transaction's id, and an `AuditLog` `SalonDepositTaken`. A re-sent
request lands once. A paid deposit with no `customerId` the till knows is not booked: the appointment's `notes` say so
and its `depositAmount` stays 0.00 (send the customer and a refund is the shop's, by hand). A deposit is never refunded
by the till on its own: a cancellation online is an ordinary `cancel` request; the shop's rules (late window, keep or
hand back) and the desk do the rest, and a refund made at the till is the shop's (`AdvanceRefund` row) — refund the card
on your side only if the shop tells you to.

## 6. Offline rule — "request only"

- `lastHeardAt` older than `rules.offlineAfterMin` (default 10, from the page and every heartbeat) → the till is away.
- Then: keep showing the page and the last published times, but say **"Request this time — the shop will confirm when
  it is back online"**; still write `pending` requests; do not promise a time; do not hold it longer than `holdMin`
  after the till is back (the till answers within a minute of coming back).
- `accepting: false` in the last heartbeat (or `rules.isOpen: false`) → the page is **closed** (show the shop's phone).
- The queue-join page: offline → "The queue is not taking phone joins just now — please ask at the desk".

## 7. Conflict rule — no silent merge

- The till decides every request. A time taken since the client asked is **not** booked automatically: the request
  stays `pending` for the desk, who accept it anyway (moving others) or decline it with a reason in client words.
- If you change a request **after** the till answered it (the client withdrew too late, your expiry ran after the
  till's accept), the till **refuses** your change, keeps its answer and its appointment, and raises a **clash on its
  Sync status screen** (`SyncConflict`, entity `SalonOnlineBooking`, detail naming your `reference`), once per version.
  The staff then contact the client. To undo an accepted booking, send a **new** `kind: cancel` request.
- Two clients asking for the same time: both are `pending`; the till takes the first that fits; the second clashes and
  goes to the desk. Never "accept" on your side.

## 8. The manage link — no password

- **Link:** `{manageUrl}{appointmentId}.{token}`, `token` = the first **22** characters of
  base64url(HMAC-SHA256(key = UTF-8 of `manageLink.key`, message = UTF-8 of `appointmentId`)), no padding. Example
  in `samples/booking-page.json` (a made-up key). The till makes it for every appointment message once it has your
  `manageUrl` (the `{ManageLink}` merge field of its confirmation, reminder and "moved" texts); you make the same link
  for appointments booked online. Check: recompute and compare in constant time; refuse an unknown `keyId` era.
- **Key:** made by the till once (32 random bytes, base64url), sent in every page as `manageLink {keyId, key}`;
  `keyId` changes only if the shop's key changes — then old links stop working. Keep only the newest key.
- **What it opens:** the appointment as the till pushed it (`SalonAppointment` + services): date, time, stylist, services.
  - **Reschedule** (until `rules.changeWindowHours` before the start): offer times from §10 → send a request
    `kind: reschedule`, `appointmentId`, `requestedStartsAt`, `requestedUserId`.
  - **Cancel** (same window): send `kind: cancel`, `appointmentId`.
  - Inside the window: "Please ring the shop".
  - **Marketing choices** (any time): three ticks (SMS, e-mail, WhatsApp) showing the client's current consent
    (newest `Consent` row per channel, pushed by the till) → send `kind: preferences` with `appointmentId`,
    `customerId` and the three ticks **as the client left them**. The till takes it at once (never waits for the desk):
    a tick gives consent, an unticked box withdraws a consent that stood.
- Never put the token or key in a log line or an analytics URL.

## 9. Links the shop shares

The till's Online booking tab shows `bookingUrl` with a QR code, `queueJoinUrl` with a door QR, and copy-ready links,
all your `bookingUrl` with a `src` parameter so you can count where clients come from:

| Where | Link |
|---|---|
| Google Business Profile → Bookings | `{bookingUrl}?src=google` |
| Instagram bio | `{bookingUrl}?src=instagram` |
| Facebook "Book now" button | `{bookingUrl}?src=facebook` |
| Apple Maps (Business Connect) action link | `{bookingUrl}?src=apple` |
| Website "Book now" button | `{bookingUrl}?src=website` |
| Website widget | `<iframe src="{bookingUrl}?src=widget&embed=1">` — **`embed=1`: show the page without your header and footer**, mobile-first, and allow framing from any site for this path only |

Keep `bookingUrl` short and stable (e.g. `https://book.example.co.uk/kings-cuts`). Ignore unknown parameters.

## 10. Free times — how the page offers them (model "a")

The till publishes, for each **option** (`productId`), each **date** of the window and each **online stylist**, the free
start times as ranges: `"10:00-11:30"` = every `intervalMin` from 10:00 to 11:30 inclusive. They come from the till's one
availability engine: working hours (rota, else trading hours), breaks, blocks, time off, booked clients (processing time
counts as free), who does what, chairs and rooms, the online gap after the booking (`bufferMin`) and only stylists
clients may pick online. You never work out availability yourself.

- **One option, a named stylist:** offer that stylist's starts.
- **"Any stylist":** the union of every stylist's starts for the option (the till picks the stylist when it accepts).
- **A basket (option + add-ons, or several options) with one stylist:** offer start `t` only when `t` is a start of the
  first item **and**, for each next item, `t + (minutes of the items before it, for that stylist)` is a start of that
  item for the same stylist. If that time is not on the grid, do not offer `t`. (The till checks again on accept.)
- **Minimum notice:** the times were worked out at `generatedAt`; hide any start earlier than now + `minNoticeMin`.
- **UTC:** `requestedStartsAt = date + HH:mm − utcOffsetMinutes` (0 in GMT, 60 in BST) — or convert with Europe/London.
  Never offer a time in the clock-change hour.
- **Freshness:** a new page comes ~20–60 s after any diary, team, menu or settings change and at least every 15 minutes.
  A time in the window can still be gone by the time the request arrives — that is what `pending` and §7 are for.
- A date with no free start is left out of `days[]`; an option nobody can do is left out of `items[]`.

## 11. Marketing consent from online

- Tick boxes per channel, **never pre-ticked**, separate from the terms. Service messages (confirmation, reminders,
  "you're next") need no consent.
- On a `new` request the till writes a `Consent` row (`given: true`, `source: onlineBooking`, `at` = the request's
  `createdAt`) for each **ticked** channel the client has not already consented to; an unticked box changes nothing.
  On `preferences` an unticked box also **withdraws** (`given: false`, `withdrawnAt` set). Rows come back in push
  (`Consent` is branch-owned, read-only to you). Only for a request with a `customerId` the till holds.
- New enum value `ConsentSource.onlineBooking` (`samples/enums.json`, `../schemas/entities/Consent.schema.json`).

## 12. Staff calendar feed — iCal per stylist (portal serves it)

- **What:** an `.ics` feed per stylist for their phone's calendar, served by **you** from the pushed `SalonAppointment`
  and `SalonAppointmentService` rows (and `User` for the name). The till sends nothing new for it.
- **Address:** `https://<portal>/salon/ical/{branchId}/{userId}/{feedToken}.ics`; `feedToken` is yours (random, per
  stylist, revocable from the business panel). The shop owner turns it on per stylist on the portal.
- **Events:** one `VEVENT` per appointment service row where `stylistUserId` (or `assistantUserId`) = the stylist and the
  appointment `status` is not `cancelled` / `noShow`; `UID` = `{appointmentServiceId}@sspos`; `DTSTART`/`DTEND` from the
  service row's `startsAt`/`endsAt` (UTC, `Z`); `SUMMARY` = service name + client **first name only**; `STATUS:CONFIRMED`;
  `LAST-MODIFIED` = `updatedAt`. Cancelled ones: keep for 7 days with `STATUS:CANCELLED`, then drop. Window: 7 days back,
  60 days ahead. No phone numbers, e-mails, notes, prices or health data in the feed. `Cache-Control: max-age=300`.

## 13. What else the portal reads (ordinary sync, nothing new)

- `SalonAppointment`, `SalonAppointmentService` (branch-owned): what is booked; `onlineBookingId` links an online one.
- `SalonQueueEntry` (branch-owned, `source: online` for phone joiners; the queue push of §4.5 is the fast copy).
- `Customer` (hub-owned), `Consent` (branch-owned), `User` (stylists' names).
- `Setting` rows `salon.online.*` (Branch scope): the same rules as the page, plus `salon.online.booking_url`,
  `queue_url`, `manage_url` (your own addresses, written back by the till). `salon.online.manage_link_key` never leaves
  the till as a setting (only inside the page body).
- Never sent: `SalonClientMedical` (health answers stay on the till).

## 14. Licence feature names — PROPOSAL, owner to decide (Z11)

| Feature key | What it would unlock | Proposed |
|---|---|---|
| `salon` | The Salon / Barber shop type itself | Owner to decide: free with the till, or paid |
| `salon_online_booking` | The booking page, links, manage link, iCal feed | Paid add-on (owner to decide) |
| `salon_queue_join` | Queue join from a phone | Inside `salon_online_booking`, or free (owner to decide) |

Until the owner decides, the till asks for none of them (`Application/Licensing/PaidFeatures` unchanged); when added, a
shop without the feature sends `accepting: false` and no page.

## 15. Error codes

`error-codes.additions.json`: `salon.not_available` (501), `salon.page_too_large` (413), `salon.join_not_found` (404),
`salon.not_a_salon` (403 — the key's shop is not a salon on your side). The till's own refusals on a join
(`salon.queue.paused`, `salon.queue.full`, `salon.queue.off`, `salon.queue.module_off`, `salon.queue.not_a_stylist`,
`salon.queue.unknown_service`, `salon.queue.join_invalid`) come in the answer body, already in client words.

## 16. Files

| File | What |
|---|---|
| `schemas/booking-page.schema.json`, `samples/booking-page.json` | §4.1 body |
| `schemas/booking-page-reply.schema.json`, `samples/booking-page-reply.json` | §4.1 reply |
| `schemas/heartbeat.schema.json`, `samples/heartbeat.json` | §4.2 |
| `schemas/queue-joins-reply.schema.json`, `samples/queue-joins-reply.json` | §4.3 |
| `schemas/queue-join-answer.schema.json`, `samples/queue-join-answer.{joined,refused}.json` | §4.4 |
| `schemas/queue.schema.json`, `samples/queue.json` | §4.5 |
| `samples/online-request.{new,accepted,preferences}.json` | §5 (`../schemas/entities/SalonOnlineBooking.schema.json`) |
| `error-codes.additions.json` | §15 |

Till side: `src/SSPOS.Application/Salon/Online/*` (page, availability window, consent, manage links, phone joins, the
worker's step), `src/SSPOS.Infrastructure/Sync/HttpsSalonOnlinePortal.cs` (the calls),
`src/SSPOS.Infrastructure/Salon/SalonOnlineWorker.cs` (the loop), `src/SSPOS.Infrastructure/Persistence/Sync/SalonPullGuard.cs`
(what a pull may write).

## 17. Test list before a real salon is connected

1. `501` until switched on; the till waits an hour, push and pull carry on.
2. Page stored; addresses returned; the same page again → `unchanged`.
3. A request booked online is pulled, auto-accepted (setting on, time free) and pushed back `accepted` with its
   appointment within a minute; the client sees "confirmed".
4. Same with the time taken meanwhile → stays `pending`; the desk declines → client told the reason.
5. Client withdraws a `pending` request → `cancelled` reaches the till; withdrawing after `accepted` → refused and
   flagged on the till; a `cancel` request then cancels the appointment.
6. Heartbeats stop (till off) → after `offlineAfterMin` the page says "request only"; requests made then are answered
   when the till is back.
7. Manage link: token checked; reschedule and cancel requests inside / outside the window; marketing choices → consent
   rows given and withdrawn.
8. Phone join → ticket in a few seconds; the same join listed twice → one ticket; paused queue → refused message shown;
   "my position" moves as the desk calls people; `called` shows "come to the desk".
9. A grocery's key never calls `sync/salon/*`; a salon row sent to a grocery is refused by its till.
10. iCal feed shows only the stylist's own appointments, first names only.
