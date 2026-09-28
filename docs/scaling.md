# Scaling: partitioning, archiving, sharding

Groundwork only (module 2.1); the work itself is module 7.6. Nothing below changes behaviour today.

## What makes it possible

- **Every tenant row carries `company_id`**, and till rows use the till's ULID as primary key. ULIDs are unique
  everywhere, so a company's rows can be copied to another server without re-keying or clashes.
- **`companies.data_connection`** names the database connection that holds the company's data (default = the
  default connection). `App\Domain\Tenancy\Support\CompanyConnection::for($company)` resolves it; an unknown name
  falls back to the default. No model routes through it yet.
- **`id_map`** keeps the till's own ids apart from ours, so moving a company never touches what the till holds.

## Partitioning (first, same server)

- Big till tables (`till_sales`, `till_sale_lines`, `till_stock_movements`, `till_applied_changes`, audit logs):
  MySQL 8 RANGE partitions by month on the trading/created date. The primary key must then include the
  partition column, so add it as `(id, created_at)` in the migration that partitions — plan it per table.
- Queries always filter by `company_id` + date, so partition pruning works without code changes.

## Archiving

- Keep 24 months hot. Older partitions are exchanged into `archive_*` tables (same schema) or exported to
  object storage as compressed JSON per company per month, then dropped. Tax data (VAT, sales) stays readable
  for 6 years: archive, never delete.
- Reports older than the hot window read `rpt_*` summaries, not the raw rows.

## `rpt_*` tables

- Pre-aggregated per company, branch, trading day (Europe/London, computed at ingest): sales, tenders, VAT,
  hourly sales, stock value. Written by the applier's after-commit jobs, idempotent (upsert by key).
- Dashboards (Phase 3) read only `rpt_*`; they are small, so they stay on the main server even after sharding.

## Sharding (later, when one server is not enough)

1. Add connections `tenants_2`, `tenants_3`… (same schema, migrations run on each).
2. Route tenant models and jobs through `CompanyConnection::for()` (a `BelongsToCompany` connection resolver);
   admin cross-tenant screens read the `rpt_*` copies on the main server.
3. Move a tenant group: set it read-only for sync (tills queue changes offline and retry, 503), copy its rows by
   `company_id` (ULIDs, no re-keying), verify counts and hashes, flip `data_connection`, re-enable.
4. Global tables stay on the main server: companies, users, plans, licences, id_map, sync_keys, billing.

## Load test (7.6)

1,000 synthetic shops (1–3 tills each) pushing every 30 s with realistic batch sizes; targets: push p95 < 1 s,
no lock waits over 1 s, applier throughput, queue lag, database size growth per shop per month.
