# Web portal changes — Pharmacy, BikeBook, Salon / Barber

Everything the SSPOS web portal (cloud) has to build or accept for the three new parts of SSPOS 3, in one file.

| | |
|---|---|
| For | The web portal team |
| Updated | 8 November 2026 |
| Pharmacy, BikeBook | Live in 0.1.85 (`main`) |
| Salon / Barber | Branch `feat/salon-barber`, not released |
| Detailed contracts | `docs/web-portal-api/` — each part names its own files |

## Contents

- [Start here](#start-here)
- [Part A — Pharmacy](#part-a--pharmacy)
  - [Pharmacy: in short](#pharmacy-in-short)
  - [Pharmacy: sync changes (must accept now)](#pharmacy-sync-changes-must-accept-now)
  - [Pharmacy: money](#pharmacy-money)
  - [Pharmacy: dm+d release server](#pharmacy-dmd-release-server)
  - [Pharmacy: settings, rights, audit](#pharmacy-settings-rights-audit)
  - [Pharmacy: go-live and files](#pharmacy-go-live-and-files)
- [Part B — BikeBook (bike workshop)](#part-b--bikebook-bike-workshop)
  - [BikeBook: in short](#bikebook-in-short)
  - [BikeBook: the connector the portal hosts](#bikebook-the-connector-the-portal-hosts)
  - [BikeBook: sync changes](#bikebook-sync-changes)
  - [BikeBook: settings, rights, licence](#bikebook-settings-rights-licence)
  - [BikeBook: what to build and test](#bikebook-what-to-build-and-test)
- [Part C — Salon / Barber](#part-c--salon--barber)
  - [Salon: in short](#salon-in-short)
  - [Salon: the five new calls](#salon-the-five-new-calls)
  - [Salon: online booking](#salon-online-booking)
  - [Salon: links, consent, queue join and calendar](#salon-links-consent-queue-join-and-calendar)
  - [Salon: sync changes (ordinary push and pull)](#salon-sync-changes-ordinary-push-and-pull)
  - [Salon: refusals, licence and go-live checks](#salon-refusals-licence-and-go-live-checks)
- [Part D — Older lines and points to settle](#part-d--older-lines-and-points-to-settle)
  - [Older lines that may never have been sent](#older-lines-that-may-never-have-been-sent)
  - [Points to settle](#points-to-settle)

## Start here

What the web portal has to build or accept for three new parts of SSPOS 3, and in what order. **Pharmacy** and **BikeBook** are already live in 0.1.85 on the stable feed. **Salon / Barber** is built on branch `feat/salon-barber` and not released yet.

| Part | Where it is | What the portal must do | When |
| --- | --- | --- | --- |
| Pharmacy | Live in 0.1.85 | Accept 3 changed sync rows and 2 new accounts; later run the dm+d release server | **Now** — until the rows are accepted, a pharmacy shop's sync is refused (422) and its queue stops |
| BikeBook | Live in 0.1.85 (direct mode needs no portal) | Build the BikeBook POS connector, the `WorkshopOrder` pull row, webhooks and an admin card | Once BikeBook registers SSPOS as a POS type |
| Salon / Barber | Branch `feat/salon-barber`, not released | Accept 41 new tables, list values, settings and rights; build 5 `sync/salon/*` calls and the online booking page | Before the salon release; the calls may answer 501 until then |
| Older lines | 0.1.54 – 0.1.83 | Check the dated lines in Part D | Check whether they were ever sent |

**Build order**

1. Pharmacy rows and accounts (Part A, "must accept now") — live today.
2. Salon sync acceptance: new tables, list values, settings, rights, accounts (Part C).
3. Salon calls and the online booking page (Part C).
4. dm+d release server for pharmacies (Part A).
5. BikeBook connector, once BikeBook agrees (Part B).
6. Older lines in Part D that were never sent.

**Rules for all three parts**

- The shop type is chosen on the till only. A pulled `vertical.*` or `setup.business_type` is skipped by the till and logged; show these keys read-only.
- Health and patient data never leaves the till: pharmacy registers, patients and the RP log; a salon client's health answers.
- Secrets (API keys, tokens, client secrets) stay on the till or are stored sealed; never in logs or messages.
- List (enum) values go on the wire in lower case, exactly as `samples/enums.json` shows.
- A field an older till does not send means empty, `notRecorded`, 0 or null.
- Every new part is hidden in every other shop type: a grocery never sends or receives any of it.

## Part A — Pharmacy

### Pharmacy: in short

Pharmacy is a shop type chosen in the setup wizard (`setup.business_type` = `Pharmacy`, which turns on `vertical.pharmacy`). It adds GSL / P / POM rules at the till, dispensing records with NHS charges and exemptions, the Responsible Pharmacist (RP) log, the legal registers (POM, controlled drugs, specials, poisons), patients, the weekly NHS medicines dictionary (dm+d), wholesaler ordering, and separate ledger lines for the NHS charge and prepayment certificates (PPC). Every new pharmacy table stays on the till. Only rows that already existed (`DispensingRecord`, `DispensingItem`, `MedicineClassification`) go to the portal, with extra fields. Nothing is recorded as done on the portal side yet.

### Pharmacy: sync changes (must accept now)

| Row | Change |
| --- | --- |
| `DispensingRecord` (branch-owned, pushed) | New `prescriberAddress`, `exemptionEvidence` (`notRecorded` / `seen` / `notSeen` / `notNeeded`), `saleLinePosition`, `chargeSettledAt`, `chargeRefunded`. `exemption` gains 10 values at the end. |
| `DispensingItem` (branch-owned, pushed) | New `freeOfChargeReason`. |
| `MedicineClassification` | Extra fields as in its schema. |

Already on the wire before this release: `DispensingRecord` carries `patientCustomerId`, prescriber name, registration and address, and `notes`; `DispensingItem` carries `directions`.

**Never pushed or pulled** (local, also left out of the history upload) — 21 tables: CdDrugPage, CdRegisterEntry, CdReturnEntry, DmdPriceConcession, DmdPriceHistory, MedicineDmdLink, MedicineIngredient, MedicineRefusal, PharmacistRegistration, PharmacyPatient, PharmacyRegisterNote, PharmacyRetailProduct, PharmacyRetailReliefDeclaration, PharmacyRetailService, PharmacyWholesalerLink, PharmacyWholesalerOrderSend, PoisonsRegisterEntry, PomRegisterEntry, RegulatedSubstance, ResponsiblePharmacistEntry, SpecialsRecordEntry. Their schemas are for reference only.

**Later:** before registers, the RP log or patients reach the portal, the portal needs encrypted storage hosted in the UK. The till will then switch them to branch-owned and send every existing row once.

### Pharmacy: money

- New `Account` rows, pushed with op `I`, pharmacy shops only: **2270** "PPC money owed to NHSBSA" (liability) and **4050** "NHS prescription charges" (income, outside VAT, no VAT box). Keep both out of VAT box 6.
- `RX-PPC-*` lines post Cr 2270 (refund Dr 2270). `RX-CHARGE` posts Cr 4050 (refund Dr 4050). Both used to post to 4020.
- New ref type `PpcDeduction`, refId `branchId:yyyy-MM`: Dr 2270 / Cr 1230.
- Sale lines with no product row: `RX-PRIVATE` (0%), `RX-PPC-3M` / `RX-PPC-12M` / `RX-PPC-HRT` (outside scope), `RX-SERVICE`. Treat them like `RX-CHARGE` or `BAG-CHARGE`.
- No new tender type.

### Pharmacy: dm+d release server

The main till of a pharmacy (only when it has a sync key) asks the portal for the weekly NHS dictionary. Not in `openapi.yaml` yet.

- `GET {portal}/api/v1/pharmacy/dmd/latest` — same auth as `sync/hello` (Bearer branch sync key + `X-SSPOS-*` headers); 403 for a branch that is not a pharmacy. 200 returns `releaseId`, `name`, `releaseDate`, `week`, `archiveFileName`, `downloadUrl`, `sizeBytes`, `sha256`, `publishedAt` (`archiveFileName`, `downloadUrl` and `sha256` required). `downloadUrl` must be https; on another host it must be pre-signed for at least 24 h, because the till sends no headers there. Cache: `no-cache` or `max-age` ≤ 300.
- `GET {downloadUrl}` — the NHSBSA zip unchanged; packages never change, so `immutable` caching is right.
- What the till does: 404 `dmd.no_release` → uses NHS TRUD or tries again in an hour; 401 / 403 → TRUD and "check Cloud sync"; 5xx, 429 and anything else (501 too) → tries again in an hour, TRUD meanwhile.
- Portal job every Monday (a bank-holiday release may come on Tuesday): download TRUD item 24 with **our own** TRUD key (stored on the portal), check `archiveFileSha256`, keep the last two weeks, keep the Open Government Licence attribution with the files. The manifest never names a zip that is not fully stored. Check TRUD's redistribution terms before go-live.
- The pharmacy's own TRUD key and its NHS Terminology Server client id and secret stay on the till.

### Pharmacy: settings, rights, audit

- **Shared settings** (show for pharmacy shops only): on/off `pharmacy.two_pack_guidance`, `pharmacy.codeine_guidance`, `pharmacy.wwham_card`, `pharmacy.dmd_library_fallback`; text `pharmacy.ods_code`, `pharmacy.gphc_premises_number`, `pharmacy.ready_message`; money `pharmacy.ppc_3_month_price`, `pharmacy.ppc_12_month_price`, `pharmacy.ppc_hrt_price`, `pharmacy.private_dispensing_fee`; number `pharmacy.private_markup_percent`.
- **Local only** (never sent): `pharmacy.dmd_trud_api_key`, `pharmacy.dmd_server_client_id`, `pharmacy.dmd_server_client_secret`, `pharmacy.dmd_checked_utc`, `pharmacy.dmd_source`, `pharmacy.dmd_problem`, `pharmacy.dmd_problem_utc`.
- **Rights** (pharmacy shops only): `pharmacy.sale_confirm`, `pharmacy.dispense`, `pharmacy.poisons_register`, `pharmacy.registers_record`, `pharmacy.cd_register`, `pharmacy.cd_witness`, `pharmacy.responsible_pharmacist`, `pharmacy.patients`, `pharmacy.ready_message`, `pharmacy.retail_manage`, `pharmacy.vat_relief`, `pharmacy.medicine_data`, `pharmacy.wholesaler_orders`. A grocery Owner no longer gets any `pharmacy.*` right.
- **Audit actions:** `DispensingChargeSettled`, `PharmacyVatCheckDone`, `PpcDeductionRecorded`, `SendOrderToWholesaler` (entity `PurchaseOrder`, reason = order number), plus `PharmacySaleConfirmed` / `Refused`, `CdEntryRecorded` / `Corrected`, `PomEntryRecorded`, `SpecialsEntryRecorded`, `PoisonsRegisterEntryAdded`, `PharmacyPatientAdded` / `Changed`, `ResponsiblePharmacistSignedIn`. Show them as text.
- **Error codes:** `pharmacy.ppc_deduction_recorded`, `_month`, `_amount`, `_note`, `_not_posted`.
- Clone packs carry no shop-type key and no `pharmacy.*` key.

### Pharmacy: go-live and files

- The portal accepts the three rows and the 2270 / 4050 accounts first; 0.1.85 is already live, so any shop set up as a pharmacy sends these fields now.
- There is no joint test list yet, and the till's release checks have no pharmacy section.
- Read: `docs/web-portal-api/PORTAL-CHANGES-pharmacy.md` (main), the four "Pharmacy …" lines (2026-10-24 / 25) in `UPCOMING-CHANGES.md`, `schemas/entities/{DispensingRecord,DispensingItem,MedicineClassification}.schema.json`, `samples/ownership.json`, `samples/settings-local-only.json`; background `docs/HOW-THE-SHOP-RUNS.md` §15.

## Part B — BikeBook (bike workshop)

### BikeBook: in short

A paid add-on for bike shops that run their repairs in BikeBook. Two modes:

- **Direct mode** (built, live in 0.1.85): the main till uses the shop's own BikeBook Public API key, reads unpaid invoices every minute, holds the parts, takes the money on the normal sale path and tells BikeBook paid, complete and refunded. **No portal involved.**
- **Connector mode** (the portal's half, not built): BikeBook calls a portal "POS connector"; the portal sends each order to the till as a new pull row `WorkshopOrder` and sends BikeBook a webhook when it is paid. Blocked until BikeBook registers SSPOS as a POS type.

A shop that never connects sees no change. The till never calls the portal for workshop work, so a 501 or an outage changes nothing for it; it only shows `workshop.connector_status`.

### BikeBook: the connector the portal hosts

BikeBook's own paths, under e.g. `/connectors/bikebook/v1`:

| Area | Calls |
| --- | --- |
| Stores and VAT | `GET /api/Stores`, `GET /api/TaxRates` |
| Products | `GET /api/Products?lastUpdated=`, `GET /api/Product/{id}` (must answer for any active product id: BikeBook's "Verify" checks labour products by id) |
| Stock | `GET /api/Inventory?variant=`, `?lastUpdated=`, `POST /api/Inventory/batch` (≤ 500) |
| Customers | `GET /api/Customer/list`, `GET /api/Customer/{id}`, `POST` / `PUT /api/Customer` (de-duplicate by phone or e-mail), `PUT /api/Customer/{id}/Address` |
| Sales orders | `POST` / `GET` / `PUT` / `DELETE /api/SalesOrder`, `POST` / `PUT` / `DELETE /api/SalesOrderLine`, `PUT /api/SalesOrder/{id}/status/{status}` |
| Webhooks | `/api/Webhook` (create, read, update, delete) |

- **Auth:** OAuth 2.0 authorisation code with refresh (`/oauth/authorize`, `/oauth/token`; the token's `tenant` is the `branchId`). Fallback: an API key in the `ApiKey` header — show it once, store only a hash, never accept `?apikey=`. A `tenant-id` that does not match the token's branch gets 403.
- **Conventions:** camelCase, UTC times, money including VAT; ≤ 500 items a page, ≤ 200 lines an order; 600 requests a minute, 429 with `Retry-After`; single GET under 500 ms p95.
- **Errors:** 400 `{error:"invalid_request", message}`; 409 `order_locked` once the till has taken money. Writes are idempotent by natural key (`orderNumber`, line id, customer id).
- **Webhooks to BikeBook:** `order.statuschanged` and `inventory.updated` required (`product.updated`, `customer.updated` optional). Sign with `X-Pos-Signature` = Base64(HMAC-SHA256(secret, raw body)) and send an `X-Pos-Event-Id` ULID. Retry after 1 min, 5 min, 15 min, 1 h, 4 h, then mark failed with a Replay button.
- **One reporter per order:** the portal reports connector orders only; it ignores an order whose `externalOrderId` it did not send (the till reports those itself).

### BikeBook: sync changes

- `CustomerOrder` gains `source` (`till` / `web` / `workshop`), `externalProvider` (`bikebook` or null), `externalOrderId`, `externalReference` (all four in the schema's `required` list). The order's reference is `WS-{job}`.
- `Product` gains `workshopExposure` (`auto` / `always` / `never`, default `auto`). `Product` is hub-owned but the till edits this field, so accept it from push.
- One `PaymentType` "Paid in BikeBook" and one `Account` **1260** "Money held by BikeBook" (asset) per company, hub-owned, created at Connect, never seeded. New tender kind `PaidElsewhere` (7): not cash, never opens the drawer, its own line on the Z. No change to the shape of sales rows; deposits are normal order deposits.
- **Local, never sent:** `WorkshopOrder`, `WorkshopOutboxItem`, `WorkshopCallLog`, `WorkshopProductLink`. Do not send `WorkshopOrder` rows until the till moves it to hub-owned.
- **Future pull row `WorkshopOrder`:** rows carry a version; the same version again does nothing; after the till takes money it refuses changes and records a SyncConflict.
- Never give BikeBook a customer's `balance`, `points`, `creditLimit`, `cardNo`, `dob` or `notes`. `workshop.bikebook_api_key` never reaches the portal (sealed to the PC).

### BikeBook: settings, rights, licence

- **Shared branch settings:** `integration.workshop`, `workshop.provider`, `workshop.bikebook_business_id`, `workshop.bikebook_business_name`, `workshop.product_scope` (default `categories`), `workshop.scope_category_ids`, `workshop.new_categories_included`, `workshop.labour_product_id`, `workshop.misc_product_id`, `workshop.category_id`, `workshop.orders_show_from`, `workshop.notify_ready`, `workshop.send_ready_message`, `workshop.complete_job_on_payment`, `workshop.refunds_to_bikebook`, `workshop.supplier_orders_by`, `workshop.bikebook_base_url`.
- `workshop.connector_status` is written **only by the portal** (`pending` / `connected` / `disconnected` / `failed`). `workshop.connection_status` belongs to the till: the portal must never write it.
- **Lists** in `samples/enums.json`: `CustomerOrderSource`, `WorkshopExposure` (proposed, not added yet: `workshopOrderStatus`, `workshopLineType`).
- **Rights:** `workshop.manage`, `workshop.book`, `workshop.price_override`, `workshop.cancel_hold`.
- **Audit actions:** `WorkshopConnected`, `WorkshopDisconnected`, `WorkshopOptionsChanged`, `WorkshopOrderOpened`, `WorkshopHoldCancelled`, `WorkshopProductLinked`, `WorkshopOutboxRetried`, `WorkshopOutboxStopped`, `WorkshopRepairBooked` (entity `WorkshopJob`), `WorkshopExposureChanged` (entity `Product`). None holds keys or BikeBook message bodies.
- **Licence:** paid feature `workshop_integration` in the licence key's `features` list; the portal sells it and switches it on per shop (price later, likely monthly). Without it the Settings card is greyed with "contact your dealer". A trial key with an empty `features` list turns every feature on.

### BikeBook: what to build and test

- **Register with BikeBook:** OAuth client id and secret, auth URLs, base URL, scopes, our rate limits, a sandbox tenant.
- **Store a `WorkshopConnection` per branch:** `id`, `companyId`, `branchId`, `provider`, `status`, `connectedAt`, `disconnectedAt`, `lastPulledAt`, `lastError`, `defaultStoreId`, `productScope`, `webhookUrl`, `webhookSecret` (encrypted), `createdBy`; plus the OAuth tokens or the API key's hash. Log every call, never tokens, keys or full addresses.
- **Build:** the connector, the `WorkshopOrder` pull row (schema, sample, `pull-reply.workshop.json`), the webhooks, and an admin card per branch (create / revoke key, a log of the last 1,000 calls and webhook deliveries with Replay, CSV export, the licence tick).
- **Joint test with BikeBook (12):** connect; 100k products synced in under 15 min; price and stock changes in BikeBook within 3 min; customer de-duplication; an order on the till within 30 s with its parts held; a change after a deposit → 409; paid → BikeBook shows Paid and Completed within a minute; cancel releases stock; refund sends `refundedTotal`; disconnect → 401; training mode sends nothing; a 10-minute portal outage gives one order, not two.
- Read: `docs/web-portal-api/WORKSHOP-BIKEBOOK.md` (§0a first), the 2026-10-28 and two 2026-11-06 lines in `UPCOMING-CHANGES.md`, `schemas/entities/{CustomerOrder,Product}.schema.json`, `samples/entities/{CustomerOrder,Product}.json`, `samples/{enums,ownership,settings-local-only,push-request}.json`, `licensing/schemas/common.schema.json`; background `tasks/P10-03.md`, `docs/HOW-THE-SHOP-RUNS.md` §16, `docs/workshop/BIKEBOOK-PUBLIC-API.md`.

## Part C — Salon / Barber

Branch `feat/salon-barber`, not released. Everything below is the salon part.

### Salon: in short

The portal needs five new calls, a public booking page, a manage link, a phone queue-join page and a staff calendar feed; everything else is ordinary sync of new salon rows. Only Salon / Barber shops send or need any of it — every other shop type sends exactly what it sent before.

The till side is built on branch `feat/salon-barber` (not released). The contract stays `X-SSPOS-Contract: 1`: additive only, no existing endpoint, entity, field or code changes. Until the portal switches the salon calls on, answer every `sync/salon/*` call `501 salon.not_available` with `Retry-After: 3600`; the till waits an hour and nothing else changes.

| # | What the portal builds or changes | Kind | Section |
| --- | --- | --- | --- |
| 1 | Five calls under `sync/salon/` (booking page, heartbeat, queue joins, join answer, queue positions) | New endpoints | The five new calls |
| 2 | Public booking page shown from the page the till publishes (services, stylists, free times) | New web page | Online booking |
| 3 | `SalonOnlineBooking` request rows: create, send in pull, read the till's answer | Sync (hub-owned row) | Online booking |
| 4 | Manage link (no password): move, cancel, marketing choices | New web page | Links, consent, queue, calendar |
| 5 | Queue join page for the door QR + "my position" page | New web pages | Links, consent, queue, calendar |
| 6 | iCal feed per stylist from pushed appointments | New feed | Links, consent, queue, calendar |
| 7 | 41 new `Salon*` tables, new settings keys, enum values, audit actions, ledger accounts | Ordinary sync | Sync changes |
| 8 | Deposits paid online (only once a payment provider is chosen) | Later, optional | Online booking |

The full pack with JSON schemas and samples is in the repo at `docs/web-portal-api/salon/` (README, `schemas/`, `samples/`, `error-codes.additions.json`), plus the dated lines in `docs/web-portal-api/UPCOMING-CHANGES.md`.

### Salon: the five new calls

Only a salon's main (or single) till calls these, with cloud sync on, using the normal sync key and every identity header (`X-SSPOS-Company-Id`, `-Branch-Id`, `-Register-Id`, `X-SSPOS-Contract: 1`, `X-SSPOS-App-Version`). Bodies use the sync's own JSON shape: camelCase, enums as camelCase text, UTC times ending `Z`, money to 2 dp.

| Call | What it carries | Reply | How often |
| --- | --- | --- | --- |
| `PUT /api/v1/sync/salon/booking-page` | The whole page, gzipped: rules, services and options with per-stylist price and minutes, stylists (first name, level, female/male group), free start times for the next N days, the manage-link key | `{result: stored \| unchanged, bookingUrl, queueJoinUrl, manageUrl}` — your public addresses | \~20 s after any diary, team, menu or settings change; at least every 15 min |
| `PUT /api/v1/sync/salon/heartbeat` | `at`, `accepting`, `queueJoinOpen`, `pendingRequests`, `pageGeneratedAt`, `offlineAfterMin` | `{result: ok}`; record last heard | Every minute; one last one with both flags false when switched off |
| `GET /api/v1/sync/salon/queue-joins?max=20` | — | `{joins: [{joinId, firstName, phone, serviceIds, stylistUserId, note, joinedAt}], hasMore}`, oldest first, unanswered only | Every 5 s while phone join is on |
| `POST /api/v1/sync/salon/queue-joins/{joinId}/answer` | `joined` (ticket, position, minutes to wait, estimated start) or `refused` (code + message in the client's words) | `{result: stored}`; `404 salon.join_not_found` | Once per join |
| `PUT /api/v1/sync/salon/queue` | Today's phone joiners only: ticket, status, position, minutes to wait; how many wait; wait for someone joining now | `{result: stored}` | After every change that moves a phone joiner; at least every minute while one waits |

- **Errors:** the normal error body `{code, message, traceId, retryAfterSeconds, rejectedKey}`. New codes: `salon.not_available` (501), `salon.page_too_large` (413, over 8 MB unzipped), `salon.join_not_found` (404), `salon.not_a_salon` (403).
- **Waits:** on 429 / 503 the till waits `Retry-After` (60 s if none); on 501 an hour; anything else a minute. Push and pull never wait for a salon call.
- **Rate:** suggest a salon limit of 60 calls a minute per key.
- **Idempotent:** every call can be repeated: keep the newest `generatedAt` / `at`; an answer already stored with the same body is 200.
- **Joins:** `joinId` is your ULID and becomes the till's ticket id, so a join listed twice makes one ticket. Drop a join nobody answered within 15 minutes and tell the client to ask at the desk.

### Salon: online booking

The till is the diary of record: the portal never writes an appointment. It shows the page the till published, writes a request row, and confirms to the client only after the till answers.

```text
Client on the booking page            Portal                                   Till (diary of record)
-------------------------------       --------------------------------------   ------------------------------------
picks services, stylist, time  --->   offers only the till's published free
                                      times; writes SalonOnlineBooking
                                      (status pending, expiresAt = hold)  --->  pulls the request
                                                                                auto-accepts if the time is free,
                                                                                else the front desk decides
                                      <---  pushes the same row back: accepted (+ appointment) or declined (+ reason)
gets "booked" or the reason   <---    confirms only after the till answers
```

A request the till cannot book automatically waits for the front desk; the portal never accepts on its own.

1. **Show the page** from the last `booking-page`: rules (`isOpen`, `slotIntervalMin`, `minNoticeMin`, `daysAhead`, `bufferMin`, `changeWindowHours`, `holdMin`, `offlineAfterMin`, `autoAccept`, `askPatchTest`, questions, terms, deposit note), services with their options (each hair length / type has its own `productId`, price and minutes, per stylist), add-ons, stylists and "Any stylist". Filter services by client group (men / women / kids / everyone) when the client says who it is for. Times are UK shop time (`Europe/London`).
2. **Offer free times** only from the till's published ranges (`"10:00-11:30"` = every interval from 10:00 to 11:30). Any stylist = the union of the stylists' starts. A basket of several items with one stylist: offer `t` only if each next item starts on that stylist's grid at `t` + the minutes before it. Hide starts earlier than now + `minNoticeMin`; never offer a time in the clock-change hour. Never work out availability yourself.
3. **Write a request**: a `SalonOnlineBooking` row (hub-owned, `branchId` set, `status: pending`, `kind: new`, `expiresAt = createdAt + holdMin`) and send it in the shop's pull. Send the client's `Customer` row first when you name one. Hold the time as pending on the page until `expiresAt`.
4. **Read the answer**: the till pushes the same row back `accepted` (with `appointmentId` and the new `SalonAppointment` + `SalonAppointmentService` rows) or `declined` (with `decisionReason` in the client's words). Take the till's version; confirm or tell the client. A request the till accepts after `expiresAt` still counts.

| Request field | Who writes it |
| --- | --- |
| `id`, `reference`, `kind`, `requestedStartsAt`, `requestedUserId`, `servicesJson`, client name / phone / e-mail, `customerId`, `notes`, `answersJson`, `termsAcceptedAt`, `marketingSms` / `marketingEmail` / `marketingWhatsApp`, `depositPaid`, `paymentReference`, `expiresAt` | Portal |
| `appointmentId` (for `kind: new`), `decidedAt`, `decidedByUserId`, `decisionReason` | Till |
| `status` | Portal: `pending`, `cancelled`, `expired` · Till only: `accepted`, `declined` |

- **Auto-accept:** the till accepts at once when the shop has it on and the time is still free; a clash is never booked automatically and waits for the front desk.
- **Offline rule:** no heartbeat for `offlineAfterMin` (default 10) → keep the page but say "Request this time — the shop will confirm"; still write pending requests. `accepting: false` or `isOpen: false` → page closed, show the shop's phone.
- **Conflict rule:** once the till has answered, any later portal change to that row is refused and shown to staff on the till's Sync status screen. To undo an accepted booking, send a new `kind: cancel` request. Never accept on the portal side.
- **Deposits paid online (later):** the payment happens on your side. Send `depositPaid` > 0, `paymentReference` and `customerId`; when the till accepts it lands as the client's advance (Dr 1210 card clearing, Cr 2260 customer credit) and links it to the appointment. The till never refunds a card; a refund on your side only when the shop says so.

### Salon: links, consent, queue join and calendar

Four client-facing pages and one feed, all served by the portal; the till only supplies the data and shows the links.

**Manage link (no password).** Link = `{manageUrl}{appointmentId}.{token}`; `token` = the first 22 characters of base64url(HMAC-SHA256(key = `manageLink.key` from the page, message = `appointmentId`)), no padding. Check it in constant time; refuse an unknown `keyId`. The till puts this link in its confirmation, reminder and "moved" messages. It opens the appointment and offers:

- **Reschedule** or **Cancel** until `changeWindowHours` before the start → a new request `kind: reschedule` / `cancel` with `appointmentId`; inside the window: "Please ring the shop".
- **Marketing choices** any time: three ticks (SMS, e-mail, WhatsApp) showing current consent → a request `kind: preferences` with the ticks as the client left them; the till takes it at once.
- Never put the token or key in a log line or an analytics address.

**Marketing consent.** Tick boxes per channel, never pre-ticked, separate from the terms. On an accepted `new` request the till writes a `Consent` row (source `onlineBooking`) for each ticked channel; on `preferences` an unticked box also withdraws. Service messages need no consent.

**"Book" links the shop shares.** The till's Online booking tab shows your `bookingUrl` with a QR code and these ready links; keep `bookingUrl` short and stable and ignore unknown parameters.

| Where | Link |
| --- | --- |
| Google Business Profile → Bookings | `{bookingUrl}?src=google` |
| Instagram bio | `{bookingUrl}?src=instagram` |
| Facebook "Book now" | `{bookingUrl}?src=facebook` |
| Apple Maps (Business Connect) | `{bookingUrl}?src=apple` |
| Website button | `{bookingUrl}?src=website` |
| Website widget | `<iframe src="{bookingUrl}?src=widget&embed=1">` — with `embed=1` show the page without your header and footer, and allow framing for this path only |

**Queue join from a phone.** The door QR opens `queueJoinUrl`: first name (required, ≤ 60), phone (optional), services (≤ 10), any or a named stylist, note (≤ 200). Validate the same rules as the till so most clients never see a refusal. The "my position" page reads the latest `PUT sync/salon/queue` (poll every 15–30 s): `called` = "Please come to the desk now". Offline: "The queue is not taking phone joins just now — please ask at the desk". No names or phone numbers of other clients, ever.

**Staff calendar feed (iCal).** `https://<portal>/salon/ical/{branchId}/{userId}/{feedToken}.ics`, `feedToken` random per stylist and revocable; the owner turns it on per stylist. One `VEVENT` per appointment service where the stylist (or assistant) is that person and the appointment is not cancelled / no-show; `UID = {appointmentServiceId}@sspos`; summary = service name + client first name only; window 7 days back, 60 ahead; cancelled kept 7 days as `STATUS:CANCELLED`. No phones, e-mails, notes, prices or health data. `Cache-Control: max-age=300`. Built from rows the till already pushes — nothing new from the till.

### Salon: sync changes (ordinary push and pull)

The portal must accept 41 new `Salon*` tables and a few new values on existing lists; no existing table, field or value changes. Schemas are `schemas/entities/Salon*.schema.json`, ownership is in `samples/ownership.json`, lists in `samples/enums.json`.

| Ownership | Tables |
| --- | --- |
| **Hub-owned** (portal may write; pulled like `Product`) — 13 | `SalonServiceDetail`, `SalonLevel`, `SalonServiceLevelPrice`, `SalonServiceAddOn`, `SalonResource`, `SalonCommissionPlan`, `SalonCommissionTier`, `SalonCommissionCategoryRate`, `SalonCommissionFixedService`, `SalonStampCardRule`, `SalonPackageDefinition`, `SalonConsultationFormTemplate`, `SalonOnlineBooking` |
| **Branch-owned** (till writes; portal reads) — 27 | `SalonStaffProfile`, `SalonStaffService`, `SalonBlock`, `SalonSaleLineStaff`†, `SalonLineStaffCorrection`†, `SalonTip`†, `SalonTipShare`†, `SalonTipPayout`†, `SalonRenterAgreement`, `SalonRenterStatement`, `SalonQueueEntry`, `SalonQueueEntryService`, `SalonAppointment`, `SalonAppointmentService`, `SalonRepeatRule`, `SalonWaitlistEntry`, `SalonClientCard`, `SalonTechnicalRecord`, `SalonClientPhoto`, `SalonConsultationAnswer`, `SalonPatchTest`, `SalonStampCard`, `SalonStampEvent`†, `SalonPackageHolding`, `SalonPackageUse`†, `SalonMessageTemplate`, `SalonMessageOutboxItem` |
| **Never sent** (stays on the till) — 1 | `SalonClientMedical` (health answers) |

† Append-only: never updated; a correction is a new row (negative amounts on refunds).

**New values on lists the portal already knows**

| List | New values |
| --- | --- |
| `CashMovement.type` | `tipTaken` (cash tip, positive), `tipPayout` (tips paid from the drawer, negative) |
| `Consent.source` | `import` (opt-in from another salon system's file), `onlineBooking` |
| Ledger accounts (salon shops only) | `2280` Tips payable, `2290` Chair renter takings held, `2300` Packages sold not used, `4200` Chair rent from renters (VAT box 6) |
| Journal `refType` | `SalonTip`, `SalonTipPayout`, `SalonRenterTakings`, `SalonRenterStatement`, `SalonRenterSettlement`, `SalonPackageSold`, `SalonPackageRelease` |
| `RolePermission` keys (salon shops only) | `salon.services_manage`, `salon.team_manage`, `salon.commission_view`, `salon.tips_manage`, `salon.queue_manage`, `salon.queue_reorder`, `salon.diary_manage`, `salon.clients_technical`, `salon.client_medical`, `salon.messages_manage`, `salon.settings` |
| `Setting` key | `vertical.salon` (Bool, written only by the till's wizard; a pulled value is ignored) |
| New salon enums | 26 `Salon*` lists plus `CommissionTierMode`, `CommissionPeriod`, `CostDeduction`, `CommissionPayRule`, `CommissionRateBasis`, `TipSplitMode`, `RenterRentModel`, `StampRewardKind` |

**Settings keys** (Branch scope, salon shops only; the till never takes a pulled `salon.*` value). Pushed: `salon.clients_we_serve`, `salon.staff_word`, `salon.slot_interval_min`, `salon.clients.patch_test_valid_days`, `salon.till.*` (2), `salon.tips.*` (3), `salon.diary.*` (9), `salon.queue.*` (7), `salon.messages.*` (9), `salon.integrations.*` (8, provider names and account ids only), `salon.online.*` (16, including `booking_url`, `queue_url`, `manage_url` written back by the till). Never pushed: `salon.queue.board_on` / `board_monitor` / `checkin_on` / `checkin_monitor` (this PC), `salon.integrations.meta_access_token`, `salon.integrations.sms_secret`, `salon.online.manage_link_key` (secrets).

**New AuditLog actions** (all from salon shops): service and team (`SalonLevelRetired`, `SalonResourceRetired`), clients (`SalonPhotoRemoved`, `SalonClientErased`, `SalonClientsImported`, `SalonAppointmentsImported`), diary (`SalonAppointmentCancelled`, `SalonAppointmentNoShow`, `SalonBlockDeleted`, `SalonDepositTaken`, `SalonDepositKept`, `SalonDepositRefunded`, `SalonFeeCharged`, `SalonFeeWaived`), queue (`SalonQueueNoShow`, `SalonQueueEntryRemoved`, `SalonQueueReordered`, `SalonQueueStepUndone`), till (`Salon.LineStaffChange`), money (`SalonTipsPaidOut`, `SalonTipsSettingsSaved`, `SalonCommissionPlanSaved`, `SalonCommissionPlanAssigned`, `SalonRenterAgreementSaved`, `SalonRenterStatementMade`, `SalonRenterStatementIssue` / `Settle` / `Cancel`, `SalonPackageExpired`), messages (`SalonMessageSent`, `SalonMessageFailed`, `SalonMessageSkipped`, `SalonMessageRetried`, `SalonAutomationSaved`, `SalonMessageSettingsSaved`, `SalonMarketingOptOut`, `SalonMessageProvidersSaved`, `SalonMarketingClientsExported`). Exact payloads are in `UPCOMING-CHANGES.md`.

**Sales stay the same shape.** A tip is never in `SalePayment.amount`, so the takings formula `SUM(amount − cashback − changeGiven)` stays sales only. A salon receipt's `receiptJson` may carry `Tip` (negative on a refund). A package session or stamp reward is a line discount with `discountSource: promotion`. Renter lines move out of sales and VAT into 2290 through the ledger.

### Salon: refusals, licence and go-live checks

**What a till refuses from a pull** (skipped and logged; never a silent merge):

- `SalonOnlineBooking` with `status` `accepted` or `declined` from the portal, any change to a row the till has already answered (flagged as a `SyncConflict` on the till), or a row naming another shop.
- Any salon row, `salon.*` setting or `salon.*` right sent to a shop that is not a salon. Pharmacy pulls behave exactly as before.
- `vertical.salon` and the shop type: only the till's setup wizard sets them.
- Branch-owned salon rows and `SalonClientMedical`: the till owns them.

**Licence — decided 2026-11-08: no separate licence feature.** Salon / Barber, online booking and queue join from a phone come with the normal licence, as UK salon systems include online booking. The portal needs no new licence feature key and the till checks none. Message costs (SMS / WhatsApp) stay on the shop's own provider account.

**Before a real salon is connected:**

- [ ] Every `sync/salon/*` call answers `501` until switched on; the till waits an hour; push and pull carry on.
- [ ] Page stored, addresses returned; the same page again → `unchanged`.
- [ ] A request booked online is pulled, auto-accepted (setting on, time free) and comes back `accepted` with its appointment within a minute.
- [ ] The time taken meanwhile → stays `pending`; the desk declines → the client is told the reason.
- [ ] Client withdraws a pending request → `cancelled`; withdrawing after `accepted` → refused and flagged; a `cancel` request then cancels it.
- [ ] Heartbeats stop → after `offlineAfterMin` the page says "request only"; those requests are answered when the till is back.
- [ ] Manage link: token checked; reschedule and cancel inside and outside the window; marketing choices give and withdraw consent.
- [ ] Phone join → ticket in a few seconds; the same join twice → one ticket; paused queue → refusal shown; "called" shows "come to the desk".
- [ ] A grocery's key never calls `sync/salon/*`; a salon row sent to a grocery is refused by its till.
- [ ] iCal feed shows only that stylist's appointments, first names only.

**Files for the portal team** (repo, branch `feat/salon-barber`): `docs/web-portal-api/salon/README.md` (the full contract), `docs/web-portal-api/salon/schemas/` + `samples/` (the five calls and online request rows), `docs/web-portal-api/salon/error-codes.additions.json`, `docs/web-portal-api/schemas/entities/Salon*.schema.json`, `docs/web-portal-api/samples/{ownership,enums,settings-local-only}.json`, and the dated lines in `docs/web-portal-api/UPCOMING-CHANGES.md`. The samples are generated from the till's own types by the contract tests, so they cannot drift from the code.

## Part D — Older lines and points to settle

### Older lines that may never have been sent

The last portal pack on record is `portal-pack-0.1.52-2026-10-06`; 0.1.53 was answered in `ANSWERS-2026-10-06-b.md`. These dated lines in `docs/web-portal-api/UPCOMING-CHANGES.md` came after it. Each later progress note says "no portal pack zip", so check whether the portal has them:

| Date (version) | Change |
| --- | --- |
| 2026-10-06 (0.1.54) | Settings `till.show_all_chip`, `till.show_top_sellers_chip`; audit `DealerPasswordAccepted` / `Refused` |
| 2026-10-06 (0.1.55) | Opening balance from the New customer form; audit `OpeningBalanceSet` |
| 2026-10-07 | Product pictures copied under ProgramData (extra `Product` pushes); saving a shop-wide key also rewrites narrower `Setting` rows; audit `RegisterAdded` / `RegisterChanged`, error `registers.from_another_till`; DNA settings (`payments.dna_weblink_key` and others) |
| 2026-10-08 | `payments.open_drawer_on_voucher` (local); `payments.dna_cloud_store_id` (local) |
| 2026-10-10 | An extra till's `Sale.Number` can differ from its `ReceiptNumber`; an extra till's saved sales: `NotReconciled` audit and related changes |
| 2026-10-11 | Goods sold by weight: `SaleLine` meaning changes |
| 2026-10-12 | Shared setting `catalogue.brands`; product re-import keeps fields the file has no column for |
| 2026-10-13 | Teya terminal: local `payments.teya_*` keys; `CardRefundUncapped` |
| 2026-10-14 | Dojo rebuilt (`ts_` / `pi_` ids, reversal attempts) |
| 2026-10-15 (0.1.74 / 0.1.75) | `PaymentAttempt.status` `settledOutside` with audit and error codes; `PaymentAttempt.onRecordAt` |
| 2026-10-16 | Dojo `Expired` lands as unknown / needs recovery; `display.tile_pictures` (local); archiving a category pushes its products as inactive |
| 2026-10-29 (0.1.82) | `StockMovement.refType` `StockNotEntered` |
| 2026-11-02 (0.1.83) | Product-image calls and the `X-SSPOS-Image-Source` header (`ANSWERS-2026-11-02.md` still to send); a scan restores an archived product; `Restore` audit on Category and Department |

### Points to settle

- **Release status:** the pharmacy and workshop documents and their `UPCOMING-CHANGES.md` lines still say "not released"; both shipped in 0.1.85.
- **List values are lower case on the wire.** The 2026-11-06 line writes `Till`, `Auto` and so on with capitals; the generated schemas and `samples/enums.json` (what is sent) use lower case.
- **`workshop.connection_status`** is in the shared keys and the till has no guard for it, so the portal must simply never write it (only `workshop.connector_status` is the portal's).
- **`WorkshopOrder` ownership:** today it is `local` in `samples/ownership.json`; it becomes a hub → till pull row only when the connector is built.
- **Pharmacy table count:** the pharmacy documents say 27 in one place and 21 in another; `samples/ownership.json` lists 21 today.
- **Workshop on the Z report:** no document names a change to `ZReport.totalsJson` for "Paid in BikeBook"; confirm with the till team before building Z screens.
