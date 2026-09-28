# A key carries the shop — what changed for the portal (2026-09-28)

**Decisions (SSPOS owner, 2026-09-28).** This note is the short version; the contract is `docs/web-portal-api.md`
§17.16 (with §17.2, §17.6, §17.15.1, §17.10 and §17.15.3 updated), the business rules `specs/licensing.md` §8.
Everything is **additive**: no field, endpoint or status is removed, and an older token still works.

## What changed

1. **A key carries the shop.** Every licence token (yours and our generator's) may carry the shop's details in a
   new optional `company` object — `businessType`, `address`, `town`, `postcode`, `phone`, `email`, `vatNumber`,
   `ownerName`, `receiptFooter` (all optional strings) — beside `businessName` and `branchName`.
2. **Tills, branches, multi-branch.** Tills = `maxRegisters` (per branch, main till included — in a per-till token
   it is now the branch's number, not 1). Branches = `limits.branches` (1 when absent). Multi-branch = feature
   **`multi_branch`** (snake_case, as the till spells it — `multi-branch` is a different name). Length =
   `validFrom` / `expiresAt`; trial / full = `kind`.
3. **The details only start the till's wizard.** The till pre-fills its first-run wizard from them; the shop can
   change every one on the till; the edits sync up as Company / Branch rows. **A later token never overwrites them.**
4. **Many tills, one shop.** Each till has its own key; every key of one shop carries the **same** `companyId`
   and `branchId`. You count a branch's tills against `maxRegisters` and a company's branches against
   `limits.branches`.
5. **Open local keys.** Our generator may leave `installCode` empty; the first till that accepts the key binds it
   to itself. Renewals for that till are made for its install code.
6. **Local keys are reported to you.** A till holding a local key calls **`POST /api/v1/licence/redeem`** once per
   token when a licence server is set and reachable (`keyType: "token"`, `installCode`, `installId`, **no
   `Authorization`**). This reverses §17.6's old "an unlinked till never calls this".

## What the portal must do

- **Sign** every token (`licence/activate`, `validate` renewals, `redeem` `applied`) with the `company` block,
  `maxRegisters`, `limits.branches`, `features` (incl. `multi_branch` when sold), `validFrom`/`expiresAt`, `kind`
  — and list the same fields in the reply's `licence` summary (§17.15.1).
- **Accept** feature names containing `_` — the name pattern is now `^[a-z0-9]+([._-][a-z0-9]+)*$`.
- **Count**: refuse a till key past `maxRegisters` (issue time; at `licence/activate` → 403 `licence.seat_limit`)
  and a branch past `limits.branches` or a second branch without `multi_branch`.
- **Local key reports** (`licence/redeem`, §17.6 (b)): verify the token with our generator public keys; **first
  sighting** of a `licenceId` → record `licenceId → installCode` (+ `installId`), reply 200 `result: "recorded"`,
  `licenceToken: null`; the **same** `licenceId` from the same install code → 200 again; from **another** install
  code → **409 `key.used_on_another_install`** (`details {licenceId, installCode, firstSeenUtc}`) — that till locks.
- **Admin panel**: customer form with the company block, tills, branches, multi-branch, features, length
  (days / months / years), trial / full; each till's `installCode` / `installId`; release and re-issue keys; the
  local key register (and a way to clear one record when a dealer moves an open key to a replacement PC).
- **Never** treat the licence form as the live shop details after first run — the synced Company / Branch rows are.

## Samples to look at (`licensing/samples/`)

| File | Shows |
|---|---|
| `licence-token.payload.per-till.json` | Portal per-till token **with** the company block, `maxRegisters: 3`, `multi_branch`, `limits.branches: 2` |
| `licence-token.payload.full.json`, `licence-token.payload.trial.json` | Portal tokens **without** the company block (still valid) |
| `licence-token.payload.local-open.json` | Generator **open** key (no `installCode`) **with** the company block |
| `licence-token.payload.local.json` | Generator key bound to an install code, **without** the company block |
| `licence-activate-reply.json` | The signed per-till token and the `licence` summary listing the shop fields |
| `redeem-request.local-report.json` → `redeem-reply.local-report.json` | A local till reporting its open key; first sighting recorded |
| `error.key-used-on-another-install.409.json` | The same `licenceId` reported from a second PC |
| `licence-token.worked-example.json` | Documentation keys; `otherTokens.perTill` and `otherTokens.localOpen` are the new tokens — your verifier must accept them |

Schemas: `licensing/schemas/common.schema.json` (`licenceCompany`, `featureName`, `limits`, `licenceSummary`),
`licence-token-payload.schema.json`, `redeem-request.schema.json` (`installId`), `redeem-reply.schema.json`,
`licence-activate-reply.schema.json`; the code list is `licensing/samples/error-codes.json`. OpenAPI `openapi.yaml` is 1.2.0 (redeem: optional
security, the `localReport` examples, `key.used_on_another_install` under 409). Postman: **Licence → "Report local
key (local till, once per key)"**, no auth.
