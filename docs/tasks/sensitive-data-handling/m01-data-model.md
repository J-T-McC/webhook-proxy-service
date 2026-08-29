> Part of the item #10 task plan — see [`index.md`](index.md) for the plan header, authorities and binding constraints.

## M1 — Data model

## T1 — Migration: `proxy_secrets` table, three `proxies` columns, three `destinations` columns (Owner-approval flag 1, approved exactly as enumerated) (plan § Data Model)
- **Description:** One new migration, plain Blueprint, exactly as the approved change set enumerates.
  New table **`proxy_secrets`**: `id`, `team_id` (`foreignId->constrained()`, RESTRICT on delete),
  `proxy_id` (`foreignId->constrained()->cascadeOnDelete()`), `purpose` `string(32)`, `value` `text`
  cast **`encrypted`**, `is_current` `boolean` **nullable**, `expires_at` `timestamp` nullable,
  timestamps. One index, a constraint: **`UNIQUE(proxy_id, purpose, is_current)`**, named
  `proxy_secrets_proxy_id_purpose_is_current_unique`. `proxies` gains `verification_scheme`
  `string(32)` nullable, `verification_header_name` `string(128)` nullable, `sensitive_fields`
  `longText` nullable. `destinations` gains `credential_header_name` `string(128)` nullable,
  `credential_secret` `text` nullable cast **`encrypted`**, `credential_set_at` `timestamp` nullable.
  No index added to `proxies` or `destinations`. No other table, column, index, enum value, FK, or
  `onDelete` behaviour is touched anywhere; no backfill, no default written to any existing row.
  `down()` is `dropIfExists('proxy_secrets')` plus two `dropColumn` calls.
- **Dependencies:** none
- **Files:** `database/migrations/2026_08_27_000001_add_sensitive_data_handling_schema.php` (new)
- **Acceptance Criteria:**
  - Migration applies cleanly; `proxy_secrets` exists with exactly the nine columns above and the one
    named unique index; `proxies` and `destinations` each carry exactly their three new columns.
  - Every pre-existing index on `proxies` and `destinations` — `ingest_token_hash` UNIQUE, the team
    foreign-key index, and every index #11 added — is still present, unchanged, after the migration.
  - Inserting two rows with the same `(proxy_id, purpose, is_current = true)` fails against the
    unique index; inserting any number of rows with `is_current = NULL` for the same
    `(proxy_id, purpose)` succeeds (MySQL/SQLite both ignore NULLs in a unique index — this is the
    partial-unique behaviour the schema relies on).
  - `down()` removes exactly `proxy_secrets` and the six new columns; every pre-existing table,
    column and index still present post-rollback; the round trip (`migrate:rollback --step=1` +
    `migrate`) is clean.
  - No other schema change appears anywhere in the diff — this migration is the only one this whole
    task list adds.
- **Testing:** `tests/Unit/Migrations/SensitiveDataHandlingSchemaTest.php` (new) — the full
  column/type/nullability assertion for all three tables (via `information_schema`, mirroring
  `AnalyticsIndexesTest`'s pattern), the unique-index-both-directions assertion (duplicate `is_current
  = true` fails; multiple `is_current = NULL` rows succeed), the pre-existing-index-survival list, and
  the rollback round trip.
- **Completion notes:** Done. Added the migration exactly as enumerated — `proxy_secrets` (nine
  columns, one partial-unique index) plus the three `proxies` and three `destinations` columns, plain
  Blueprint throughout, `down()` a `dropIfExists` and two `dropColumn` calls. New test file covers the
  column/type/nullability shape, both directions of the unique index, pre-existing-index survival, and
  the rollback round trip. `composer lint`, `composer types:check` and `./vendor/bin/sail test
  --parallel` all green.

  Incidental fix required to keep the full suite green: this migration's timestamp
  (`2026_08_27_000001`) sorts after `2026_08_26_000001_add_analytics_indexes_to_delivery_tables`, so
  `AnalyticsIndexesTest`'s rollback test — which called `migrate:rollback --step=1` assuming its own
  migration was always the most recently run — started rolling back this migration instead of its
  own, failing. `--step=1` walks the last N migrations by run order, not by name, so this was a
  latent ordering assumption that any later migration would have broken, not a defect in this
  migration. Fixed by computing the step count needed to reach that test's own migration by name
  (`tests/Unit/Migrations/AnalyticsIndexesTest.php`) rather than hardcoding `1`; both rolled-back
  migrations are reapplied in the same test, so its round-trip assertions are unaffected. No
  requirement, interface, data model or ADR'd decision changed — test-only, root-cause fix.

## T2 — `ProxySecret` model, `SecretPurpose` enum, `Proxy`/`Destination` relations and casts (plan § Services & Actions, § Data Model)
- **Description:** `App\Models\ProxySecret` (Eloquent): `value` cast `encrypted`, **`$hidden =
  ['value']`**, a `live()` local scope (`is_current = true OR (is_current IS NULL AND (expires_at IS
  NULL OR expires_at > now()))` — read as "current, or superseded-but-not-yet-expired," matching
  `plan-10`'s "current first, non-expired" live-set predicate), `#[Fillable]` entries, docblocks.
  `App\Enums\SecretPurpose` backed enum: `Verification = 'verification'`, `Signing = 'signing'`. `Proxy`
  gains a `secrets(): HasMany` relation to `ProxySecret`, casts for `verification_scheme`
  (`VerificationScheme::class` — enum created at T16; declare the cast here referencing the
  not-yet-created class, or defer the cast line to T16 and note it, whichever keeps this task's own
  suite green) and `sensitive_fields` (`array`), and `#[Fillable]` entries for
  `verification_header_name`/`sensitive_fields`. `Destination` gains casts for `credential_secret`
  (`encrypted`) and `credential_set_at` (`datetime`), and `#[Fillable]` entries for
  `credential_header_name`/`credential_set_at`. Docblocks (`@property`, `@property-read`) updated on
  both models per this project's existing convention.
- **Dependencies:** T1
- **Files:** `app/Models/ProxySecret.php` (new), `app/Enums/SecretPurpose.php` (new),
  `app/Models/Proxy.php`, `app/Models/Destination.php`
- **Acceptance Criteria:**
  - `ProxySecret::make(['value' => 'plaintext'])->value` round-trips through the `encrypted` cast; a
    raw `DB::table('proxy_secrets')` read of the same row's `value` column is **not** the plaintext.
  - `(new ProxySecret())->toArray()` and `->toJson()` never contain a `value` key (the `$hidden` guard,
    independent of any resource).
  - `Proxy::factory()->create()->secrets` resolves the `HasMany` relation; `ProxySecret::live()`
    excludes an expired row and includes a current or not-yet-expired one, exercised against a small
    fixture of three rows (current, superseded-not-expired, superseded-expired).
  - `composer types:check` (PHPStan level 7) passes on both models with no suppression.
- **Testing:** `tests/Unit/Models/ProxySecretTest.php` (new) — the cast round-trip, the raw-column
  ciphertext assertion, the `$hidden` assertion on both `toArray()`/`toJson()`, and the `live()` scope
  fixture. `Proxy`/`Destination` factory updates as needed for the new nullable columns (no required
  fixture change — every new column is nullable).
- **Completion notes:** Done. `ProxySecret` (encrypted `value` cast, `$hidden = ['value']`, a
  `scopeLive()` local scope implementing "current, or superseded-but-not-yet-expired") and
  `App\Enums\SecretPurpose` added. `Proxy` gains a `secrets(): HasMany` relation, a `sensitive_fields`
  array cast, and `#[Fillable]` entries for `verification_header_name`/`sensitive_fields`; `Destination`
  gains `credential_secret` (encrypted) and `credential_set_at` (datetime) casts and `#[Fillable]`
  entries for `credential_header_name`/`credential_set_at`. Both models' `@property`/`@property-read`
  docblocks updated. No `Proxy`/`Destination` factory changes were needed — every new column is
  nullable, matching the task's own note.

  Per the task's explicit either/or: deferred `verification_scheme`'s cast (and its `#[Fillable]` entry)
  to T16, where `App\Enums\VerificationScheme` is created — referencing that not-yet-existing class here
  would have broken this task's own suite. `proxies.verification_scheme` stays an uncast raw string
  column until then; noted inline in `Proxy::casts()`.

  `composer lint`, `composer types:check` and `./vendor/bin/sail test --parallel` all green.

## T3 — Schema and encryption-surface regression tests (plan § Test strategy "Encryption at rest and the closed store set")
- **Description:** No production code. Pins the two guarantees that must survive every later task in
  this feature untouched: (1) the `APP_PREVIOUS_KEYS` column list ADR-021 § Impact enumerates —
  `webhook_events.body`, `webhook_events.headers`, `dispatched_payloads.body`, `proxies.ingest_token`,
  `proxy_secrets.value`, `destinations.credential_secret` — matches the casts actually declared, via
  reflection over the five models, so the list cannot silently drift from the code; (2) a secret write
  produces no plaintext secret in the query log (`DB::listen` over an `INSERT`/`UPDATE` touching either
  encrypted column).
- **Dependencies:** T2
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - A reflection test enumerates every `encrypted`-cast property across `ProxySecret`, `Proxy`,
    `Destination`, `WebhookEvent`, `DispatchedPayload` and asserts the set is exactly the six columns
    named above — failing loudly if a future cast is added or removed without updating this test.
  - `DB::listen` captures no plaintext secret value in any SQL binding logged during a `ProxySecret`
    or `Destination` credential write.
- **Testing:** `tests/Unit/Models/EncryptedColumnSurfaceTest.php` (new).
- **Completion notes:** Done. Reflection test constructs each of the five models, reads
  `getCasts()`, filters to casts whose string starts with `encrypted` (so `encrypted:array` on
  `WebhookEvent::headers` counts alongside plain `encrypted`), and asserts the resulting
  `table.column` set is exactly the six ADR-021 § Impact names (order-independent via
  `assertEqualsCanonicalizing`, since PHP array key order from five merged model calls carries no
  meaning). Two `DB::listen` tests (one `ProxySecret` write, one `Destination` credential write)
  assert the plaintext value never appears in any logged binding for a query touching that table —
  true by construction, since the `encrypted` cast runs at attribute-set time before the query is
  built. `composer lint` (which reordered this file's imports/PHPDoc alignment — no behaviour
  change), `composer types:check` and `./vendor/bin/sail test --parallel` all green.

---
