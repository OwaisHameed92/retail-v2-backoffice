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
- `SaveTillSetting` refuses `setup.business_type`, `vertical.*`, `workshop.connection_status` and (until the connector
  exists) `workshop.connector_status`.
- Role editor: words for `pharmacy.*` and `workshop.*` rights when a till sent them; never offered to other shops.
- Till audit actions (receipt history) and the `CardRefundUncapped` exception type: plain words.
- Till error codes (`pharmacy.ppc_deduction_*`, `registers.from_another_till`, `payment.settled_outside_*`…) are the
  till's own refusals and never reach the portal: nothing to label.

## Waiting for EPOS or later waves

- Salon / Barber: sync acceptance done in W2 (`docs/salon.md`); the `sync/salon/*` calls and pages are W3.
- dm+d release server (`GET /api/v1/pharmacy/dmd/latest`): W4.
- BikeBook connector, `WorkshopOrder` pull row, webhooks, `workshop.connector_status`: W5, once BikeBook registers SSPOS.
- Editing pharmacy settings from the portal: not in W1 (shown read-only).
- Confirm with the till team: whether "Paid in BikeBook" appears in `ZReport.totalsJson`, and that RX lines are in the
  sale's `SaleVat` rows like order deposits (the reports take them off SaleVat).
- A pulled `vertical.*` / `setup.business_type` that one shop pushed is still relayed to the business's other shops as
  before (0.1.85 tills skip it); not changed so existing shops see no difference.
