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
- dm+d release server: built in W4 behind `PHARMACY_DMD` (off); see "dm+d release server" below.
- BikeBook connector, `WorkshopOrder` pull row, webhooks, `workshop.connector_status`: W5, once BikeBook registers SSPOS.
- Editing pharmacy settings from the portal: not in W1 (shown read-only).
- Confirm with the till team: whether "Paid in BikeBook" appears in `ZReport.totalsJson`, and that RX lines are in the
  sale's `SaleVat` rows like order deposits (the reports take them off SaleVat).
- A pulled `vertical.*` / `setup.business_type` that one shop pushed is still relayed to the business's other shops as
  before (0.1.85 tills skip it); not changed so existing shops see no difference.

## EPOS answers (2026-11-08)

1. **"Paid in BikeBook" on the Z:** `totalsJson.tenders[]` has one line per payment type that took money in the shift. The "Paid in BikeBook" line is `notCounted: true`, with expected = declared and variance 0. This matches how we show it (its own non-cash line).
2. **RX-CHARGE / RX-PPC-* and VAT:** these lines carry `vatRateId: ""` (out of scope). The sale's VAT rows include one row with `vatRateId ""`, percentage 0, vat 0, net = gross = the NHS money, code `E`. Identify it by `vatRateId == ""`, not by the code. We take these lines off that row, so no `E` row is left in the VAT report and none of it reaches a VAT box. Test: `Till0185ReportingTest` "out-of-scope SaleVat row". `RX-PRIVATE` goes on the shop's 0% rate as a normal sale.

## dm+d release server (W4, behind `PHARMACY_DMD`, OFF)

PORTAL-CHANGES-pharmacy.md item 12: a pharmacy's main till fetches NHSBSA's weekly dm+d zip from us first, and only
uses NHS TRUD with the pharmacy's own key when we cannot help. Built and tested; **switched off** everywhere until the
owner to-dos below are done. Off, nothing changes for any shop: the two routes answer 501 and the job does nothing.
Decision: `docs/DECISIONS.md` "Pharmacy dm+d release server (W4)".

| Call | Answer |
|---|---|
| `GET /api/v1/pharmacy/dmd/latest` | Auth and headers exactly as `sync/hello` (Bearer branch sync key, `X-SSPOS-*`, contract 1, the sync key's limit) plus 10 a minute per key. Off → **501** `dmd.not_available`, `Retry-After: 3600` (an unknown key is still 401 first). Shop not a pharmacy → **403** `dmd.not_pharmacy`. Nothing held → **404** `dmd.no_release` (the contract's code). **200** `{releaseId, name, releaseDate, week, archiveFileName, downloadUrl, sizeBytes, sha256, publishedAt}`, sha256 upper-case hex as in the contract's sample, `Cache-Control: no-cache, private` |
| `GET {downloadUrl}` = `https://<portal>/api/v1/pharmacy/dmd/packages/<archiveFileName>?expires=…&signature=…` | No headers needed. Signed link, 48 h (never under 24 h) from each manifest. Bad, altered or expired signature → **403** `dmd.link_invalid`; pruned or unknown name → 404 `dmd.no_release`; off → 501; 20 a minute per IP. 200 streams the zip unchanged: `application/zip`, exact `Content-Length`, `ETag` = SHA-256, `Cache-Control: public, max-age=31536000, immutable` |

Only `dmd.no_release` is named by the contract; the 501 and 403 codes are ours (the till acts on the status). They and
the manifest schema are written down as files in `docs/contracts/proposals/pharmacy-dmd/` (the pack has prose only) and
every manifest reply in every test is checked against them (`ContractReplyGuard`).

**Who is a pharmacy:** the sync key's shop, when the business type is Pharmacy, or a till sent `setup.business_type` =
Pharmacy / `vertical.pharmacy` = true for the business or for that shop (`PharmacyBranch`). A grocery's key is 403;
another business's key never gets past `auth.wrong_branch` for our shops.

**Weekly job** `pharmacy:dmd-fetch` (`FetchDmdRelease`; Monday 07:30 UK, again Tuesday 07:30 for a bank-holiday week;
runnable by hand): only with the switch on and `PHARMACY_DMD_TRUD_KEY` set, one run at a time. TRUD item 24's latest
release → already held in full: nothing written (idempotent) → else the zip goes to a temp file (timeouts: 15 s connect,
30 s list, 900 s zip; only from `isd.digital.nhs.uk`), its size and SHA-256 must equal TRUD's `archiveFileSizeBytes` /
`archiveFileSha256` (else thrown away) → stored unchanged at `pharmacy/dmd/<releaseId>/<archiveFileName>` on
`PHARMACY_DMD_DISK` (default the private `local` disk) with `ATTRIBUTION.txt` (Open Government Licence v3.0) → only then
the `dmd_releases` row, in one write → older weeks pruned (the newest and the previous two kept; row first, then files).
Each run is a `dmd_fetch_runs` row; a failure is logged as an error. TRUD puts our key in the URL path, so every TRUD
message is redacted before it is logged or stored.

**Admin:** Admin → Till health, card "dm+d release server" (read only): on / off, TRUD key "Set" / "Not set" (never the
key), the release served (name, week, NHSBSA date, size, first 12 characters of the SHA-256, published), last run, last
error.

**Config** (`config/pharmacy.php`): `PHARMACY_DMD` (false), `PHARMACY_DMD_TRUD_KEY`, `PHARMACY_DMD_DISK` (local),
`PHARMACY_DMD_PUBLIC_URL` (https base of `downloadUrl`, default APP_URL with https); item 24, TRUD URLs, timeouts, path,
keep weeks (2), link hours (48) and limits in the file.

**Migration** `2026_12_15_100000_create_dmd_release_tables.php`: `dmd_releases`, `dmd_fetch_runs` (new tables, SQLite and
MySQL 8).

Tests: `tests/Feature/Pharmacy/DmdManifestApiTest.php`, `DmdPackageDownloadTest.php`, `FetchDmdReleaseTest.php` (TRUD
always `Http::fake`d), `DmdAdminCardTest.php`, `tests/Feature/Contract/DmdManifestContractTest.php`.

### Owner to-dos before switching on

1. Get our own NHS TRUD account (https://isd.digital.nhs.uk), subscribe it to item 24 "NHSBSA dm+d", and put its API key
   in `PHARMACY_DMD_TRUD_KEY` in the server's `.env` only (never in the repository, a ticket or chat).
2. Check TRUD's subscription and redistribution terms for item 24: serving NHSBSA's zip to our pharmacy customers must be
   allowed (dm+d itself is under the Open Government Licence v3.0; the attribution is kept with the files).
3. Then set `PHARMACY_DMD=true`, run `php artisan pharmacy:dmd-fetch` once and check the card on Admin → Till health
   (a week served, no error). Plan disk for about three zips of up to 150 MB.
