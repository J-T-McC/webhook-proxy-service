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
