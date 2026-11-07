# SSPOS till — pharmacy shop type — what changes for the portal (2026-10-24, branch `pharmacy`, not yet released)

For the web-portal developer. Everything below is on the till's `pharmacy` branch; nothing has shipped to a shop
yet. The contract is still v1 (`X-SSPOS-Contract: 1`): no released field, table, enum value or setting key was removed
or renamed, and a shop that is not a pharmacy sends exactly what it sends today. One new read-only endpoint is asked
for (item 12).

## How the cut-off was decided

The pharmacy is a shop type chosen in the till's setup wizard (`setup.business_type` = `Pharmacy`, which also sets
`vertical.pharmacy` = true). Everything new is gated on that. Every new pharmacy table is **local** for this
release: it never enters the till's ChangeLog, is never pushed, never pulled, and is left out of the history upload.
A refused row would hold up that shop's whole sync queue, and the portal has no tables for them yet — so the only
pharmacy rows on the wire are the ones that already exist on main (`DispensingRecord`, `DispensingItem`,
`MedicineClassification`), now with a few additive fields.

## Summary

| # | What changed | Type | Till | Portal |
|---|---|---|---|---|
| 1 | `DispensingRecord.exemption` gains 10 values at the end: `agedUnder16Or60Plus`, `fullTimeEducation16To18`, `hrtPrepaymentCertificate`, `incomeRelatedEsa`, `incomeBasedJsa`, `pensionCreditGuaranteeCredit`, `universalCredit`, `freeContraceptive`, `sexualHealthOrTuberculosis`, `prisonerOnRelease`. Old values stay (old records keep them). | enum, additive | pharmacy shops | **must** accept before any pharmacy runs this build — a 422 would block that shop's queue |
| 2 | `DispensingRecord` gains `prescriberAddress` (string), `exemptionEvidence` (`notRecorded`, `seen`, `notSeen`, `notNeeded`), `saleLinePosition` (int), `chargeSettledAt` (date-time or null) and `chargeRefunded` (money) | fields, additive | pharmacy shops | **must** accept; treat a missing field (older till) as `""` / `notRecorded` / 0 / null |
| 3 | `DispensingItem` gains `freeOfChargeReason` | field, additive | pharmacy shops | **must** accept; missing = none |
| 4 | 21 new pharmacy tables (registers, RP record, patients, poisons, dm+d links / NHS price history / concessions, retail flags, wholesaler links and order sends …). The dm+d dictionary itself is **not** a table any more: it is a file of its own on the main till (item 12) | new tables, `local` | pharmacy shops | nothing — never sent; listed as `local` in `samples/ownership.json`, schemas in `schemas/entities/` for reference only |
| 5 | Shared Setting keys a pharmacy may push: `pharmacy.two_pack_guidance`, `pharmacy.codeine_guidance`, `pharmacy.wwham_card` (bool); `pharmacy.ods_code`, `pharmacy.gphc_premises_number`, `pharmacy.ready_message` (text); `pharmacy.ppc_3_month_price`, `pharmacy.ppc_12_month_price`, `pharmacy.ppc_hrt_price`, `pharmacy.private_dispensing_fee` (money); `pharmacy.private_markup_percent` (number); `pharmacy.dmd_library_fallback` (bool) | setting keys, additive | pharmacy shops | **should** store like any shared key; show them only for a shop whose `setup.business_type` is `Pharmacy` |
| 6 | Local-only setting keys `pharmacy.dmd_trud_api_key`, `pharmacy.dmd_server_client_id`, `pharmacy.dmd_server_client_secret`, `pharmacy.dmd_checked_utc`, `pharmacy.dmd_source`, `pharmacy.dmd_problem`, `pharmacy.dmd_problem_utc` | settings, local | pharmacy shops | nothing — never sent (listed in `samples/settings-local-only.json`) |
| 7 | The till now **ignores** a pulled `vertical.pharmacy`, `vertical.*` or `setup.business_type` (only its own setup wizard sets the shop type) | behaviour | every shop | **should** show those keys read-only; a value the portal sends is skipped (`Skipped`, not an error) |
| 8 | `RolePermission` keys `pharmacy.*` (e.g. `pharmacy.responsible_pharmacist`, `pharmacy.cd_register`) | permission keys, additive | pharmacy shops only | **should** accept; a grocery shop no longer gets any `pharmacy.*` row on its Owner role |
| 9 | Non-catalogue sale-line product ids `RX-PRIVATE` (0% VAT), `RX-PPC-3M`, `RX-PPC-12M`, `RX-PPC-HRT` (outside the scope of VAT), `RX-SERVICE` (pharmacy service) — alongside the existing `RX-CHARGE` | sale lines | pharmacy shops | **should** treat like `RX-CHARGE` / `BAG-CHARGE` (no product row behind them) |
| 10 | New AuditLog actions: `DispensingChargeSettled`, `PharmacyVatCheckDone`, and the RP / register / poisons / patient writes (`entityName` = the table) | audit rows | pharmacy shops | nothing (show as text) |
| 11 | Clone packs never carry a shop-type key or a `pharmacy.*` setting | behaviour | every shop | nothing |
| 12 | **New endpoint** `GET /api/v1/pharmacy/dmd/latest` + the zip it names: the weekly NHS dm+d release for pharmacy tills (section below) | new endpoint, read-only | pharmacy main tills | **should** build before pharmacies go live; until then the till uses NHS TRUD with the pharmacy's own key (a 404 is fine) |
| 13 | Ordering from a wholesaler: 2 more local tables `PharmacyWholesalerLink`, `PharmacyWholesalerOrderSend`; permission key `pharmacy.wholesaler_orders`; AuditLog action `SendOrderToWholesaler` (`entityName` `PurchaseOrder`, `reason` = order number) | new tables `local`, permission key, audit rows | pharmacy shops | nothing — tables never sent; the key and audit rows as items 8 and 10 |
| 14 | Ledger: new `Account` rows `2270` "PPC money owed to NHSBSA" (Liability) and `4050` "NHS prescription charges" (Income, outside VAT, no VAT box), pushed op `I` only by a pharmacy. `RX-PPC-*` lines post Cr 2270 (refund Dr 2270), `RX-CHARGE` Cr 4050 (refund Dr 4050) — they used to land in 4020. New posting ref type `PpcDeduction` (refId `branchId:yyyy-MM`, Dr 2270 / Cr 1230), AuditLog action `PpcDeductionRecorded`, error codes `pharmacy.ppc_deduction_*` | accounts, postings, additive | pharmacy shops only | **must** accept the `Account` rows and journal lines on them; **should** keep 4050 and 2270 out of any VAT box 6 figure |

## Order of rollout

1. Portal accepts items 1–3 (and ideally 5, 7, 8, 9).
2. Only then is a pharmacy build released to a pharmacy shop. Grocery, halal-meat and other shops can take the build
   at any time — they send nothing new.

## dm+d from the SSPOS release server (item 12, 2026-10-25)

A pharmacy's till keeps NHSBSA's Dictionary of medicines and devices (dm+d) in a file of its own, rebuilt every week.
It fetches the week's release **from our server first**, and only falls back to NHS TRUD with the pharmacy's own TRUD
key when our server cannot help — so a pharmacy needs no TRUD account. The till looks quietly (after trading hours,
overnight, or when the till is idle), on its main till only; a secondary till never calls. Nothing is pushed: this is a
read-only download.

**What the server serves.** NHSBSA's weekly release zip **exactly as TRUD gives it** (`nhsbsa_dmd_<version>_<yyyymmdd>000001.zip`,
the GTIN zip still nested inside). Do not unpack, repack or rename it: the till reads it with the same importer as a
file the pharmacy imports by hand, and it compares `archiveFileName` with the release in use to know whether there is
anything new — the same name TRUD lists, so switching between our server and TRUD never downloads a week twice.

### `GET {portal}/api/v1/pharmacy/dmd/latest` — the manifest

- **Address**: the portal address the till already uses for sync (the Hub address / `hubUrl`, else the Licence server,
  else the built-in one — `SIMPLE-SETUP.md` §2).
- **Auth**: exactly as `sync/hello`: `Authorization: Bearer <branch sync key>` and the section 3 headers
  (`X-SSPOS-Company-Id`, `X-SSPOS-Branch-Id`, `X-SSPOS-Register-Id`, `X-SSPOS-Contract: 1`, `X-SSPOS-Store-Protocol`,
  `X-SSPOS-App-Version`). A till with no sync key does not call at all (it uses TRUD). The portal **should** answer only
  branches whose `setup.business_type` is `Pharmacy` (403 otherwise).
- **200** `application/json` (unknown fields are ignored; more may be added):

```json
{
  "releaseId": "nhsbsa_dmd_10.3.0_20261026000001",
  "name": "Release 10.3.0",
  "releaseDate": "2026-10-26",
  "week": "2026-W44",
  "archiveFileName": "nhsbsa_dmd_10.3.0_20261026000001.zip",
  "downloadUrl": "https://portal.sspos.co.uk/api/v1/pharmacy/dmd/packages/nhsbsa_dmd_10.3.0_20261026000001.zip",
  "sizeBytes": 41234567,
  "sha256": "9F86D081884C7D659A2FEAA0C55AD015A3BF4F1B2B0B822CD15D6C15B0F00A08",
  "publishedAt": "2026-10-26T07:12:00Z"
}
```

  | Field | Rule |
  |---|---|
  | `archiveFileName` | required; NHSBSA's own zip name, unchanged |
  | `downloadUrl` | required; `https`. Relative is allowed (resolved against the portal address). On the portal's own host the till sends the bearer and headers again; on **any other host** (a CDN, blob storage) it sends **nothing** but the GET, so such a URL must be pre-signed and should expire no sooner than 24 hours after it is handed out |
  | `sha256` | required; 64 hex characters (either case) of the zip. A manifest without it is refused |
  | `sizeBytes` | the zip's size; the till refuses a download of another size (0 = not checked) |
  | `releaseDate` | NHSBSA's publication date (`yyyy-MM-dd`); the till uses it to know whether this week's release is in use |
  | `name`, `releaseId`, `week`, `publishedAt` | shown / logged only |

- **404** with the error envelope (`code` e.g. `dmd.no_release`): nothing published yet. The till falls back to TRUD (or
  tries again in an hour). **401/403**: key refused / not a pharmacy — the till falls back to TRUD and shows "check
  Cloud sync". **5xx/429**: tried again in an hour (TRUD meanwhile). No other code is needed.
- **Caching**: `Cache-Control: no-cache` (or `max-age` ≤ 300). An `ETag` is welcome but the till does not send
  `If-None-Match` yet; the manifest is a few hundred bytes.

### `GET {downloadUrl}` — the zip

`200 application/zip`, the whole file (no `Range` needed; the till downloads it whole and retries later on a cut-off).
Packages are immutable — a name is never reused for other bytes — so `Cache-Control: public, max-age=31536000,
immutable` is right. The till checks the size and SHA-256 before it reads anything; a mismatch is thrown away and it
tries TRUD. **Size**: NHSBSA's weekly zip is tens of MB (roughly 30–60 MB; plan for 150 MB). Each pharmacy main till
downloads it **once a week**, at night or after closing; nothing on a day when the week in use is already the newest.

### Publishing a new week

1. Every Monday after NHSBSA publishes on TRUD (usually by 06:00 UK time), a job downloads TRUD item 24 ("NHSBSA dm+d")
   with **our own** TRUD key, and checks TRUD's own `archiveFileSha256`.
2. It stores the zip unchanged under its own name and computes `sizeBytes` and `sha256`.
3. Only then does it switch the manifest to the new week, in one write — the manifest never names a zip that is not
   fully stored. Keep the previous two weeks' zips for tills that read the old manifest a moment before the switch.
4. A bank-holiday week may come on Tuesday: just publish it when it appears. Tills that found nothing new look again
   every six hours that week.

**Licence.** dm+d is NHSBSA's, under the Open Government Licence v3.0; the TRUD subscription terms for item 24 apply to
our download. Keep the attribution ("Contains public sector information licensed under the Open Government Licence
v3.0") with the files, and check TRUD's terms for redistribution before going live.

## Later (not in this release)

When the registers, RP record or patients are to reach the portal, they hold UK special-category health data and
need encrypted, UK-hosted storage first. The till will then move those tables from `local` to branch-owned and queue
every existing row once (a one-time "I" ChangeLog backfill), so nothing written while they were local is lost.
