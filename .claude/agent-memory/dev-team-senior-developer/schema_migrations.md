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
