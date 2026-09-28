# Simple set-up — the shop types keys, nothing else (2026-09-28)

**Owner's rule:** whoever installs SSPOS types only the **licence key**. If the shop wants the online dashboard, it
also types a **sync key**. That is all — no server address, no API key + URL + on/off switch, no activation code.

This note is for the portal developer: what the shop types, what the portal must issue, which calls the till
makes after each key, and the error codes the till turns into words. Paths, headers, bodies and replies are
unchanged from `docs/web-portal-api.md` (§3 headers, §4.1 hello, §7 push, §8 pull, §17.4/§17.15 activate,
§17.8 migrate) — this note only says how they chain.

## 1. What the shop types

| Key | Where | How many | Needed? |
|---|---|---|---|
| **Licence key** | First-run wizard step "Licence key", or Settings → System → Licence → Enter key | **One per till** (per PC) | Yes — a till cannot be set up without one |
| **Sync key** | Settings → System → Network → Cloud sync → "Sync key (for the online dashboard)" → **Connect** | **One per branch** (shop), typed on the branch's **main till** | Only for the online dashboard |

On a secondary till the sync key box is greyed: "Enter the sync key on the main till". Only the main till syncs.

## 2. The portal address — built into the till

Every till is built with **one portal address** (`build/portal.json` → `pwsh build/publish.ps1 -PortalUrl …`,
baked into the exe). Licence calls, the cloud move and sync all go to it, with the fixed `/api/v1/…` paths.
**Send the owner the production base URL** (e.g. `https://portal.example.co.uk`, https only) and it goes into
`build/portal.json` before the next release.

Which address a call uses, in order (the first one that is filled in):

| Calls | 1st | 2nd | 3rd |
|---|---|---|---|
| `licence/*`, `devices/deactivate`, `cloud/migrate*` | Settings → Licence → "Licence server" | Hub address (the `hubUrl` your reply sent) | built-in |
| `sync/hello`, `sync/push`, `sync/pull` | Hub address (the `hubUrl` your reply sent) | Settings → Licence → "Licence server" | built-in |

The two settings still exist as **advanced overrides** (dealers, test portals); a shop leaves them blank. So:
if sync lives on another host, send `hubUrl` in the activate / validate / migrate reply and the till uses it for
sync from then on. If sync lives on the same host, `hubUrl` may be omitted from activate/validate — the till
uses the built-in address.

## 3. What the portal must issue

1. **A licence key per till** — exactly as today (`PER-TILL-LICENSING.md`, §17.15).
2. **A sync key per branch** (shown to the owner in the business panel, "Connect the dashboard"). The **same
   string** must work as:
   - the **link code** of `POST /api/v1/cloud/migrate` (`activationCode` in the body, no `Authorization`), and
   - the **bearer** of `sync/hello`, `sync/push`, `sync/pull` (`Authorization: Bearer <sync key>`) for that branch.

   It must keep working as a bearer for as long as the branch is linked (after a Disconnect + Connect, a till
   that has synced before only sends `hello` with it). A `cloud/migrate` reply may issue a different `apiKey`;
   the till then uses that one — but the simplest portal returns the sync key itself as `apiKey`.

   `cloud/migrate` with a sync key must answer 200 **also for a branch the portal already knows** (a shop that
   started on a portal licence: its ids came from you) — `idMapping` "adopted" with the same ids, an upload
   (possibly small), `hubUrl`, `apiKey`. It stays idempotent per `(activationCode, installId)`: a retry returns
   the same reply and `uploadId`.

## 4. The calls, in order

### After the licence key (every till)

1. `POST {portal}/api/v1/licence/activate` — the key, `installId`, `installCode`, device, existing ids (§17.15).
2. 200 → the till stores the signed `licenceToken` and trades. **If the reply carries the cloud link**
   (`apiKey`, optionally `hubUrl`, with this till's `companyId` / `branchId`), the till stores it and switches
   cloud sync **on** by itself — **no sync key needed**. `hubUrl` may be left out when sync is on the built-in address.
3. Daily `POST licence/validate`; an `apiKey` there replaces the held one (rotation).
4. A shop that traded on a local key and now enters a portal key: the key is also left as the cloud-move code
   (§17.8), unchanged.

### After the sync key (the branch's main till, "Connect")

The till looks at whether **this branch has ever sent anything** to the portal (no finished upload, no open
upload, no normal push yet):

**A. Never sent anything → the cloud move (§17.8)**

1. `POST {portal}/api/v1/cloud/migrate` — `activationCode` = the sync key; the till's own ids, registers, licence
   (`localLicenceToken` for a local key, `localTrialEndsAt` on the built-in trial, both null on a portal licence)
   and data summary. Sent **while the owner waits** — on any licence, even before the first sale.
2. 200 → the till stores `hubUrl` + `apiKey` + the upload, switches cloud sync on and says "Connected — the
   dashboard updates about every 30 seconds". Then, in the background: `GET sync/hello` (Bearer `apiKey`) →
   `POST sync/push` with `X-SSPOS-Sync-Mode: initial` in batches → `POST cloud/migrate/complete` → normal sync.
3. A refusal (4xx) → nothing is saved; the reason is shown (table below).

**B. Has sent before (reconnecting) → hello (§4.1)**

1. `GET {hub}/api/v1/sync/hello` with `Authorization: Bearer <sync key>`.
2. 200 with this till's `companyId` / `branchId` → the key is stored as the branch key, cloud sync on,
   push/pull every 30 s (`sync.interval_seconds`).
3. 200 naming **another** company/branch → refused as "belongs to another shop" (`sync.key_other_install`).

**No internet / portal busy** (timeout, no answer, 5xx, 429, 503): the key is **kept**, cloud sync switched on,
and the till says "No internet just now — the sync key is saved and the till keeps trying by itself". Route A
retries `cloud/migrate` in the background (back-off 1 → 2 → 5 → 15 minutes); route B says hello on the next sync run.

**Disconnect** (grey unless connected, confirmed, audited): cloud sync off and the key forgotten on the till.
Nothing is sent to the portal. An upload in progress resumes from where it stopped on the next Connect.

## 5. Error codes the till shows

Send the envelope of §9 (`code`, `message`). For a sync key the till shows its own sentence for these codes and
your `message` for any other refusal:

| `code` (HTTP) | Till says |
|---|---|
| `activation.code_not_found`, `sync_key.not_found`, `key.not_found` (404), `auth.invalid_key` (401) | The portal does not know this sync key. Check it was copied in full from your online dashboard account. |
| `activation.code_expired`, `sync_key.expired`, `key.expired` (410/409) | This sync key has expired. Make a new one on your online dashboard account, then press Connect again. |
| `activation.code_used`, `sync_key.used`, `device.branch_already_linked`, `migrate.already_migrated` (409) | This sync key is already in use by another shop. Each shop has its own sync key. |
| `auth.wrong_branch` (403), `licence.wrong_shop`, `licence.wrong_branch`; till-side `sync.key_other_install` | This sync key belongs to another shop. Use this shop's sync key. |
| `app.update_required` (426), `contract.unsupported` (409) | The online dashboard needs a newer version of SSPOS. Update the till. |
| any other 4xx | your `message`, as sent |
| 5xx, 429, 503, timeout, no answer | No internet just now — the key is saved and the till keeps trying. |

Till-side only (never from the portal): `sync.key_required` (empty box), `sync.wrong_till` (secondary till),
`sync.no_portal_address` (a build with no portal address and nothing typed — "Ask your dealer for the current
version"), `sync.https_only` (a typed override that is not https).

For the **licence key** the codes are those of §17.15 / `licensing/samples/error-codes.json` (`key.not_found`,
`key.already_used`, `key.expired`, `licence.not_active`, `activation.too_many_attempts`, …); with the built-in
address the till no longer says "no licence server" unless the build has none.

## 6. Checklist for the portal

- [ ] Give the owner the production base URL (https) for `build/portal.json`.
- [ ] One sync key per branch in the business panel; the same string works as `cloud/migrate` code **and** sync bearer.
- [ ] `cloud/migrate` answers 200 for a branch you already know (adopted, same ids), idempotent per `(code, installId)`.
- [ ] Optionally send `apiKey` (and `hubUrl` if sync is on another host) in `licence/activate` / `validate`, so a
      portal-licensed shop needs no sync key at all.
- [ ] Use the codes above for a wrong / expired / used / other-shop sync key.
