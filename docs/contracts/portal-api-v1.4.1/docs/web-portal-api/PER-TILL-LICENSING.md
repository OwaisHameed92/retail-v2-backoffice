# Per-till licensing — what changed for the portal (2026-09-26)

**Decision (SSPOS owner): every till has its own licence.** This note is the short version; the contract is
`docs/web-portal-api.md` §17.15 (it supersedes the branch/seat model in §17 for licences).

> **Updated 2026-09-28 — read `KEY-CARRIES-SHOP.md` next.** The token now carries the shop (`company` block),
> `maxRegisters` = tills allowed in the **branch** (no longer 1), `limits.branches`, feature `multi_branch`; every
> till key of one shop shares `companyId`/`branchId`; local keys are reported to `licence/redeem`.

## The flow

1. The shop installs SSPOS. **Since 2026-09-28 the first-run wizard needs a licence key before set-up** — so the
   portal must e-mail a **trial key** at sign-up (any length you choose), not rely on a built-in trial.
2. You (the portal) e-mail the shop a **licence key per till**, e.g. `SSPK-4F7Q-XM2D-9KTR-B6WN` (layout is your
   choice: letters, digits, dashes, 8–64 characters).
3. On the till: Settings → Licence (or the lock screen) → paste the key → **Enter key**.
4. The till calls **`POST /api/v1/licence/activate`** with the key, its **`installId`** (the PC's system id, a ULID
   made once per install), `installCode` (short code shown on the till, e.g. `AC4F-3FHG`), PC name, app version,
   OS, clock, trusted key ids and its own company/branch/register ids.
5. You bind the key to that `installId` **on first use** and return a signed token for that till
   (`installCode` inside it, `onlineCheck` daily / 14 days' grace, `validFrom`/`expiresAt` = what was sold; since
   2026-09-28 also the shop's `companyId`/`branchId`, names and `company` block, `maxRegisters` = tills in the
   branch, `features`, `limits.branches` — `KEY-CARRIES-SHOP.md`).
6. The same key from **another PC** → `409 key.already_used` (say which PC in `message`). The same PC again → 200.
7. Every day each till calls **`POST /api/v1/licence/validate`** for itself. You answer `active` / `expiring`, or
   `expired` / `revoked` / `suspended` / **`released`** (you released the key from that PC) — anything but
   active/expiring locks that till after its current sale. A new token in the reply (renewal, extension, features)
   is picked up automatically.
8. No internet for 14 days → the till locks until it can check in. Your `portalTimeUtc` is taken as the true time.

## Your admin panel needs

- Create keys (per till) with length, features, limits; e-mail them.
- Per key: bound or not, PC name, install code, install id, first activation, last check-in, app version, lock state.
- **Release** a key from its PC (reinstall / new PC) — or issue a new key. **Renew / extend / revoke / suspend.**
- Since 2026-09-28: the customer form (company block, tills, branches, multi-branch, features, length, trial/full)
  and the local key register — `KEY-CARRIES-SHOP.md`.

## Endpoints, schemas, samples

| | Request | Reply | Errors |
|---|---|---|---|
| `POST /api/v1/licence/activate` (NEW, no auth — the key is the credential) | `licensing/schemas/licence-activate-request.schema.json`, `licensing/samples/licence-activate-request.json` | `licence-activate-reply.schema.json`, `licence-activate-reply.json` (token really signed with doc key `k13799fa4`) | 404 `key.not_found`, 409 `key.already_used`, 410 `key.expired`, 403 `licence.not_active`, 429 `activation.too_many_attempts` — `licensing/samples/error.key-*.json`, `error-codes.json` |
| `POST /api/v1/licence/validate` (per till; branch key optional) | `validate-request.schema.json`, `validate-request.per-till.json` | `validate-reply.per-till.json`, `validate-reply.released.json` | as §17.5 |

Token payload sample: `licensing/samples/licence-token.payload.per-till.json`. Status list now includes
`released` (`licensing/schemas/common.schema.json`). OpenAPI (`openapi.yaml`) and Postman (folder **Licence** →
"Activate licence key (per till)", "Validate (per till, daily)"; environment variable `licenceKey`) are updated.

## Unchanged

- Token format, Ed25519 signing, key ids, the worked example (§17.2) — sign with **your** key and send us your
  public key + kid (`licensing/samples/public-key-handover.json`); since 2026-09-28 we send back a **signer
  certificate** for it (§17.17), so your key needs no till release — tills accept built-in kids and keys an
  approver certified.
- Cloud **sync** linking — a separate topic, now `SIMPLE-SETUP.md`: `apiKey` in your `licence/activate` /
  `validate` reply, or a sync key typed at Connect (`cloud/migrate` / `sync/hello`), then push/pull with the branch
  key. `devices/activate` is not called by the till (§17.4).
- Offline dealer keys (our key generator) keep working on tills without internet; such a shop can move to the
  cloud later with a portal key + `cloud/migrate`. (Since 2026-09-28 a till reports its local key to
  `licence/redeem` when it can reach you — `KEY-CARRIES-SHOP.md`.)

## To test with a real till, send us

1. Your base URL (HTTPS), e.g. `https://portal.example.co.uk` — it is built into the till at release (for a test
   portal it can also be set in the till's **Licence server** field, an advanced override).
2. Your public key line + kid (we send back your signer certificate, §17.17).
3. One test licence key (and later: release it, to test `released`).
