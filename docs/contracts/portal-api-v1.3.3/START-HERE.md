# SSPOS web portal — API package (v1.3.3) — START HERE

This package is everything needed to build the SSPOS web portal: the cloud side that the SSPOS tills
(Windows point-of-sale software in shops) talk to. The tills are written against this contract; the portal must
implement it exactly.

## What changed

- **2026-09-28 — v1.3.3 — answers to the portal team: `docs/web-portal-api/ANSWERS-2026-09-28.md`** (read first).
  Ids: the till keeps its own ids — adopt / alias (§17.3 step 3). One code: return `apiKey` in the
  `licence/activate` / `validate` reply when the licence has the dashboard (schemas updated); `devices/activate` is
  not called — do not build it. Trial: key required, no built-in trial (§17.15 fixed). §18.3 rewritten for per-till
  keys. MySQL 8 appendix in `DASHBOARD.md`. The 11 feature names (§17.2). BranchPrice and portal purchase orders
  are coming in v1.4 (`UPCOMING-CHANGES.md`).
- **2026-09-28 — v1.3.2 — `SIMPLE-SETUP.md`**: the shop types a licence key per till and, for the dashboard, a sync
  key per branch; the portal address is built into the till.
- **2026-09-28 — v1.3.1 — `DASHBOARD.md`**: dashboards build spec.
- **2026-09-28 — v1.3**
  - **Signer certificates — your signing key needs no till release** (`docs/web-portal-api.md` §17.17). Send the
    owner your **public** key (`licensing/samples/public-key-handover.json`); you get back a certificate
    (`SSPOSCERT1.…`). Put it in **every** token you sign as the new payload field **`signerCert`**. Every request
    that can receive a token now also carries **`approverKids`** beside `trustedKids`. New error
    `licence.bad_signer_certificate`. Worked example you can test your signer against byte for byte:
    `licensing/samples/signer-certificate.worked-example.json`.
  - **A licence key is required on a new install.** The till's first-run wizard no longer offers a built-in trial:
    **e-mail a trial key at sign-up** (any length you choose). Tills already on the old built-in trial keep it until it
    ends. (`PER-TILL-LICENSING.md` step 1, §17.3 local path.)
  - §14 and §18.10 corrected: the till's HTTPS sync client and licence calls are built.
  - **Read `docs/web-portal-api/UPCOMING-CHANGES.md`** — decided additions coming in v1.4 (times incl. `receivedAt`,
    several tills per shop, customer balance, branch transfers, settings sync). Build the rest now; leave room for these.
- 2026-09-28 — v1.2 a key carries the shop (`docs/web-portal-api/KEY-CARRIES-SHOP.md`, §17.16).
- 2026-09-26 — v1.1 per-till licensing (`docs/web-portal-api/PER-TILL-LICENSING.md`, §17.15).
- 2026-09-26 — v1: sync §1–16, licensing §17, portal product §18, sync rules §19, offline and future changes §20.

## What the portal does

1. **Onboarding** — a shop owner signs up; the portal creates their business (company) and first branch,
   issues a **trial licence key** and e-mails it with the installer link.
2. **Licensing** — licences are bought on the portal. The portal signs licence tokens (Ed25519, with its
   `signerCert`), checks every till once a day, counts tills per branch, extends trials, renews, can withdraw a licence.
3. **Sync** — each branch's main till pushes its changes (sales, stock, shifts…) and pulls what the portal
   owns (products, prices, users…). One business → many branches → many tills per branch.
4. **Two panels** — an **Admin panel** (businesses → shops → tills, licences, setup e-mails, dashboards) and a
   **Business panel** where each shop owner sees and changes their own business. Full brief: §18.

## Read in this order (paths from the package root)

| # | File | What it is |
|---|---|---|
| ★ | `docs/web-portal-api/ANSWERS-2026-09-28.md` | **Answers to your 10 questions** (v1.3.3) |
| ★ | `docs/web-portal-api/SIMPLE-SETUP.md` | What the shop types and what the portal must issue |
| ★ | `docs/web-portal-api/DASHBOARD.md` | Build the dashboards from this (MySQL 8 appendix at the end) |
| ★ | `docs/web-portal-api.md` §17.17 | Signer certificates, `signerCert`, `approverKids` |
| ★ | `docs/web-portal-api/UPCOMING-CHANGES.md` | Decided additions coming in v1.4 |
| ☆ | `docs/web-portal-api/KEY-CARRIES-SHOP.md`, `PER-TILL-LICENSING.md` | v1.2 and v1.1 changes |
| 0 | `docs/web-portal-api.md` §18 | The portal product: panels, roles, screens, dashboards, e-mails |
| 0b | `docs/web-portal-api.md` §19 | Synced once, never twice, never backwards: duplicate, echo and conflict rules + tests |
| 0c | `docs/web-portal-api.md` §20 | Everything the shop does comes up; the till never waits for the portal |
| 1 | `docs/web-portal-api.md` §1–3 | Company → branch → till, auth and headers |
| 2 | `docs/web-portal-api.md` §17 | Licensing, devices, onboarding: endpoints, token format, flows, statuses, checklist, errors |
| 3 | `docs/web-portal-api.md` §4–16 | Sync: hello / push / pull, envelope, JSON rules, ownership, errors, testing, known gaps |
| 4 | `specs/licensing.md` | Business rules behind licensing |
| 5 | `docs/web-portal-api/openapi.yaml` | OpenAPI 3.1 — every operation, schema, example, error |
| 6 | `docs/web-portal-api/SSPOS.postman_collection.json` + environment | Postman: every request with example replies (usable as a mock server) |

## Folders (under `docs/web-portal-api/`)

- `schemas/`, `samples/` — sync data: JSON Schema + real sample for every envelope and entity. **Generated from the
  till's code** — never edit by hand.
- `licensing/schemas/`, `licensing/samples/` — licensing requests/replies, token payload, every error code
  (`error-codes.json`), token and signer-certificate worked examples (documentation-only keys).

## Before you start

- **Signing key:** generate the portal's Ed25519 key pair on the server (§17.2); the private key never leaves it.
  Send the owner the **public key** only and use the certificate you get back (§17.17).
- **Compatibility (§17.11):** ignore unknown fields, enums are strings, everything under `/api/v1`; new fields may
  appear in any reply or token.
