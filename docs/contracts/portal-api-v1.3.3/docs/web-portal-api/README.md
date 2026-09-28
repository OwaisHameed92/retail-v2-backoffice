# SSPOS web portal — API package (v1) — START HERE

This package is everything needed to build the SSPOS web portal: the cloud side that the SSPOS tills
(Windows point-of-sale software in shops) talk to. The tills are already written against this contract;
the portal must implement it exactly.

## What changed

- 2026-09-26 — first package (v1): sync §1–16, licensing §17, portal product §18, sync rules §19, offline and future changes §20.
- 2026-09-26 (later) — **per-till licensing**: every till has its own e-mailed licence key, activated online and bound to
  that PC. New `POST /api/v1/licence/activate`; `licence/validate` sent by every till for itself; new status `released`.
  **Read `PER-TILL-LICENSING.md` first**, then `docs/web-portal-api.md` §17.15.
- 2026-09-28 — **a key carries the shop**: tokens may carry the shop's details (`company` block), tills
  (`maxRegisters` per branch), branches (`limits.branches`) and feature `multi_branch`; the details only start the
  till's wizard. Local keys may be open (no install code) and are **reported** to `licence/redeem` (no auth); the
  same key from a second PC gets 409 `key.used_on_another_install`. **Read `KEY-CARRIES-SHOP.md`**, then
  `docs/web-portal-api.md` §17.16. OpenAPI 1.2.0.

## What the portal does

1. **Onboarding** — a shop owner signs up, the portal creates their business (company) and first branch,
   issues a 7-day trial licence and emails a setup link with an **activation code** and the installer link.
2. **Licensing** — licences are bought on the portal. The portal signs licence tokens (Ed25519), checks
   every till once a day, counts tills per branch, extends trials, renews, and can withdraw a licence.
3. **Sync** — each branch's main till pushes its changes (sales, stock, shifts…) and pulls what the portal
   owns (products, prices, users…). One business → many branches → many tills per branch.
4. **Two panels** — an **Admin panel** (businesses → shops → tills, licences, tills allowed per shop,
   setup e-mails, dashboards) and a **Business panel** where each shop owner logs in to see and change
   their own business. Full brief in `docs/web-portal-api.md` §18; licence checklist §17.10.

## Read in this order (paths from the package root)

| # | File | What it is |
|---|---|---|
| 0 | `docs/web-portal-api.md` §18 | **The portal product**: Admin panel and Business panel, roles, screens, dashboards, e-mails, what a portal change does on the till and how fast |
| 0b | `docs/web-portal-api.md` §19 | **Synced once, never twice, never backwards**: duplicate, echo and conflict rules, and the test list to pass before a real shop is connected |
| 0c | `docs/web-portal-api.md` §20 | Everything the shop does comes up (incl. staff clock-in), the till never waits for the portal, and how future changes reach you |
| 1 | `docs/web-portal-api.md` §1–3 | How a till is structured (company → branch → till), auth and headers |
| 2 | `docs/web-portal-api.md` §17 | Licensing, devices and onboarding: every licensing endpoint, the token format, flows, statuses, the admin checklist, error codes |
| 3 | `docs/web-portal-api.md` §4–13 | Sync: hello / push / pull, the change envelope, JSON conventions, who owns which data, errors |
| 4 | `specs/licensing.md` | The business rules behind licensing (owner decisions) |
| 5 | `docs/web-portal-api/openapi.yaml` | OpenAPI 3.1 — all 9 operations, schemas, examples, errors (open in Swagger Editor / Stoplight) |
| 6 | `docs/web-portal-api/SSPOS.postman_collection.json` + `SSPOS.postman_environment.json` | Postman collection: 15 requests in Onboarding / Licence / Devices / Sync / Migration, with example replies (usable as a Postman mock server) and tests that save ids/keys/tokens |

## Folders (under `docs/web-portal-api/`)

- `schemas/`, `samples/` — the sync data: JSON Schema and a real sample for every envelope and entity the
  till sends or accepts. **Generated from the till's code** — never edit by hand; ask for a new package.
- `licensing/schemas/`, `licensing/samples/` — licensing requests/replies, the token payload, every error
  code (`licensing/samples/error-codes.json`), and `licence-token.worked-example.json`: a full signing
  walk-through with documentation-only key pairs and real signed tokens to test your signer against.

## Before you start

- **Signing key hand-over:** generate the portal's own Ed25519 key pair on the server (§17.2), keep the
  private key server-side only, and send us the **public key and its key id** (format in
  `licensing/samples/public-key-handover.json`). Tills only accept tokens from keys built into them.
  The key pairs in the worked example are public test vectors — never use them for real licences.
- **Compatibility rules (§17.11):** ignore unknown fields, enums are strings, everything under `/api/v1`;
  new fields may appear in any reply or token without notice.
- **Test:** import the Postman collection + environment, point `baseUrl` at your server, run Onboarding →
  Licence → Sync. Your signer must reproduce the worked-example tokens byte for byte (PHP and Node
  snippets in §17.2 do).

Questions about the contract go back to the SSPOS team; the version and date of this package are in
the file name.
