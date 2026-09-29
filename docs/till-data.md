# Till data store (modules 2.3 + 2.4)

How the portal stores every row the tills send, and the one way rows get in: `ApplySyncChanges`.
Contract v1.4.1: `docs/contracts/portal-api-v1.4.1/docs/web-portal-api.md` §5–10, §16, §19, §20; schemas and samples in
`docs/contracts/portal-api-v1.4.1/docs/web-portal-api/` (the samples win where text and samples differ).

## Shape

| Piece | Where | Written by |
|---|---|---|
| Overrides | `app/Domain/TillData/definitions.php` | hand |
| Generator | `app/Domain/TillData/Generator/*`, `php artisan till:entities:generate` | hand |
| Migrations, additive per release | v1.1: `2026_09_27_1100NN_create_till_<group>_tables.php`; v1.3.1: `2026_10_02_10000{0,1}_…_v1_3_1_…`; v1.4.1: `2026_10_06_100000_create_till_v1_4_1_tables.php`, `2026_10_06_100001_add_till_v1_4_1_columns.php` (+ hand `2026_10_06_100002_add_v1_4_1_sync_columns.php`) | generated |
| Schema lock | `database/till-schema.json`: what each release's migrations made | generated |
| Models (141) | `app/Domain/TillData/Models/*.php` | generated |
| Enums (88) | `app/Domain/TillData/Enums/*.php` | generated |
| Registry | `app/Domain/TillData/EntityRegistry.php` | generated |
| Behaviour traits, casts, queries | `app/Domain/TillData/{Concerns,Casts,Queries}` | hand |
| Applier | `app/Domain/TillData/Actions/ApplySyncChanges.php` + `app/Domain/TillData/Sync/*` | hand |
| Sync bookkeeping | `2026_09_27_100000_create_till_sync_tables.php`, `…100001_add_till_columns_to_tenancy_tables.php` | hand |

145 schema entities (v1.4.1). 144 have a registry entry; 141 have their own table and model. `Company`, `Branch` and
`Register` land on module 1.2's `companies`, `branches` and `registers` tables. The `local` tables of
`ownership.json` (`SyncState`, `DomainEventRecord`, `ProcessedCommand`; `EntityRegistry::LOCAL`) are never synced
and never stored: a push of one is acknowledged as `skipped` (v1.3.1's `till_sync_states` table is kept, unused).
`Setting` and `RolePermission` are **keyed rows** (below).

Groups (table order): catalogue, promotions, customers, sales, stock, purchasing, cash, accounts, staff, system,
compliance.

## Column rules

- `id` = the till's ULID, `string(26)` primary key, stored exactly as sent. Never re-keyed.
- `company_id` on every table. `branch_id` / `register_id` where the entity has them.
- **Scopes** (registry `scope`):
  - `company`: company-wide master data (products, customers, VAT…). No branch.
  - `branch` / `register`: the row's own `branchId` / `registerId`. Must be the sending branch and one of its tills.
  - `child`: rows with a parent and no branch of their own (`SaleLine`, `SalePayment`, `SaleVat`,
    `CustomerOrderPayment`, `JournalLine`, `*Line`, `SupplierPaymentAllocation`). `branch_id` (and `register_id`
    when the parent is register-scoped) is copied from the parent (in the same batch or stored), else the sending
    branch. A child stored before its parent gets the parent's `register_id` when the parent lands (backfill).
  - `sender`: branch-owned rows without a `branchId` (FinancialYear, VatReturn, Licence, StandingOrder…): stored
    with the sending branch in `branch_id`, so reports can tell which shop made them.
- Money `decimal(12,2)`; costs and quantities `decimal(14,4)`; percentages `decimal(9,4)`; exchange rates
  `decimal(16,6)`. Models cast them to fixed-scale strings (`MoneyCast`, `QuantityCast`, `RateCast`). Never floats.
- Date-times are UTC `dateTime` (not `timestamp`: dates past 2038 exist). Dates are `date`, times `time`.
- Embedded JSON strings (`receiptJson`, `linesJson`, `*Json`, `PrintJob.payload`) are `longText`, stored verbatim
  (a MySQL `json` column would re-format them, and `""` is not valid JSON).
- Enums are `string(40)`, cast with `TillEnumCast` to the generated backed enum. A value the contract does not list
  yet is stored as sent, read back as `null`, and logged once per batch (never lose a row for an additive change).
- Derived members (`isDeleted`, `domainEvents`, `key`, `balanceDue`, `isCompleted`, Money objects like
  `priceIncVat`, navigation collections like `Product.barcodes`) are not stored.
- Unknown members (a newer till adds a column) go into `extra` (json). Secret-looking ones are redacted there.
- Secrets: `Licence.licenceKey` is stored as `licence_key_hash` (HMAC-SHA256 under APP_KEY, as module 1.3 hashes
  keys) and `licence_key_last4`. `User.remoteApprovalSecret` and `…SetAt` (removed from the contract in v1.4, still
  sent by older tills) are dropped (`drop`): not stored, not in `extra`, not in a conflict payload. `User.pinHash` and
  `rfid` are `$hidden`. Deny-listed settings are never stored (keyed rows, below).
- **Keyed rows** (v1.4 §10.3): `Setting` (scope, scopeId, key → `till_settings`, column `setting_key`) and
  `RolePermission` (roleId, permissionKey → `till_role_permissions`) have no ULID, companyId or row version. The
  stored id is derived from the payload with our ids (`SyncRowIds`, the till's formula); `row_version` = the pushing
  branch's seq; the later `updatedAt` (RolePermission: the change's `at`) wins, then the higher seq. A setting must be
  scoped to the pushing company/branch. `SettingSyncPolicy` (= `samples/settings-local-only.json`): register-scope
  and deny-listed keys are acknowledged as `skipped` and never stored; their values are never logged.
- Every till column is nullable in the database; the applier enforces the schema's nullability. The database
  stays tolerant of schema relaxations and tombstones.
- Sync columns: `row_version` (the till's rowVersion), `created_at` / `updated_at` / `deleted_at` (the till's),
  `synced_at` (when we last stored it), `portal_received_at` (when the row first reached the portal; v1.4's
  "received by the portal"), `sync_seq` (the push seq that last wrote it). Hub-owned tables also have the pull
  bookkeeping: `hub_version` (module 2.5's pull version of the current content; null = not stamped yet),
  `hub_edited_at` (set by a portal edit), `hub_hash` (content hash, `RowHash`) and `origin_branch_id` (the branch
  whose push made the current content; null = the portal).
- No foreign keys (rows arrive out of order). Indexes: `(company_id, updated_at)` and `(company_id, branch_id)`
  everywhere, the parent key on children, plus report indexes from `definitions.php` (sales by
  `(company_id, branch_id, completed_at)`, sale lines by `(company_id, product_id)`, stock movements by
  `(company_id, branch_id, product_id, at)`, barcodes by `(company_id, barcode)`…).
- Tables whose names clash with portal tables get `till_`: `till_users`, `till_roles`, `till_audit_logs`,
  `till_licences`, `till_licence_add_on_trials`, `till_sync_conflicts`, `till_sync_states`; `Account` is
  `ledger_accounts`.

## Applying changes

```php
$result = app(ApplySyncChanges::class)->handle($company, $sendingBranch, $changes); // decoded envelopes
$result->toPushReply();   // ['acknowledgedSeq' => 18239, 'accepted' => 9, 'receivedAt' => '2026-09-28T10:15:02Z']
$result->rejected;        // list<Rejection>: key, seq, code, message (in seq order)
$result->outcomes;        // ['applied' => 7, 'stale' => 1, 'unchanged' => 0, 'duplicate' => 0, 'conflict' => 1, 'skipped' => 0]
$result->receivedAt;      // after the commit; a retry gets the first time (ChangeLedger::receivedAt)
```

Per change, in seq order, 500 per transaction:

1. **Envelope** (`EnvelopeReader`): the sync-change schema; `companyId` = the company; a non-empty `branchId` =
   the sending branch; a non-empty `registerId` = one of its tills; entity known.
2. **Payload** (`PayloadMapper`): every schema member present with its type (the generated rule set in the
   registry), `id` = `entityId`, `companyId` = the company, branch/register rules above. Values are normalised.
3. **Never twice** (§19.1): a `(company, sending branch, seq)` already in `sync_applied_changes` is a duplicate
   (accepted, nothing changes). **Never backwards** (§19.3): a lower version is stale; an equal version is stale
   unless its payload `updatedAt` is later than the stored one (the tie rule; a replay is always a no-op). This is
   the `(entity, entityId, version)` idempotency and covers pull replays (seq 0). **Never echoed** (§19.2): a
   hub-owned row whose content hash equals `hub_hash` is `unchanged` (accepted, no write, no conflict, not re-sent).
4. **Write** (`EntityWriter`): bulk upsert of whole rows (`BulkWriter`). An id another company holds is rejected.
   `D` = soft delete with the payload stored (or `deleted_at` only, if no payload).
5. **Historic rows** (`immutable` in definitions.php): completed/voided sales and their lines, payments and VAT,
   journal entries and lines, audit logs, customer order payments. Once frozen, only the listed columns (e.g. a
   sale's `status`, `void_reason_id`, `voided_by`; a payment's `status`), `deleted_at` and the sync columns change;
   any other difference is kept out and recorded in `sync_conflicts` (`immutableChange`). Sale children are frozen
   when their sale was completed at the child's seq.
6. **Hub-owned rows** pushed by a till are stored like any row, unless the portal has overtaken the till's change:
   with `baseVersion` (v1.4) below `hub_version` (and that version is not the sender's own change) →
   `hubVersionNewer`; without it, `hub_edited_at` > the change's `at` → `hubEditNewer`. Row versions order a hub
   row only against the same shop's earlier edit (`origin_branch_id` = sender): each till counts its own. A row
   last written by another shop is overtaken when that shop's `updatedAt` is later, or (with `baseVersion`) when its
   edit is not stamped yet → `branchEditNewer`. The stored row is kept and the conflict holds the till's payload. An applied change sets `origin_branch_id` = sender, `hub_hash`, and
   clears `hub_version`. A portal save or soft delete (`HubOwnedRow`) clears `hub_version` and `origin_branch_id` and
   sets `hub_hash`. **Module 2.5** stamps rows with a null `hub_version` with its next pull version and never sends a
   row to its `origin_branch_id`.
7. **Company/Branch/Register** (`TenancyRowApplier`): a till may send only its own company, its own branch and that
   branch's tills. Only `tillFields` are written (names, address, phone, VAT number, nation, licensed hours, DRS
   point, area, the till's counters); ids, codes, status, `is_active`, `is_main_till` stay the portal's. A delete is
   never applied (`tenancyDelete` conflict).
8. **Ack**: `acknowledgedSeq` = the highest seq with every lower seq of the batch accepted; the first rejection
   stops it (first change rejected → its seq − 1). Gaps in seq numbering do not. Rows after a rejection are still
   applied; the till resends them and they come back as duplicates.

**Unknown entities** (a newer till, contract §18.8, §21.1) are never rejected: `UnknownEntityRows` keeps them raw in
`till_unknown_rows` (company, branch, entity, id, op, version, seq, `at`, payload JSON, received_at), one row per
(company, entity, id) at its highest version, recorded in the push ledger so a retry is a duplicate.

If the database refuses a chunk, it is rolled back and replayed one change at a time: only the change that fails is
rejected (`store.failed`, logged without the payload).

Rejection codes: `change.invalid`, `sync.wrong_company`, `sync.wrong_branch`,
`sync.unknown_register`, `sync.duplicate_seq`, `sync.parent_rejected`, `payload.missing`, `payload.id_mismatch`,
`payload.invalid`, `entity.id_taken`, `entity.not_found`, `store.failed`.

### What the caller must do (done by module 2.2's `PushChanges`)

- Authenticate the branch's sync key and pass that branch as `$sender`; take a per-branch lock around `handle()`.
- Decode JSON with `json_decode(..., true)`. Numbers arrive as PHP floats; their shortest round-trip string is the
  number the till wrote (up to 15 significant digits), which is what the applier stores.
- Reply 200 with `toPushReply()`. When `rejected` is not empty, put `rejected[0]->key` in logs / the error body's
  `rejectedKey` as the contract suggests.

## Push (module 2.2)

`POST /api/v1/sync/push` → `EnsureTillContract` → `AuthenticateSyncKey` → `GuardSyncRequest` →
`SyncController::push` → `App\Domain\Sync\Actions\PushChanges`:

1. Headers: `X-SSPOS-Contract: 1` (409), Bearer key for `X-SSPOS-Branch-Id` (401/403), app version, company and
   register headers (400), per-key rate limit (429 `rate.limited`). `X-SSPOS-Store-Protocol` is optional (log
   context only). 426 `app.update_required` only for a version in `sync.blocked_app_versions` (never on licence calls).
2. `X-SSPOS-Sync-Mode` (`delta` default, `initial` + `X-SSPOS-Upload-Id`), optional `Idempotency-Key`.
3. `PushBody::decode`: gzip or plain JSON, ≤ 50 MB inflated, ≤ 5,000 rows (413 `batch.too_large`), a non-empty list.
4. Branch lock (`Cache::lock('sync-push:branch:{id}')`); busy → 503 `server.busy`.
5. Idempotency-Key replay, else `ApplySyncChanges::handle($company, $keyBranch, $changes, $stream)` (`$stream` =
   '' or the upload id: the ledger keys changes by company + branch + stream + seq).
6. `SyncStatusRecorder::pushed()` → `sync_branch_status`; reply 200 `toPushReply()` (`receivedAt` required since
   v1.4.1: UTC `Z`, taken after the commit; the ledger's `applied_at` of the rows this call stored is set to it, and a
   retry answers the latest `applied_at` of its seqs, i.e. the first time), or 422 `row.invalid` with `rejectedKey`
   when the first row of the batch was rejected.

`GET /api/v1/sync/hello` (`SayHello`) returns the key's company/branch in the till's ids, the branch name, server time
and `maxBatchRows`. Settings: `config/sync.php` (`push.*`, `rate_limit_per_minute`). Timing: a 5,000-row gzip push
(4.7 MB JSON, 121 KB gzip) takes ≈ 0.7 s wall / CPU through HTTP on in-memory SQLite, a retry ≈ 0.25 s
(`php artisan test --group=perf`). Production: PHP `post_max_size` must exceed the compressed body; the cache store
must be shared between PHP workers (locks).

## Pull (module 2.5)

`GET /api/v1/sync/pull?since={version}&max={n}` → the same middleware as push → `SyncController::pull` →
`App\Domain\Sync\Actions\PullChanges`:

1. `since` (required, ≥ 0) and `max` (1…, capped at 5,000) → else 400 `request.invalid`.
2. `HubVersions::stampPending($company)`: every hub-owned row with `hub_version` null (accepted from a till, or a
   portal change whose after-commit stamp did not run) gets the next version, parents first
   (`HubVersions::ORDER`), oldest change first.
3. In one transaction: `PullFeed::page()` — a `UNION ALL` over the tables of `HubVersions::feed()` of
   `(entity, id, hub_version)` where `hub_version > since` and `PullVisibility` allows it: hub-owned rows (keyed
   `Setting`/`RolePermission` included; a branch setting, `NewsTitle` and `BranchPrice` only for their branch), the
   relayed branch rows (§10.2: a dispatched transfer + lines to its `toBranchId`, a receipt + lines to the
   transfer's `fromBranchId`, ledger rows to every other branch), head-office orders drafted for this branch (§10.6)
   and portal edits of its Company / Branch (§6.1); never a row whose `origin_branch_id` is the caller;
   `ORDER BY hub_version LIMIT max + 1`. Then the full rows per entity.
4. `PullEnvelopes` → `PullPayload::envelope()` (keyed and Company/Branch rows have their own shapes, module 2.9B): `seq` 0, `op` D / I (`createdAt` = `updatedAt`) / U, `version` = `hub_version`, the till's
   company/branch ids (`IdTranslator::toTill`), `branchId` "" or the addressed branch, `registerId` "", the whole
   row in the till's shape (see DECISIONS "Pull"), no secrets. `highestVersion` = the last version on the page (or
   `since`), `hasMore` = more rows waiting.
5. `SyncStatusRecorder::pulled()` → `sync_branch_status.last_pull_*`. gzip reply when the till accepts it.

**The change feed.** `sync_hub_counters` holds each company's last version. Stamping (`HubVersions::stamp` /
`stampPending`) updates that row first in a transaction, so the lock orders all stamps of a company and versions
become visible in order. Who stamps:

- A portal save, soft delete or restore of a hub-owned model: `HubOwnedRow` clears `hub_version` and
  `origin_branch_id`, sets `hub_hash`, then stamps after the transaction commits (a rollback uses no version).
- Code that writes hub-owned rows without Eloquent: call
  `app(PublishHubChange::class)->handle($companyId, 'Product', $ids)` after the write (it sets the same
  bookkeeping and stamps). Never hard-delete a hub-owned row: a pull cannot send it.
- Rows a till pushes (`ApplySyncChanges` clears `hub_version` and sets `origin_branch_id`): the next pull's sweep.
  Relayed tables (`OwnershipRules::RELAYED`) too; a shop's own drafted-table rows (its purchase orders) get
  `hub_version` 0 and never enter the feed. Stamping a transfer header or a receipt re-queues its lines after it.
- Head-office orders: `DraftHeadOfficeOrder` (stamps the order, then its lines). Settings and permissions:
  `SaveTillSetting`, `SetRolePermission` (HubOwnedRow). Company / Branch: `SentToTills` on a portal save of a till
  member. A hub conflict re-queues the row the portal kept, so the overruled shop gets the winner.
- Customer `balance` / `points` are the ledger's sum (`RecomputeCustomerBalances`, run in every push chunk); they are
  not part of `hub_hash` and never stamp a new version (§10.1).

Indexes: `(company_id, hub_version)` on every hub-owned table (`2026_10_05_100000_add_sync_pull_feed.php`; v1.4.1's
three new hub tables in `2026_10_06_100002_add_v1_4_1_sync_columns.php`; hand
written; not in `till-schema.json`). Timing: 5,000 products pulled by another branch (stamping included, gzip):
≈ 0.6 s wall / CPU, a repeat ≈ 0.4 s (`php artisan test --group=perf`).

## Reading (phase 3)

All models are tenant-scoped (`BelongsToCompany`): run inside a request with a current company, or
`CurrentCompany::runAs()`. Branch-owned models are read-only (`TillOwnedRow` throws on save/delete).

```php
Sale::query()->trading()->forBranch($branchOrNull)->completedBetween($from, $to)->get();
Sale::totals($query);               // count, total, vat_total, net_total… as exact strings
SaleLine::totals(SaleLine::query()->forProduct($id));
StockMovement::netQty(StockMovement::query()->forBranch($b)->forProduct($id)->between($from, $to));
TillSum::many($anyQuery, ['total' => 2, 'qty' => 4]);   // exact sums in the database, never PHP floats
$sale->saleLines; $line->sale; $row->branch; $row->register;
```

## Regenerating

```bash
php artisan till:entities:generate          # writes changed files, deletes stale generated ones
php artisan till:entities:generate --check  # CI: exit 1 if anything would change
```

Output is deterministic: running it twice changes nothing (a test checks this).

Generated code passes Pint and Larastan level 6 as written. One Larastan false positive is ignored in
`phpstan.neon`, scoped to these models: in a `final` class it reads `BelongsToCompany::withoutCompanyScope()`'s
`Builder<static>` as `Builder<static(Sale)>` and calls it a mismatch with `Builder<Sale>`.

Migrations are additive. `database/till-schema.json` records what each release's migrations made; the generator
writes, for the release named in `definitions.php` (`release`), one migration creating the tables earlier releases
lack and one adding their missing columns and indexes. It never touches an earlier release's migration. A column
whose definition changed stops the generator with its name (write that alter migration by hand). After a contract
update: copy the new folder, point `contract` at it, set `release` to the new name and a later `migrationPrefix`,
regenerate, run `php artisan migrate --pretend`, the tests, read the diff.

## Adding an override

Everything goes in `definitions.php` (the file documents each key):

- Table or class name: `'Entity' => ['table' => '…', 'class' => '…']`.
- Parent for a child row: `'parent' => ['Sale', 'saleId']` (adds relations and branch/register inheritance).
- Index: `'indexes' => [['company_id', 'branch_id', 'date']]`.
- Decimal kind: add the field (or `Entity.field`) to `decimals.cost|quantity|percent|rate` (default is money).
- A derived member: `'derived' => ['isSomething']`. A stored object/array member: `'json' => ['permissions']`.
- A secret: `'secret' => ['fieldName']` (hash + last 4) or `'drop' => ['fieldName']` (never stored). Hidden from JSON:
  `'hidden' => ['fieldName']`.
- Historic rows: `'immutable' => ['when' => [...], 'whenParent' => [...], 'always' => true, 'mutable' => [...]]`.
- Behaviour (scopes, helpers): write a trait in `Concerns/` and list it under `traits`. Never edit generated files.

Then regenerate and run `php artisan test tests/Feature/TillData`.

## Performance

5,000 mixed rows (700 sales with lines, payments, VAT and stock movements, plus 100 products) in one push:
see the `perf` group test (`php artisan test --group=perf`), which prints wall and CPU time. The budget is
5 s of CPU time. Measured (2019 i9, in-memory SQLite, machine shared with other jobs at load 18–30): ~1.45 s CPU,
2.6–3.4 s wall; the same batch retried: ~0.2 s CPU / 0.5 s wall. Where the time goes: validation and mapping
~0.5 s, SQLite upserts ~0.3 s, reads, ledger and bookkeeping the rest. The whole TillData suite also passes on
MySQL 8.0.35.
