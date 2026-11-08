# Till 0.1.85: pharmacy and BikeBook on the portal (W1)

SSPOS 3 adds three shop types. Pharmacy and the BikeBook bike workshop shipped in till **0.1.85** (stable feed,
2026-11-08); Salon / Barber is on the till's `feat/salon-barber` branch, not released. This page says what W1 built so
the portal accepts everything 0.1.85 sends, and what is left. Plan: `docs/PHASES.md` "SSPOS 3 verticals".
Decision: `docs/DECISIONS.md` "Till 0.1.85: pharmacy + BikeBook sync acceptance (W1)".

## The contract pack

`docs/contracts/portal-api-0.1.85/docs/web-portal-api/` is the EPOS team's released folder (tag `v0.1.85`, commit
`2abd28d`), copied as sent. The till entity store is now generated from it (`definitions.php` `contract`, release
`0.1.85`); `TillFixtures::CONTRACT` and `ContractSchema::ROOT` point at it, so the sample replays and schema checks use
the 0.1.85 samples and schemas. `docs/contracts/portal-api-v1.4.1/` is unchanged: `TillFixtures::LEGACY_CONTRACT`
replays its push sample (a till ≤ 0.1.84) and the reply must equal its `push-reply.json`.

What changed against our previous copy (all additive):

| Row | Change | Stored as |
|---|---|---|
| `DispensingRecord` | `prescriberAddress`, `exemptionEvidence`, `saleLinePosition`, `chargeSettledAt`, `chargeRefunded`, `chargeKept`; `exemption` gains 10 values (22 in all) | columns (`prescriber_address` is text) |
| `DispensingItem` | `freeOfChargeReason` (the exemption list) | column |
| `CustomerOrder` | `source` (till / web / workshop), `externalProvider`, `externalOrderId`, `externalReference` | columns (ids and reference as text) |
| `Product` | `workshopExposure` (auto / always / never) | column, till-owned (below) |
| `PaymentAttempt` | `status` `settledOutside`, `onRecordAt` | enum value, column |
| 26 new tables | 21 pharmacy, 4 workshop, `ProductPictureUpload` | all `local`: acknowledged as skipped, never stored |

Migration `2026_12_14_100001_add_till_0_1_85_columns.php` (generated, nullable columns on 5 tables) and
`2026_12_14_100002_add_nhs_charges_to_rpt_sales_daily.php` (hand). Both run on SQLite and MySQL 8.

## Leniency (proven by tests)

- Unknown member of a known entity: kept in the row's `extra` (secret-looking ones redacted).
- Unknown value of a known list: stored as sent and logged once per batch; never a 422.
- Unknown entity: kept raw in `till_unknown_rows`. A `local` entity (a pharmacy register, a patient, a workshop order):
  acknowledged, nothing stored.
- A member an older till does not send: `notRecorded` (exemption evidence), `none` (free-of-charge reason), `till`
  (order source), else blank / 0 / null.
- Local-only settings (`pharmacy.dmd_*` keys, `workshop.bikebook_api_key`, the Teya keys…) are acknowledged and dropped;
  `SettingSyncPolicy` equals the 0.1.85 `settings-local-only.json`.
- Nothing was refused before W1 either: the generic path already took unknown members, values and entities. What W1
  fixed is what *storing* got wrong: a long prescriber address would have been refused once it had a varchar column, a
  product push differing only in `workshopExposure` would have been taken for an echo (the hash left out `extra`), and
  missing members would have become `""` instead of the README's defaults.

Tests: `tests/Feature/Sync/Till0185PushTest.php`, `Till0185ProductTest.php`, `tests/Feature/Reporting/Till0185ReportingTest.php`,
`tests/Feature/ShopSettings/VerticalSettingsTest.php`; the 0.1.85 samples replay in `SampleReplayTest` and `SyncPushApiTest`.

## Product.workshopExposure (till-owned member of a hub row)

`TillOwnedFields`: absent or null means "no change" (a new row keeps null, a stored value stays); null is left out of
`hub_hash`, so every hash stored before 0.1.85 is still valid and an older till's unchanged push is still an echo; never
pulled while null (`PullPayload::KEPT_WHEN_BLANK`). When a till's product change loses to a newer portal edit, the
conflict is recorded and the portal's fields stay, but the till's `workshopExposure` is taken and the row goes to every
till.

## Money and reports

- `RX-CHARGE` (NHS charge, account 4050, outside VAT) and `RX-PPC-3M` / `-12M` / `-HRT` (owed to NHSBSA, 2270) lines
  are not sales: out of every sales and VAT figure (so out of VAT boxes 1 and 6), summed in `rpt_sales_daily.nhs_charges`,
  still in takings ("NHS charges" in the takings hint). `RX-PRIVATE` (0%) and `RX-SERVICE` are sales with no product row,
  listed by line name. A receipt shows RX lines without a product link, flagged "NHS charge (not a sale)".
- We only read the till's journal: accounts 2270, 4050 and 1260 and `PpcDeduction` entries land in the ledger as sent.
- "Paid in BikeBook" (tender kind `PaidElsewhere`; the kind itself is never on the wire, the portal sees a payment type
  with no flags): not cash, so out of cash takings, the drawer and cash variance (all read `isCash`); its own line by
  name in the tender report, cash-ups and the Z (which reads the till's `totalsJson` as sent). Receipts and the payment
  types list call it "Paid elsewhere" (`PaidElsewhere`, by name).

## Labels

- Till settings page: read-only sections "Pharmacy" (pharmacy shops: business type Pharmacy, or a till sent
  `vertical.pharmacy` = true / `setup.business_type` = Pharmacy), "Bike workshop (BikeBook)" (a till sent
  `integration.workshop` or a `workshop.*` key) and "Shop type" (`setup.business_type`, `vertical.*`). Grocery shops see
  none of them. Also `till.show_all_chip` / `till.show_top_sellers_chip` (editable) and `catalogue.brands` (read-only).
- `SaveTillSetting` refuses `setup.business_type`, `vertical.*`, `workshop.connection_status` and
  `workshop.connector_status` (only the W5 connector writes the latter, `WriteConnectorStatus`).
- Role editor: words for `pharmacy.*` and `workshop.*` rights when a till sent them; never offered to other shops.
- Till audit actions (receipt history) and the `CardRefundUncapped` exception type: plain words.
- Till error codes (`pharmacy.ppc_deduction_*`, `registers.from_another_till`, `payment.settled_outside_*`…) are the
  till's own refusals and never reach the portal: nothing to label.

## Waiting for EPOS or later waves

- Salon / Barber (41 tables, list values, settings, rights, accounts 2280–2300 / 4200, `sync/salon/*`): W2, W3.
- dm+d release server (`GET /api/v1/pharmacy/dmd/latest`): W4.
- BikeBook connector, `WorkshopOrder` pull row, webhooks, `workshop.connector_status`: built in W5, switched off (below).
- Editing pharmacy settings from the portal: not in W1 (shown read-only).
- Confirm with the till team: whether "Paid in BikeBook" appears in `ZReport.totalsJson`, and that RX lines are in the
  sale's `SaleVat` rows like order deposits (the reports take them off SaleVat).
- A pulled `vertical.*` / `setup.business_type` that one shop pushed is still relayed to the business's other shops as
  before (0.1.85 tills skip it); not changed so existing shops see no difference.

## BikeBook connector (W5, built and switched off)

BikeBook calls the portal (BikeBook's "POS connector" spec, `WORKSHOP-BIKEBOOK.md` §1–§9); the portal answers from
the shop's synced till data, sends "Send to POS" orders to the shop's till as `WorkshopOrder` pull rows and tells
BikeBook when they are paid. Module `app/Domain/Workshop`; decision `docs/DECISIONS.md` "BikeBook POS connector (W5)";
proposal for the EPOS team `docs/contracts/proposals/workshop-order/`.

**Switch.** `WORKSHOP_CONNECTOR=false` (default; keep it off in production). Off: every `/connectors/bikebook/*`,
`/oauth/authorize`, `/oauth/token` and admin workshop route answers **404**; no pull or push reads a workshop table and
every pull is byte-identical; `workshop.connector_status` never goes down; no webhook; no admin tab, no tenant card.
`WORKSHOP_CONNECTOR_MIN_TILL` (optional) limits the `WorkshopOrder` rows to tills at least that version. Direct mode
(the till with the shop's own BikeBook key) needs none of this and is unaffected either way.

**Calls** (base `https://<portal>/connectors/bikebook/v1`): `GET /api/Stores`, `GET /api/TaxRates`,
`GET /api/Products?lastUpdated=&page=&pageSize=&fields=id,updated`, `GET /api/Product/{id}`,
`GET /api/Inventory?variant=` / `?lastUpdated=`, `POST /api/Inventory/batch`, `GET /api/Customer/list`,
`GET /api/Customer/{id}`, `POST|PUT /api/Customer`, `PUT /api/Customer/{id}/Address`,
`POST|GET|PUT|DELETE /api/SalesOrder`, `PUT /api/SalesOrder/{id}/status/{Saved|Complete|Cancelled}`,
`POST|PUT|DELETE /api/SalesOrderLine`, `GET|POST|PUT /api/Webhook`, `GET|DELETE /api/Webhook/{id}`.

**Auth.** OAuth 2.0 authorisation code + refresh: `GET /oauth/authorize?response_type=code&client_id=&redirect_uri=
&scope=&state=` (the shop owner signs in to the portal, picks the shop, allows), `POST /oauth/token` (`tenant` = the
shop's branch id; BikeBook sends it back as `tenant-id`). Fallback: an API key from the admin card in the `ApiKey`
header (never `?apikey=`).

**What the owner and the shop see.** Admin → tenant → "Workshop (BikeBook)" tab: per shop the licence tick
(`workshop_integration`, no price yet), Connect, Create / Replace / Revoke API key, Disconnect, status (Connected since,
"Connected, webhook failing" with the reason, Disconnected), last BikeBook call, counts, and the Sync log page (last
1,000 calls and the webhook deliveries with Replay, CSV export). Tenant portal → Shops → a shop: a read-only "Bike
workshop (BikeBook)" card; without the add-on it says "Contact Switch & Save".

### Owner to-dos before switching it on

1. **Register SSPOS with BikeBook as a POS type** (Q1): send them our OAuth client id and secret (choose them, put
   them in `BIKEBOOK_CLIENT_ID` / `BIKEBOOK_CLIENT_SECRET`), the URLs `https://<portal>/oauth/authorize` and
   `https://<portal>/oauth/token`, the base URL `https://<portal>/connectors/bikebook/v1`, the scopes
   (`offline_access products:read inventory:read customers:read customers:write stores:read taxrates:read
   salesorders:write webhooks:write`), our limits (600 a minute per shop, 500 a page, 200 lines an order, 1 MB), "API
   key in the `ApiKey` header only" (Q6), and ask for their exact redirect URI(s) (`BIKEBOOK_REDIRECT_URIS`).
2. **A sandbox:** a test business and shop on staging with the add-on ticked and a test owner login; ask BikeBook
   for their staging host and run the joint tests below with them.
3. **Set a price** for `workshop_integration` (Q10, probably monthly). Today the tick is free and nothing is billed.
4. **Ask BikeBook** Q2 (batch / delta inventory and `inventory.updated`), Q3 (untracked stock: we send `null`), Q4/Q7
   (paid-in-BikeBook amounts, job number, bike and warranty flags on Send to POS), Q5/Q11 (extra fields on
   `order.statuschanged`, deposits), and whether a product leaving the scope as `enabled: false` suits them.
5. **Tell the till team** (proposal README): `WorkshopOrder` local → hub; which release applies it (then set
   `WORKSHOP_CONNECTOR_MIN_TILL`).
6. Then `WORKSHOP_CONNECTOR=true` on the server, `php artisan migrate`, and the scheduler runs
   `workshop:send-webhooks` every minute.

### Joint test list (§9): what the tests prove, what needs BikeBook's sandbox

| # | Test | Covered by our tests | Still needs BikeBook / the till |
|---|---|---|---|
| 1 | Connect; one store; one default tax rate | OAuth and key (`ConnectorAuthTest`), Stores / TaxRates | BikeBook's real OAuth client and redirect |
| 2 | 100,000 products, 3 categories, < 15 min | scope by categories, paging ≤ 500, light list | the timing on staging with a big shop |
| 3 | Price and stock change shows within 3 min | delta by `lastUpdated`, `inventory.updated` from the till's push | BikeBook polling / reading the webhook |
| 4 | Customer from BikeBook on the till; no duplicate | created customer in every till's pull; de-dup by phone and e-mail | — |
| 5 | Send to POS on the till within 30 s, parts held | `WorkshopOrder` in the shop's pull, schema-valid | the till applying the row and holding stock |
| 6 | Change after a deposit refused (409) | `order_locked` after the till's deposit push | BikeBook showing the message |
| 7 | Paid on the till → BikeBook Paid and Completed | signed `order.statuschanged` Complete with paidTotal | BikeBook reading it |
| 8 | Cancel in BikeBook → gone from the till, stock released | pull row `status: cancelled` | the till dropping it |
| 9 | Refund → `refundedTotal` | second `order.statuschanged` with the refund | BikeBook reading it |
| 10 | Disconnect → 401; the till hides its screens | 401 for key and tokens; `workshop.connector_status` = disconnected pulled | the till reacting to the setting |
| 11 | Training mode reaches nobody | — (the till never pushes training sales) | till rule |
| 12 | Portal down during Send to POS → one order | a repeated POST answers the same order | BikeBook's retry |
