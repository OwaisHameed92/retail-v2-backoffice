# Contract tests (module 2.6)

How the portal proves it implements the EPOS contract v1.4.1 (`docs/contracts/portal-api-v1.4.1/`) exactly, and where
each item of the contract's test lists (§19.4, §21) is tested. Run them all with `php artisan test`; the contract files
alone with `php artisan test tests/Feature/Contract`.

## The machinery

| Piece | Where | What it does |
|---|---|---|
| JSON Schema validator | `tests/Support/ContractSchema.php` | `opis/json-schema` 2.x (dev dependency), spec-compliant mode, draft 2020-12. Every schema is loaded by its path under `docs/web-portal-api/`, so the licensing schemas' relative `$ref`s (`common.schema.json#/$defs/…`, `../../schemas/error-reply.schema.json`) resolve across files. `pullErrors()` checks a pull reply's envelope and every payload against `schemas/entities/<Entity>.schema.json`. Replaced the hand-written `JsonSchemaSubset`. |
| Reply guard | `tests/Support/ContractReplyGuard.php`, registered in `tests/TestCase.php` | Every reply of `/api/v1/{sync,licence,devices,cloud}/*` in **every** feature test is checked after the test (so timing tests measure the endpoint only): 2xx against the endpoint's reply schema (pull: entity payloads too; licence replies: the token's payload against `licence-token-payload.schema.json`); any other status against `licensing/schemas/error-reply.schema.json`, with a code that is in `error-codes.json` **with the same HTTP status** (or pending, below). A violation fails the test that produced it. |
| Sample coverage | `tests/Support/ContractSampleCoverage.php`, `tests/Feature/Contract/ContractSamplesTest.php` | Every sample file (84) is schema-validated (push samples: envelopes and payloads with every member). Each is mapped to the test(s) that replay it, or marked `pending` (a later module; the test fails once that endpoint gets a route) or `notApplicable` (a model the contract replaced). A sample file without an entry, or an entry naming a test that does not exist, fails the build. |
| Error codes | `tests/Feature/Contract/ErrorCodesTest.php` | Static scan of `app/` (code only, comments stripped) for every code we throw or render; each must be in `error-codes.json`. Runtime: the reply guard. Framework errors on till URLs become contract codes. |
| Secrets in logs | `tests/Feature/Contract/SecretsNeverLoggedTest.php` | A log spy (`MessageLogged`, every level, with exception traces) across activate (+ replay), wrong key, key on a second PC, validate, a key in a query string, deactivate, hello, push (rejected row, deny-listed setting, secret members), pull (gzip), a wrong and a revoked sync key: no licence key (any form), sync key, licence token or `Authorization` header in any line, and none of the secrets the till sent by mistake in a stored row. |

Pending codes: `licence.ids_conflict` (409, module 2.1) — our code, not in `error-codes.json` yet; pending EPOS
confirmation (`docs/DECISIONS.md`). `ContractReplyGuard::PENDING_CODES` is the only allow-list, and a test checks each
entry is recorded as pending in `docs/DECISIONS.md`.

## §19.4 test list → tests

Store-level tests call `ApplySyncChanges` directly; HTTP tests go through `sync/push` and `sync/pull`. All files are
under `tests/Feature/`.

| # | Contract item | Tests |
|---|---|---|
| 1 | Push the same batch twice → one set of rows, identical replies | `Contract/SyncTestListTest` "19.4 #1-2 over HTTP: a batch pushed twice, and a retry overlapping it, store one set of rows with identical replies"; `Sync/SyncPushApiTest` "the same batch twice gives the same reply and no duplicates; an Idempotency-Key replays the stored reply"; `TillData/SyncRulesTest` "19.4 #1-2: stores a retried, overlapping batch once, with identical replies and a correct acknowledgedSeq" |
| 2 | Lost reply, retry overlapping the previous batch → no duplicates, `acknowledgedSeq` correct | the same three; `Sync/SyncPushApiTest` "a rejected row mid-batch: 200 with acknowledgedSeq before it; the rows after it are stored and come back as duplicates" |
| 3 | Product made on the portal reaches the till; the till's next push does not contain it | `Contract/SyncTestListTest` "19.4 #3 over HTTP: a product made on the portal reaches Leeds once; Leeds sending it back changes nothing and goes nowhere"; `TillData/SyncRulesTest` "19.4 #3: acknowledges a till echoing the portal's product without a conflict, a write or a re-send". (Not pushing a pulled row is the till's side, §19.2.) |
| 4 | Price changed at shop A → portal once → shop B receives it → A does not get it back | `TillData/SyncRulesTest` "19.4 #4: stores a price changed at shop A once, marks it A's for the pull, and takes shop B's copy as an echo"; `Sync/SyncPullApiTest` "never echoed: a row Leeds pushed goes to Bradford (parents first, in the till's shape) but not back to Leeds" |
| 5 | Portal and till change one product before either syncs → one conflict, the portal's row wins, both sides identical | `TillData/SyncRulesTest` "19.4 #5: a portal and a till edit of one product before either syncs make one conflict, and the portal wins"; `Sync/ResolveSyncConflictTest` "19.4 test 5: one conflict, the portal's row wins and is sent to the shop again" |
| 6 | A delayed older version after a newer one (either direction) → the newer stays | `TillData/SyncRulesTest` "19.4 #6: keeps the newer row when an older version arrives late; …"; `Contract/SyncTestListTest` "19.4 #6-7 over HTTP: …"; pull direction: `Sync/SyncPullApiTest` "pages with max and hasMore, and since filters what was already applied" (versions strictly increase; the till skips `version ≤ since`) |
| 7 | Product deleted on the portal, then a late till update → stays deleted | `TillData/SyncRulesTest` "19.4 #7: a product deleted on the portal stays deleted when a late till update arrives"; `Contract/SyncTestListTest` "19.4 #6-7 over HTTP: a late, older version never wins, and a product deleted on the portal stays deleted" (every shop is sent `D`) |
| 8 | Two tills of one shop sell offline for an hour → every sale once, numbered per till | `TillData/SyncRulesTest` "19.4 #8: two tills of one shop selling offline for an hour arrive exactly once, numbered per till"; `Sync/SyncPushApiTest` "the three push samples replay into the right company, branch and tills, …" (second till inside the main till's push) |
| 9 | Kill the connection mid-push and mid-pull a dozen times → identical to a clean run | `TillData/SyncRulesTest` "19.4 #9: pushes and pull replays killed a dozen times end with exactly the data of a clean run"; `Sync/SyncPushApiTest` "two pushes of one branch never interleave: …", "the same Idempotency-Key while its first push still runs: 409 request.in_progress, never stored" |
| 10 | Times: `receivedAt` UTC `Z`, the same on a retry; stored rows keep till time and `received_at`; portal rows arrive with the portal's `createdAt`/`updatedAt`; 23:30 UTC in June is the next trading day | `Sync/SyncPushApiTest` "receivedAt: UTC Z after the commit; a retry (duplicates or the same Idempotency-Key) gets the first time, never now"; `TillData/SyncRulesTest` "keeps the time a row first reached the portal, and the latest in synced_at"; `Sync/SyncPullApiTest` "portal creates, updates and deletes a category, product and barcode: …"; `Contract/SyncTestListTest` "19.4 #10: a sale at 23:30 UTC in June counts on the next London trading day, keeping its till time and when it reached us" |
| 11 | Customer ledger: sale at A, payment at B, both offline → balances move once everywhere; a relay twice changes nothing; a `Customer` row never sets balance/points | `Sync/RelayPullTest` "§10.1 and 19.4 test 11: an account sale at Leeds and a payment at Bradford move the balance once, everywhere", "a full recompute puts every customer back to the ledger's sum"; `Sync/ResolveSyncConflictTest` "a customer's ledger figures are never taken from the shop's version" |
| 12 | Stock transfer: A dispatches to B → header and every line; relay twice = one; B's receipt back to A; nobody gets its own rows back | `Sync/RelayPullTest` "replays pull-reply.relay.json: …", "a transfer is relayed only once dispatched, every line straight after it; …", "a receipt goes back to the sending shop (I), and again when it moves on (U); never to the shop that received" |
| 13 | Settings: portal change reaches the till, not pushed back; till change comes up once; deny-list / register scope refused; a permission taken away | `Sync/SettingsPullTest` "a company setting changed on the portal reaches every till in the keyed envelope, …", "replays push-request.settings.json: …", "the deny-list never leaves the portal: …", "a register-scope or deny-listed row that got stored anyway is never sent", "role permissions granted and taken away on the portal: I and D, never from the Owner role"; `TillData/KeyedRowsTest` "never stores a deny-listed or register-scope setting: …" |
| 14 | Two tills take a customer order in the same second → two references, each stored once, keyed by `id` | `Contract/SyncTestListTest` "19.4 #14: two tills of one shop take a customer order in the same second: two references, each stored once, keyed by id" |

## §21 guarantees → tests (portal side)

| § | Guarantee | Tests |
|---|---|---|
| 21.1 | Every table synced; unknown entities kept raw; one bad row never fails the batch | `TillData/SampleReplayTest` "stores every sample entity with every field exactly as sent"; `TillData/ApplySyncChangesTest` "accepts an entity it does not know, …", "rejects a malformed row with its key while the others apply, …"; performance: `Sync/SyncPushPerformanceTest`, `Sync/SyncPullPerformanceTest` |
| 21.2 | Times | §19.4 #10 above |
| 21.3 | No duplicates | §19.4 #1, 2, 8, 9, 14 above; `TillData/SampleReplayTest` "keys the second branch by its own branch and seq" |
| 21.4 | Transfers and head-office orders relayed | §19.4 #12; `Sync/HeadOfficeOrderTest` (all) |
| 21.5 | Shop prices | `Sync/BranchPriceSyncTest` (all) |
| 21.6 | Offline catch-up in order | `TillData/ApplySyncChangesTest` "stores rows in seq order whatever order they arrive in", "acknowledges across gaps in seq numbering and stops at the first rejection", "stores a child that arrives before its sale and gives it the sale's till when the sale lands"; `Sync/SyncPullApiTest` "pages with max and hasMore, …" |
| 21.7 | Retries idempotent | `Licensing/Api/LicenceApiGuardsTest` "a repeated Idempotency-Key gets the same status and body; the action runs once", "an error reply is replayed too, and a different body with the same key is 422"; `Sync/SyncPushApiTest` (retries, `request.in_progress`); `Contract/SyncSampleReplayTest` "push-request.initial.json: …" (history batch retried with its key) |
| 21.8 | Secrets never leave the till | `Contract/SecretsNeverLoggedTest`; `Licensing/Api/LicenceApiSecretsAndIsolationTest` "keys are never logged, stored or echoed, even on errors and alerts"; `Sync/SyncPullApiTest` "secrets never go down: …"; `TillData/SyncRulesTest` "never stores a user's remote approval secret …" |

## Samples not replayed (yet)

| Samples | Why |
|---|---|
| `licensing/samples/redeem-*`, `error.key-already-redeemed.409`, `error.key-used-on-another-install.409`, `error.licence-bad-signature.422` | `licence/redeem` is module 2.8 |
| `licensing/samples/migrate-*`, `error.branch-already-linked.409` | `cloud/migrate` and `migrate/complete` are module 2.8 |
| `samples/web-order.json` | §12 is a proposal; web orders are Phase 8 |
| `licensing/samples/activate-*`, `error.activation-code-not-found.404`, `error.use-migrate.409` | `devices/activate` is deprecated and never called (§17.4) |
| `licensing/samples/validate-reply.seat-limit.json` | the branch model's `seatLimit`/`registers[]`; per-till licences refuse at `licence/activate` (`error.seat-limit.403`, replayed) |

All of them are still validated against their schemas, and their tokens verified (`Licensing/Signing/SsposTokenTest`
"verifies every sample token signed with a documentation key").
