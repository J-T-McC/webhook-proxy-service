---
name: schema-migrations
description: MySQL schema/migration gotchas in this Laravel app (LONGBLOB, JSON key order, information_schema tests)
metadata:
  type: project
---

Schema/migration gotchas (MySQL 8 / InnoDB via sail; PHPStan L7):

- **No LONGBLOB/MEDIUMBLOB Blueprint helper.** Laravel 13's `$table->binary()` maps to
  MySQL `blob` (64 KiB) — verified in `MySqlGrammar::typeBinary` (returns `'blob'`; only
  `length` yields `binary(n)`/`varbinary(n)`). For a `LONGBLOB` column, build the rest of the
  table in `Schema::create` then add the column with a raw
  `DB::statement('ALTER TABLE ... ADD col LONGBLOB NOT NULL AFTER ...')`. (Used for
  `webhook_events.body`, which holds the `encrypted`-cast base64 envelope.)
- **The `encrypted` cast + LONGBLOB is binary-safe** and round-trips arbitrary bytes
  (test with `"\x00...\xff"`); ciphertext at rest ≠ plaintext. Record any plaintext
  `byte_size` BEFORE the cast (e.g. `strlen($rawBody)` in the writing service), since the
  cast expands the stored value ~35%.
- **MySQL JSON columns do NOT preserve object key order.** For an `array`-cast column
  round-trip, assert with `assertEquals` (order-insensitive), never `assertSame`.
- **Schema-fact tests** (nullability/default/DATA_TYPE/indexes) query `information_schema`
  with `TABLE_SCHEMA = DATABASE()` — see `ProxyTest`, `DeliveryAttemptTest`, `WebhookEventTest`
  for the reference queries (single-column UNIQUE via `STATISTICS ... NON_UNIQUE=0` grouped by
  `INDEX_NAME`; `COLUMN_DEFAULT IS NULL` for no-default). The test DB is real MySQL, so
  `longblob`/`information_schema` assertions work; `migrate:fresh` runs per suite so new
  migrations are picked up automatically.
- **`foreignId(...)->constrained()->unique()` fails PHPStan/runtime** — `constrained()` returns a
  `ForeignKeyDefinition`, which has no `unique()`. Declare the unique separately:
  `$table->foreignId('x')->constrained(); $table->unique('x');`.
- **`BelongsToCurrentTeam` auto-assigns `team_id` on create only if empty** (no global read
  scope; team-read scoping is middleware on the team route group). On the team-unscoped ingest
  path, set `team_id`/`proxy_id` explicitly from the resolved proxy (mirrors `DeliverToDestination`).
- **`encrypted`/`encrypted:array` casts pass NULL straight through** (both types are in Eloquent's
  `$primitiveCastTypes`, so `castAttribute`/`setAttribute` short-circuit before
  encrypting/decrypting). Safe to make an already-`encrypted`-cast column nullable and set it to
  `null` — no double-encryption of "empty", no decrypt-of-null error. Used for
  `webhook_events.body`/`headers` and `dispatched_payloads.body` going NULL on erase (#5).
- **Column-type change that a JSON-validated MySQL column can't survive under a new
  `encrypted:*` cast: drop-and-re-add, not `MODIFY`.** MySQL validates `json NOT NULL` on write;
  an `encrypted` envelope is not valid JSON (error 3140), so `MODIFY ... JSON` fails once any row
  holds encrypted plaintext-turned-ciphertext. Use `Schema::table()->dropColumn()` then a raw
  `ALTER TABLE ... ADD col MEDIUMTEXT NULL AFTER prior_col` to preserve column order — this is
  destructive to existing rows' plaintext values in that column (used for `webhook_events.headers`
  json→`encrypted:array` in #5; document the data loss explicitly in the migration docblock, don't
  silently drop it).
- **Verify a documented-as-non-round-tripping `down()` by actually running the cycle**, not by
  skipping the test: `sail artisan migrate` → `migrate:rollback --step=1` → `migrate` against a
  fresh (empty) DB proves `up`/`down`/`up` all apply cleanly even when `down()` would fail against
  rows already mutated (documented caveat, not a defect).
- **`foreignId(...)->constrained()->cascadeOnDelete()` IS valid** (unlike `->unique()` above) —
  `ForeignKeyDefinition` has `cascadeOnDelete()`/`restrictOnDelete()`/etc. but no `unique()`; add a
  separate `$table->unique('col')` line when both are needed on the same column.
- **Compare-and-set write guard across two write paths racing the same parent row**: wrap the
  read-check-write in `DB::transaction(fn () => ...)`, `lockForUpdate()` the parent row inside it,
  re-check the guard column, then do the dependent write — the row lock serializes against any
  other transaction doing the same (e.g. a GC pass's own compare-and-set `UPDATE ... WHERE
  guard_col IS NULL`), closing the select→act race without a separate advisory lock. Used for
  `CaptureDispatchedStep`'s post-clean guard (#5) racing `PurgeExpiredPayloads`.
- **A composite UNIQUE whose leftmost column is a FK becomes that FK's sole supporting index** —
  InnoDB won't create a redundant single-column index once a left-prefix-matching index exists.
  In a `down()` that adds `foreignId('x')->constrained()` then `unique(['x', 'y'])` (in that order
  in `up()`), reversing naively (`dropUnique` before dropping the FK) fails with *"needed in a
  foreign key constraint"* (MySQL 1553). Always `dropForeign(['x'])` before `dropUnique([...])`/
  `dropColumn('x')` in the reversal. Used for `delivery_attempts.delivery_id` +
  `UNIQUE(delivery_id, attempt_number)` (#6 T5).
- **Verify multi-step `up()`/`down()` migrations by running a full `migrate:fresh` first, not just
  a single `migrate` on a possibly-already-mutated dev DB** — a dev DB nudged by earlier
  rollback/re-migrate experiments in the same session can carry stale index state that produces
  misleading errors unrelated to the migration under test. `migrate:fresh` → inspect
  `information_schema` → isolated `migrate:rollback --step=1` → inspect again → `migrate` is the
  reliable up/down/up proof.
- **Adding a composite index whose leftmost column is a plain (non-unique) FK column can ALSO
  silently drop that FK's pre-existing auto-generated single-column support index** — not only the
  UNIQUE case above. If a column got its index only implicitly (`foreignId(...)->constrained()`
  with no explicit `->index()`/`->unique()` in that or a later migration), adding
  `$table->index(['that_col', ...])` later makes InnoDB drop the old auto index as redundant in the
  same `ALTER TABLE`, in this project's MySQL 8.4 (verified via `SHOW CREATE TABLE` before/after,
  and reproduced against a throwaway table). A literal `down()` that `dropIndex`s the new composite
  then fails with 1553 ("needed in a foreign key constraint") — nothing else covers the FK.
  Reversible `down()` must restore an equivalent single-column `$table->index(['that_col'])` BEFORE
  dropping the composite one — and guard that restoration with
  `Schema::hasIndex($table, ['that_col'])` so a repeat `down()` (e.g. a parallel test worker
  re-running the same rollback test across separate `sail test --parallel` invocations against its
  persisted database) doesn't hit "Duplicate key name" on the second run. A column that already had
  an EXPLICIT index/composite index from an earlier migration (e.g. `(proxy_id, status)`) is
  unaffected — that index isn't the auto-generated kind InnoDB reclaims. Used for T1 of #11's
  `deliveries.team_id`/`deliveries.proxy_id` composite analytics indexes.
- **DDL inside a `RefreshDatabase`-wrapped test escapes the per-test transaction sandbox on
  MySQL** (implicit commit on DDL) and mutates the real test database directly, not just within
  that test's rollback-at-teardown boundary. A migration-rollback test (`migrate:rollback` +
  `migrate` inside one test method) is therefore only "clean" if it's actually idempotent/safe to
  run more than once — `sail test --parallel` persists one MySQL database per worker
  (`testing_test_1..N`) and only re-migrates on a migration-file checksum change, so a second full
  suite run against the same worker DBs re-executes any such test's DDL against schema state the
  FIRST run already mutated. Reset all worker DBs (`for i in 1..N: DB_DATABASE=testing_test_$i
  artisan migrate:fresh`) and run `sail test --parallel` twice in a row to prove idempotency, not
  just once.
- **The full migration set cannot run against SQLite at all**, despite `stack.md` naming SQLite as
  local/default — the `webhook_events.body` LONGBLOB migration's raw `ALTER TABLE ... ADD body
  LONGBLOB NOT NULL AFTER content_type` is MySQL-only DDL syntax (`AFTER col`); SQLite's parser
  rejects it outright (`near "AFTER": syntax error`). `RefreshDatabase`-based feature/unit tests
  therefore only run under `./vendor/bin/sail test` (MySQL) in practice, even locally. To verify a
  raw SQL expression (e.g. a `DB::raw()` bucket/grouping expression) against SQLite anyway, skip
  migrations entirely and exercise the query builder against a bare temp table:
  `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan tinker` (host PHP, no Sail needed) —
  `CREATE TABLE`/`insert`/`select` by hand, compare output to the same call against
  `sail artisan tinker` (MySQL). Confirmed one instance already: `SUBSTRING(col, 1, n)` produces
  identical output on MySQL 8.4 and SQLite 3.53+ (SQLite registers it as a `substr()` alias) — no
  driver branch needed for that expression.
- **`DB::raw($dynamicString)` fails PHPStan's `literal-string` check on `Connection::raw()` even
  when `$dynamicString` is itself built from an exhaustive `match` — a helper method's declared
  `: string` return type erases the literal-ness at the call boundary.** Fix by inlining the
  `match` directly inside the `DB::raw(...)` call (or the `select(...)` argument list) rather than
  extracting it to a `private function foo(): string`; PHPStan's literal-string flow analysis sees
  through an inline `match`/ternary of string literals but not through an intervening function
  call.
- **`information_schema.COLUMNS` row order is not guaranteed without `ORDER BY ORDINAL_POSITION`** —
  a schema test that reads columns via `information_schema` and asserts on `array_keys($columns)`
  with `assertSame` (relying on implicit row order) can pass under a single-worker run and fail
  under `./vendor/bin/sail test --parallel` (each worker gets its own schema/session, and MySQL
  doesn't promise ordinal order without an explicit `ORDER BY`). Always add
  `ORDER BY ORDINAL_POSITION` to the query, and prefer `assertEqualsCanonicalizing` over `assertSame`
  when the acceptance criterion is "these columns exist", not "in this row order" (item #10 T1/T6).
- **A migration test that creates Eloquent/DB rows *between* two `Artisan::call()` migration
  commands in the same test method leaks those rows permanently**, not just schema state — the DDL
  implicit-commit hazard above applies to any DML sandwiched between two DDL calls, since the
  wrapping transaction is already gone by the second call. Symptom is a **misleading, unrelated**
  failure in a totally different test file later in the same run/worker (e.g. `firstOrFail()`/
  `first()` with no explicit ordering silently returning someone else's leaked row instead of the
  row the failing test itself just created) — this reads exactly like a production regression in
  whatever feature the leaked row's table belongs to, and following that lead wastes time before
  the actual cause (a sibling migration test's own hygiene) is found. Diagnostic tell: the
  "regressed" test passes in complete isolation and against a freshly-reset database. Fix: wrap the
  row-creation-and-assert block in `try`/`finally`, explicitly deleting the created rows in
  `finally` regardless of pass/fail — don't rely on transactional rollback once DDL has run in that
  method. Verify by resetting every `sail`-managed testing database (`testing`, `testing_test_1..N`)
  and running the full parallel suite **twice in a row** against the same now-checksum-skipped
  worker databases (item #10 T54's `RemoveInboundVerificationMigrationTest`, which leaked into
  `SensitiveFieldsPersistenceTest`).
- **`migrate:rollback --step=N` walks the last N migrations by run order (id), never by name or
  path** — verified in `Migrator::getMigrationsForRollback()`/`rollback()`; passing `--path` only
  restricts which files are loaded to resolve a migration class, it does NOT filter which rows get
  selected for rollback. A test that does `migrate:rollback --step=1` assuming "my own migration is
  always the most recent" breaks the moment ANY later-dated migration is added by another feature —
  this is a latent trap for every such test, not a defect in the new migration. Fix: compute the
  step count dynamically, e.g. `DB::table('migrations')->where('migration', '>=', $ownMigrationName)
  ->count()`, then rollback that many steps and reapply everything with a single `migrate` — the
  round-trip assertions are unaffected since all rolled-back migrations get reapplied together
  (`AnalyticsIndexesTest` fixed this way when item #10 T1 added a later-dated migration).
