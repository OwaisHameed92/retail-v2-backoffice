# Upcoming changes (v1.4) — being built on the till now

These are **additive** changes to the contract, already decided. Build everything else in v1.3 as written; leave room
for these. v1.4 of this package will specify each one exactly (fields, samples, schemas).

## Times — "created on the till at X, reached the portal at Y"
- `POST sync/push` reply gains **`receivedAt`** (UTC, the moment you durably stored the batch). Store it per row next
  to the row's till times and show both in the Admin and Business panels (e.g. an order: created on the till / received
  by the portal).
- Every time on the wire is **UTC ISO-8601 ending in `Z`** (`at`, `createdAt`, `updatedAt`, business times such as a
  sale's completion time). Rows you send in `pull` keep the `createdAt` / `updatedAt` you send — the till will no
  longer re-stamp them.
- A sale taken offline on a secondary till carries the time it was **taken**, not the time it was sent.

## Several tills in one shop
- A secondary till's sales, refunds, voids, shifts, cash movements and clock-ins reach the portal through the branch's
  main till, each with **that till's own `registerId`**, shift and user. Receipt numbers are unique per till
  (`registerId` + number). Key every row by `id` (a ULID made on the till) — never by receipt or order number.

## Data rules
- **Customer order reference** becomes unique per branch; still key orders by `id`.
- **Customer balance and points**: the transaction ledger is the truth. The portal derives balance/points from the
  ledger rows the tills push and sends the result down; a till's absolute balance is a cache, never an overwrite.
- **Branch-to-branch stock transfers**: the portal relays the sending branch's dispatched transfer (header + lines) to
  the receiving branch in its `pull`; the receiving branch books its own receipt rows. Relaying the same transfer twice
  must be a no-op.
- **Settings and role permissions** sync (keyed by scope/key and role/permission). Secrets and device-local settings
  (API keys, passwords, signing material, printer ports, `licence.*`, `install.*`) never leave the till.

## Reliability (till side, no contract change except where noted)
- Pull continues after a failed push; parked pulled rows survive a restart; a conflict resolved "portal wins" applies
  your row.
- `Idempotency-Key` is reused on every retry of the same logical request (as 17.11 rule 5 already says) — keep
  returning the same answer for a repeated key.

## Before a real shop connects
v1.4 adds a **Guarantees checklist** (every table synced when online, both times recorded, no duplicates with several
tills and branches, transfers relayed, offline catch-up in order, retries idempotent) with the test for each.
