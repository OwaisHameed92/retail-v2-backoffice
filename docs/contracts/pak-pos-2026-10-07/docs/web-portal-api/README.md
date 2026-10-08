# SSPOS web portal — API package v1.4 (2026-09-29) — START HERE

**Since the v1.4-PREVIEW you built from (2026-09-28 22:48)** — no endpoint moved, no field was removed or renamed:
- `UPCOMING-CHANGES.md` is folded into `docs/web-portal-api.md` (§2, §7, §8, §10.3, §10.5–§10.7, §19.3, §20.1); new **§21 Guarantees checklist**.
- `BranchPrice` now also reaches secondary tills (≈20 s), labels, electronic shelf labels, customer orders and the scale PLU export (§10.5) — same rows, same pull.
- A head-office order the shop has sent, cancelled or started receiving is final: your later changes are refused and flagged (§10.6).
- Schemas now list what the till already sends: validate `lock.reason` adds `wrongInstall` / `wrongBranch` / `other`; redeem adds `deviceName`, `appVersion`, `os`, `tillClockUtc`; optional `sync_key.*` error codes.
- §17.9: a per-till till locks on `seatLimit` like any status but `active` / `expiring`; §17.12 lists `licence.bad_signer_certificate`.

This package is everything needed to build the SSPOS web portal: the cloud side that the SSPOS tills
(Windows point-of-sale software in shops) talk to. The tills are already written against this contract and
their side is built; the portal must implement it exactly. Contract major `1` (`/api/v1`, `X-SSPOS-Contract: 1`),
document revision 1.4, OpenAPI and Postman 1.4.0. Nothing is pending (`docs/web-portal-api/UPCOMING-CHANGES.md` says
so).

## Read in this order (paths from the package root)

| # | File | What it is |
|---|---|---|
| 1 | `docs/web-portal-api/ANSWERS-2026-09-28.md` | Answers to the portal team's questions (Roman Urdu), with a short **v1.4 update** at the end |
| 2 | `docs/web-portal-api/SIMPLE-SETUP.md` | What the shop types (a licence key per till, a sync key per shop for the dashboard) and which calls follow each key |
| 3 | `docs/web-portal-api/DASHBOARD.md` | The dashboards: ingest, reporting tables, every figure the till shows and how to get the same number |
| 4 | `docs/web-portal-api.md` §1–20 | **The contract.** §1–3 set-up, structure, transport · §4–9 sync (hello / push / pull, envelope, JSON, times, errors) · §10–13 who owns what, relays, settings, shop prices, head-office orders, secrets, payloads · §14–16 testing, files, what is built · §17 licensing, devices, migration · §18 the portal product · §19 never twice, never backwards · §20 offline first, future changes |
| 5 | `docs/web-portal-api.md` §21 | **Guarantees checklist**: every guarantee, what the portal must do, and the test to pass before a real shop is connected |
| 6 | `specs/licensing.md` | The business rules behind licensing (owner decisions, dated; later sections supersede earlier ones) |
| 7 | `docs/web-portal-api/openapi.yaml` | OpenAPI 3.1 — the 9 endpoints the till calls plus `devices/activate` (deprecated, not called): headers, bodies, replies, errors, idempotency, examples (open in Swagger Editor / Redocly / Stoplight with the folder beside it) |
| 8 | `docs/web-portal-api/SSPOS.postman_collection.json` + `SSPOS.postman_environment.json` | Postman: every call with sample bodies, saved example replies (usable as a mock server) and tests |

Background notes (already folded into the contract): `PER-TILL-LICENSING.md` (§17.15), `KEY-CARRIES-SHOP.md`
(§17.16).

## What changed

**v1.4 — 2026-09-29 (this package; everything since v1.3.3).** Additive: `X-SSPOS-Contract` stays `1`.
- **New data:** `BranchPrice` — a shop's own price, portal-owned (§10.5); head-office purchase orders —
  `PurchaseOrder.origin: "headOffice"` drafted on the portal for one shop, received there with the normal goods-in,
  which may also send or cancel it (§10.6, `"hubDrafted"` in `samples/ownership.json`); `Setting` and
  `RolePermission` keyed rows both ways with a deny-list (§10.3); relayed transfers, receipts and customer ledger
  rows (§10.2); the customer ledger is the truth for balance and points (§10.1, `CustomerTransactionType.opening`);
  customer-order references `CO-{branch}-{till}-{number}` (§10.4). New columns: `PurchaseOrder.origin`,
  `StockTransfer.lineCount`, `Register.nextOrderNo`, `SyncConflict.hubChange` (§13). New samples: `samples/pull-reply.head-office.json`,
  `pull-reply.relay.json`, `push-request.settings.json`, `settings-local-only.json`,
  `entities/{BranchPrice,Supplier,PurchaseOrder,PurchaseOrderLine,Setting,RolePermission,StockTransfer,CustomerTransaction}.json`.
- **Removed from the JSON:** `User.remoteApprovalSecret` and `remoteApprovalSecretSetAt` — never sent, never read;
  a blank `pinHash` keeps the till's PIN (§10.7). Delete any values you stored from test pushes.
- **Reliability:** a push entry is the row's latest state and several entries of one row share one triple (§7);
  clashes keep your row (new column `SyncConflict.hubChange`, pushed with the clash), child-before-parent rows wait
  on disk for 7 days, and the till pulls even after a failed push (§8, §9, §19.3).
- **Retries:** one `Idempotency-Key` per logical request on every POST (and each history-upload batch), the same
  body byte for byte on every retry; do not store a 408 / 409 `request.in_progress` / 429 / 502 / 503 / 504 reply
  under a key (§17.11 rule 5).
- **Tills:** store protocol 3 — a shop's staff, shop-wide settings and shop prices are the main till's, copied to
  every till over the shop LAN only (nothing extra reaches you; labels, electronic shelf labels and the scale PLU
  export use the shop price too, §10.5); every row's `userId` is a main-till user (§2 point 2, §3). Licence calls
  do not send `X-SSPOS-Store-Protocol` (§3).
- **Keys:** permission `sale.view_all_orders` (without it staff see only their own sales), setting
  `till.refund_needs_manager` (default off), refund PIN defaults now off (`till.manager_pin_refund_over` `0.00`,
  `till.refund_without_receipt_pin` `false`) (§10.3); every approved refund writes an `AuditLog` row
  `RefundApproved` under the approver (§20.1).
- **Contract text made true:** `devices/activate` is not called (deprecated in OpenAPI); sync keys may be refused
  with the optional codes `sync_key.not_found` / `.expired` / `.used` (§17.12); the redeem request lists what the
  till sends (`deviceName`, `appVersion`, `os`, `tillClockUtc`); §14 (how to test), §16 (built / still open) and
  §18.10 say what is built today; §17.9 marks `seatLimit` / `seat` as the branch model (a per-till till locks on
  any status but `active` / `expiring`); §17.12 lists `licence.bad_signer_certificate`; **§21 guarantees
  checklist** is new. `UPCOMING-CHANGES.md` is folded in and now says "Nothing pending".

**Earlier packages**
- v1 — 2026-09-26: first package: sync §1–16, licensing §17, portal product §18, sync rules §19, offline and
  future changes §20.
- v1 (later) — 2026-09-26: **per-till licensing** — every till has its own e-mailed licence key, activated online
  and bound to that PC: `POST /api/v1/licence/activate`, `licence/validate` for every till, status `released`
  (`PER-TILL-LICENSING.md`, §17.15).
- v1.2 — 2026-09-28: **a key carries the shop** — `company` block, `maxRegisters` per branch, `limits.branches`,
  feature `multi_branch`; open local keys, reported to `licence/redeem` (no auth); 409
  `key.used_on_another_install` (`KEY-CARRIES-SHOP.md`, §17.16). OpenAPI 1.2.0.
- v1.3 — 2026-09-28: **times** — the push reply's `receivedAt`, every time ends in `Z`, pulled rows keep your
  `createdAt` / `updatedAt` (§6.1). OpenAPI 1.3.0. Also in the v1.3.x packages: **signer certificates** — your
  signing key needs no till release (§17.17) — and a licence key is required on a new install (§17.15).
- v1.3.1 — 2026-09-28: **dashboards** — `DASHBOARD.md` (ingest, reporting tables, every figure; MySQL 8 appendix).
- v1.3.2 — 2026-09-28: **simple set-up** — the shop types a licence key per till and, for the dashboard, one
  sync key (Connect → `cloud/migrate` or `hello`); the portal address is built into the till; `apiKey` in the
  `licence/activate` / `validate` reply connects the dashboard with no second key (`SIMPLE-SETUP.md`).
- v1.3.3 — 2026-09-28: **answers** to the portal team's questions — the till keeps its own ids (adopt / alias),
  one code instead of two, trial keys, MySQL 8, the 11 feature names, signer hand-over
  (`ANSWERS-2026-09-28.md`).
- v1.4-PREVIEW — 2026-09-28 22:48: the v1.4 rules while they were being written, with `UPCOMING-CHANGES.md` as the
  newer text. Superseded by this package (differences at the top of this page).

## What the portal does

1. **Onboarding** — a shop owner signs up; the portal creates their business (company) and first branch and
   e-mails **one licence key per till** (a trial key at sign-up) with the installer link.
2. **Licensing** — licences are bought on the portal. The portal signs licence tokens (Ed25519, with its signer
   certificate), answers every till's daily check, counts tills per branch, extends trials, renews, and can
   release or withdraw a key.
3. **Sync** — each branch's main till pushes its changes (sales, stock, shifts…) and pulls what the portal
   owns (products, prices, shop prices, users…). One business → many branches → many tills per branch.
4. **Two panels** — an **Admin panel** (businesses → shops → tills, licence keys, tills allowed per shop,
   setup e-mails, dashboards) and a **Business panel** where each shop owner sees and changes their own
   business. Full brief in `docs/web-portal-api.md` §18; licence checklist §17.10; guarantees §21.

## Folders (under `docs/web-portal-api/`)

- `schemas/`, `samples/` — the sync data: JSON Schema and a real sample for every envelope and entity the
  till sends or accepts. **Generated from the till's code** — never edit by hand; ask for a new package.
- `licensing/schemas/`, `licensing/samples/` — licensing requests/replies, the token payload, every error
  code (`licensing/samples/error-codes.json`), `licence-token.worked-example.json` and
  `signer-certificate.worked-example.json`: full signing walk-throughs with documentation-only key pairs and real
  signed tokens to test your signer against.

## Before you start

- **Signing key hand-over:** generate the portal's own Ed25519 key pair on the server (§17.2), keep the
  private key server-side only, and send us the **public key and its key id** (format in
  `licensing/samples/public-key-handover.json`). We send back a **signer certificate** (§17.17) that you put in
  every token. The key pairs in the worked examples are public test vectors — never use them for real licences.
- **Address:** send us your production base URL (HTTPS) — it is built into the till at release (§1).
- **Compatibility rules (§17.11):** ignore unknown fields, enums are strings, everything under `/api/v1`;
  new fields may appear in any reply or token without notice.
- **Test:** import the Postman collection + environment, point `baseUrl` at your server, run Licence →
  Sync → Migration; then the §21 checklist with a real test till (§14). Your signer must reproduce the
  worked-example tokens byte for byte (PHP and Node snippets in §17.2 do).

Questions about the contract go back to the SSPOS team; the version and date of this package are in
the file name.
