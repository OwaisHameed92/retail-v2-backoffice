# retail-v2-backoffice

Cloud backoffice for the new SSPOS desktop retail EPOS (the "till"). It replaces the legacy
`/Applications/MAMP/htdocs/retail-backoffice`. Do not copy code from the legacy project; use it only to
understand old behaviour.

Read before any work: `docs/PHASES.md` (what to build, in what order), `docs/DECISIONS.md` (product
decisions already made), `docs/BRAND.md` (Switch & Save brand and the UI quality bar), `docs/components.md`
(shared components to reuse), and for sync work `docs/contracts/portal-api-v1.1/README-web-portal-api.md`.

## The three areas

| Area | URL prefix | Who | Layout |
|---|---|---|---|
| Super admin | `/admin` | SSPOS staff (us) | `resources/js/layouts/admin-layout.tsx` |
| Tenant portal | `/app` | A customer company's users | `resources/js/layouts/app-layout.tsx` |
| Till APIs | `/api/v1/licence/*`, `/api/v1/sync/*` | EPOS tills | JSON only |

A tenant = one Company. Company → Branch (shop) → Register (till). One licence = one register, always.

## Stack

- Laravel 12, PHP 8.4.1+ required by the lock file (dev machine runs 8.5; CI uses 8.4), Pest 3 for tests (write new tests as Pest functions; existing PHPUnit classes are fine), Pint for PHP style.
- Inertia 2 + React 19 + TypeScript + Tailwind 4 + shadcn/ui (`resources/js/components/ui`). Charts: Recharts.
- Dev DB: SQLite (`database/database.sqlite`). Target: MySQL 8 (MAMP). Write migrations that run on both.

## Architecture rules

- **Modules.** Domain code lives in `app/Domain/<Module>/` (Models, Actions, Data, Enums, Policies, Events).
  Controllers in `app/Http/Controllers/{Admin,App,Api}/` stay thin: validate (Form Request) → call one Action →
  return an Inertia page or a JSON resource. No business logic in controllers, routes, or React.
- **Actions.** One class per use case with a single `handle()` method, e.g. `IssueLicence`, `ActivateLicence`.
  Actions are what tests, controllers, jobs and AI tools call.
- **Ids.** Every table synced with the till uses the till's ULID as primary key (`$table->ulid('id')->primary()`),
  stored exactly as received. Never re-key a till row. Portal-created rows also get ULIDs (`HasUlids`).
- **Tenancy.** Shared database. Every tenant-owned table has `company_id`; tenant models use the
  `BelongsToCompany` trait (global scope + auto-fill). Never query tenant data without the scope. Every tenant
  feature needs a test proving company A cannot see or change company B's data.
- **Money.** `decimal(12,2)` for prices/totals, `decimal(14,4)` for costs and quantities. Never float. Pounds, not
  pence (matches the till contract).
- **Time.** Store UTC. Display Europe/London.
- **Enums.** PHP backed enums with camelCase string values matching `docs/contracts/portal-api-v1.1/samples/enums.json`.
- **Files.** Keep files under ~300 lines. Split before a class grows (the legacy `SyncService.php` hit 4,436 lines).

## Security rules

- Admin routes: `auth:admin` guard + `can:` policies. Tenant routes: `auth:web` + company membership + role
  permission. Add a test for every route that an unauthorised user gets 403/redirect (legacy had an unprotected
  `/admin`).
- Secrets (licence keys, sync API keys): store a hash + last 4 characters only. Never log them. Never accept them
  in a query string.
- Till APIs: rate limited, JSON error body `{code, message, traceId, retryAfterSeconds, rejectedKey}`.

## Testing and quality

- Every Action and every endpoint gets Pest tests. Sync endpoints get contract tests that validate replies
  against `docs/contracts/portal-api-v1.1/schemas/*.schema.json` and replay the samples.
- Before finishing any task run: `vendor/bin/pint --dirty`, `composer check` (pint, larastan level 6, tests), `npm run lint`, `npm run build`.
  All must pass. Report failures honestly.
- UI: follow `docs/BRAND.md` exactly (brand tokens, layouts, "complete means complete" checklist). Reuse the shared
  components in `resources/js/components/shared`. The owner wants top-notch, professional, full functionality.

## Working as an agent

- Work only inside the module you were given. If you need a change in shared code (layouts, traits, base
  classes), keep it small and say so in your report.
- Update `docs/PHASES.md` status for your module when done, and add any new decision to `docs/DECISIONS.md`.
- Do not commit unless asked.
