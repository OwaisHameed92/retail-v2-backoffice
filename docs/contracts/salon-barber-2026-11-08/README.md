# Salon / Barber contract pack (2026-11-08) — UNRELEASED

The EPOS team's salon pack, copied as sent from `web-portal-api-pack-2026-11-08/feat-salon-barber/docs/web-portal-api/`.
It comes from the till's `feat/salon-barber` branch, which is **not released**: no till on the stable feed sends any of
it yet.

It is an **overlay** on the released 0.1.85 contract (`docs/contracts/portal-api-0.1.85/`), not a full contract. It
holds only what the salon branch adds or changes:

- `schemas/entities/Salon*.schema.json` — the 41 new tables (`SalonClientMedical` is local: never sent, never stored);
- `schemas/entities/CashMovement.schema.json`, `Consent.schema.json` — supersets of 0.1.85 (`tipTaken` / `tipPayout`,
  `import` / `onlineBooking`);
- `samples/ownership.json`, `samples/enums.json`, `samples/settings-local-only.json` — supersets of 0.1.85;
- `salon/` — the five `sync/salon/*` calls (W3), the online request samples, `error-codes.additions.json`;
- `README-PHARMACY-BIKEBOOK-SALON.md` (Part C is the salon part), `UPCOMING-CHANGES.md` (the dated salon lines).

Every other file is read from 0.1.85. The entity store generator reads both (`app/Domain/TillData/definitions.php`
`overlay`, `Generator\ContractFiles`: the overlay's file wins), and so do the tests (`TillFixtures::SALON`,
`ContractSchema::SALON`). What the portal built from it: `docs/salon.md`.

When the salon branch ships in a till release, the EPOS team sends a full contract folder; copy it as a new
`portal-api-<version>` pack, point `contract` at it and drop `overlay`.
