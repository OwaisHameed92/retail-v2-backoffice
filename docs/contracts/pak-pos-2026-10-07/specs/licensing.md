# Licensing, trial and onboarding (owner decisions 2026-09-26)

Replaces the "a licence problem never stops selling" reading of specs/ops-additions.md §21 — the owner
decided that an unlicensed till locks (below). Everything else in §21 (activation, device/store/register
licences, transfer, dealer view, feature flags) stands and is made concrete here.

## 1. Two kinds of licence, one key format

| | Cloud licence (portal) | Local licence (our key generator) |
|---|---|---|
| Issued by | the web portal (built by another team, to docs/web-portal-api.md) | `tools/licence-generator` — a separate Windows app, only on the owner's / chosen dealers' PCs, never shipped to shops |
| Online check | **once a day**; if the portal cannot be reached, a daily alert "Your licence has not been validated with the server — connect the internet"; after **14 days** without a successful check the till locks | none — runs until the date in the key |
| Ends | at `expiresAt`, when the portal revokes it (next daily check), or after 14 days offline | at `expiresAt` (a date is **required** — no lifetime keys) |
| Trial | the portal issues a trial licence at sign-up (default **7 days**, the portal can set any length per customer and extend it) | **a key is mandatory (owner 2026-10-03, §9)** — a trial is a trial key from the generator (any length) or the portal; there is no built-in trial: a till with no genuine key is locked |
| Seats (tills) | counted by the portal (daily check lists the branch's tills) **and** by the branch's main till | counted by the branch's main till |
| Later | — | a local customer can move to the cloud: enters a portal activation code; history is uploaded; days left on the local key carry over |

**One format for both** — a signed licence token the till verifies **offline**:

```
SSPOS1.<base64url(payload JSON)>.<base64url(Ed25519 signature over "SSPOS1." + payload part)>
```

- The till holds the **public** keys only (in the exe), each with a key id (`kid`). The portal has its own
  signing key; every generator installation has its own. A leaked or retired key is removed by id in the
  next release — the others keep working. The token records who issued it. (Since 2026-09-28 the portal's key is
  not built in: the owner's generator approves it with a signer certificate carried in every portal token —
  docs/web-portal-api.md §17.17.)
- Payload (JSON, camelCase; **unknown fields are ignored** so new ones never break older tills):

| Field | Meaning |
|---|---|
| `v` | token format version (1) |
| `kid` | which signing key |
| `licenceId` | ULID of this licence (a renewal is a new token with the same `licenceId`) |
| `kind` | `trial` or `full` |
| `source` | `portal` or `local` |
| `issuer` | who issued it (portal, or the generator's name/dealer) |
| `companyId`, `branchId` | the business and branch it is for (the branch the seats belong to) |
| `businessName`, `branchName` | shown on the till's licence screen |
| `installCode` | binds a **local** key to one install (the till shows its install code; the generator asks for it). Portal licences bind by `companyId`/`branchId` instead |
| `maxRegisters` | tills allowed in this branch |
| `features` | open list of paid feature names (e.g. `"whatsapp"`, `"multi-branch"`) — a new paid feature is a new name, no till change beyond gating the screen |
| `issuedAt`, `validFrom`, `expiresAt` | UTC; `expiresAt` required |
| `onlineCheck` | `{ "required": true, "intervalHours": 24, "graceDays": 14 }` for cloud licences, absent/false for local — the policy travels in the token, so it can change per customer without a till release |
| `limits` | open object for future numeric limits (e.g. `{ "users": 10 }`) |
| `notes` | free text for the licence screen |

## 2. What the till does

- Verifies signature, `kid`, dates, `installCode`/branch, then applies `maxRegisters` and `features`.
- **Clock guard:** keeps the latest time it has seen (the existing clock watermark); a clock set backwards
  beyond tolerance locks until corrected or the portal confirms the time. The portal's time is used on
  every successful check.
- **Warnings:** from 7 days before `expiresAt`, and daily for a cloud licence that has not checked in.
- **Lock (no key, key not started yet, trial key over, expired, revoked, 14 days offline, clock tampering, seats exceeded):** the till
  finishes the sale in progress, then shows the lock screen. **Only three things work there:** enter a
  licence key / "Check again"; data backup/export (take data out only); support number. No sales, no
  products, no settings.
- **Paid features** not in the licence show **greyed** with "This is a paid feature — contact your dealer"
  (never hidden). During a trial every feature is on unless the trial token lists otherwise.
- **Seats:** the branch's main till refuses a join past `maxRegisters`; the portal refuses activation past it.
- **Trial length** is never editable on the till — only a signed token changes it.

## 3. Onboarding (cloud)

> **Superseded (owner, 2026-09-28) — kept as the record of the first decision.** There is no activation code and
> `devices/activate` is not called: every till enters its own licence key (§7), the till **keeps its own ids** and
> the portal adopts or aliases them, and the dashboard is linked by `apiKey` in the licence reply or a sync key
> typed at Connect (docs/web-portal-api.md §17.3, `docs/web-portal-api/SIMPLE-SETUP.md`).

1. Owner signs up on the portal → portal creates Company + Branch + a trial licence → emails a setup link
   with an **activation code** and the installer link.
2. First-run wizard gains a first step **"Link to your account"**: enter the activation code (Skip is greyed
   since 2026-09-28: a new install needs a key; "Restore my old till from a backup" is on this step too).
3. Till calls `POST /api/v1/devices/activate` → receives companyId, branchId, registerId, the branch sync
   key, the licence token, the portal time. The till adopts those ids (instead of its self-seeded ones).
4. Further tills of the branch join the main till as today; the main till reports them in its daily check.

## 4. API additions (docs/web-portal-api.md §17, OpenAPI + Postman)

`activate`, `licence/validate` (daily; carries the branch's tills, versions, clock), `licence/redeem`
(a key bought on the portal or made by the generator, reported for the portal's records), `devices/deactivate`
(transfer a till), `cloud/migrate` (local → cloud), plus the existing sync `hello`/`push`/`pull`.
Every reply may grow new fields; clients ignore unknown fields; enums are strings and an unknown value is
treated as "not active" by the till, never as a crash.

## 5. Decided / open

- **Install code binding: decided (owner 2026-09-26) — option (a).** The install code comes from an install id
  stored **in the database** (a new `install.id` value created once on first start), not from the PC's
  hardware: a backup restored onto a new PC keeps the same install code and its key keeps working. Accepted
  risk: a copied database carries the key with it (seats per branch still apply; cloud licences are also
  checked daily). The till shows it on Settings → Licence and on the lock screen, with Copy and "Send on
  WhatsApp". **Name: "Install code"** (owner 2026-09-26) — everywhere: till, lock screen, key generator,
  token field `installCode`, API. "Shop code" stays the branch code on the Shops screen (e.g. LDS).
- **Portal questions, decided (owner 2026-09-26, the proposed defaults):** a local key entered on a
  cloud-linked till is only recorded (the cloud licence stays in charge); the portal issues no offline
  install-code keys in v1; over the seat limit the main till and the earliest-activated tills keep their
  seats; activation switches cloud sync on; activation codes last 30 days, transfer codes 7 days.

- Local trial reinstall: **superseded (owner 2026-09-28)** — a new install needs a licence key in the first-run
  wizard (Skip greyed, Next refuses an empty box; installer key page stays optional and pre-fills it). No key →
  no set-up, so a reinstall with the data folder deleted no longer gets a fresh trial. ~~Installs already on the
  built-in trial keep it until it ends, then lock as before.~~ **Superseded (owner 2026-10-03, §9): the built-in
  trial is gone; those installs lock at the update until a key is entered.** A reinstall restores its backup
  (licence included) from the licence step without a new key.
- Paid feature list — **open**, the owner will give it later; the token's `features` list carries any names.
- **Withdrawing a signer: decided (owner 2026-09-26) — cut-off date.** A dealer's generator key that has to go
  is not deleted from the till: its key id gets a cut-off date (`TrustedLicenceKeys.RetiredAfter`). Keys it issued
  on or before the date keep working, so that dealer's customers are not locked out; keys it signs later are refused
  (`licence.retired_key`). Deleting the line (every key it ever issued stops) is kept for a leak where old keys cannot
  be trusted either. Accepted risk: a leaked private key can back-date `issuedAt` — for that case delete the line.

## 6. Till side — as built (2026-09-26)

- **One licence per branch, not per till.** The key (local or cloud) is the branch's; `maxRegisters` is the number of
  tills, main till included. The main till holds the key; a secondary till is handed it at every join
  (`JoinReply.LicenceKey/LicenceInstallCode/LicenceTrialStartedUtc`) and trades on it; the main till refuses a join
  past the seats (`StoreJoinService`, main till = one seat) and a refused till locks ("Too many tills"). On the
  built-in trial there is no seat limit.
- **Licence record** = register-scope settings, no table: `install.id` (ULID, once), `licence.key`,
  `licence.trial_started_utc` (once, first start of this build — an existing install gets 7 days from the update),
  `licence.last_online_check_utc`, `licence.revoked_key_hash` (a withdrawn key stays locked across restarts; any new
  key is unaffected), `licence.main_install_code` + `licence.seat_refused` (secondary tills). Never a plain field
  (`SettingsLayout.EditedByOwnPage`). The old `Licence` row is no longer seeded or read (table kept, no migration);
  trial length / grace are no longer settings.
- **Gate** (`Infrastructure/Licensing/LicenceGate`, port `ILicenceGate`): `LicenceRecord` → `LicenceEvaluator` at
  start-up (after the clock check), every 15 min, after Enter key / Check again; raises the clock watermark to now
  each run (never lowers it). Before its first read it answers "trial, may trade". `ILicenseService` (Has, limits,
  ExpiresOn) and `FeatureGate.IsOnAsync(…, licence)` answer from it.
- **Enter key** (`ActivateLicenceCommand`, no permission so it works on the lock screen): signature → refuse if it
  could never let this till trade (another install code / branch, ended, not started, withdrawn, entered on a
  secondary till) → save → AuditLog `EnterLicenceKey` (what the key says, never the key). A key accepted while the
  clock guard had locked the till resets the watermark — a dealer's fresh key is the way out of a clock once set
  far ahead.
- **Lock screen** (`Features/Licence/LicenceLockViewModel`, in `ShellWindow` under the PIN page): waits while the
  cart is open, then covers the shell (shell disabled, scans not delivered). Only: key box + Enter key, Check again,
  Back up now, Export data, Sign out, the dealer's support number (branding.json `SupportPhone`), install code with
  Copy and Send on WhatsApp (`wa.me` link to the support number; a number written with a leading 0 is taken as UK).
- **Banner** (amber, above the screen, never blocks): trial days left; last 7 days of a key; cloud check overdue.
- **Paid features**: `Application/Licensing/PaidFeatures` (empty until the owner's list) + Desktop
  `behaviours:PaidFeature.Name="…"` greys the element with "This is a paid feature — contact your dealer."
- **Not built yet (cloud phase)** at the time of this section: the portal calls (`activate`, daily `validate` writing
  `last_online_check_utc` / `revoked_key_hash`, `redeem`, `deactivate`, `migrate`); "Check again" re-reads locally
  until then. Messages[] and the "Link to your account" wizard step come with them. **Built since** (§7, §8):
  `licence/activate`, `licence/validate`, `licence/redeem`, `devices/deactivate`, `cloud/migrate`, messages, and a
  "Licence key" wizard step in place of "Link to your account".

## 7. Per-till licensing (owner, 2026-09-26, after §6) — till side BUILT; supersedes §6's branch sharing/seats

The portal developer asked for a licence per till; the owner agreed. This replaces "one licence per branch" (§1,
§6) for **cloud** licences:
- **One licence per till.** The customer receives a short key by e-mail (a trial key at sign-up) and enters it in
  the first-run wizard — a new install cannot be set up without one (owner 2026-09-28). Pasted on the till, the key is checked **online only**: the till sends key + `installId`/`installCode`,
  the portal binds the key to that install on first use and returns a signed token bound to that `installCode`.
  The same key on another PC is refused ("already used on another machine"). The portal thus learns each till's
  system id.
- **Local generator keys stay** (offline shops, portal down): unchanged, bound to the install code, work with no
  internet. The generator needs no change.
- **Offline → online later:** a local shop that wants cloud sync is given a portal key; entering it moves the till
  to the cloud licence and uploads its data (`cloud/migrate`, docs/web-portal-api.md §17.8).
- **Decided:** the portal releases / re-issues keys and decides every length (trial, months, years) — the till only
  obeys the signed token.
- **Built (till):** `ILicencePortal` → `Infrastructure/Licensing/HttpLicencePortal` (`POST {licence.portal_url}/api/v1/
  licence/activate` and `/licence/validate`, HTTPS only, 30 s, never throws); Enter key: e-mailed key → online,
  `SSPOS1.` key → offline (`Domain/Licensing/LicenceKeyFormat`); `Application/Licensing/Gate/LicenceCheckIn` (daily per
  till; a new token replaces the held one; any status but active/expiring withdraws it; server time clears the clock
  guard); portal tokens carrying an `installCode` bind to it, not the branch (`LicenceEvaluator`); §6's main-till key
  sharing, seat check and `licence.main_install_code` / `licence.seat_refused` REMOVED; setting `licence.portal_url`
  (Settings → Licence → Licence server). Contract: docs/web-portal-api.md §17.15, `docs/web-portal-api/PER-TILL-LICENSING.md`.

## 8. Key carries the shop; open local keys; one key box (owner, 2026-09-28) — supersedes §5's "local key always needs the install code first"

**What a key carries.** Every key (generator or portal) may carry, besides §1's fields:
- `company` block (`Domain/Licensing/LicenceCompany`): `businessType` (a `BusinessType` name), `address`, `town`,
  `postcode`, `phone`, `email`, `vatNumber`, `ownerName`, `receiptFooter`. The shop name is `businessName`, the branch
  `branchName`. Optional; an older key without it still works.
- Tills = `maxRegisters` (unchanged). Branches = `limits.branches` (`Domain/Licensing/LicenceLimits.Branches`; 1 when
  absent). Multi-branch = feature `multi_branch` (`Application/Ports/Feature.MultiBranch`). Days = `validFrom`/`expiresAt`.
- The details only **start** the first-run wizard: the shop edits any of them afterwards (owner: editable) and the edit
  syncs like any Company/Branch change. A later key never overwrites them.

**One key box, no choice.** The till tells the kinds apart (`LicenceKeyFormat`): `SSPOS1.…` = local, checked offline;
a short key = portal, activated online (§7). Same box in the installer, the wizard, Settings → Licence and the lock screen.

**Open local key.** The generator may leave the install code blank. The till that first accepts such a key binds it to
its own install: register setting `licence.bound_install_code` = its install code, saved with the key. An open key is
valid only where `licence.bound_install_code` equals the till's install code (`LicenceEvaluator`). Right after accepting
it the till shows "Licence accepted" + its install code (Copy, Send on WhatsApp to the dealer) + one summary line
(kind · days · until · tills · branches · features). **Renewals are made for that install code** (a normal bound key),
so a copied open key can only run until the first key ends. Accepted risk: offline, an open key pasted on a second PC
also binds there; it is caught the first time either PC reaches the licence server (below).

**Reporting local keys.** When `licence.portal_url` is set and reachable, a till holding a local key it has not reported
calls `POST /api/v1/licence/redeem` (`keyType: "token"`, `installCode`, `installId`) — once per token (setting
`licence.reported_key_hash`), retried with the daily check until it succeeds. Portal: first sighting of a `licenceId`
records `licenceId → installCode` and replies `result: "recorded"`; the same `licenceId` from **another** install code
→ 409 `key.used_on_another_install` → the till stores the key hash in `licence.revoked_key_hash` and locks (existing
reason Revoked, text "This key is already used on another PC — contact your dealer"). Unreachable = nothing changes.

**Many tills, one shop (local).** Each till has its own key. Every key of one shop carries the **same** `companyId` and
`branchId` (the generator's customer list reuses them). The main till refuses a join when the joining till's key names
another `companyId`/`branchId`, or when live tills would exceed its own key's `maxRegisters`. A till's database ids are
never re-keyed to match the key (invariant 5); the key's ids are compared key-to-key.

**Branches.** Adding a branch needs feature `multi_branch` and stays within `limits.branches`; otherwise the key is
greyed with "This is a paid feature — contact your dealer" (never hidden).

**Installer.** An optional "Licence key" page (Inno Setup). The text is written to
`%ProgramData%\SSPOS\data\pending-licence.txt` (no registry); the first-run wizard reads it, enters it as if typed, and
deletes the file once accepted.

**Generator.** Install code optional ("Bind to a PC" on by default for a customer who already has an install code);
customer list (company/branch ids, details, install codes per till) so a second till's key or a renewal reuses them;
fields for the company block, tills, branches, multi-branch, features, length.

**As built (2026-09-28).** Generator `tools/licence-generator` (customers.json beside the signing key); till: open-key
binding in `ActivateLicenceHandler`/`LicenceEvaluator`, join check `Server/Services/StoreJoinLicence` (+ `JoinOutcome`
LicenceForAnotherShop/LicenceKeyCopied, register setting `licence.join_refused`), Add shop in `UpsertShopHandler` +
greyed key on Settings → Shops; redeem/deactivate/hub link/messages in `LicenceCheckIn`, `DeactivateTill/`,
`PortalLink/`; wizard step `Features/FirstRun` (Licence first, `PendingLicenceFile`); a local shop's first portal key is
left in `sync.migrate_code` for the cloud move (§7). Company/Branch details edited on the portal come back by pull
(detail columns only, `HubRowMaps`). Open: durable portal row versions / `baseVersion` (needs a table); one manual run
of the generator's Make key with a scratch signing key.

## 9. Licence key mandatory — no built-in trial (owner, 2026-10-03) — supersedes §6's "no valid key = built-in 7-day trial"

The owner saw the till running with no key. A licence key is not optional:
- **No genuine key = locked.** `LicenceEvaluator`: no stored key, or one whose signature does not check out, locks with
  `LicenceLockReason.NoKey` ("This till has no licence key. Enter your licence key to start…"); a key whose `validFrom`
  has not come locks with `KeyNotStarted` (it used to fall back to the trial). Order unchanged: clock guard → seats /
  join refusal → the key. A trial is only ever a **trial key** (generator or portal).
- **Existing installs** that were still on the built-in trial lock at the update; the lock screen's key box is the way in.
- **First-run wizard:** a new install is locked from its first read, so the lock stays off the wizard
  (`LicenceLockViewModel.HoldForSetup`, driven by `ShellViewModel.IsSetupRunning`) — its first step is the key — and
  decides again when the wizard closes (finished, or joined a main till as an extra till).
- **Before the gate's first read** it still answers "may trade" (a till never locks on "not known yet"); that read is
  awaited at start-up before the shell opens.
- **Record:** `licence.trial_started_utc` is no longer written or read (the setting stays defined so stored rows and old
  backups resolve). `cloud/migrate` sends `localTrialEndsAt: null`.
- **Main till with no key takes no tills (owner 2026-10-03).** `StoreJoinLicence.Check(…, mainTillHasNoKey)` refuses
  every join with `JoinOutcome.MainTillHasNoLicence` ("The main till has no licence key. Enter the licence key on the
  main till, then connect this till again."), and adds no till to the till list. `MainTillLicence.HasNoKey` is true
  only once the gate has read the licence and found no genuine key — never on "not read yet". The joining till is
  **not** locked for it (its own key is fine; `licence.join_refused` is not written): it shows
  `TillLinkProblem.MainTillNoLicence` in its banner / Connect tills and trades in emergency mode until the main till
  has a key. A main till locked for another reason (expired, withdrawn) still judges joins by its stored key as before.

## 10. Country — a key names its country; a till is built for one (owner, 2026-10-07; the pak-pos line)

Each country has its own portal, its own signing key and its own till build (docs/web-portal-api.md §17.18).
- **The till's country** is `SSPOS.Shared.CountryProfile.Current` — set once at start-up from
  `SSPOS.Desktop/ProductLine` (the pak-pos line: Pakistan). Tests that do not ask run as the United Kingdom.
- **The key's country** is the token's optional `country` ("GB", "PK"; `LicenceClaims.Country`, kept in capitals).
  A key that names none - every key made before, and a generator key - works in any till.
- **Rule** (`LicenceEvaluator`, first check on a valid key): a key that names another country locks with
  `LicenceLockReason.KeyForAnotherCountry`. At Enter key it is refused with `licence.other_country` and plain words
  ("This key is for the United Kingdom. This till is for Pakistan — ask your dealer for a key for Pakistan."), nothing
  saved; an activate reply whose own `country` is another's is refused the same way before its token is read.
- The daily check reports the lock as `lock.reason` `wrongCountry`.
