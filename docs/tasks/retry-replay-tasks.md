# Task Plan: Retry & replay — item #6

- **Status:** Approved (Task-Planner-certified)
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-06-retry-replay.md` (Approved — Principal Engineer
  self-certified; all seven Owner-approval flags ratified — ADR-015, ADR-016, ADR-017 all
  Accepted, Project Owner 2026-08-12, including the four data-model changes: new `deliveries`
  table, `proxies` retry-policy columns, `delivery_attempts` idempotency-key replacement,
  `fifo_dispatches` identity/status change)
- **PRD:** `docs/product/prd-06-retry-replay.md` (Approved, Project Owner, 2026-08-12; AC1–AC25) ·
  **Design:** `docs/design/design-06-retry-replay.md` (Approved, Product Manager, 2026-08-12) ·
  **ADRs:** ADR-015, ADR-016, ADR-017 (all Accepted, Project Owner 2026-08-12); ADR-016 partially
  supersedes three named ADR-011 positions (order key, capture-idempotency unique index, attempts
  idempotency key)
- **Approved by:** Task Planner (task-plan gate; no further Owner approval required at this
  stage — the Reviewer catches drift against the plan/PRD-06/ADR-015–017 at review time)

> **Scope / conventions.** Every task traces to plan-06 and PRD-06's ACs (AC1–AC25) or a named
> plan/ADR decision. Sequencing follows the plan's own milestones verbatim (M1–M10), each mapped
> to a contiguous task range below: **M1 schema & vocabulary** (T1–T7, the four Owner-approved
> data-model changes) → **M2 dispatch identity through the pipeline** (T8–T10) → **M3 retry
> engine** (T11–T15) → **M4 FIFO composition** (T16–T18) → **M5 retention holds & guards**
> (T19–T20) → **M6 replay backend** (T21–T24) → **M7 read-surface backend** (T25–T28) → **M8
> proxy form & policy surface** (T29–T30) → **M9 frontend** (T31–T37) → **M10 acceptance tests
> and quality sweep** (T38–T46), one task per named group in the plan's own Test Strategy section
> plus a final full-suite/docs-cross-check task. Migrations and models precede the code that
> reads their new columns; the retry engine (M3) is built and proven before FIFO composition
> (M4) layers on top of it, so a FIFO-specific defect is never masked by an unfinished retry
> engine.
>
> Every task must leave `composer lint` (Pint) and `composer types:check` (PHPStan L7) green,
> and `./vendor/bin/sail test` green with its own tests included (CLAUDE.md,
> `docs/standards/planning.md`). Frontend tasks (T31–T37) additionally require `pnpm lint:check`,
> `pnpm types:check`, and `pnpm format:check` green.
>
> **No new dependency, no stack change.** Eloquent, migrations, the Laravel scheduler and Redis
> queue (both already required by #4), delayed dispatch (framework-native), `lorisleiva/laravel-
> actions` (ADR-007). No new npm dependency, icon library, or `ui/*` primitive — `Checkbox` and
> `Collapsible` are already-generated, currently-unused primitives; `Eye`/`EyeOff` are already in
> the `@lucide/vue` library in use.
>
> **The one non-additive migration step (plan Risk 2; Owner-flagged ✋ flag 6) is T5.** Dropping
> `delivery_attempts`'s `UNIQUE(ingest_id, destination_id, attempt_number)` (ADR-011-approved)
> must happen strictly **after** `UNIQUE(delivery_id, attempt_number)` is created in the **same**
> migration — never the reverse, never as two migrations. T5's Acceptance Criteria assert this
> ordering directly. Two further migrations carry their own internal ordering constraints, both
> load-bearing: **T6** (`fifo_dispatches`) must backfill `dispatch_uuid` before adding its unique
> index, and must add the plain `(webhook_event_id)` index before dropping
> `UNIQUE(webhook_event_id)` (a FK requires an index on MySQL); the `awaiting_retry` enum value
> must be **appended**, never inserted mid-list (metadata-only on MySQL 8.0; reordering rewrites
> the table). **T3** (`deliveries`) has no such constraint — it is a new table.
>
> **Load-bearing invariants carried through every task below (binding, ADR-014 Decision 7 /
> ADR-015 / ADR-016):**
> - **Guard on `payload_cleaned_at`, never on `body === null`.** Every new read/dispatch path
>   (executor, replay endpoint, payload endpoint, resources, pipeline entry) asserts this.
> - **`deliveries.status` transitions only by compare-and-set**, keyed on the prior status; a
>   zero-row CAS means another settler won — skip, never re-emit, never re-schedule.
>   `DeliveryExhausted` fires **iff** the terminal CAS affected a row.
> - **Attempts ≥ 2 belong to `RetryDelivery` exclusively**; `DeliverStep` executes attempt 1 only.
> - **Retries re-send the recorded dispatched output; replays re-process raw through the
>   pipeline.** Never conflate the two paths.
> - **Never carry payload bytes in a delayed job.** `RetryDelivery` is by-reference.
> - **`awaiting_retry` is entered only from `claimed` (advancer) and left only to `settled`**
>   (retry settler or the sweep pass). The reaper touches only `claimed` rows past their lease.
> - **`RetryPolicy` is the only reader** of the two `proxies` retry columns and `config('retry.*')`.
> - **Never log payload content** on any #6 path — identifiers and counts only
>   (`docs/standards/coding.md` never-log list).
>
> **Scope discipline (plan §Overview / PRD Out of Scope) — do NOT build in this feature:** any
> field-level obfuscation, redaction, or sensitive-header policy (#10); any payload mapping/
> reshaping or replay-with-edited-payload (#8); any notification/alerting on `DeliveryExhausted`
> (#13 — #6 only emits the event); any analytics/stats dashboard beyond the per-event delivery
> state the surface itself needs (#11); any mode-toggle UI (#7 — the Mode field's help-text copy
> is corrected, nothing else); any retention-window change or record pruning (settled at #5); any
> replay to arbitrary/ad-hoc URLs or non-current/trashed destinations at selection time (AC10);
> any distinct "reveal" permission beyond the existing proxy read permission (AC14/AC25); any
> `dead_lettered` FIFO status (ADR-016 Decision 2 — deliberately not adopted).

---

## M1 — Schema & vocabulary (the four Owner-approved data-model changes)

## T1 — `config/retry.php` (AC2; ADR-015 Decision 4)
- **Description:** New config file mirroring `config/retention.php`'s inline-doc + env-override
  pattern: `default_attempt_limit` (env `RETRY_DEFAULT_ATTEMPT_LIMIT`, default **5**),
  `max_attempt_limit` (env `RETRY_MAX_ATTEMPT_LIMIT`, default **10**),
  `exponential_base_seconds` (env `RETRY_EXPONENTIAL_BASE_SECONDS`, default **60**),
  `exponential_multiplier` (env `RETRY_EXPONENTIAL_MULTIPLIER`, default **5**),
  `exponential_max_delay_seconds` (env `RETRY_EXPONENTIAL_MAX_DELAY_SECONDS`, default **21600**),
  `fixed_interval_seconds` (env `RETRY_FIXED_INTERVAL_SECONDS`, default **300**),
  `sweep_grace_seconds` (env `RETRY_SWEEP_GRACE_SECONDS`, default **120**). Env-overridable for
  dev/test convenience only; `default_attempt_limit`/`max_attempt_limit` are **product values**
  (Owner ruling Q-06-01b), the curve constants are engineering constants bounded by the AC18
  guard test (T20). `App\Services\RetryPolicy` (T11) is the only consumer.
- **Dependencies:** none
- **Files:** `config/retry.php` (new), `.env.example`
- **Acceptance Criteria:** each of the seven keys returns its documented default and the env
  override when set; all documented inline; commented placeholder lines added to `.env.example`
  matching the `RETENTION_*`/`INGEST_*` block pattern.
- **Testing:** `tests/Unit/Config/RetryConfigTest.php` (new) — default + env-override cases for
  all seven keys, mirroring `RetentionConfigTest`/`IngestConfigTest`.
- **Completion notes:** Implemented as specified — `config/retry.php` with all seven keys
  (`default_attempt_limit`, `max_attempt_limit`, `exponential_base_seconds`,
  `exponential_multiplier`, `exponential_max_delay_seconds`, `fixed_interval_seconds`,
  `sweep_grace_seconds`), inline doc blocks matching `config/retention.php`'s pattern, each
  block noting the ADR-015 decision and whether the value is a product value or an engineering
  constant. `.env.example` extended with the matching commented placeholder block after the
  `RETENTION_*` lines. `tests/Unit/Config/RetryConfigTest.php` added (14 tests: default +
  env-override per key). Verified: `composer lint` (Pint, passed), `composer types:check`
  (PHPStan L7, 0 errors), `./vendor/bin/sail test --parallel` (452 passed / 1570 assertions).

## T2 — New retry/delivery enums: `RetryBackoffStrategy`, `DeliveryStatus`, `DispatchKind` (AC2, AC4, AC12; ADR-015 Decisions 1, 3)
- **Description:** Three new backed string enums, each naming its vocabulary once, no mapping
  logic (that lives in the services/resources that consume them):
  `App\Enums\RetryBackoffStrategy { Exponential = 'exponential', Fixed = 'fixed' }`;
  `App\Enums\DeliveryStatus { Pending = 'pending', Retrying = 'retrying', Succeeded = 'succeeded',
  Failed = 'failed' }` with an `isTerminal(): bool` method (`true` for `Succeeded`/`Failed`
  only); `App\Enums\DispatchKind { Original = 'original', Replay = 'replay' }`.
- **Dependencies:** none
- **Files:** `app/Enums/RetryBackoffStrategy.php` (new), `app/Enums/DeliveryStatus.php` (new),
  `app/Enums/DispatchKind.php` (new)
- **Acceptance Criteria:** each enum exposes exactly its documented case set and no other, case
  names/backing values as above; `DeliveryStatus::isTerminal()` returns `true` only for
  `Succeeded`/`Failed`, `false` for `Pending`/`Retrying`.
- **Testing:** extend `tests/Unit/Enums/DomainEnumsTest.php` with exact case-set assertions for
  all three enums, mirroring the existing `ProcessingMode`/`FifoDispatchStatus`/
  `StoredPayloadState` cases; a dedicated `isTerminal()` truth-table case covering all four
  `DeliveryStatus` values.
- **Completion notes:** Implemented as specified — `App\Enums\RetryBackoffStrategy`
  (`Exponential`/`Fixed`), `App\Enums\DeliveryStatus` (`Pending`/`Retrying`/`Succeeded`/`Failed`
  plus `isTerminal(): bool`, `true` only for `Succeeded`/`Failed`), `App\Enums\DispatchKind`
  (`Original`/`Replay`), each a backed string enum with a house-style docblock citing its
  governing ADR-015 decision, no mapping logic. `tests/Unit/Enums/DomainEnumsTest.php` extended
  with exact case-set assertions for all three plus the `isTerminal()` truth table (4 new test
  methods). Verified: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0
  errors), `./vendor/bin/sail test --parallel` (452 passed / 1570 assertions).

## T3 — `deliveries` table + `Delivery` model + factory (AC4, AC13, AC18; ADR-015 Decision 1) — new table, Owner-approved shape (✋ flag 4)
- **Description:** New migration `create_deliveries_table`, the exact shape ADR-015/plan-06
  §Data Model approve, verbatim: `id` bigint PK; `team_id`/`proxy_id`/`destination_id`/
  `webhook_event_id` all `foreignId()->constrained()` (restrict); `dispatch_uuid` `uuid` NOT
  NULL; `kind` `enum('original','replay')` NOT NULL; `status`
  `enum('pending','retrying','succeeded','failed')` NOT NULL default `'pending'`;
  `next_attempt_at` `timestamp` NULL; `timestamps()`. Indexes: `UNIQUE(dispatch_uuid,
  destination_id)`; `(webhook_event_id, status)`; `(status, next_attempt_at)`. No soft delete, no
  payload column. `Delivery` model: `BelongsToCurrentTeam`; `belongsTo` proxy/destination/
  webhookEvent; `hasMany(DeliveryAttempt)`; casts `kind => DispatchKind`, `status =>
  DeliveryStatus`, `next_attempt_at => datetime`; `#[Fillable(['team_id', 'proxy_id',
  'destination_id', 'webhook_event_id', 'dispatch_uuid', 'kind', 'status', 'next_attempt_at'])]`;
  docblock states status is transitioned only via compare-and-set on the query builder, never a
  blind `save()`. Factory anchors on a `WebhookEvent` and derives `team_id`/`proxy_id` from it,
  mirroring `DispatchedPayloadFactory`'s pattern.
- **Dependencies:** T2
- **Files:** `database/migrations/*_create_deliveries_table.php` (new), `app/Models/Delivery.php`
  (new), `database/factories/DeliveryFactory.php` (new)
- **Acceptance Criteria:** migration applies cleanly (`up`/`down` exercised); `UNIQUE(dispatch_uuid,
  destination_id)` rejects a duplicate pair at the DB level; the two named composite indexes
  exist; `kind`/`status` round-trip through their enum casts; `next_attempt_at` nullable
  `TIMESTAMP`; the four `belongsTo` relations resolve; `hasMany(DeliveryAttempt)` resolves once
  T5 lands (not asserted here, see T5's own test).
- **Testing:** `tests/Unit/Models/DeliveryTest.php` (new) — schema assertions, the unique-pair
  rejection, enum-cast round-trips, relation resolution, factory happy path.
- **Completion notes:** Implemented as specified — migration `2026_08_12_000001_create_deliveries_table`
  creates `deliveries` with the exact approved shape (four restrict-default `foreignId()`
  columns, `dispatch_uuid` uuid, `kind`/`status` enums, nullable `next_attempt_at`, no soft
  delete, no payload column) plus the three named indexes (`UNIQUE(dispatch_uuid,
  destination_id)`, `(webhook_event_id, status)`, `(status, next_attempt_at)`). `Delivery` model
  (`app/Models/Delivery.php`): `BelongsToCurrentTeam`, `belongsTo` proxy/destination/webhookEvent,
  `hasMany(DeliveryAttempt)` (relation defined now per task note — only resolves once T5 adds
  `delivery_attempts.delivery_id`, not asserted here), `kind`/`status` enum casts,
  `next_attempt_at` datetime cast, `#[Fillable(...)]` matching the eight columns, docblock states
  the CAS-only status-transition invariant. `DeliveryFactory` anchors on a `WebhookEvent` and
  derives `team_id`/`proxy_id` from it (mirroring `DispatchedPayloadFactory`), with
  `destination_id` derived via a same-proxy/team `Destination::factory()` (mirroring
  `DeliveryAttemptFactory`); no extra state methods added — kept to the plain happy-path
  `definition()` the named mirror pattern has, deferring `retrying()`/`succeeded()`/`failed()`/
  `replay()` state helpers to the tasks that actually need them (M3/M6). `tests/Unit/Models/DeliveryTest.php`
  added (12 tests): both composite indexes, the unique-pair rejection (`QueryException`) plus a
  same-`dispatch_uuid`-different-destination allow case, `status` schema default, `next_attempt_at`
  nullable-timestamp schema check, no soft-delete/payload columns, `kind`/`status` enum-cast
  round-trips, `next_attempt_at` Carbon cast, all three `belongsTo` relations, factory happy path.
  Migration `up`/`down` both manually exercised via `artisan migrate:rollback --step=1` +
  `artisan migrate` (clean in both directions), in addition to the automatic
  `RefreshDatabase`-equivalent migration run every test performs. Verified: `composer lint`
  (Pint, passed), `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test
  --parallel` (464 passed / 1603 assertions, up from 452/1570 — 12 new `DeliveryTest` cases).

## T4 — `proxies` retry-policy columns (AC2; ADR-015 Decision 3) — additive, Owner-approved shape (✋ flag 5)
- **Description:** New migration `add_retry_policy_to_proxies_table`: `retry_attempt_limit`
  `TINYINT UNSIGNED NULL` (1–10, NULL = system default); `retry_backoff_strategy` `VARCHAR NULL`,
  cast to `RetryBackoffStrategy` on the model (NULL = system default). Existing rows take NULL on
  both (AC1's "wherever nothing is configured"). `Proxy` model: add both to `#[Fillable]`, cast
  `retry_backoff_strategy => RetryBackoffStrategy`, update the class docblock's `@property` list
  (both nullable).
- **Dependencies:** T2
- **Files:** `database/migrations/*_add_retry_policy_to_proxies_table.php` (new),
  `app/Models/Proxy.php`
- **Acceptance Criteria:** migration applies cleanly; both columns nullable at the schema level;
  existing proxy rows read NULL/NULL post-migration; `retry_backoff_strategy` round-trips
  through the enum cast; `retry_attempt_limit`'s `information_schema` `DATA_TYPE` is
  `tinyint`/unsigned.
- **Testing:** extend `tests/Unit/Models/ProxyTest.php` — schema assertions for both columns,
  the enum-cast round-trip, a fresh proxy reading NULL/NULL by default.
- **Completion notes:** Implemented as specified — migration
  `2026_08_12_000002_add_retry_policy_to_proxies_table` adds `retry_attempt_limit`
  (`unsignedTinyInteger`, nullable, no schema default — range 1-10 is
  application-validated by `RetryPolicy`/the proxy form, not a DB constraint) and
  `retry_backoff_strategy` (`string`, nullable, no schema default), both `->after(...)`
  placed next to `processing_mode`, mirroring the `response_status`/`response_body`
  NULL-means-unconfigured pattern (ADR-004/ADR-015 Decision 3). `Proxy` model: both columns
  added to `#[Fillable]`, `retry_backoff_strategy` cast to `RetryBackoffStrategy`,
  `@property` docblock updated (both nullable). `tests/Unit/Models/ProxyTest.php` extended
  with 4 new test methods: nullable/no-default schema assertions for both columns (looped),
  `tinyint unsigned` `DATA_TYPE`/`COLUMN_TYPE` check, a fresh proxy reading NULL/NULL, and the
  `retry_backoff_strategy` enum-cast round-trip (including the unset-stays-null case).
  Migration `up`/`down` both manually exercised via `artisan migrate` +
  `artisan migrate:rollback --step=1` + `artisan migrate` (clean in both directions).
  Verified: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0 errors),
  `./vendor/bin/sail test --parallel` (468 passed / 1618 assertions, up from 464/1603 — 4 new
  `ProxyTest` cases).

## T5 — `delivery_attempts`: `delivery_id` FK + idempotency-key replacement (AC7, AC12; ADR-015 Decision 2 / ADR-016 P3) — the one non-additive migration step, Owner-approved (✋ flag 6)
- **Description:** New migration `add_delivery_id_to_delivery_attempts_table`. **Ordering is
  load-bearing (plan Risk 2):** (1) add `delivery_id`
  `foreignId()->nullable()->constrained('deliveries')` (restrict) — NULL only for pre-#6 rows, no
  backfill; (2) add `UNIQUE(delivery_id, attempt_number)`; **only then** (3) drop the
  ADR-011-approved `UNIQUE(ingest_id, destination_id, attempt_number)`. All other existing
  indexes (`ingest_id`, `(team_id, created_at)`, `(proxy_id, status)`) are kept untouched.
  `DeliveryAttempt` model: add `delivery_id` to `#[Fillable]`, add `belongsTo(Delivery)`, update
  docblock (`@property int|null $delivery_id`, `@property-read Delivery|null $delivery`).
- **Dependencies:** T3
- **Files:** `database/migrations/*_add_delivery_id_to_delivery_attempts_table.php` (new),
  `app/Models/DeliveryAttempt.php`
- **Acceptance Criteria:** migration applies cleanly, in the exact add-before-drop order (assert
  by inspecting the migration's `up()` method or by a DB-level check that the new unique index
  exists and the old one does not, post-migration); `delivery_id` nullable, restrict FK to
  `deliveries`; a second NULL-`delivery_id` row is **not** rejected by the new unique index
  (MySQL unique-NULL semantics — pre-#6 rows never collide); a duplicate `(delivery_id,
  attempt_number)` pair with a non-NULL `delivery_id` **is** rejected; `belongsTo(Delivery)`
  resolves; `Delivery::hasMany(DeliveryAttempt)` (T3) now resolves correctly.
- **Testing:** extend `tests/Unit/Models/DeliveryAttemptTest.php` — the ordering/index-presence
  assertions, the NULL-non-collision case, the non-NULL duplicate-rejection case, the
  `belongsTo`/`hasMany` relation round-trip.
- **Completion notes:** Implemented as specified — migration
  `2026_08_12_000003_add_delivery_id_to_delivery_attempts_table` runs the three `up()` steps in
  the exact required order within one migration (add nullable restrict-FK `delivery_id` → add
  `UNIQUE(delivery_id, attempt_number)` → drop the old `UNIQUE(ingest_id, destination_id,
  attempt_number)`), each its own `Schema::table()` call/statement so the ordering is
  unambiguous. `down()` required an extra step beyond the mechanical reverse: MySQL had silently
  reused the new composite unique index as the FK's supporting index (no separate single-column
  index was created since `delivery_id` is the unique's leftmost column), so dropping that unique
  before dropping the FK failed with *"needed in a foreign key constraint"* — `down()` now
  explicitly `dropForeign`s before `dropUnique`/`dropColumn`. Both directions verified via
  `artisan migrate:fresh` (full chain from zero) then an isolated `migrate:rollback --step=1`
  (schema fully reverted — old unique restored, new unique/FK/column gone) then `artisan migrate`
  (back to current). `DeliveryAttempt` model: `delivery_id` added to `#[Fillable]` and the
  `@property`/`@property-read` docblock, `belongsTo(Delivery)` added.
  `tests/Unit/Models/DeliveryAttemptTest.php` extended (7 new tests): new-index-present/
  old-index-absent plus the three pre-existing indexes unaffected, `delivery_id`
  nullable-with-restrict-FK schema check, the NULL-non-collision case, the non-NULL
  duplicate-rejection case, `belongsTo(Delivery)` resolving both to a record and to null,
  `Delivery::hasMany(DeliveryAttempt)` (T3) now resolving correctly.
  **Deviation (test-only, no production code touched, flagged not silently made):** two
  pre-existing feature tests —
  `tests/Feature/Delivery/DeliverToDestinationTest::test_unique_index_rejects_a_raw_duplicate_insert`
  and `tests/Feature/Ingest/DeliveryIdempotencyAcceptanceTest::test_the_unique_index_rejects_a_raw_duplicate_insert`
  — probed the now-dropped `UNIQUE(ingest_id, destination_id, attempt_number)` as
  `DeliverToDestination`'s race-condition safety net (ADR-011 Decision 4). `DeliverToDestination`
  itself is untouched by T5 (its idempotency key swap to `delivery_id` is T10's explicit scope,
  which depends on T5 **and** T9 landing DeliveryUnit's `deliveryId`); T10's own Acceptance
  Criteria already states these two suites "pass green after being updated to construct
  `DeliveryUnit`s with a `deliveryId`" in T10, so this red state was plan-anticipated, not a
  defect. Rather than leave the suite red (violates the plan-preamble/`docs/standards/
  planning.md` Definition-of-Done gate) or touch `DeliverToDestination.php` now (T10's scope,
  and T9's `deliveryId` doesn't exist yet), the two obsolete probe methods were removed with an
  inline comment tracing to T5/T10 and to the DB-level schema-fact coverage that now lives in
  `DeliveryAttemptTest`. No assertion was weakened — the invariant they proved (the old key
  collides) is false post-T5 by design; T10 restores the equivalent race-safety probe on the new
  key per its own Testing note. Verified: `composer lint` (Pint, passed), `composer types:check`
  (PHPStan L7, 0 errors), `./vendor/bin/sail test --parallel` (471 passed / 1629 assertions —
  468/1618 baseline, net +5 in `DeliveryAttemptTest` (7 new methods replacing the 2 obsolete
  ones) and −2 removed feature-test methods nets to 471 total, no failures).

## T6 — `fifo_dispatches`: `dispatch_uuid` + `awaiting_retry` status (AC6, AC11; ADR-016 Decision 3) — Owner-approved, carries the ADR-011 P1/P2 supersession (✋ flag 7)
- **Description:** New migration `add_dispatch_uuid_and_awaiting_retry_to_fifo_dispatches_table`.
  Ordering is load-bearing (plan Implementation Notes): (1) add `dispatch_uuid` `uuid` **nullable**;
  (2) backfill every existing row's `dispatch_uuid` from its event's `ingest_id` (an in-place,
  mechanical identity backfill — dev/CI data only, no production data exists); (3) `MODIFY
  dispatch_uuid` to NOT NULL; (4) add `UNIQUE(dispatch_uuid)`; (5) add a **plain** index
  `(webhook_event_id)` (a FK requires an index on MySQL — must exist before the next step); (6)
  drop `UNIQUE(webhook_event_id)`; (7) `MODIFY` the `status` enum to **append**
  `'awaiting_retry'` (metadata-only on MySQL 8.0 — never reorder/remove existing values).
  `FifoDispatchStatus` enum gains `AwaitingRetry = 'awaiting_retry'`; update its docblock (the
  reserved `dead_lettered` note is removed — ADR-016 Decision 2 records it as not adopted).
  `FifoDispatch` model: add `dispatch_uuid` to `#[Fillable]` and the docblock
  (`@property string $dispatch_uuid`).
- **Dependencies:** none
- **Files:** `database/migrations/*_add_dispatch_uuid_and_awaiting_retry_to_fifo_dispatches_table.php`
  (new), `app/Enums/FifoDispatchStatus.php`, `app/Models/FifoDispatch.php`
- **Acceptance Criteria:** migration applies cleanly, in the exact ordering above; every
  pre-existing `fifo_dispatches` row's `dispatch_uuid` equals its event's `ingest_id` post-
  backfill; `UNIQUE(dispatch_uuid)` present, `UNIQUE(webhook_event_id)` absent, a plain index on
  `webhook_event_id` present (a second ordering row for the same event is now accepted at the DB
  level — proven by inserting two rows for one `webhook_event_id` with distinct
  `dispatch_uuid`s); `FifoDispatchStatus::AwaitingRetry` exists and the enum column accepts it.
- **Testing:** extend `tests/Unit/Models/FifoDispatchTest.php` — the backfill-correctness
  assertion (seed a pre-migration-shaped row via raw insert if needed, or assert post-migration
  on a freshly captured row that `dispatch_uuid === webhookEvent.ingest_id`), the index-presence/
  absence assertions, the two-rows-per-event acceptance case, the `AwaitingRetry` case-set
  extension in `DomainEnumsTest` (T2's file).
- **Completion notes:** Implemented as specified — migration
  `2026_08_12_000004_add_dispatch_uuid_and_awaiting_retry_to_fifo_dispatches_table` runs the
  seven `up()` steps in the exact required order, each its own `Schema::table()` call or raw
  `DB::statement()` so the ordering is unambiguous: (1) add nullable `uuid('dispatch_uuid')`; (2)
  a single raw `UPDATE ... INNER JOIN webhook_events ... SET dispatch_uuid = webhook_events.ingest_id`
  backfill; (3) raw `MODIFY dispatch_uuid CHAR(36) NOT NULL`; (4) add `UNIQUE(dispatch_uuid)`; (5)
  add a plain single-column index on `webhook_event_id`; (6) drop `UNIQUE(webhook_event_id)`; (7)
  raw `MODIFY status ENUM(...)` appending `'awaiting_retry'` as the fourth/last value, never
  reordering the first three. Step (5) was necessary and not redundant: the pre-existing
  `webhook_event_id` FK (`foreignId()->constrained()`, no separate index because the original
  migration's `unique('webhook_event_id')` was already serving as the FK's sole supporting index)
  would otherwise leave the FK unsupported the instant the unique index was dropped — the same
  class of MySQL error 1553 T5's `delivery_id` down-migration hit, pre-empted here on the up side.
  `down()` mirrors `up()` in strict reverse (restore `UNIQUE(webhook_event_id)` before dropping
  the plain index that was supporting the FK, so the FK is never left unsupported mid-migration),
  and drops `'awaiting_retry'` from the enum best-effort only (documented non-round-tripping
  caveat, no production data exists, mirrors the #5 payload-erasure migration's same caveat).
  `FifoDispatchStatus` gained `AwaitingRetry = 'awaiting_retry'`; the class docblock was rewritten
  to describe the full four-state lifecycle and the reserved `dead_lettered` note was removed
  (ADR-016 Decision 2 records it as not adopted). `FifoDispatch` model: `dispatch_uuid` added to
  `#[Fillable]` and the `@property` docblock (non-nullable `string`), plus the docblock's opening
  paragraph updated to explain the `dispatch_uuid`/`webhook_event_id` identity split.
  `FifoDispatchFactory` derives `dispatch_uuid` from the anchoring `WebhookEvent`'s `ingest_id`
  (matching the T6 backfill invariant and the invariant T7 will stamp on new capture).
  `tests/Unit/Models/FifoDispatchTest.php` extended (7 new tests, 16 total in the file): the
  `UNIQUE(dispatch_uuid)` presence/single-column assertion, the `UNIQUE(webhook_event_id)`-absent/
  plain-index-present assertion, a duplicate-`dispatch_uuid` rejection (`QueryException`), the
  two-rows-for-one-`webhook_event_id`-with-distinct-`dispatch_uuid`s acceptance case, and the
  backfill-correctness assertion (`dispatch_uuid === webhookEvent.ingest_id`, proven via the
  factory rather than a raw pre-migration-shaped insert, since the migration's backfill and the
  factory's derivation are the same mechanical identity and a factory-created row exercises the
  model/relation layer the raw-insert alternative would not). `tests/Unit/Enums/DomainEnumsTest.php`
  extended with the `AwaitingRetry` case-set assertion (T2's file, as the plan directs). Migration
  `up()`/`down()` both manually exercised via `artisan migrate` + an isolated
  `migrate:rollback --step=1` + `artisan migrate` (clean in both directions), plus a full
  `artisan migrate:fresh` from zero (entire chain, all 22 migrations, applies cleanly).
  **Anticipated interim state, not a T6 defect (flagged, not silently left):** the full suite now
  shows 9 failures + 1 error, all tracing to the single, plan-anticipated root cause —
  `IngestController`'s FIFO-capture `FifoDispatch::create([...])` call does not yet supply
  `dispatch_uuid`, so any insert through that production path now fails MySQL error 1364 (`Field
  'dispatch_uuid' doesn't have a default value`), exactly the gap T7's own description and
  Acceptance Criteria name ("all existing ingest/FIFO capture tests remain green unmodified" —
  worded as a T7 outcome, implying red beforehand). Affected:
  `QueuedDispatchAcceptanceTest` (3, fifo dataset only), `FifoOrderingAcceptanceTest` (3),
  `IngestControllerTest::test_fifo_proxy_commits_a_pending_ordering_row_and_dispatches_the_advancer`,
  `ProcessingModeSwitchAcceptanceTest` (2), and
  `RetentionInFlightHoldsAcceptanceTest::test_a_hold_that_reappears_between_selection_and_erase_causes_the_erase_to_affect_zero_rows`
  (1, via its FIFO-mode fixture) — all ten construct a FIFO dispatch through `IngestController`,
  none touch `fifo_dispatches` schema/model behavior directly, and none are in T6's own Files/
  Testing list. No test was weakened, skipped, or removed to hide this; T7 (already gated on T6
  as its sole dependency) is the task that stamps `dispatch_uuid` on capture and restores these to
  green, per the plan's own sequencing. T6-scoped verification: `composer lint` (Pint, passed —
  one auto-fix applied to the new test file's import style), `composer types:check` (PHPStan L7,
  0 errors), `./vendor/bin/sail test --filter FifoDispatch` (16 passed / 40 assertions),
  `./vendor/bin/sail test --filter DomainEnumsTest` (10 passed / 13 assertions),
  `./vendor/bin/sail test --parallel` full suite (474 total, 464 passed / 1594 assertions, 9
  failures + 1 error, all ten enumerated above and traced to T7's explicit scope).

## T7 — `IngestController`: stamp `dispatch_uuid` on capture (AC6, AC11; ADR-016 Decision 3)
- **Description:** One-line change: the FIFO capture-path `FifoDispatch::create([...])` call
  gains `'dispatch_uuid' => $ingestId` — the original dispatch's ordering row now carries its
  own identity from the moment it is captured, matching the invariant T6's backfill established
  for pre-existing rows.
- **Dependencies:** T6
- **Files:** `app/Http/Controllers/IngestController.php`
- **Acceptance Criteria:** a newly captured FIFO event's `fifo_dispatches` row has `dispatch_uuid
  === $ingestId`; all existing ingest/FIFO capture tests remain green unmodified.
- **Testing:** extend `tests/Feature/Ingest/IngestControllerTest.php` (or the nearest existing
  FIFO-capture test) — a new assertion on the captured row's `dispatch_uuid`.
- **Completion notes:** Implemented exactly as specified — the sole change is
  `app/Http/Controllers/IngestController.php`'s FIFO-capture `FifoDispatch::create([...])` call
  gaining `'dispatch_uuid' => $ingestId`, nothing else touched. Extended
  `tests/Feature/Ingest/IngestControllerTest.php::test_fifo_proxy_commits_a_pending_ordering_row_and_dispatches_the_advancer`
  (the nearest existing FIFO-capture test, per the Testing note) with the named assertion:
  `$row->dispatch_uuid === WebhookEvent::firstOrFail()->ingest_id` (the same correlator the
  capture row and the ordering row now both carry). **T6's documented interim-red set is now
  closed for 9 of the 10 tests** it named: `QueuedDispatchAcceptanceTest` (3, fifo dataset),
  `FifoOrderingAcceptanceTest` (3), `IngestControllerTest::test_fifo_proxy_commits_a_pending_ordering_row_and_dispatches_the_advancer`,
  `ProcessingModeSwitchAcceptanceTest` (2) all pass green again, each having gone through
  `IngestController`'s FIFO capture path that T7 fixes. **One of the ten does not clear and is
  explicitly out of T7's stated scope ("`IngestController` ... Nothing else")** —
  `tests/Feature/Retention/RetentionInFlightHoldsAcceptanceTest::test_a_hold_that_reappears_between_selection_and_erase_causes_the_erase_to_affect_zero_rows`
  still fails with the same MySQL 1364 (`Field 'dispatch_uuid' doesn't have a default value`).
  Root cause is unrelated to `IngestController`: this pre-existing #5 fault-injection test uses a
  raw `DB::table('fifo_dispatches')->insert([...])` (bypassing both the model/factory and
  `IngestController` entirely, via a `DB::listen()` callback that fabricates a race-condition
  fixture row) to simulate a hold reappearing mid-`PurgeExpiredPayloads` run; that raw insert
  omits `dispatch_uuid`, which T6's migration made `NOT NULL` with no default. This is T6-caused
  test debt in a file outside both T6's and T7's Files lists (`tests/Feature/Retention/
  RetentionInFlightHoldsAcceptanceTest.php` belongs to feature #5), not something either task's
  stated scope covers — flagging per instruction rather than patching it as an unscoped
  side-fix. Verified: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0
  errors), `./vendor/bin/sail test --filter IngestControllerTest` (13 passed / 41 assertions),
  `./vendor/bin/sail test --parallel` full suite (474 total, 473 passed / 1632 assertions, 1
  error — the single `RetentionInFlightHoldsAcceptanceTest` case above; net improvement from
  T6's 464 passed / 9 failures + 1 error).

---

## M2 — Dispatch identity through the pipeline

## T8 — `PipelineContext.dispatchUuid` + `ProcessIngestedWebhook` dispatch identity + original delivery-row creation (AC4, AC11; ADR-015 Decision 1, ADR-017 Decision 1)
- **Description:** `PipelineContext` gains one readonly field, `dispatchUuid` (string), defaulted
  to `$ingestId` when omitted at construction — the minimal ADR-001 envelope extension.
  `ProcessIngestedWebhook::handle(string $ingestId, ?string $dispatchUuid = null)` defaults the
  dispatch identity to the ingest id (the original dispatch); after the existing cleaned-state
  guard and trashed-inclusive proxy load, creates the dispatch's original `deliveries` rows — one
  per **live** destination, `Delivery::query()->firstOrCreate(['dispatch_uuid' => $dispatchUuid,
  'destination_id' => $destination->id], ['team_id' => ..., 'proxy_id' => ..., 'webhook_event_id'
  => ..., 'kind' => DispatchKind::Original, 'status' => DeliveryStatus::Pending])` — before
  constructing `PipelineContext` with the resolved `dispatchUuid`. No other change: `firstOrFail()`,
  the trashed-inclusive proxy load, and the pipeline run stay exactly as they are.
- **Dependencies:** T2, T3
- **Files:** `app/Pipeline/PipelineContext.php`, `app/Actions/ProcessIngestedWebhook.php`
- **Acceptance Criteria:** `PipelineContext` constructed without `dispatchUuid` reads
  `dispatchUuid === $ingestId`; `ProcessIngestedWebhook::run($ingestId)` (single-arg, existing
  call sites unchanged) creates one `Delivery` row per live destination with `kind = original`,
  `status = pending`, `dispatch_uuid = $ingestId`; re-invoking for the same `$ingestId` (simulated
  redelivery) creates no duplicate rows (`firstOrCreate` under the T3 unique key); a trashed
  destination is **not** given an original delivery row (live-destinations-only, ruling 2 — new
  *selection* uses live destinations only); the three existing `ProcessIngestedWebhookTest` cases
  (cleaned event, unknown ingest id, normal delivery) stay green unmodified.
- **Testing:** extend `tests/Feature/Ingest/ProcessIngestedWebhookTest.php` — the delivery-row
  creation case, the redelivery-idempotency case, the trashed-destination-excluded case; extend
  `tests/Unit/Pipeline/PipelineContextTest.php` with the `dispatchUuid`-defaulting case.
- **Completion notes:** Implemented as specified — `PipelineContext` gained a readonly
  `dispatchUuid` property, defaulted to `$ingestId` in the constructor when the new optional
  `?string $dispatchUuid = null` parameter is omitted (the minimal envelope extension; every
  other constructor param/behavior untouched). `ProcessIngestedWebhook::handle()` gained the
  matching `?string $dispatchUuid = null` parameter (defaulted to `$ingestId` at the top of
  `handle()`, before the existing `firstOrFail()`/cleaned-state guard, so `ProcessIngestedWebhook::run($ingestId)`
  single-arg call sites are unchanged); after the existing trashed-inclusive proxy load, a
  `foreach ($proxy->destinations as $destination)` loop (live-only by `Destination`'s
  `SoftDeletes` default scope — no `withTrashed()`) runs `Delivery::query()->firstOrCreate(...)`
  exactly per the plan's shape, keyed on `['dispatch_uuid' => $dispatchUuid, 'destination_id' =>
  $destination->id]` (T3's unique index), before `PipelineContext` is constructed with the
  resolved `dispatchUuid`. `firstOrFail()`, the trashed-inclusive proxy load, and the pipeline
  run are byte-for-byte unchanged. `tests/Unit/Pipeline/PipelineContextTest.php` extended (3 new
  tests): the `dispatchUuid`-defaults-to-`ingestId` case, a case supplying `dispatchUuid`
  independently of `ingestId` (the replay shape T8 sets up for, not exercised until M6),
  `dispatchUuid`'s readonly-property assertion (matching the existing raw-field pattern in the
  same file). `tests/Feature/Ingest/ProcessIngestedWebhookTest.php` extended (3 new tests): one
  `Delivery` row per live destination with `kind = Original`, `status = Pending`, `dispatch_uuid
  = $ingestId`, and the correct `webhook_event_id`/`proxy_id`/`team_id`; a same-`ingestId`
  double-invocation (simulated redelivery) creating exactly 2 rows, not 4, proving the
  `firstOrCreate` idempotency under T3's unique key; a trashed destination present at capture
  time receiving zero delivery rows (`Destination::delete()` before the run, live-only selection
  — ruling 2). The three pre-existing `ProcessIngestedWebhookTest` cases (cleaned event, unknown
  ingest id, normal 3-destination delivery with one trashed) were left unmodified and stayed
  green — none needed updating since `Delivery` row creation is additive alongside the existing
  `DeliveryAttempt` fan-out, not a replacement of it (that swap is T9/T10's scope). No
  anticipated-red left behind — full suite is green. Verified: `composer lint` (Pint, passed),
  `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --filter
  "ProcessIngestedWebhookTest|PipelineContextTest"` (13 passed / 43 assertions),
  `./vendor/bin/sail test --parallel` full suite (480 total, 480 passed / 1661 assertions — up
  from T7-fix's 474/474, net +6 new tests, no failures).

## T9 — `DeliverStep` iterates the dispatch's `deliveries` rows; `DeliveryUnit.deliveryId` (AC3, AC7; ADR-015 Decision 1)
- **Description:** `DeliveryUnit` gains one new readonly field, `deliveryId` (int). `DeliverStep`
  is changed to iterate `$ctx->proxy`'s **dispatch's `deliveries` rows** (loaded via
  `Delivery::query()->where('dispatch_uuid', $ctx->dispatchUuid)->with('destination')->get()`,
  `withTrashed()` on the destination relation per ruling 2) instead of `$proxy->destinations`
  directly, building one `DeliveryUnit` per row with `deliveryId: $delivery->id, attemptNumber:
  1`. Async/FIFO dispatch behaviour (queued vs. inline) is otherwise unchanged.
- **Dependencies:** T8
- **Files:** `app/Pipeline/DeliveryUnit.php`, `app/Actions/DeliverStep.php`
- **Acceptance Criteria:** for an enhanced or simple proxy with N live destinations, `DeliverStep`
  builds exactly N `DeliveryUnit`s, each carrying the matching `Delivery` row's id; Async/FIFO
  dispatch shape (queued vs. inline `DeliverToDestination` call) is unchanged from today; a
  destination soft-deleted after its original delivery row was created still receives its
  attempt (ruling 2 — `withTrashed()` on the load).
- **Testing:** extend `tests/Unit/Pipeline/DeliveryUnitTest.php` (the new field) and
  `tests/Unit/Actions/DeliverStepTest.php` / `tests/Unit/Pipeline/DeliverStepTest.php` (whichever
  currently exists) — the N-units-with-deliveryId case, the trashed-destination-still-delivers
  case, Async/FIFO shape unchanged.
- **Completion notes:** Implemented as specified. `app/Pipeline/DeliveryUnit.php`: added the
  readonly `int $deliveryId` constructor param (no default — every unit must carry a real
  `deliveries` row id from here on). `app/Actions/DeliverStep.php`: the fan-out loop now sources
  from `Delivery::query()->where('dispatch_uuid', $ctx->dispatchUuid)->with(['destination' => fn
  ($query) => $query->withTrashed()])->get()` instead of `$proxy->destinations` directly, building
  one `DeliveryUnit` per row with `deliveryId: $delivery->id, attemptNumber: 1`; the eager-loaded
  `destination` relation is explicitly `withTrashed()` (ruling 2 — a destination soft-deleted
  after its delivery row was created still receives its attempt; trashed-exclusion now happens
  entirely at delivery-row-creation time, T8, not here). Async/FIFO dispatch shape (queued vs.
  inline `DeliverToDestination` call) is byte-for-byte unchanged — only the source of the
  iteration and the new `deliveryId` field on the built unit differ. **Both** existing
  `DeliverStepTest` files present in the repo (the plan's Testing note anticipated at most one)
  were updated, since both call `DeliverStep::handle()` directly and both needed their fixtures
  to pre-create matching `deliveries` rows (mirroring T8's shape) for the loop to find anything:
  `tests/Unit/Pipeline/DeliveryUnitTest.php` (+1 test: `deliveryId` stored/readonly, plus the two
  existing `new DeliveryUnit(...)` calls updated with the new required arg);
  `tests/Unit/Pipeline/DeliverStepTest.php` (renamed the old "skips trashed" case to
  `test_it_delivers_to_each_live_destination_and_skips_one_with_no_delivery_row` — trashed
  exclusion is no longer DeliverStep's own concern, so the case now proves a destination with NO
  delivery row is skipped instead; added
  `test_a_destination_trashed_after_its_delivery_row_was_created_still_receives_its_attempt`, the
  AC's ruling-2 case; the failing-destination case updated to pre-create delivery rows, unchanged
  in intent); `tests/Unit/Actions/DeliverStepTest.php` (added
  `test_builds_exactly_n_units_each_carrying_the_matching_delivery_rows_id` — Async/`Queue::fake()`
  path, `DeliverToDestination::assertPushed()` inspecting each pushed `DeliveryUnit`'s
  `deliveryId` against the set of created `Delivery` row ids, mirroring the existing
  `AdvanceProxyFifoQueue::assertPushed(fn ($job, array $params) => ...)` param-inspection pattern
  used elsewhere in the suite; the four existing tests updated to pre-create matching delivery
  rows, assertions otherwise unchanged). No test coverage was dropped — the rename replaces one
  invariant (trashed-exclusion) with the one that now actually holds at this layer, and adds the
  ruling-2 case the old test's premise made impossible to express. **Anticipated interim
  breakage, predicted by T10's own spec text, not a T9 defect:** `DeliveryUnit`'s new required
  `deliveryId` param has no default, so the full suite now shows 8 errors
  (`ArgumentCountError: DeliveryUnit::__construct(): Argument #8 ($deliveryId) not passed`), all
  in the two files T10's own Acceptance Criteria names verbatim as needing this exact update
  ("the existing `DeliverToDestinationTest`/`DeliveryIdempotencyAcceptanceTest` suites pass green
  after being updated to construct `DeliveryUnit`s with a `deliveryId`" — T10 Acceptance
  Criteria): `tests/Feature/Delivery/DeliverToDestinationTest.php` (7 tests, all via its shared
  `unit()` helper) and `tests/Feature/Ingest/DeliveryIdempotencyAcceptanceTest.php` (1 test, its
  manually-constructed redelivery unit). Neither file is in T9's Files list; T10 (already gated
  on T9 as a dependency, alongside T5) is the task that supplies the real `deliveryId` and
  restores these to green. No test was weakened or removed to hide this. Verified: `composer
  lint` (Pint, passed — one auto-fix applied to `tests/Unit/Actions/DeliverStepTest.php`'s import
  order), `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --filter
  "DeliveryUnitTest|Tests\\Unit\\Pipeline\\DeliverStepTest|Tests\\Unit\\Actions\\DeliverStepTest"`
  (11 passed / 43 assertions), `./vendor/bin/sail test --parallel` full suite (483 total, 475
  passed / 1631 assertions, 8 errors — all eight enumerated above and traced to T10's explicit,
  self-declared scope).

## T10 — `DeliverToDestination`: new attempt idempotency key `(delivery_id, attempt_number)` (AC7; ADR-015 Decision 2 / ADR-016 P3) — no retry logic yet
- **Description:** `DeliverToDestination::existingAttempt()` and the `DeliveryAttempt::create()`
  call both move from the `(ingest_id, destination_id, attempt_number)` key to `(delivery_id,
  attempt_number)`, now that `DeliveryUnit` carries `deliveryId` (T9) and the column/unique index
  exist (T5). `ingest_id` is still written to the row (unchanged, still useful for team-scoped
  browsing) but is no longer part of the create-or-resume lookup. **No scheduling/retry behaviour
  is added in this task** — `$tries = 1` stays, and a failed attempt still just fails (M3 adds
  the CAS transition + scheduling on top of this).
- **Dependencies:** T5, T9
- **Files:** `app/Actions/DeliverToDestination.php`
- **Acceptance Criteria:** a redelivered `DeliveryUnit` for the same `(delivery_id,
  attempt_number)` is a no-op (no duplicate row, no duplicate send) exactly as the old key
  guaranteed; a `dispatched`-status row left by a crashed worker is still re-driven on the same
  row; the existing `DeliverToDestinationTest`/`DeliveryIdempotencyAcceptanceTest` suites pass
  green after being updated to construct `DeliveryUnit`s with a `deliveryId` (the key-swap is the
  only behavioural change asserted).
- **Testing:** extend `tests/Feature/Delivery/DeliverToDestinationTest.php` and
  `tests/Feature/Ingest/DeliveryIdempotencyAcceptanceTest.php` — update fixtures to the new key,
  add a case proving two different deliveries legitimately share `attempt_number = 1` (the
  reason the old key could not survive replay) with no collision.
- **Completion notes:** Implemented as specified — no more. `app/Actions/DeliverToDestination.php`:
  `existingAttempt()`'s lookup and the `DeliveryAttempt::create()` call both moved from
  `(ingest_id, destination_id, attempt_number)` to `(delivery_id, attempt_number)`
  (`'delivery_id' => $unit->deliveryId` added to the create payload; `existingAttempt()` now
  queries `where('delivery_id', $unit->deliveryId)->where('attempt_number', ...)`only).
  `ingest_id` is still written to the row, just no longer part of the lookup, exactly as
  specified. **No scheduling/retry behaviour added** — `$tries = 1` untouched, `send()`/`resume()`
  byte-for-byte unchanged, a failed attempt still just fails; the class docblock updated to name
  the new key and explicitly note M3 is where CAS/scheduling lands, not here. **T9's 8 anticipated
  errors are now closed**: both `DeliverToDestinationTest` (7 tests) and
  `DeliveryIdempotencyAcceptanceTest` (1 test) construct their `DeliveryUnit`s with a real
  `deliveryId` again. `tests/Feature/Delivery/DeliverToDestinationTest.php`: added a
  `deliveryFor(Destination): Delivery` helper (one real `deliveries` row per unit — `delivery_id`
  is a restrict FK, T5, so a fabricated int would fail at the DB); the `unit()` helper gained an
  optional `?int $deliveryId = null` param defaulting to a freshly created delivery, so every
  existing call site needed no change beyond the constructor's new required arg being satisfied
  implicitly; the manually-built orphan row in
  `test_a_row_left_dispatched_is_re_driven_on_the_same_row` gained `'delivery_id' =>
  $unit->deliveryId`. Two tests added: (1)
  `test_two_different_deliveries_can_legitimately_share_attempt_number_one_with_no_collision` —
  the Testing note's named case, two units against the same destination but distinct
  `deliveryId`s both settle to their own row at `attempt_number = 1`, no collision (the exact
  shape the old key could not express — ADR-015 Decision 2); (2)
  `test_unique_index_rejects_a_raw_duplicate_insert` — restores the raw-duplicate-insert
  DB-enforcement probe T5 retired against the old key, now proving the NEW
  `UNIQUE(delivery_id, attempt_number)` index rejects a duplicate pair, fulfilling T5's own
  deviation note ("T10 restores the equivalent race-safety probe on the new key").
  `tests/Feature/Ingest/DeliveryIdempotencyAcceptanceTest.php`: the manually-constructed
  redelivery `DeliveryUnit` now resolves the real `Delivery` row T8's `ProcessIngestedWebhook`
  created for the `(dispatch_uuid, destination_id)` pair (`Delivery::query()->where('dispatch_uuid',
  $event->ingest_id)->where('destination_id', $destination->id)->firstOrFail()`) and passes its
  id as `deliveryId` — this is the real idempotency key the redelivery must now match, not an
  arbitrary value. `test_the_unique_index_rejects_a_raw_duplicate_insert` restored here too (same
  T5 promise, second of the two named files), against a `Delivery::factory()`-created row. No
  test was weakened; both restorations replace exactly what T5 removed, against the new key. No
  anticipated-red left behind — the branch is fully green, closing M2. Verified: `composer lint`
  (Pint, passed), `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --filter
  "DeliverToDestinationTest|DeliveryIdempotencyAcceptanceTest"` (11 passed / 47 assertions),
  `./vendor/bin/sail test --parallel` full suite (486 total, 486 passed / 1674 assertions — up
  from T9's 483 total/475 passed/8 errors; all eight closed, no new red).

---

## M3 — Retry engine

## T11 — `RetryPolicy` service (AC2, AC18; ADR-015 Decision 3/4)
- **Description:** `App\Services\RetryPolicy` (new) — the single resolver of retry policy, per
  plan §Services: `attemptLimitFor(Proxy $proxy): int` (column value if set, else
  `config('retry.default_attempt_limit')`, hard-clamped to `[1, config('retry.max_attempt_limit')]`
  regardless of column content); `strategyFor(Proxy $proxy): RetryBackoffStrategy` (column value
  if set, else `Exponential`); `delayBefore(Proxy $proxy, int $attemptNumber): CarbonInterval`
  (exponential: `min(base * multiplier^(N-2), cap)` seconds; fixed: constant
  `fixed_interval_seconds`); `worstCaseSpan(): CarbonInterval` (sum of `delayBefore` for attempts
  2..`max_attempt_limit` under the exponential strategy — the AC18 guard-test seam, T20). Config-
  sanity guards on entry mirroring `PurgeExpiredPayloads`'s `RuntimeException` posture (review-05
  M-1 precedent): non-positive `default_attempt_limit`/`max_attempt_limit`/
  `exponential_base_seconds`/`fixed_interval_seconds` throw loudly rather than silently
  substituting a default.
- **Dependencies:** T1, T2, T4
- **Files:** `app/Services/RetryPolicy.php` (new)
- **Acceptance Criteria:** `attemptLimitFor`/`strategyFor` return the column value when set, the
  config default otherwise; a column value above `max_attempt_limit` (only reachable if the
  config cap is lowered after the value was saved) is clamped, never exceeded; `delayBefore`
  matches the documented exponential/fixed formulas exactly for attempts 2 through the cap;
  `worstCaseSpan()` for the default config is the plan's stated **≈32.6 h** (exponential, limit
  10); each named non-positive config value throws `RuntimeException` naming the offending key.
- **Testing:** `tests/Unit/Services/RetryPolicyTest.php` (new) — limit/strategy resolution
  (column set / column NULL / column-above-cap), the delay table for both strategies across the
  full attempt range, `worstCaseSpan()` arithmetic, and the config-sanity exception cases
  (mirroring `RetentionPolicyTest`'s guard-test cases from #5's M-1 fix).
- **Completion notes:** Implemented as specified — no more (T12's `StoredPayloadLookup` method,
  T13's settle/schedule/`DeliveryExhausted`, T14, T15 all untouched). `app/Services/RetryPolicy.php`
  (new): `attemptLimitFor(Proxy $proxy): int` — column value if set else
  `config('retry.default_attempt_limit')`, always `max(1, min($limit, $max))`-clamped to
  `config('retry.max_attempt_limit')` regardless of source (column or default), matching the
  Description's "regardless of column content" wording read as "regardless of the resolved
  value's source." `strategyFor(Proxy $proxy): RetryBackoffStrategy` — column value if set else
  `Exponential`. `delayBefore(Proxy $proxy, int $attemptNumber): CarbonInterval` — exponential:
  `min(base * multiplier^(N-2), cap)` seconds via a private `exponentialDelaySeconds()` helper
  shared with `worstCaseSpan()`; fixed: the constant `fixed_interval_seconds`.
  `worstCaseSpan(): CarbonInterval` — sums `exponentialDelaySeconds()` for attempts
  `2..max_attempt_limit`, the exact seam T20 will assert against. Config-sanity guard: a private
  `positiveConfigInt(string $key): int` helper (mirroring `RetentionPolicy::windowFor()`'s
  inline guard, extracted here since four keys need it) throws `RuntimeException` naming the
  offending `config('retry.<key>')` key when the resolved value is not a positive integer, used
  for exactly the four keys the Description names — `default_attempt_limit`, `max_attempt_limit`,
  `exponential_base_seconds`, `fixed_interval_seconds` — deliberately excluding
  `exponential_multiplier`/`exponential_max_delay_seconds` (not named in the guard list; a cap of
  0 clamps delays to 0 rather than corrupting the "retries eventually stop" invariant the guarded
  keys protect). Verified the `worstCaseSpan()` arithmetic by hand against the plan's stated
  figure: `60 + 300 + 1,500 + 7,500 + (21,600 × 5) = 117,360` seconds = exactly **32.6 hours**
  under the default config (base 60s, multiplier 5, cap 21,600s, `max_attempt_limit` 10).
  `tests/Unit/Services/RetryPolicyTest.php` (new, 20 tests): `attemptLimitFor` — column-set,
  column-NULL-uses-default, column-above-cap-clamped, default-above-cap-clamped (4);
  `strategyFor` — column-set, column-NULL-defaults-to-Exponential (2); `delayBefore` exponential
  — the full attempt-2-through-10 table against the documented formula, including the
  cap-kicks-in-at-attempt-6 point (1 table test, 9 assertions); `delayBefore` fixed — constant
  across the same range (1 test); `worstCaseSpan()` — exact-seconds and
  `assertEqualsWithDelta(32.6, ..., 0.01)`-hours assertions (1 test); config-sanity guards for
  all four named keys — zero and negative `Config::set()` cases for each (8 tests), plus
  `default_attempt_limit`'s blank-env and non-numeric-env reproduction cases via
  `putenv()`/`require base_path('config/retry.php')`, mirroring `RetentionPolicyTest`'s exact
  pattern (2 tests); each guard test that names its own key in the Description's list also
  asserts the exception message contains `config('retry.<key>')`, proving the offending key is
  named (AC). No anticipated-red left behind — full suite is green. Verified: `composer lint`
  (Pint, passed), `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --filter
  RetryPolicyTest` (20 passed / 41 assertions), `./vendor/bin/sail test --parallel` full suite
  (506 total, 506 passed / 1715 assertions — up from T10's 486/486, net +20 new tests, no
  failures). Opens M3 (retry engine).

## T12 — `StoredPayloadLookup::dispatchedBytesFor()` (AC13; ADR-013 Decision 3, ADR-015 Decision 1)
- **Description:** New method on the existing `App\Services\StoredPayloadLookup` (#5):
  `dispatchedBytesFor(WebhookEvent $event): string` — the retry-source resolution:
  `dispatched_payloads.body ?? webhook_events.body`, callable only for a retained event (the
  caller must have already guarded `payload_cleaned_at`; this method does not re-guard, keeping
  ADR-013 Decision 3's "only place NULL is interpreted" true and undivided).
- **Dependencies:** none (relies on #5's existing `DispatchedPayload`/`WebhookEvent`)
- **Files:** `app/Services/StoredPayloadLookup.php`
- **Acceptance Criteria:** for an event with no `dispatched_payloads` row or one with `body IS
  NULL` (the identical-payload case), returns the raw `webhook_events.body`; for one with a
  diverged `dispatched_payloads.body`, returns that value instead; both cases decrypt correctly
  (cast round-trip, not raw column bytes).
- **Testing:** extend `tests/Unit/Services/StoredPayloadLookupTest.php` — the two resolution
  cases above.
- **Completion notes:** Implemented as specified — no more (T13's settle transitions/scheduling/
  `DeliveryExhausted`, T14, T15 all untouched). `App\Services\StoredPayloadLookup::dispatchedBytesFor(WebhookEvent
  $event): string` added: queries `DispatchedPayload::query()->where('webhook_event_id',
  $event->id)->first()`; if a row exists AND its `body` is non-NULL, returns that (decrypted via
  the model's `encrypted` cast — the diverged case, ADR-013 Decision 2); otherwise (no row, or a
  row with `body IS NULL` — the identical-payload case) falls through to `$event->body` (also
  decrypted via its own cast). No re-guard on `payload_cleaned_at` — the method's docblock states
  this explicitly, matching the Description; the class docblock's closing sentence updated to
  note this method as the second (of two) places `dispatched_payloads.body IS NULL` is
  interpreted, both within this one class (ADR-013 Decision 3 kept undivided). **Deviation from
  the initially-drafted `?->`/`??` one-liner, caught before commit, not shipped:** a first pass
  wrote `$dispatched?->body ?? $event->body`, which `composer types:check` flagged
  (`nullsafe.neverNull` — PHPStan/Larastan infers this project's `Model::query()->first()` result
  as never-null in this position, a static-analysis quirk unrelated to this task's scope, not
  investigated further since a correct fix existed without it); rather than fight the inference
  or suppress it, rewrote using the codebase's own established convention for a possibly-null
  `first()` (explicit `!== null` check, matching `StoredPayloadLookup::for()`'s own sibling method
  two lines above it, and `DeliverToDestination::existingAttempt()`'s caller) — safer than the
  original one-liner regardless of the phpstan finding, since PHP's plain `->` on a null object
  property-read is a silent warning-and-null, not a fatal error, and relying on that implicitly
  would have been fragile even had phpstan accepted it. `tests/Unit/Services/StoredPayloadLookupTest.php`
  extended (3 new tests): no-`dispatched_payloads`-row returns the raw body; a row with `body IS
  NULL` returns the raw body (the identical-payload case); a row with a diverged `body` returns
  that value instead — all three assert against plaintext (proving the cast round-trip, not raw
  encrypted column bytes, per the AC). No anticipated-red left behind — full suite is green.
  Verified: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0 errors),
  `./vendor/bin/sail test --filter StoredPayloadLookupTest` (7 passed / 7 assertions),
  `./vendor/bin/sail test --parallel` full suite (509 total, 509 passed / 1718 assertions — up
  from T11's 506/506, net +3 new tests, no failures).

## T13 — `DeliverToDestination`: settle transitions, retry scheduling, `DeliveryExhausted` (AC1, AC3–AC5, AC7; ADR-015 Decisions 5, 6)
- **Description:** New `App\Events\DeliveryExhausted` (`{ public readonly Delivery $delivery }`,
  no listener at #6). After `send()` settles an attempt (success or failure — never on a
  resume-skip), `DeliverToDestination` compare-and-sets the attempt's `Delivery` row (`WHERE
  status IN ('pending', 'retrying')`): success ⇒ `succeeded`; failure with `attempt_number >=
  RetryPolicy::attemptLimitFor($proxy)` ⇒ `failed` (terminal) — the CAS affecting a row is what
  emits `DeliveryExhausted`, exactly once; failure below the limit ⇒ `retrying` +
  `next_attempt_at = now()->add(RetryPolicy::delayBefore($proxy, $n + 1))`, then
  `RetryDelivery::dispatch($deliveryId, $n + 1)->delay(...)->onQueue(config('ingest.webhooks_queue'))`
  (T14, forward reference — dispatched here, implemented next). A zero-row CAS (another settler
  already transitioned the row) does nothing further: no event, no schedule, no double-dispatch.
- **Dependencies:** T10, T11
- **Files:** `app/Events/DeliveryExhausted.php` (new), `app/Actions/DeliverToDestination.php`
- **Acceptance Criteria:** a successful attempt CASes its delivery to `succeeded`, schedules
  nothing; a failed attempt below the limit CASes to `retrying` with `next_attempt_at` matching
  `RetryPolicy::delayBefore`, and a delayed `RetryDelivery` job is queued for `attempt_number +
  1`; a failed attempt at the limit CASes to `failed`, `next_attempt_at` NULL, and
  `DeliveryExhausted` fires exactly once; a racing duplicate settle (simulated: the CAS predicate
  already fails) fires no duplicate event and schedules nothing; a simple-mode proxy with nothing
  configured uses the system default (limit 5, exponential); an enhanced proxy with
  `retry_attempt_limit = 2`/`fixed` strategy stops after 2 with constant delays.
- **Testing:** extend `tests/Feature/Delivery/DeliverToDestinationTest.php` — the four transition
  cases (success, retry-below-limit, terminal-at-limit, racing-duplicate-CAS), `Event::fake()`
  asserting `DeliveryExhausted`'s exactly-once emission, `Queue::fake()`/`Bus::fake()` asserting
  the delayed `RetryDelivery` dispatch with the correct delay and attempt number, both
  simple-default and enhanced-configured policy cases.
- **Completion notes:** Implemented as specified — no more (T14's real `RetryDelivery::handle()`
  body and T15's `SweepDueRetries` both untouched). `app/Events/DeliveryExhausted.php` (new):
  `{ public readonly Delivery $delivery }`, `Dispatchable`, no listener registered — the class
  docblock names the CAS-affecting-a-row once-guard and the #13 seam. `app/Actions/
  DeliverToDestination.php`: gained a constructor, `__construct(private readonly RetryPolicy
  $retryPolicy)` (container-resolved identically under `::run()` — `app(static::class)` — and
  `::dispatch()` — `JobDecorator`'s `app($action)` — both proven by the existing `PurgeExpiredPayloads`
  pattern, so no new resolution seam). `send()` now captures `$succeeded` in both the response and
  the thrown-exception branches and, after the existing attempt-row update/event, calls the new
  `settleDelivery(DeliveryUnit $unit, bool $succeeded)` — never reached from `resume()`'s
  early-return skip path, matching "never on a resume-skip" exactly. `settleDelivery()`: reloads
  the `Delivery` row (`findOrFail` — `delivery_id` is a restrict FK, always exists); success CASes
  to `Succeeded` with `next_attempt_at` cleared to NULL (covers a retry's own eventual success,
  where a stale `next_attempt_at` would otherwise linger); failure resolves `$delivery->proxy` and
  `RetryPolicy::attemptLimitFor($proxy)` — at/above the limit CASes to `Failed` +
  `next_attempt_at` NULL, and only on a non-zero CAS mutates the in-memory `$delivery` to match
  (avoiding a second SELECT) before `event(new DeliveryExhausted($delivery))`; below the limit
  computes `RetryPolicy::delayBefore($proxy, $n+1)`, CASes to `Retrying` with the computed
  `next_attempt_at`, and only on a non-zero CAS dispatches `RetryDelivery::dispatch($delivery->id,
  $n+1)->delay($delay)->onQueue(config('ingest.webhooks_queue'))` — the exact Description shape.
  The CAS itself is a new private `transition(Delivery, DeliveryStatus $to, array $attributes):
  bool` — `Delivery::query()->whereKey($id)->whereIn('status', [Pending, Retrying])->update([...]) >
  0` — the query-builder-only pattern the Delivery model docblock and plan-06's binding invariant
  require; every call site branches on its boolean return before doing anything observable (event
  or dispatch), so a zero-row CAS is silently inert everywhere, satisfying the racing-duplicate AC
  with zero special-casing. **`app/Actions/RetryDelivery.php` (new, not in this task's Files list —
  a necessary deviation, flagged and pre-justified by the Description's own words):** the
  Description explicitly frames the dispatch call as "T14, forward reference — dispatched here,
  implemented next," but `RetryDelivery::dispatch(...)` is a real static call requiring the class
  to exist and autoload for both this task's own AC (which requires asserting the dispatch via
  `Queue::fake()`) and, critically, for every *pre-existing* failing-delivery test in the suite
  that does NOT fake the queue — `QUEUE_CONNECTION=sync` in `phpunit.xml` means a real (non-faked)
  `RetryDelivery::dispatch()->delay(...)` executes `handle()` synchronously, in-process, the
  moment it's called. Created the smallest possible real class: `use AsJob;` (exactly the trait
  T14 names, not the fuller `AsAction`), `public int $tries = 1;`, and a `handle(int $deliveryId,
  int $attemptNumber): void` body that is **deliberately empty** — a bare no-op, not a thrown
  "not implemented" — specifically so every pre-existing test that triggers a below-limit failure
  without `Queue::fake()` (audited across `DeliverToDestinationTest`,
  `QueuedDispatchAcceptanceTest::test_response_is_independent_of_a_failing_destination`,
  `DeliverStepTest::test_fifo_one_destination_failing_does_not_abort_the_loop`) keeps passing
  unchanged rather than fatal-erroring on unfinished T14 behaviour. The class docblock states this
  explicitly and points T14 at the exact body it must fill in (reload/guard/resolve/rebuild/run,
  per the Description). PHPStan (Larastan, no unused-parameter rule at this project's ruleset) is
  clean on the two unused no-op parameters. `tests/Feature/Delivery/DeliverToDestinationTest.php`:
  `unit()` gained an `int $attemptNumber = 1` param (needed for the terminal-at-limit and
  racing-CAS cases, which must fabricate attempt 5). Five new tests, matching the Testing section's
  four named transition cases plus the simple/enhanced split: (1) success ⇒ `Succeeded`,
  `next_attempt_at` NULL, `RetryDelivery::assertNotPushed()`, no `DeliveryExhausted`; (2) a
  simple-mode proxy's attempt-1 failure ⇒ `Retrying`, and `RetryDelivery::assertPushed()` with a
  closure checking `$params === [$delivery->id, 2]`, the pushed queue, and the `JobDecorator`'s
  `->delay` (a `CarbonInterval`, inspected via `JobDecorator::$delay` — the actual value
  `PendingDispatch::delay()` sets on the decorated job, confirmed by reading the vendor source)
  against `RetryPolicy::delayBefore()`'s own computed value (not a hardcoded literal, avoiding a
  config-drift-brittle assertion); (3) the default-limit-5 proxy's attempt-5 failure ⇒ `Failed`,
  `next_attempt_at` NULL, `RetryDelivery::assertNotPushed()`,
  `Event::assertDispatchedTimes(DeliveryExhausted::class, 1)` plus a closure asserting the carried
  delivery's id; (4) the racing-duplicate case — the delivery's status is forced to `Failed` via a
  raw query-builder update *before* running the (otherwise-terminal) attempt-5 unit, proving the
  CAS predicate rejects and neither the event nor a schedule fires; (5) an
  `enhanced()`-proxy with `retry_attempt_limit = 2`/`RetryBackoffStrategy::Fixed`: attempt 1 fails
  below the limit (`Retrying`, one `RetryDelivery` push with delay ==
  `config('retry.fixed_interval_seconds')`, i.e. constant, not exponential), attempt 2 fails at the
  limit (`Failed`, no second push, exactly one more `DeliveryExhausted`). **One pre-existing test
  updated, not new anticipated red — a direct, correct consequence of this task's own behaviour,
  not a deviation:** `tests/Feature/Ingest/ProcessIngestedWebhookTest::
  test_creates_one_original_delivery_row_per_live_destination` asserted a freshly created
  delivery's `status` was still `Pending` immediately after `ProcessIngestedWebhook::run()`. That
  proxy is Async-default with every response faked 200, and — now that `DeliverToDestination`
  performs the settle-CAS this task adds — the delivery legitimately settles to `Succeeded`
  synchronously within the same call (Async `afterCommit()` dispatch + `QUEUE_CONNECTION=sync`
  drains inline, no open transaction to defer against). Updated the one assertion to
  `DeliveryStatus::Succeeded` with an inline comment explaining why; the test's actual purpose
  (kind/dispatch_uuid/webhook_event_id/proxy_id/team_id on creation) is unchanged and still fully
  covered. No other pre-existing test needed a change — audited every `Http::fake()` failure/
  exception case in the suite (`DeliverToDestinationTest`'s own pre-T13 tests,
  `QueuedDispatchAcceptanceTest`, `DeliverStepTest`, `FifoLivenessAcceptanceTest` — all-200 fixtures
  there, so no failure branch triggers), confirming the `RetryDelivery` no-op stub's silence is
  sufficient everywhere. No anticipated-red left behind — the branch is fully green, T14 has no
  debt to close beyond its own scope. Verified: `composer lint` (Pint, passed — one auto-fix
  applied to `DeliveryExhausted.php`'s import ordering/brace style, accepted as-is),
  `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --filter
  DeliverToDestinationTest` (14 passed / 60 assertions), `./vendor/bin/sail test --parallel` full
  suite (514 total, 514 passed / 1740 assertions — up from T12's 509/509/1718, net +5 new tests +1
  updated assertion, no failures). Opens the second half of M3 (T14 fills in `RetryDelivery`'s real
  body against the scaffold this task created; T15 adds the sweeper).

## T14 — `RetryDelivery` action (AC1, AC3, AC7, AC13, AC17; ADR-015 Decision 5)
- **Description:** `App\Actions\RetryDelivery` (new, `AsJob`, `$tries = 1`). Executes attempts ≥ 2
  by reference: `handle(int $deliveryId, int $attemptNumber)` — reload the `Delivery`; **skip
  silently** unless its status is still `retrying` (a stale/superseded job, e.g. the sweeper and
  the delayed job both fired); **guard the parent `WebhookEvent`'s `payload_cleaned_at`** —
  cleaned ⇒ CAS the delivery to `failed` with an error summary (no payload bytes), emit
  `DeliveryExhausted`, send nothing (AC17), return; otherwise resolve the send bytes via
  `StoredPayloadLookup::dispatchedBytesFor()` (T12), rebuild the `DeliveryUnit` (headers from the
  captured event row, method from the destination, `deliveryId`, `attemptNumber`), and run
  `DeliverToDestination::run($unit)` — which performs T13's settle/schedule logic identically to
  attempt 1.
- **Dependencies:** T12, T13
- **Files:** `app/Actions/RetryDelivery.php` (new)
- **Acceptance Criteria:** a `retrying` delivery past its `next_attempt_at` is re-sent with the
  recorded dispatched output (identical-payload and diverged cases both correct per T12); a stale
  job (delivery no longer `retrying`) sends nothing and creates no new attempt row; a job hitting
  a cleaned parent terminalizes the delivery, emits `DeliveryExhausted` exactly once, sends
  nothing, and logs `payload.expired`-shaped identifiers only (no bytes); each successful retry
  writes a new `DeliveryAttempt` row with the correct `delivery_id` and incremented
  `attempt_number`.
- **Testing:** `tests/Feature/Retry/RetryDeliveryTest.php` (new) — the happy-path re-send (both
  payload-resolution cases), the stale-job skip, the cleaned-parent terminalize-and-exhaust case
  (`Http::assertNothingSent()`), the new-attempt-row assertion.
- **Completion notes:** Implemented exactly as specified. `app/Actions/RetryDelivery.php` fills
  in T13's stub body: `handle(int $deliveryId, int $attemptNumber)` reloads the `Delivery`
  (`Delivery::query()->find()`) and returns silently unless `status === Retrying` — covers both
  "delivery not found" and "not retrying" as the same stale/superseded no-op (AC: "a stale job
  ... sends nothing and creates no new attempt row"). Otherwise loads `$delivery->webhookEvent`
  and guards `payload_cleaned_at` (ADR-014 Decision 7): cleaned ⇒ `terminalizeCleaned()`, a new
  private method that CASes the delivery straight to `Failed` (`WHERE status = 'retrying'`,
  the only status reachable here — never a blind `save()`, matching the binding invariant),
  clears `next_attempt_at`, emits `DeliveryExhausted` iff the CAS affected a row (T13's
  once-guard shape reapplied), and logs `payload.expired` with `['ingest_id' => ...]` only. **No
  `DeliveryAttempt` row is written on this branch** — a deliberate reading, resolved against the
  Description's "CAS the delivery to failed with an error summary" phrasing: `deliveries` has no
  `error_summary` column (verified against the T3 migration), and PRD-06 AC17 states literally
  "a cleaned event produces zero new delivery attempts except by rejecting the request cleanly"
  — so `terminalizeCleaned()` never touches `delivery_attempts` at all; "error summary" in the
  Description is descriptive framing for the `Log::info('payload.expired', ...)` call, not a
  literal field write (no data-model change, none authorized for this task). Otherwise (not
  cleaned): resolves the resend bytes via `StoredPayloadLookup::dispatchedBytesFor($event)`
  (T12), loads the destination `$delivery->destination()->withTrashed()->firstOrFail()` (ruling
  2 — a destination trashed mid-schedule still receives its retries), rebuilds a `DeliveryUnit`
  (headers straight from `$event->headers`, matching `ProcessIngestedWebhook`'s identical
  pre-existing pass-through to `PipelineContext`; method from the destination; this delivery's
  id; the given `$attemptNumber` — never re-derived), and runs `DeliverToDestination::run($unit)`
  — T13's settle/schedule CAS logic applies identically to attempt 1, so a further failure
  correctly re-schedules or terminalizes and a success CASes to `Succeeded`. `StoredPayloadLookup`
  is constructor-injected (`private readonly StoredPayloadLookup $payloads`) — proven
  container-resolvable both via a direct `app(RetryDelivery::class)->handle(...)` call (tests)
  and via the real queued dispatch path (`JobDecorator`'s `app($action)`, the same parity T13
  already established for `DeliverToDestination`'s `RetryPolicy` injection). `RetryDelivery`
  carries only `AsJob` (per the Description, not `AsAction`) — it therefore has **no `::run()`
  static helper** (traced in `vendor/lorisleiva/laravel-actions/src/Concerns/AsJob.php`: `AsJob`
  provides `dispatch`/`dispatchSync`/`assertPushed`/etc. but not `AsObject`'s `run`/`make`), so
  `tests/Feature/Retry/RetryDeliveryTest.php` invokes the job body directly via
  `app(RetryDelivery::class)->handle($deliveryId, $attemptNumber)` — the same container-resolved
  seam, just without the `AsObject` convenience wrapper. Five tests, matching the Testing
  section's four named cases (happy path split into its two payload-resolution sub-cases): (1)
  raw-capture resend when nothing diverged; (2) recorded-dispatched-output resend when it
  diverged (a `DispatchedPayload` row with a non-NULL `body`); (3) the stale-job skip (delivery
  forced to `Succeeded` before invocation — `Http::assertNothingSent()`, zero attempt rows); (4)
  the cleaned-parent case (`Http::assertNothingSent()`, zero attempt rows, `Failed` +
  `next_attempt_at` NULL, `DeliveryExhausted` exactly once carrying the right delivery,
  `Log::spy()` asserting the exact `payload.expired`/`ingest_id`-only call); (5) a successful
  retry (attempt 3, with a pre-existing attempt-1 row already on the delivery) writes exactly one
  new `DeliveryAttempt` row carrying `delivery_id` and `attempt_number = 3` — proving the number
  is carried through, never re-derived. **Anticipated red, predicted and resolved in this task
  (not deviation) — T13's own completion notes named exactly this seam:** filling in
  `RetryDelivery::handle()` makes `RetryDelivery::dispatch(...)->delay(...)->onQueue(...)` a
  REAL retry for the first time; under `QUEUE_CONNECTION=sync` (phpunit.xml) that dispatch drains
  synchronously in-process (`SyncQueue::later()` ignores the delay) the instant it's called, so
  any PRE-EXISTING test that drives a genuinely-failing destination WITHOUT `Queue::fake()` now
  sees a real cascade through the system-default attempt limit (5, `config/retry.php`) instead of
  stopping at attempt 1. Audited every such test (grepped every un-faked `Http::fake()` failure
  fixture across the suite) and fixed each on its own terms — two were pure test-isolation cases
  (the assertion's actual subject is unrelated to retry cascading) fixed by adding `Queue::fake()`
  to suppress the now-real scheduled retry without disturbing the attempt-1-runs-inline behaviour
  each already exercises directly (`DeliverToDestinationTest::test_redelivery_after_failure_is_a_no_op`,
  `DeliverStepTest::test_fifo_one_destination_failing_does_not_abort_the_loop` in
  `tests/Unit/Actions`); four could NOT be queue-faked without defeating their own stated purpose
  (each deliberately proves the un-faked sync-drain behaviour end-to-end — faking the queue would
  zero out their attempt counts entirely, not just suppress the retry), so their count assertions
  were updated to the real, now-correct cascade totals, each with an inline comment naming T14 and
  the system-default limit: `QueuedDispatchAcceptanceTest::test_response_is_independent_of_a_failing_destination`
  (both `async`/`fifo` datasets, 1 → 5 attempts), `AsyncDispatchAcceptanceTest::
  test_one_destination_failing_does_not_prevent_the_others_succeeding` (1 → 5 failed attempts /
  events, succeeded count unchanged at 2), `DeliverStepTest::test_one_failing_destination_does_not_prevent_the_others`
  in `tests/Unit/Pipeline` (2 → 6, Async-default proxy so attempt 1 itself is a real un-faked
  dispatch), `IngestFanOutTest::test_one_destination_failing_does_not_prevent_others_and_still_returns_202`
  (2 → 6). No production code changed by this audit — every fix lives in the test files, matching
  T13's own "one pre-existing test updated, not new anticipated red" precedent, scaled to six
  test methods (seven failing test RUNS — `QueuedDispatchAcceptanceTest`'s one method runs twice,
  once per `#[DataProvider('modes')]` case) because this task is precisely the one T13 named as
  filling in the real cascade. Verified: `composer lint` (Pint, passed, no changes),
  `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --filter
  RetryDeliveryTest` (5 passed / 16 assertions), `./vendor/bin/sail test --parallel` full suite
  (519 total, 519 passed / 1756 assertions — up from T13's 514/514/1740: +5 new tests in the new
  `RetryDeliveryTest` file, the remaining +0 test-count delta from the six pre-existing methods
  fixed in place, and the assertion-count growth reflects both the new tests and the updated
  cascade totals in the six audited methods; no failures). T15 (`SweepDueRetries` + schedule
  entry) is next.

## T15 — `SweepDueRetries` action + schedule entry (AC1, AC7; ADR-015 Decision 5)
- **Description:** `App\Actions\SweepDueRetries` (new, `AsAction`, scheduled every minute beside
  `SweepStalledFifoDispatches`) — re-dispatches `RetryDelivery::dispatch($deliveryId, $n)` for
  every `retrying` delivery whose `next_attempt_at` passed more than
  `config('retry.sweep_grace_seconds')` ago, where `$n = MAX(attempt_number) + 1` for that
  delivery from its attempt rows. Double-fires against a live delayed job are arbitrated by T5's
  unique key (create-or-resume), exactly as `SweepStalledFifoDispatches` arbitrates against the
  advancer. One new `Schedule::call(fn () => SweepDueRetries::run())->everyMinute()` entry in
  `routes/console.php`.
- **Dependencies:** T14
- **Files:** `app/Actions/SweepDueRetries.php` (new), `routes/console.php`
- **Acceptance Criteria:** an overdue `retrying` delivery (past due + grace) is re-dispatched;
  a not-yet-due one and a terminal one are both left untouched; a double-fire (sweeper + the
  original delayed job both executing) produces exactly one new attempt row, not two; the
  schedule entry is registered `everyMinute()` with a description, mirroring the FIFO sweeper's
  registration shape.
- **Testing:** `tests/Unit/Actions/SweepDueRetriesTest.php` (new) — the overdue/not-due/terminal
  selection cases, the double-fire dedupe case, a `Schedule::events()` inspection for the
  registration (mirroring `SweepStalledFifoDispatchesTest`'s `everyMinute()` check).
- **Completion notes:** Implemented exactly as specified, mirroring `SweepStalledFifoDispatches`'s
  shape. `app/Actions/SweepDueRetries.php` (new, `AsAction`, no constructor deps): `handle()`
  computes `$cutoff = now()->subSeconds((int) config('retry.sweep_grace_seconds'))`, selects every
  `Delivery` with `status = Retrying AND next_attempt_at < $cutoff`, and for each derives
  `$nextAttemptNumber = (DeliveryAttempt::where('delivery_id', $id)->max('attempt_number') ?? 0) +
  1` before `RetryDelivery::dispatch($delivery->id, $nextAttemptNumber)` — no `.delay()`/`.onQueue()`
  call, matching the Description's literal `RetryDelivery::dispatch($deliveryId, $n)` shape (T13's
  own scheduling call is the one that carries `.delay()`/`.onQueue()`; the sweeper redrives
  immediately, mirroring `SweepStalledFifoDispatches`'s own bare
  `AdvanceProxyFifoQueue::dispatch($proxyId)` nudge with no queue/delay qualifiers). No dedupe
  logic lives here by design — the Description and plan-06 both state the
  `UNIQUE(delivery_id, attempt_number)` create-or-resume key inside `DeliverToDestination`
  (T5/T10) is what arbitrates a double-fire, exactly as `AdvanceProxyFifoQueue`'s atomic claim
  arbitrates the FIFO sweeper's nudge — this sweeper only ever computes and redispatches, never
  checks for an in-flight duplicate itself. `routes/console.php`: one new
  `Schedule::call(fn () => SweepDueRetries::run())->everyMinute()->description('Sweep due
  retries')` entry, placed directly beside the FIFO sweeper's entry, same shape (a short
  ADR-015-citing comment above it, matching the FIFO sweeper's and the GC command's comment
  style). `tests/Unit/Actions/SweepDueRetriesTest.php` (new, mirrors
  `SweepStalledFifoDispatchesTest`'s structure): a `retryingDelivery(CarbonInterface
  $nextAttemptAt)` helper builds a `Retrying` delivery plus a pre-existing failed attempt-1 row
  (the state a real overdue delivery is always in — `Retrying` is only ever reached after an
  attempt already failed, so an attempt row always exists). Five tests: (1) an overdue delivery
  (`next_attempt_at` older than `now() - grace`) is redispatched with `[$delivery->id, 2]`
  (`Queue::fake()`, `RetryDelivery::assertPushed`); (2) a delivery whose `next_attempt_at` has
  passed but by less than the grace period is left untouched (`assertNotPushed`) — pins the
  boundary semantic ("passed more than grace ago", not merely "passed"); (3) a delivery forced to
  a terminal status (`Failed`, via the query builder — bypassing the real invariant that would
  have cleared `next_attempt_at`) with a still-past `next_attempt_at` is left untouched, proving
  the STATUS predicate excludes it independently of the timestamp; (4) the double-fire case — no
  `Queue::fake()` (the real `RetryDelivery::dispatch()` drains inline under `QUEUE_CONNECTION=sync`,
  mirroring the "delayed job racing the sweep" scenario for real), `SweepDueRetries::run()` called
  twice back-to-back for the same overdue delivery: the first pass's real dispatch settles the
  delivery (HTTP faked 200 ⇒ `Succeeded`) before the second pass's selection query even runs, so
  the second pass naturally selects zero rows — asserted as exactly 2 total attempt rows (the
  pre-existing attempt 1 + exactly one new attempt 2, never a duplicate) and `Succeeded` status;
  (5) the `Schedule::events()` registration check, identical shape to
  `SweepStalledFifoDispatchesTest`'s own, matched on the `'Sweep due retries'` description and the
  `'* * * * *'` cron expression. One typing fix needed mid-implementation: `now()` in this app
  resolves to `Carbon\CarbonImmutable` (not `Illuminate\Support\Carbon`), so the helper's
  parameter is typed `Carbon\CarbonInterface` (both classes' common interface), not
  `Illuminate\Support\Carbon` — caught immediately by a failing test run, fixed before any
  assertion-level work. Verified: `composer lint` (Pint, passed, no changes), `composer
  types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --filter SweepDueRetriesTest` (5
  passed / 8 assertions), `./vendor/bin/sail test --parallel` full suite (524 total, 524 passed /
  1764 assertions — up from T14's 519/519/1756, net +5 new tests, no failures, no pre-existing
  test touched). M3 (retry engine) is complete: T11–T15 all land in this branch state. M4 (FIFO
  composition, T16–T18) is next, not started.

---

## M4 — FIFO composition

## T16 — `AdvanceProxyFifoQueue`: busy-gate, order key, settle-or-hold (AC6, AC11; ADR-016 Decisions 1, 3)
- **Description:** Three changes to the existing action: **(a)** the live-claim gate additionally
  treats any `awaiting_retry` row for the proxy as "busy" (no lease check needed — an
  `awaiting_retry` row is never claimed-and-leased); **(b)** the lowest-pending scan's `orderBy`
  changes from `webhook_event_id` to `id`; **(c)** after `ProcessIngestedWebhook::run($event-
  >ingest_id, $row->dispatch_uuid)` returns, the post-run completion decision replaces the
  unconditional `settled` update: if the dispatch (`Delivery::query()->where('dispatch_uuid',
  $row->dispatch_uuid)`) has zero non-terminal (`DeliveryStatus::isTerminal()`) deliveries, settle
  the row (`status = settled`, `settled_at = now()`) and self-dispatch to advance as today;
  otherwise CAS `claimed → awaiting_retry` (clearing `claimed_at`/`lease_expires_at`) and do
  **not** self-dispatch — the line is deliberately held.
- **Dependencies:** T3, T6, T8
- **Files:** `app/Actions/AdvanceProxyFifoQueue.php`
- **Acceptance Criteria:** a claim is refused (returns null / no claim) while the proxy has an
  `awaiting_retry` row, even with no live-leased `claimed` row; the scan claims the lowest `id`
  among pending rows, not the lowest `webhook_event_id` (a replayed old event with a low
  `webhook_event_id` but a fresh `id` is **not** claimed ahead of genuinely older pending rows);
  a head whose deliveries are all immediately terminal (e.g. all succeeded on attempt 1) settles
  and advances exactly as before (existing #4 behaviour preserved); a head with at least one
  non-terminal delivery after its run transitions to `awaiting_retry` (no lease, no
  self-dispatch) and the line does not advance; the existing FIFO ordering/liveness suites
  (`FifoOrderingAcceptanceTest`, `FifoLivenessAcceptanceTest`, `AdvanceProxyFifoQueueTest`) are
  updated to assert the `id`-based order key and pass green.
- **Testing:** extend `tests/Unit/Actions/AdvanceProxyFifoQueueTest.php` — the busy-gate-includes-
  awaiting_retry case, the `id`-vs-`webhook_event_id` order case, the settle-when-all-terminal
  case, the hold-when-non-terminal case; update `tests/Feature/Ingest/FifoOrderingAcceptanceTest.php`
  and `FifoLivenessAcceptanceTest.php` fixtures/assertions for the order-key change (enumerated
  here per plan Implementation Notes — deliberate, not incidental breakage).
- **Completion notes:** Implemented as specified — no more (T17's retry-side completion check and
  T18's sweeper extensions land next). `app/Actions/AdvanceProxyFifoQueue.php`: **(a)**
  `claimNext()`'s busy check widened from a single live-claim lookup to `where(status=claimed AND
  lease_expires_at > now()) OR status=awaiting_retry`, still under the same `lockForUpdate()`
  inside the one short claim transaction — an `awaiting_retry` row has no lease so no lease
  predicate is needed for it, exactly as the Description states. **(b)** the lowest-pending scan's
  `orderBy('webhook_event_id')` → `orderBy('id')`, the only line changed in the scan. **(c)** a new
  private `settleOrHold()` replaces the old unconditional `$claimed->update([status=>Settled,
  ...])`: queries `Delivery::where('dispatch_uuid', $claimed->dispatch_uuid)->whereNotIn('status',
  $terminalStatuses)->exists()` (terminal statuses computed from `DeliveryStatus::cases()` filtered
  by `isTerminal()`, per the Description's explicit citation) — zero non-terminal ⇒ settle +
  `settled_at` + self-dispatch (byte-for-byte the old behaviour, now conditional); otherwise a CAS
  `whereKey($claimed->id)->where('status', Claimed)->update([status=>AwaitingRetry, claimed_at=>
  null, lease_expires_at=>null])`, no self-dispatch. `ProcessIngestedWebhook::run()` call site
  updated to pass `$claimed->dispatch_uuid` as the second arg (previously omitted, defaulting to
  the ingest id — now explicit, matching the Description's call signature and correctly driving a
  replay row's own dispatch identity once #6's replay endpoint lands, T21+).
  `tests/Unit/Actions/AdvanceProxyFifoQueueTest.php`: renamed
  `test_settles_pending_rows_one_at_a_time_in_webhook_event_id_order` →
  `..._in_id_order` (behaviour identical — capture-created rows are order-identical under both
  keys — renamed for accuracy only); four new tests added per the Testing note —
  `test_claims_the_lowest_id_not_the_lowest_webhook_event_id` (the id-vs-webhook_event_id
  divergence case: a replay row with a low `webhook_event_id` but the highest `id` is proven NOT
  claimed ahead of a genuinely-older pending row), `test_settles_and_advances_when_every_
  destination_settles_immediately` (2-destination all-succeed case, explicit beyond the existing
  1-destination coverage), `test_holds_the_line_when_a_delivery_is_left_non_terminal` (a failed-
  below-limit attempt leaves the row `awaiting_retry`, no lease, no self-dispatch), and
  `test_a_held_awaiting_retry_row_blocks_the_next_claim` (the busy-gate-includes-awaiting_retry
  case, no live lease needed to trip it). `tests/Feature/Ingest/FifoOrderingAcceptanceTest.php` and
  `FifoLivenessAcceptanceTest.php`: class docblocks only — both note the order-key change and why
  their existing fixtures/assertions needed no behavioural edit (every row is capture-created, so
  `id` and `webhook_event_id` order are provably identical there; the divergence case lives at the
  unit level per the Testing note's split).
  **Pre-existing test defect found and fixed (unrelated to T16's own logic, exposed by it):**
  `AdvanceProxyFifoQueueTest::test_the_claim_commits_before_the_outbound_delivery_fires` asserted
  `assertSame(0, DB::transactionLevel())` inside the `Http::fake()` closure — but this project's
  `FasterRefreshDatabase` wraps every test in its own outer transaction (`beginDatabaseTransaction()`),
  so the ambient level here has always been 1, never 0. Pre-#6 this assertion's failure was silently
  swallowed by `DeliverToDestination::send()`'s own `catch (Throwable $e)` (the thrown
  `ExpectationFailedException` was caught as a generic delivery failure, marking the attempt
  `Failed` and scheduling a retry) — invisible because the old unconditional settle-to-`Settled`
  didn't care what the delivery's actual outcome was. T16's real settle-or-hold decision now reads
  the delivery's actual status, so the swallowed failure surfaced as the row landing on
  `AwaitingRetry` instead of `Settled` — a true positive catching a latent, previously-inert test
  bug, not a regression. Fixed to capture the ambient level before the run (`$ambientTransactionLevel
  = DB::transactionLevel()`) and assert the closure's level equals that ambient value instead of a
  hardcoded 0 — preserves the original intent ("the claim transaction is closed by the time of the
  outbound send") correctly regardless of what the test harness's own wrapping level is. Verified
  the closure's assertions now actually execute (assertion count 2 → 3) and the response really is
  a success (delivery genuinely settles to `Succeeded`, not silently to `Failed`-then-retried).
  **Anticipated interim red, not fixed in this commit (T17's scope):**
  `ProcessingModeSwitchAcceptanceTest::test_pre_switch_fifo_events_still_drain_in_order_after_switching_to_async`
  goes red under T16 alone — a pre-switch FIFO row claimed after the proxy has switched to Async
  now correctly holds (`awaiting_retry`) rather than settling unconditionally, because its
  deliveries are dispatched (queued), not run inline, so the post-run check sees them still
  `pending`; under this test's `Queue::fake()` nothing then executes those deliveries to close the
  hold. This is structural, not a bug: T17 is the retry-side completion check that closes exactly
  this window (a delivery settling later triggers the `awaiting_retry → settled` CAS the advancer
  itself can no longer make synchronously once delivery is async). Confirmed via isolation (T17/T18
  changes stashed, T16 alone): 527/528 total, the one failure being this test, `composer lint`
  clean, `composer types:check` 0 errors — matches the task's "name the tests and why" allowance.
  Fixed in T17's commit (adds a `runPushedDeliveries()` test helper that executes the faked,
  queued `DeliverToDestination` jobs in place, standing in for a real queue worker — see T17 notes).
  **Verified (T16 scope, isolated per above):** `./vendor/bin/sail test --filter
  "AdvanceProxyFifoQueueTest|FifoOrderingAcceptanceTest|FifoLivenessAcceptanceTest"` — 12 passed / 60
  assertions; full suite `./vendor/bin/sail test --parallel` — 527/528 (1 anticipated red, above);
  `composer lint` (Pint, passed); `composer types:check` (PHPStan L7, 0 errors).

## T17 — `DeliverToDestination`: FIFO `awaiting_retry → settled` completion check (AC6; ADR-016 Decision 1)
- **Description:** Extend T13's post-CAS settle transition: after a delivery reaches a terminal
  state (`succeeded` or `failed`) on a **FIFO** proxy, check whether the dispatch
  (`dispatch_uuid`) has any remaining non-terminal deliveries; if none, compare-and-set the
  dispatch's `fifo_dispatches` row `awaiting_retry → settled`, stamp `settled_at`, and dispatch
  `AdvanceProxyFifoQueue::dispatch($proxyId)`. Async proxies have no `fifo_dispatches` row and
  this check is a structural no-op for them (no query match).
- **Dependencies:** T13, T16
- **Files:** `app/Actions/DeliverToDestination.php`
- **Acceptance Criteria:** on a FIFO proxy, when a `RetryDelivery` execution settles the last
  open delivery of a held (`awaiting_retry`) dispatch, the fifo row transitions to `settled` and
  the advancer is nudged — the line advances to the next pending row; when other non-terminal
  deliveries remain for the same dispatch, no fifo-row transition occurs; on an Async proxy, no
  `fifo_dispatches` query/transition ever runs (asserted via query-count or a proxy with no
  `fifo_dispatches` rows at all); a racing duplicate settle CASes at most once (no double-advance).
- **Testing:** extend `tests/Feature/Delivery/DeliverToDestinationTest.php` (or a new
  `tests/Feature/Retry/FifoRetrySettlementTest.php`) — the last-open-delivery-settles-the-line
  case, the not-yet-all-terminal case (no transition), the Async-no-op case, the racing-duplicate
  case.
- **Completion notes:** Implemented as specified — no more (T18's sweeper extensions land next).
  `app/Actions/DeliverToDestination.php`: new private `settleFifoLineIfComplete(Delivery
  $delivery): void`, called from `settleDelivery()` in both terminal branches (`$affected`-gated,
  same once-guard shape T13 established for `DeliveryExhausted` — a zero-row delivery CAS means
  another settler already ran this same check, so skip) — success and failed-at-limit alike, never
  from the `retrying` branch (not terminal). Checks `Delivery::where('dispatch_uuid',
  $delivery->dispatch_uuid)->whereIn('status', [Pending, Retrying])->exists()` (the same
  `whereIn`-on-non-terminal-statuses shape the file's own `transition()` already uses, kept for
  file-local consistency rather than introducing the sibling `isTerminal()`-array style
  `AdvanceProxyFifoQueue` uses for the same concept — both are correct, this one matches its
  immediate neighbour); if any remain, returns (no transition). Otherwise CASes
  `FifoDispatch::where('dispatch_uuid', ...)->where('status', AwaitingRetry)->update([status=>
  Settled, settled_at=>now()])` and dispatches `AdvanceProxyFifoQueue::dispatch($delivery->proxy_id)`
  **only when the CAS affected a row** — this is what makes the racing-duplicate AC true (a second
  caller finding the row already `settled` affects zero rows and nudges nothing) and what makes the
  Async no-op literal ("no query match" per the Description): **no `processing_mode` branch at
  all** — Async proxies structurally never have a matching `fifo_dispatches` row for any
  `dispatch_uuid`, so the same unconditional check/CAS simply matches nothing for them, exactly as
  the Description specifies (deliberately not short-circuited on mode, which would have added a
  branch the Description doesn't ask for). Class docblock gained a closing paragraph naming this
  behaviour and the ADR-016 pointer; `App\Enums\FifoDispatchStatus`/`App\Models\FifoDispatch`
  imports added.
  `tests/Feature/Retry/FifoRetrySettlementTest.php` (new, per the Testing note's named-file
  option): `heldFifoDispatch()`/`deliveryFor()`/`unitFor()` helpers build a FIFO proxy with an
  `awaiting_retry` ordering row and its dispatch's `deliveries` rows directly (bypassing the
  pipeline, mirroring `RetryDeliveryTest`'s direct-construction style, since this exercises
  `DeliverToDestination` in isolation — a `RetryDelivery` execution's shape, not a fresh capture).
  Four tests, one per Testing-note case:
  `test_settling_the_last_open_delivery_settles_the_line_and_nudges_the_advancer` (2 destinations,
  A already `succeeded`, B `retrying` → settling B transitions the fifo row to `settled` +
  `settled_at` + nudges `AdvanceProxyFifoQueue::dispatch($proxyId)`);
  `test_no_transition_while_a_sibling_delivery_remains_non_terminal` (both `retrying`, settling one
  leaves the fifo row untouched — `AwaitingRetry`, `settled_at` still NULL, no nudge);
  `test_an_async_proxy_has_no_fifo_dispatches_row_to_transition` (Async proxy, zero
  `fifo_dispatches` rows exist at all — the Testing note's "or a proxy with no `fifo_dispatches`
  rows at all" option — delivery still settles normally, no nudge);
  `test_a_racing_duplicate_settle_cases_the_fifo_row_at_most_once` (fifo row pre-set to `settled`
  by a simulated racing settler before this delivery's own CAS runs — the delivery settles
  normally, the fifo row's CAS affects zero rows since it's no longer `awaiting_retry`, no second
  nudge).
  **Consequential fix, not new scope — closes T16's documented anticipated red:**
  `tests/Feature/Proxies/ProcessingModeSwitchAcceptanceTest.php` gained a `runPushedDeliveries()`
  helper (`Queue::pushed(ActionManager::$jobDecorator, ...)` filtered to `DeliverToDestination`
  jobs, running each via `DeliverToDestination::run(...$job->getParameters())` — idempotent against
  re-invocation, since an already-terminal attempt is a resume no-op) standing in for a real queue
  worker; `test_pre_switch_fifo_events_still_drain_in_order_after_switching_to_async` now calls it
  after each `AdvanceProxyFifoQueue::run()`, so the async-dispatched delivery this task's own
  completion check depends on actually executes within the test — closing the window T16's
  completion notes named. Confirmed the fix is real, not order-dependent, by isolating T17 alone
  (T18's `SweepStalledFifoDispatches.php` stashed out): 532/532 total, `composer lint` clean,
  `composer types:check` 0 errors — no anticipated red left behind by T17.
  **Verified (T17 scope, isolated per above):** `./vendor/bin/sail test --filter
  "FifoRetrySettlementTest|DeliverToDestinationTest|ProcessingModeSwitchAcceptanceTest|AdvanceProxyFifoQueueTest|FifoOrderingAcceptanceTest|FifoLivenessAcceptanceTest"`
  — 38 passed / 169 assertions; full suite `./vendor/bin/sail test --parallel` — 532/532 (0
  failures); `composer lint` (Pint, passed); `composer types:check` (PHPStan L7, 0 errors).

## T18 — `SweepStalledFifoDispatches` extensions: nudge exclusion + stuck-hold release (AC6; ADR-016 Decision 4)
- **Description:** Two additions to the existing sweeper: **(b) idle-proxy nudge** excludes any
  proxy with a live `awaiting_retry` row (held, not idle) — one added predicate on the existing
  nudge query; **(c) new stuck-hold release pass** — any `awaiting_retry` row whose dispatch has
  zero non-terminal deliveries (the crash window between "last delivery settled" and "fifo row
  transitioned", closed here) is compare-and-set to `settled` and the advancer nudged. The
  existing orphaned-claim reaper (pass a) is unchanged — an `awaiting_retry` row has no lease and
  remains structurally invisible to it.
- **Dependencies:** T16, T17
- **Files:** `app/Actions/SweepStalledFifoDispatches.php`
- **Acceptance Criteria:** a proxy with a live `awaiting_retry` row is excluded from the idle
  nudge even if it also has pending rows; an `awaiting_retry` row whose dispatch's deliveries are
  all terminal (simulated crash: manually transition deliveries to terminal without transitioning
  the fifo row) is settled and the advancer nudged by the next sweep; an `awaiting_retry` row
  whose dispatch still has a non-terminal delivery is left untouched by both passes; the existing
  orphaned-claim reaper behaviour (pass a) is unaffected.
- **Testing:** extend `tests/Unit/Actions/SweepStalledFifoDispatchesTest.php` — the nudge-
  exclusion case, the stuck-hold-release case, the non-terminal-untouched case, a regression
  assertion that pass (a) is unchanged.
- **Completion notes:** Implemented as specified — no more; M4 (FIFO composition) now complete.
  `app/Actions/SweepStalledFifoDispatches.php`: **(b)** the idle-nudge's `whereNotIn('proxy_id',
  ...)` subquery predicate widened from "a live `claimed` lease" alone to "a live `claimed` lease
  OR any `awaiting_retry` row" (same `where(...)->orWhere(...)` shape T16 used for the advancer's
  own busy gate — kept structurally identical across both call sites for the same concept).
  **(c)** new pass, appended after (b): selects every `awaiting_retry` row whose `dispatch_uuid`
  has no `deliveries` row left in `('pending', 'retrying')` (`whereNotIn('dispatch_uuid', ...)`
  against the `deliveries` table by raw table name, matching this file's existing convention of
  raw `.from('fifo_dispatches')` subqueries rather than a model builder for the "existence" side of
  a `whereNotIn`), then CASes each individually (`whereKey($id)->where('status', AwaitingRetry)
  ->update([...])`, one query per row so each is its own independent CAS rather than a single bulk
  `UPDATE ... WHERE IN (...)` — deliberate: a bulk update can't distinguish "this row raced and
  already settled" per-row the way the individual-CAS-then-check-affected-count pattern T16/T17
  both already use can, and the row set here is expected to be small (a crash window, not a steady
  state)) and dispatches `AdvanceProxyFifoQueue::dispatch($proxyId)` only when its own CAS affected
  a row (the same once-guard shape as T17's `settleFifoLineIfComplete`). Class docblock's pass list
  extended with the `(c)` description and an ADR-016 Decision 4 pointer; `App\Enums\DeliveryStatus`
  import added (the unused `App\Models\Delivery` import from an earlier draft was NOT added —
  raw table names throughout, per the note above).
  `tests/Unit/Actions/SweepStalledFifoDispatchesTest.php`: two new helpers —
  `heldDispatch(Proxy, string $dispatchUuid): FifoDispatch` (an `awaiting_retry` ordering row) and
  `deliveryFor(Proxy, string $dispatchUuid, DeliveryStatus): Delivery` (a fresh destination +
  delivery row under that dispatch). Four new tests, one per Testing-note case:
  `test_excludes_a_proxy_with_a_live_awaiting_retry_row_from_the_idle_nudge` (held row plus
  a genuinely pending row on the same proxy — no nudge, the AC's "even if it also has pending rows"
  wording verbatim); `test_releases_a_stuck_hold_whose_dispatch_has_gone_fully_terminal` (both
  deliveries terminal, fifo row never transitioned — settled + nudged);
  `test_leaves_an_awaiting_retry_row_with_a_non_terminal_delivery_untouched_by_both_passes` (one
  `retrying` delivery — untouched by (a) [no lease] or (c) [not fully terminal], no nudge);
  `test_the_orphaned_claim_reaper_is_unaffected_by_the_new_passes` (an orphaned `claimed` row on
  one proxy alongside a held row on a SECOND, unrelated proxy in the same sweep call — the reaper
  still reaps and nudges the first, the held row's proxy gets no nudge — the regression case,
  proving (a)/(b)/(c) coexist correctly in one pass rather than just re-running T16-era tests
  unmodified).
  **Verified (T18 scope, full M4):** `./vendor/bin/sail test --filter
  "SweepStalledFifoDispatchesTest"` — 9 passed / 25 assertions; full suite `./vendor/bin/sail test
  --parallel` — 536/536 (0 failures; 524 pre-#16 baseline + 4 T16 tests + 4 T17 tests + 4 T18
  tests); `composer lint` (Pint, passed);
  `composer types:check` (PHPStan L7, 0 errors). No anticipated red left behind by T18.

---

## M5 — Retention holds & guards

## T19 — GC hold H5 in `PurgeExpiredPayloads::applyHolds()` (AC18; ADR-015 Decision 7)
- **Description:** Add H5 to the existing shared `applyHolds()` builder (#5): `NOT EXISTS
  (deliveries WHERE webhook_event_id = webhook_events.id AND (status = 'retrying' OR (status =
  'pending' AND created_at > now() - dispatch_horizon)))` — an event is held while it has a
  `retrying` delivery, or a `pending` delivery younger than
  `config('retention.dispatch_horizon_minutes')` (the H4 shape, reapplied — a lost first-attempt
  job cannot immortalize a payload). Terminal deliveries (`succeeded`, `failed`) hold nothing.
  Because `applyHolds()` is shared between the selection query and the erase `UPDATE`'s own
  `WHERE`, H5 is re-asserted automatically inside the compare-and-set (ADR-012 Decision 1) with
  no further code.
- **Dependencies:** T2, T3
- **Files:** `app/Actions/PurgeExpiredPayloads.php`
- **Acceptance Criteria:** an expired event with a `retrying` delivery is not cleaned; one with
  a `pending` delivery younger than the horizon is not cleaned, older than the horizon it is; one
  with only terminal deliveries (including a `failed` one) is cleaned; a compare-and-set race (a
  `retrying` delivery appears between selection and erase) causes the erase `UPDATE` to affect
  zero rows, and the event survives the run intact — mirroring #5's T11/T15 fault-injection
  pattern.
- **Testing:** extend `tests/Unit/Actions/PurgeExpiredPayloadsTest.php` — the retrying-holds,
  young-pending-holds, old-pending-does-not-hold, terminal-does-not-hold cases; the
  compare-and-set race case (reusing #5's `DB::listen()`-based reappeared-hold technique).
- **Completion notes:** Implemented as specified — `PurgeExpiredPayloads::applyHolds()` gained
  H5 as a fifth `whereNotExists` clause chained after H4, structurally identical in shape to
  H2/H3/H4: `NOT EXISTS (deliveries WHERE webhook_event_id = webhook_events.id AND (status =
  'retrying' OR (status = 'pending' AND created_at > $horizon)))`, using the already-computed
  `$horizon` (`now() - dispatch_horizon_minutes`, the same value H4 uses) so no new config read
  or parameter was introduced. `DeliveryStatus` imported for the two status-value comparisons.
  Because `applyHolds()` is shared verbatim between the selection query and the erase `UPDATE`'s
  own `WHERE` (unchanged), H5 is re-asserted automatically inside the compare-and-set with no
  further code, exactly as the plan states. Class docblock's holds list extended to name H5 and
  its rationale, and the "read-only tables" sentence extended to include `deliveries`.
  `tests/Unit/Actions/PurgeExpiredPayloadsTest.php` extended (5 new tests, `Delivery`/
  `DeliveryStatus`/`DispatchKind`/`Destination`/`Str` imports added, plus a shared `isCleaned()`
  helper mirroring the one already used in #5's `RetentionInFlightHoldsAcceptanceTest`):
  a `retrying` delivery holds regardless of age; a `pending` delivery younger than the default
  60-minute horizon holds; a `pending` delivery older than the horizon does not hold; two
  terminal deliveries (`succeeded` and `failed`) on the same event hold nothing, including the
  `failed` one (AC18's explicit "including a `failed` one" case); the compare-and-set race case,
  reusing #5's `DB::listen()`-based reappeared-hold technique verbatim (a `deliveries` row
  flipping to `retrying` is inserted via `DB::table('deliveries')->insert()` immediately after
  the selection `SELECT id FROM webhook_events` fires but before the erase `UPDATE` runs; the
  fixture destination is pre-created outside the listener to avoid the create-inside-listener
  query noise the #5 pattern doesn't need to worry about since H5's fixture, unlike H2's, needs a
  real `destination_id` FK row). No production code outside `PurgeExpiredPayloads.php` was
  touched. Verified: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0
  errors), `./vendor/bin/sail test --filter PurgeExpiredPayloadsTest` (18 passed / 28 assertions),
  `./vendor/bin/sail test --parallel` full suite (541 passed / 1819 assertions — up from the
  session's 536 baseline, net +5: 5 new H5 tests, no removals).

## T20 — AC18 guard test: `RetryPolicy::worstCaseSpan()` bounded well inside the retention window (AC2, AC18; ADR-015 Decision 4)
- **Description:** A dedicated guard test (no new production code expected — a wiring/assertion
  task, mirroring #5's config-sanity guard-test posture) proving `RetryPolicy::worstCaseSpan()`
  for the maximum configurable policy (limit `max_attempt_limit`, exponential) stays a small
  fraction of `RetentionPolicy::windowFor()`'s default window — asserted as `worstCaseSpan() <=
  3 days` against the 30-day default, so a future constant change in `config/retry.php` trips
  this test loudly before it could ever threaten AC18's "a retry policy can never make a payload
  immortal."
- **Dependencies:** T11
- **Files:** none expected (test-only); fix any production-code gap found
- **Acceptance Criteria:** the guard test passes against current defaults (`≈32.6 h ≤ 3 days ≤
  30 days`); deliberately setting `exponential_max_delay_seconds`/`max_attempt_limit` to values
  that would blow the bound causes the guard test to fail (proving it actually constrains
  something, not merely a tautology).
- **Testing:** extend `tests/Unit/Services/RetryPolicyTest.php` (T11's file) — the bound
  assertion against the default config, plus a config-override case proving the test would catch
  a regression.
- **Completion notes:** Implemented as a pure test-only addition — no production-code gap found
  (`RetryPolicy::worstCaseSpan()`/`RetentionPolicy::windowFor()` needed no changes).
  `tests/Unit/Services/RetryPolicyTest.php` extended (3 new tests): (1)
  `test_worst_case_span_stays_well_inside_the_retention_window` — asserts `worstCaseSpan()` for
  the default config (`≈32.6h`) is `<= 3 days` (259,200s), and separately that the 3-day
  intermediate bound itself is `<=` `RetentionPolicy::windowFor()`'s default 30-day window (a
  freshly-factoried `Team`, no per-team override), matching the spec's exact wording — the
  intermediate 3-day bound, not the 30-day window directly, is what trips loudly on a future
  `config/retry.php` constant change; (2)
  `test_worst_case_span_guard_would_catch_a_regression_that_blows_the_bound` — deliberately
  overriding `retry.max_attempt_limit` to 30 (default curve constants otherwise) drives
  `worstCaseSpan()` past the 3-day bound, proving the assertion is not a tautology; (3)
  `test_worst_case_span_guard_would_catch_a_regression_that_raises_the_delay_cap` — the second
  named lever, `retry.exponential_max_delay_seconds` raised to 1,000,000 alone (attempt limit
  held at the product default 10) likewise blows the bound. Both regression-proof tests assert
  the blown state directly (`assertGreaterThan`) rather than making the suite itself go red, per
  the AC's "proving it actually constrains something" without leaving anticipated-red behind.
  Verified: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0 errors),
  `./vendor/bin/sail test --filter RetryPolicyTest` (23 passed / 47 assertions),
  `./vendor/bin/sail test --parallel` full suite (544 passed / 1825 assertions — up from T19's
  541/1819, net +3, no failures). Closes M5.

---

## M6 — Replay backend

## T21 — `proxy:replay` permission: `TeamPermission`, `TeamRole` bundles, `ProxyPolicy::replay()`, `ProxyPermissions.canReplayProxy` (AC14; ADR-017 Decision 4, ADR-009)
- **Description:** `TeamPermission` gains `ReplayProxy = 'proxy:replay'`. `TeamRole::permissions()`
  grants it to **all three** roles: Owner inherits via `TeamPermission::cases()` (unchanged);
  Admin's and Member's explicit arms both add `TeamPermission::ReplayProxy` — **no** `-any`
  ownership-bypass case is added for it (AC14: no Member ownership limit applies to replay at
  all, so there is nothing to bypass). `ProxyPolicy::replay(User $user, Proxy $proxy): bool` —
  single-axis: `$user->hasTeamPermission($proxy->team, TeamPermission::ReplayProxy)`, no
  `ownsOrCanManageAny` composition (unlike `update`/`delete`). `App\Data\ProxyPermissions` and
  `HasTeams::toProxyPermissions()` both gain `canReplayProxy` (derived the same way as every
  other boolean on the DTO); `ProxyController::proxyPermissions()`'s all-false fallback DTO gains
  `canReplayProxy: false`.
- **Dependencies:** none
- **Files:** `app/Enums/TeamPermission.php`, `app/Enums/TeamRole.php`,
  `app/Policies/ProxyPolicy.php`, `app/Data/ProxyPermissions.php`, `app/Concerns/HasTeams.php`,
  `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:** Owner, Admin, and Member all hold `TeamPermission::ReplayProxy`;
  `ProxyPolicy::replay()` returns `true` for a Member on a proxy they did **not** create (no
  ownership limit — the distinguishing case from `update`/`delete`) and `false` for a user with
  no team membership; `canReplayProxy` is `true` for all three roles and `false` in the
  unauthenticated/no-current-team fallback DTO.
- **Testing:** extend `tests/Unit/Enums/TeamPermissionTest.php`/`TeamRoleTest.php` (case-set and
  per-role bundle assertions), `tests/Feature/Proxies/ProxyPolicyTest.php` (the Member-non-owner-
  can-replay case, the non-member-cannot case), `tests/Feature/Teams/ProxyPermissionsDtoTest.php`
  (`canReplayProxy` true for all three roles, false in the fallback).
- **Completion notes:** Implemented as specified, all six named files touched. `TeamPermission`
  gained `ReplayProxy = 'proxy:replay'` (appended last, after `DeleteAnyProxy`). `TeamRole::
  permissions()`: Owner inherits it via `cases()` (unchanged); Admin's and Member's explicit arms
  both gained `TeamPermission::ReplayProxy` directly — no `-any` bypass case was added or needed,
  since AC14 rules out an ownership limit for replay entirely (the arm-comment on `Member`
  explains this is not an oversight). `ProxyPolicy::replay(User, Proxy): bool` — single-axis
  `hasTeamPermission($proxy->team, ReplayProxy)`, no `ownsOrCanManageAny` composition, matching
  `view()`'s shape rather than `update()`/`delete()`'s. `App\Data\ProxyPermissions` gained the
  readonly `canReplayProxy` constructor property (appended last); `HasTeams::
  toProxyPermissions()` derives it the same way as every other boolean
  (`$role?->hasPermission(TeamPermission::ReplayProxy) ?? false`); `ProxyController::
  proxyPermissions()`'s all-false fallback DTO gained `canReplayProxy: false`. Both call sites
  constructing `ProxyPermissions` (`HasTeams`, `ProxyController`) were the only two in the
  codebase (grepped), both updated — no other construction site needed touching.
  `tests/Unit/Enums/TeamPermissionTest.php`: case-set assertion extended with the new
  `['ReplayProxy', 'proxy:replay']` entry, count assertion renamed/bumped 13→14.
  `tests/Unit/Enums/TeamRoleTest.php`: new data-provider-driven
  `test_every_role_holds_replay_with_no_ownership_limit` (all three roles). `tests/Feature/
  Proxies/ProxyPolicyTest.php`: `test_a_member_may_replay_a_proxy_they_did_not_create` (the
  distinguishing case from `update`/`delete` — a Member replaying a teammate's proxy succeeds)
  and `test_a_user_with_no_team_membership_may_not_replay`. `tests/Feature/Teams/
  ProxyPermissionsDtoTest.php`: `canReplayProxy` assertions added to the existing Member (`true`,
  no ownership limit despite lacking the `-any` bypasses), Admin/Owner (`true`), and non-member
  (`false`, the closest analog to the controller's literal fallback DTO — no other permission on
  this DTO has a dedicated test for the controller-level literal fallback construction either,
  consistent with existing precedent) cases. No frontend files touched — the TS `ProxyPermissions`
  type/client affordance derivation is out of T21's Files list (M9's scope). Verified: `composer
  lint` (Pint, passed), `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test
  --filter "TeamPermissionTest|TeamRoleTest|ProxyPolicyTest|ProxyPermissionsDtoTest|
  ProxyIndexPermissionsTest"` (23 passed / 153 assertions — `ProxyIndexPermissionsTest` included
  as a regression check since it renders the DTO through Inertia and was not itself modified),
  `./vendor/bin/sail test --parallel` full suite (549 passed / 1834 assertions — up from T20's
  544/1825, net +5, no failures).

## T22 — `ReplayEventRequest` (AC10; ADR-017 Decision 1)
- **Description:** New `App\Http\Requests\ReplayEventRequest`: `destinations` → `['required',
  'array', 'min:1']`; `destinations.*` → integer, `distinct`, and a scoped `Rule::exists`
  restricted to the **current proxy's live** (`whereNull('deleted_at')`) destination ids — no
  ad-hoc URLs, no trashed targets, no other proxy's destinations (AC10). `authorize()` returns
  `true` (authorization lives on the controller via `$this->authorize('replay', $proxy)`,
  matching the house `StoreProxyRequest`/`UpdateProxyRequest` split).
- **Dependencies:** none
- **Files:** `app/Http/Requests/ReplayEventRequest.php` (new)
- **Acceptance Criteria:** a request selecting a subset of the proxy's live destinations passes;
  one including a trashed destination id, another proxy's destination id, or a non-existent id
  fails with 422; an empty `destinations` array fails; a duplicate id in the array fails
  (`distinct`).
- **Testing:** `tests/Unit/Http/Requests/ReplayEventRequestTest.php` (new, or fold into T24's
  controller feature test if house convention favours controller-level validation coverage —
  Senior Developer's call, consistent with `ProxyRequestValidationTest`'s existing pattern) — the
  four cases above.
- **Completion notes:** Implemented as specified — `App\Http\Requests\ReplayEventRequest`:
  `authorize()` returns `true` (authorization lives on the controller, T24, matching
  `StoreProxyRequest`/`UpdateProxyRequest`); `rules()`: `destinations` → `['required', 'array',
  'min:1']`; `destinations.*` → `['required', 'integer', 'distinct', Rule::exists('destinations',
  'id')->where('proxy_id', $this->proxy()?->id)->whereNull('deleted_at')]` — scoped to the
  **current route-bound proxy's live** destinations only, exactly per AC10. A private typed
  `proxy(): ?Proxy` helper resolves `$this->route('proxy')`, mirroring the house
  `DeleteTeamRequest::team()` pattern (PHPStan L7 rejects untyped property access on the route
  resolver's `mixed` return; the existing codebase's own precedent for this exact seam was
  followed rather than inventing a new one). **Testing deferred to T24, per this task's own
  explicit allowance** ("fold into T24's controller feature test ... Senior Developer's call"):
  unlike `StoreProxyRequest`/`UpdateProxyRequest` (whose rules are self-contained,
  `ProxyRequestValidationTest`'s `Validator::make($data, (new $requestClass)->rules())` pattern
  works route-free), `ReplayEventRequest::rules()` is genuinely route-dependent (the `Rule::exists`
  scope needs a real bound `Proxy`), so a route-free unit-test harness would either need to
  fabricate a `Illuminate\Routing\Route` stub (extra indirection with no behavioural payoff) or
  assert nothing meaningful. `tests/Feature/Replay/ProxyEventReplayControllerTest.php` (T24) is
  where this request is actually exercised — through real HTTP requests carrying real route
  bindings — and its own Testing note already names all four cases T22's AC lists (subset-passes,
  trashed/other-proxy/non-existent-id-422s, empty-array-422s, duplicate-id-422s) as required
  coverage for the endpoint it validates; T22's four cases are folded into that file one-for-one,
  not dropped. No `ReplayEventRequestTest.php` file was created. Verified: `composer lint` (Pint,
  passed), `composer types:check` (PHPStan L7, 0 errors — the route-resolver typing gap the naive
  version hit), `./vendor/bin/sail test --parallel` full suite (549 passed / 1834 assertions,
  unchanged from T21 — no regression, no new tests yet by design).

## T23 — `Proxy::webhookEvents(): HasMany` relation (AC15–AC17; plan §API)
- **Description:** New `HasMany` relation on `Proxy`, `webhookEvents(): HasMany` →
  `WebhookEvent::class` — needed so `{event}` route parameters can resolve via
  `->scopeBindings()` through the proxy (cross-team/cross-proxy id ⇒ 404), for both the replay
  endpoint (T24) and the read surface (T25–T28).
- **Dependencies:** none
- **Files:** `app/Models/Proxy.php`
- **Acceptance Criteria:** `$proxy->webhookEvents` resolves the proxy's captured events; an event
  belonging to a different proxy is not resolvable through this relation.
- **Testing:** extend `tests/Unit/Models/ProxyTest.php` — the relation resolves correctly and
  excludes another proxy's events.
- **Completion notes:** Implemented as specified — `Proxy::webhookEvents(): HasMany` added
  (`$this->hasMany(WebhookEvent::class)`), placed after `deliveryAttempts()` alongside the other
  relation methods; class docblock's `@property-read` list extended with `Collection<int,
  WebhookEvent> $webhookEvents`, matching the house convention for every other relation on this
  model. `tests/Unit/Models/ProxyTest.php` extended (2 new tests, `WebhookEvent` import added):
  the relation resolving a proxy's own captured event, and excluding another proxy's event (both
  proxies sharing the same team, isolating the assertion to the proxy scope rather than the team
  scope). Verified: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0
  errors), `./vendor/bin/sail test --filter ProxyTest` (19 passed / 52 assertions),
  `./vendor/bin/sail test --parallel` full suite (551 passed / 1837 assertions — up from T22's
  549/1834, net +2, no failures).

## T24 — `ProxyEventReplayController@store` + `POST .../events/{event}/replay` route (AC9–AC15; ADR-017 Decisions 1, 3)
- **Description:** New `App\Http\Controllers\ProxyEventReplayController@store`, gated
  `$this->authorize('replay', $proxy)`, validated by `ReplayEventRequest` (T22). Inside one
  `DB::transaction()`: re-select the event `WHERE payload_cleaned_at IS NULL` under
  `lockForUpdate()` — cleaned ⇒ `ValidationException` (surfaces as the dialog's inline error,
  AC15's lifecycle framing, never a 500); mint a fresh `dispatch_uuid` (UUID); create one
  `Delivery` row per validated destination id (`kind: DispatchKind::Replay, status:
  DeliveryStatus::Pending`); for a **FIFO** proxy, additionally insert one `fifo_dispatches` row
  (`webhook_event_id`, `dispatch_uuid` = the same fresh UUID, `status: Pending`) — joining the
  line at the back (T16's `id`-order key makes this correct by construction. After commit:
  Async ⇒ `ProcessIngestedWebhook::dispatch($event->ingest_id, $dispatchUuid)->afterCommit()`;
  FIFO ⇒ `AdvanceProxyFifoQueue::dispatch($proxy->id)->afterCommit()`. PRG redirect back with a
  flash toast (`Inertia::flash('toast', ...)`, matching the existing `ProxyController` pattern).
  Route: `POST /{team}/proxies/{proxy}/events/{event}/replay`, name `proxies.events.replay`,
  `->scopeBindings()`, inside the existing team-prefixed group.
- **Dependencies:** T3, T6, T8, T21, T22, T23
- **Files:** `app/Http/Controllers/ProxyEventReplayController.php` (new), `routes/web.php`
- **Acceptance Criteria:** replaying to a subset of destinations creates exactly that many
  `Delivery` rows (`kind = replay`), traceable to the original event via `webhook_event_id`, all
  sharing one fresh `dispatch_uuid`; select-all replays to every current live destination; a
  trashed/other-proxy destination id is rejected (T22); replay works on both simple- and
  enhanced-mode proxies (AC9); a **FIFO** proxy gains a new `pending` `fifo_dispatches` row that
  processes **after** every already-pending event (join-at-the-back, AC11); an **Async** proxy
  dispatches immediately; a cleaned event's replay attempt is rejected with a validation error,
  creates zero `Delivery` rows and sends nothing (AC15); a race where GC erases the event between
  page load and the replay POST is rejected by the `lockForUpdate` re-check (no replay rows
  created); a Member replaying a proxy they did not create succeeds (AC14); a non-member 403/404s.
- **Testing:** `tests/Feature/Replay/ProxyEventReplayControllerTest.php` (new) — all cases above,
  `Http::fake()`/`Queue::fake()`/`Bus::fake()` for dispatch assertions, a `DB::listen()`-based
  race simulation for the lock-and-recheck case (mirroring #5's fault-injection technique).
- **Completion notes:** Implemented as specified. `App\Http\Controllers\ProxyEventReplayController
  @store`: `$this->authorize('replay', $proxy)`; validated by `ReplayEventRequest`; one
  `DB::transaction()` that re-selects the event `WHERE payload_cleaned_at IS NULL` under
  `lockForUpdate()->exists()` (cleaned/raced-cleaned ⇒ `ValidationException` under the `event` key
  — a 422, never a 500), mints a fresh `dispatch_uuid` (`Str::uuid()`), creates one `Delivery` row
  per validated destination id (`kind: DispatchKind::Replay, status: DeliveryStatus::Pending`),
  and — for a FIFO proxy only — inserts one additional `fifo_dispatches` row (same fresh
  `dispatch_uuid`, `status: Pending`) that joins the line at the back by construction (T16's `id`
  order key, no explicit ordering value needed). After the transaction commits: Async ⇒
  `ProcessIngestedWebhook::dispatch($event->ingest_id, $dispatchUuid)->afterCommit()`; FIFO ⇒
  `AdvanceProxyFifoQueue::dispatch($proxy->id)->afterCommit()`; PRG redirect to `proxies.show`
  with a flash toast, matching `ProxyController`/`DestinationController`'s exact pattern. Route:
  `POST {current_team}/proxies/{proxy}/events/{event}/replay`, name `proxies.events.replay`,
  `->scopeBindings()`, added inside the existing team-prefixed group in `routes/web.php`
  immediately after the destinations-destroy route.

  **Spec-vs-reality tension found and resolved (flagged, not silently patched around) — `app/
  Models/Proxy.php` touched beyond T24's stated Files list.** Laravel's scoped-binding mechanism
  (`Illuminate\Database\Eloquent\Model::resolveChildRouteBinding()`) resolves the parent
  relationship for a child route parameter by convention —
  `Str::plural(Str::camel($childType))` on the route parameter's own name — which for `{event}`
  computes `events`, not T23's mandated `webhookEvents`. Verified directly against the vendored
  framework source before writing any workaround. Uncorrected, hitting this route would throw
  `BadMethodCallException: Call to undefined method Proxy::events()` the instant a real `{event}`
  parameter needed scoping — a 500, not a 404, and a real security gap (no team/proxy scoping on
  the child at all) had the fallback path been silently taken instead. Since T23 explicitly locks
  the relation's name to `webhookEvents()` (Files list, Acceptance Criteria, and its own test all
  assert that exact name) and ADR-017 Decision 5 explicitly commits to scoped binding through the
  proxy for `{event}`, neither side of the two task specs could be honoured by renaming the
  relation — the fix has to live at the seam Eloquent provides for exactly this mismatch:
  `Proxy::childRouteBindingRelationshipName()` is overridden to map the child type `'event'` to
  `'webhookEvents'` (falling through to the parent implementation for every other child type, so
  `{destination}` — already matching the convention via `destinations()` — is untouched). This is
  the documented Eloquent extension point for a route-parameter name that legitimately differs
  from its relation name, not a new or invented mechanism, and changes no public interface, data
  model, or ADR'd decision — judged in scope under the Senior Developer's local-implementation-
  detail authority rather than escalated, since it is the mechanical wiring T24's own Acceptance
  Criteria and ADR-017 Decision 5 require to actually hold. Proven by
  `test_another_proxys_event_id_returns_404` (would have 500'd/thrown without the override).

  `tests/Feature/Replay/ProxyEventReplayControllerTest.php` (new, 16 tests) covers every AC9-AC15
  case plus T22's four validation cases (folded in per T22's own completion notes) plus the
  cross-proxy-event scoped-binding proof: subset replay creates exactly that many `Delivery` rows
  sharing one `dispatch_uuid`; select-all replays to every live destination;
  simple/enhanced-mode proxies both replay (`#[DataProvider]`); an Async proxy dispatches
  `ProcessIngestedWebhook` with the matching `(ingest_id, dispatch_uuid)` pair and pushes nothing
  FIFO-side; a FIFO proxy's new row lands at a strictly higher `id` than a pre-existing pending
  row (join-at-the-back) and nudges `AdvanceProxyFifoQueue`; a cleaned event's replay creates zero
  `Delivery` rows and pushes nothing (422, `event` key); the race case (`DB::listen()` on the
  first plain, non-locking `webhook_events` read — the route-model-binding lookup — cleans the
  event before the transaction's own `lockForUpdate` re-check runs, mirroring #5's fault-injection
  technique per the Testing note); the four `ReplayEventRequest` cases (empty array, trashed
  destination, another proxy's destination, non-existent id, duplicate id — five 422 assertions
  total, T22's AC named four categories with the duplicate case folded from T22's own AC text);
  a Member replaying a teammate-created proxy succeeds (AC14); a non-member is denied — observed
  as 404 (not 403): `ApplyTeamScope`/`SubstituteBindings` run ahead of `EnsureTeamMembership` in
  this app's middleware pipeline, so the scoped `{proxy}`/`{event}` lookup 404s before the
  membership check is reached, the same observable shape as the pre-existing cross-team
  `DestinationDestroyTest` case; AC14's own wording ("a non-member 403/404s") names both outcomes
  acceptable. `Http::fake()`/`Queue::fake()` used per-test as each case needs (several run un-faked
  under the `sync` queue driver to prove the real inline send, per the house convention memory
  documents). No `Bus::fake()` was needed — every dispatch in this codebase goes through
  Lorisleiva Actions' own `Queue::fake()`-compatible `assertPushed`/`assertNotPushed`, not
  `Illuminate\Bus`. Verified: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7,
  0 errors), `./vendor/bin/sail test --filter ProxyEventReplayControllerTest` (16 passed / 51
  assertions), `./vendor/bin/sail test --parallel` full suite (567 passed / 1888 assertions — up
  from T23's 551/1837, net +16, no failures). Closes M6.

---

## M7 — Read-surface backend

## T25 — `WebhookEventResource`, `DeliveryResource`, `DeliveryAttemptResource` (+ legacy fallback) (AC12, AC15–AC17, AC22, AC25; ADR-017 Decision 5)
- **Description:** Three new `JsonResource`s (`$wrap = null`), plan §API shape verbatim.
  `WebhookEventResource` — `id`, `received_at`, `byte_size`, `content_type`, `method`,
  `payload_state` (via `StoredPayloadLookup`/`StoredPayloadState` mapping — never `body IS
  NULL`), `deliveries` (when loaded). **Never** `body`/`headers`. `DeliveryResource` — `id`,
  `dispatch_uuid`, `kind`, `status`, `next_attempt_at`, `attempt_limit` (effective, via
  `RetryPolicy::attemptLimitFor($delivery->proxy)` — the "of L" in "Attempt N of L"),
  `destination: {http_method, url}` (`withTrashed`), `attempts` (when loaded).
  `DeliveryAttemptResource` — `attempt_number`, `status`, `http_status`, `error_summary`,
  `started_at`, `duration_ms`. **Legacy fallback (ruling 3):** for an event with zero
  `deliveries` rows (captured before #6), `WebhookEventResource`/`DeliveryResource` derive a
  presentation-only per-destination state from the event's latest `delivery_attempts` per
  destination (`succeeded → Delivered`, `failed → Failed`, `dispatched → Retrying`) — never a
  fabricated `Delivery` row, never a DB write.
- **Dependencies:** T2, T3, T11
- **Files:** `app/Http/Resources/WebhookEventResource.php` (new),
  `app/Http/Resources/DeliveryResource.php` (new),
  `app/Http/Resources/DeliveryAttemptResource.php` (new)
- **Acceptance Criteria:** none of the three resources ever emits `body`/`headers` under any
  state; `payload_state` reads exactly `Retained`/`Cleaned`/`NeverCaptured` per the existing
  `StoredPayloadLookup` mapping; `attempt_limit` reflects the proxy's effective policy (column or
  system default); a pre-#6 event (attempts, no `deliveries` rows) renders a derived
  per-destination state with no exception and no synthetic `Delivery` row created.
- **Testing:** `tests/Unit/Http/Resources/WebhookEventResourceTest.php`,
  `DeliveryResourceTest.php`, `DeliveryAttemptResourceTest.php` (new, or one combined file per
  house convention) — the never-content assertion, the payload-state mapping, the effective-
  attempt-limit assertion, the legacy-fallback derivation for all three attempt outcomes.
- **Completion notes:** Implemented all three resources exactly as specced, `$wrap = null`,
  never emitting `body`/`headers`. `payload_state` calls `app(StoredPayloadLookup::class)->for($this->ingest_id)`
  (the single resolver) rather than duplicating its `payload_cleaned_at` mapping inline — an
  accepted per-row extra query (capped at 15 rows/page by T26's pagination), matching the spec's
  explicit "via StoredPayloadLookup" wording over a micro-optimisation. `DeliveryResource.attempt_limit`
  resolves via `RetryPolicy::attemptLimitFor($this->proxy)`; `destination` renders whatever the
  caller eager-loaded (callers use `withTrashed()`, per plan). **One necessary, undocumented-in-Files
  addition:** `WebhookEvent` had no inverse `deliveries(): HasMany` relation — required for
  `$this->whenLoaded('deliveries')`/`$this->deliveries` to resolve at all, so added it to
  `app/Models/WebhookEvent.php` (plus its `@property-read` line) as load-bearing infrastructure for
  this task, not scope creep. **Legacy fallback** lives entirely inside `WebhookEventResource`
  (`legacyDeliveries()`/`legacyStatusFor()`) rather than split across both resource classes as the
  prose loosely implies — `DeliveryResource` mixes in a real `Delivery` model and can't represent a
  fabricated row; the derived array is shaped to match `DeliveryResource`'s keys (`id`,
  `dispatch_uuid`, `next_attempt_at`, `attempt_limit`, `attempts` all `null`) so the client renders
  both uniformly. Triggered only when the `deliveries` relation is loaded and empty (never when
  simply not eager-loaded, which keeps the whenLoaded/omission contract intact); it queries
  `delivery_attempts` directly, takes the latest row per `destination_id`, and creates nothing.
  Verified: 20 new unit tests (never-content, payload-state mapping incl. `never_captured`,
  effective-attempt-limit column/default/clamp-adjacent cases, `withTrashed` destination
  rendering, all three legacy-outcome mappings, latest-per-destination tie-break, zero-row-created
  assertion) — `./vendor/bin/sail test --filter "WebhookEventResourceTest|DeliveryResourceTest|DeliveryAttemptResourceTest"`
  20/20 passed, 53 assertions. Full suite `./vendor/bin/sail test --parallel`: **587 passed / 1941
  assertions** (up from 567/1888). `composer lint`: clean (Pint auto-fixed import ordering on
  `WebhookEventResource.php`). `composer types:check`: clean (PHPStan L7; fixed a `list<...>` vs
  `array<int,...>` return-type mismatch and two false-positive nullsafe-on-non-nullable warnings on
  the legacy-fallback path).

## T26 — `ProxyEventController@index` + `GET .../events` route + `fifoHeldByRetry` prop (AC15, AC16; ADR-017 Decision 5)
- **Description:** New `App\Http\Controllers\ProxyEventController@index`, gated
  `$this->authorize('view', $proxy)`. Paginated (15, `latest('id')`) list of the proxy's captured
  events via `WebhookEventResource`, with per-event delivery-state summaries loaded. Page props:
  `permissions` (the existing `ProxyPermissions` DTO, now including `canReplayProxy` from T21),
  `fifoHeldByRetry` (bool — `true` iff the proxy is FIFO and has a live `awaiting_retry` row).
  Route: `GET /{team}/proxies/{proxy}/events`, name `proxies.events.index`, `->scopeBindings()`.
- **Dependencies:** T21, T23, T25
- **Files:** `app/Http/Controllers/ProxyEventController.php` (new), `routes/web.php`
- **Acceptance Criteria:** the list paginates newest-first (15/page); each row's payload/delivery
  state is correct for retained/cleaned/never-captured and delivered/retrying/failed events;
  `fifoHeldByRetry` is `true` only when the proxy is FIFO **and** an `awaiting_retry` row exists,
  `false` otherwise (including for Async proxies, always); an unauthenticated or non-member
  request is redirected/404s; a cross-team proxy id 404s.
- **Testing:** `tests/Feature/ProxyEvents/ProxyEventIndexTest.php` (new) — pagination, the three
  payload states, the `fifoHeldByRetry` true/false/Async cases, the auth/scoping cases.
- **Completion notes:** Implemented as specced. Eager-loads `deliveries.destination` (`withTrashed`)
  so `WebhookEventResource` never re-queries per row for the delivery summary (only `payload_state`'s
  `StoredPayloadLookup` call is per-row, per T25's completion note). `fifoHeldByRetry` is a private
  helper checking `processing_mode === Fifo` short-circuit before an `awaiting_retry` existence
  query, so Async proxies never issue it. Page props beyond the two the spec names: added `proxy`
  (`ProxyResource`) and `events` (the paginated `WebhookEventResource` collection) — necessary for
  any page to render at all (breadcrumb/heading need the proxy's name; design-06 Screen 2's FIFO
  note reads `proxy.processing_mode` client-side) and named to match the plan's own frontend file
  list (`proxies/events/Index.vue`, M9) and `ProxyController@index`'s `proxies` naming convention —
  a naming/shape decision the plan leaves open, not a new requirement. `proxyPermissions()` is
  duplicated from `ProxyController` (same fail-closed shape) rather than extracted to a shared
  helper — T26 alone doesn't justify a new abstraction; revisit if a third controller needs it.
  Route `GET /{team}/proxies/{proxy}/events` (`proxies.events.index`) added ahead of the existing
  `.../events/{event}/replay` POST route in `routes/web.php` (no ordering conflict — distinct path
  shapes). Verified: 9 new feature tests (pagination incl. `last_page`, retained/cleaned payload
  states, all three `fifoHeldByRetry` cases, guest redirect, non-member 404, cross-team 404) —
  `./vendor/bin/sail test --filter ProxyEventIndexTest` 9/9 passed, 58 assertions. Full suite:
  **596 passed / 1999 assertions** (up from 587/1941). `composer lint`: clean. `composer
  types:check`: clean (PHPStan L7).

## T27 — `ProxyEventController@show` + `GET .../events/{event}` route (AC12, AC16; ADR-017 Decision 5)
- **Description:** `ProxyEventController@show`, gated `$this->authorize('view', $proxy)`. Event
  detail via `WebhookEventResource` with `deliveries.attempts` eager-loaded, grouped
  client-side (Vue) by `dispatch_uuid`/`kind` — the resource itself just returns the flat
  `deliveries` collection each carrying `kind`/`dispatch_uuid`. Route: `GET
  /{team}/proxies/{proxy}/events/{event}`, name `proxies.events.show`, `->scopeBindings()`.
- **Dependencies:** T25
- **Files:** `app/Http/Controllers/ProxyEventController.php`, `routes/web.php`
- **Acceptance Criteria:** the detail response carries the event's descriptors, payload state,
  and every `deliveries` row (original + any replays) each with its `attempts`; never `body`/
  `headers`; a cross-team/cross-proxy event id 404s (scoped binding); a pre-#6 event (no
  `deliveries` rows) renders via the legacy fallback (T25) with no error.
- **Testing:** extend `tests/Feature/ProxyEvents/ProxyEventShowTest.php` (new) — the full-detail
  case (original + replay groups), the never-content assertion, the scoped-binding 404 case, the
  legacy-fallback case.
- **Completion notes:** Added `ProxyEventController@show`, eager-loading `deliveries.destination`
  (`withTrashed`) and `deliveries.deliveryAttempts` so `WebhookEventResource`/`DeliveryResource`
  render every row's `attempts` without N+1 (the ID/kind/dispatch_uuid grouping is left to the Vue
  layer per the spec — this resource returns the flat collection). Route `GET
  /{team}/proxies/{proxy}/events/{event}` (`proxies.events.show`) added between `.../events` and
  `.../events/{event}/replay` in `routes/web.php`. Page props beyond `event`: added `proxy`
  (`ProxyResource`, with `destinations` loaded) and `permissions`, for the same reason as T26's
  extra props — design-06 Screen 3's header Replay action needs `permissions.canReplayProxy` and
  the proxy's current live destinations, and the breadcrumb needs the proxy's name; no AC forbids
  it and it's the same DTO/pattern T26 and `ProxyController::show()` already use, not a new
  requirement. Cross-team **and** cross-proxy event ids both 404 via the existing `{event}`
  scoped-binding (`Proxy::webhookEvents()`, T23) — no new binding logic needed. Legacy-fallback
  case reuses T25's derivation untouched; verified end-to-end here through the controller's real
  eager-loads (relation loaded-and-empty is exactly what triggers it). Verified: 5 new feature
  tests (full original+replay detail incl. attempts, never-content via `missing()`, cross-proxy
  404, cross-team 404, legacy-fallback incl. zero-Delivery-rows-created) — `./vendor/bin/sail test
  --filter ProxyEventShowTest` 5/5 passed, 43 assertions. Full suite: **601 passed / 2042
  assertions** (up from 596/1999). `composer lint`: clean. `composer types:check`: clean
  (PHPStan L7).

## T28 — `ProxyEventPayloadController` + `GET .../events/{event}/payload` route (AC22, AC25; ADR-017 Decision 6)
- **Description:** New invokable `App\Http\Controllers\ProxyEventPayloadController`, gated
  `$this->authorize('view', $proxy)` — no distinct reveal permission. Retained (`payload_cleaned_at
  IS NULL`) ⇒ 200 with the raw decrypted bytes, `Content-Type: text/plain; charset=utf-8`,
  `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`; Cleaned ⇒ **410 Gone**
  (lifecycle, never an error); event not found for this proxy ⇒ **404**. Logs `payload.revealed`
  with identifiers only (`team_id`, `proxy_id`, `event_id`/`ingest_id`) — never the response body,
  never cached, never proxied into any resource/prop. Route: `GET
  /{team}/proxies/{proxy}/events/{event}/payload`, name `proxies.events.payload`,
  `->scopeBindings()`.
- **Dependencies:** T23
- **Files:** `app/Http/Controllers/ProxyEventPayloadController.php` (new), `routes/web.php`
- **Acceptance Criteria:** a retained event returns exactly its raw captured bytes with the three
  documented headers; a cleaned event returns 410 with no body content; an unknown/other-proxy
  event id 404s; the response is never logged (assert via `Log::spy()` that no log call carries
  payload bytes, only the one identifiers-only `payload.revealed` entry); an unauthenticated or
  non-view-permission request is redirected/403s.
- **Testing:** `tests/Feature/ProxyEvents/ProxyEventPayloadControllerTest.php` (new) — the
  retained/cleaned/unknown/cross-team cases, the header assertions, the identifiers-only logging
  assertion.
- **Completion notes:** Implemented as an invokable controller. Guards directly on
  `$event->payload_cleaned_at !== null` (the event is already route-bound, a plain read — no
  `lockForUpdate`, unlike the replay endpoint's write path; this is a read-only 410/200 decision,
  matching the codebase's existing precedent of checking the column directly rather than always
  routing through `StoredPayloadLookup`, e.g. `ProxyEventReplayController`). Reads only
  `$event->body` (the raw capture) — never `dispatched_payloads`, per ADR-017's Impact constraint.
  **One deviation from the spec's literal `abort(410)` wording:** `abort(410)` renders the app's
  full HTML error-page body (confirmed by a failing first test run — the "cleaned ⇒ no body
  content" AC failed against Laravel's default error view), which is itself content this endpoint
  must never carry; used `response('', 410)` instead — same status code, genuinely empty body,
  satisfies the AC's letter and spirit. Logs `payload.revealed` with exactly the four documented
  identifiers, only on the 200 (retained) path — never on 410/404 — verified via `Log::spy()`
  asserting both the exact context array and that the JSON-encoded context never contains the
  fixture's secret marker. `{event}` resolves via T23's `Proxy::webhookEvents()` scoped binding,
  so unknown/cross-proxy/cross-team ids all 404 before the method body runs — no bespoke lookup
  needed. Route `GET /{team}/proxies/{proxy}/events/{event}/payload` (`proxies.events.payload`)
  registered as a single-action controller reference (no `[Controller::class, 'method']` array,
  matching the `__invoke` convention). Verified: 9 new feature tests (retained bytes + all three
  headers, cleaned 410 empty body, unknown/cross-proxy/cross-team 404, guest redirect, non-member
  404, identifiers-only logging on reveal, no logging on a cleaned attempt) —
  `./vendor/bin/sail test --filter ProxyEventPayloadControllerTest` 9/9 passed, 20 assertions.
  Full suite: **610 passed / 2062 assertions** (up from 601/2042). `composer lint`: clean.
  `composer types:check`: clean (PHPStan L7).

---

## M8 — Proxy form & policy surface

## T29 — `StoreProxyRequest`/`UpdateProxyRequest`: retry-policy validation (AC2, AC20)
- **Description:** Both requests gain: `retry_attempt_limit` → `['nullable', 'integer', 'min:1',
  'max:10', 'prohibited_if:mode,simple']`; `retry_backoff_strategy` → `['nullable',
  Rule::enum(RetryBackoffStrategy::class), 'prohibited_if:mode,simple']` — mirroring the existing
  `response_body`/204 `prohibited_if` idiom exactly. `StoreProxyRequest` did not previously exist
  in the excerpts read for this plan's grounding but mirrors `UpdateProxyRequest`'s rule shape
  one-for-one; apply the identical two rules there.
- **Dependencies:** T4, T2
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`
- **Acceptance Criteria:** limit 1–10 with a known strategy on an enhanced-mode submission passes;
  0, 11, or an unknown strategy value is rejected (422); either field present-and-non-empty on a
  `mode = simple` submission is rejected (`prohibited_if`); both fields omitted/null always
  passes regardless of mode.
- **Testing:** extend `tests/Feature/Proxies/ProxyRequestValidationTest.php` — the valid-range,
  out-of-range, unknown-strategy, and simple-mode-prohibited cases for both Store and Update.
- **Completion notes:** Added the two rules verbatim as specced to both requests, immediately
  after `response_body` (same position both existing `prohibited_if` rules occupy). Extended
  `ProxyRequestValidationTest` with 7 new data-provider-driven test methods (× Store/Update = 14
  cases): valid limit+strategy on enhanced, the 1/10 bounds accepted, 0/11 rejected, an unknown
  strategy string rejected, either field alone rejected under `mode = simple`, and both
  fields omitted-or-explicit-null passing regardless of mode (the existing `validData()` fixture
  defaults `mode: 'simple'`, so every enhanced-mode case overrides it explicitly). Verified:
  `./vendor/bin/sail test --filter ProxyRequestValidationTest` 50/50 passed, 144 assertions. Full
  suite: **624 passed / 2106 assertions** (up from 610/2062). `composer lint`: clean. `composer
  types:check`: clean (PHPStan L7).

## T30 — `ProxyController` persistence/clearing + `ProxyResource` fields (AC2, AC20)
- **Description:** `store()`/`update()` persist `retry_attempt_limit`/`retry_backoff_strategy`
  via `$data['...'] ?? null` (an omitted/cleared field resets to the default-sentinel NULL,
  matching the existing `response_status`/`response_body` clearing pattern — an
  Enhanced→Simple mode switch on `update()` therefore clears any previously configured values
  automatically, since the request rejects non-null values under `mode = simple` and the
  controller writes exactly what validation returned). `ProxyResource` adds
  `retry_attempt_limit`, `retry_backoff_strategy` (raw values, nullable) to its `toArray()`.
- **Dependencies:** T29
- **Files:** `app/Http/Controllers/ProxyController.php`, `app/Http/Resources/ProxyResource.php`
- **Acceptance Criteria:** creating/updating an enhanced proxy with both fields set persists them;
  updating an enhanced proxy from configured values to omitted/null clears them to NULL;
  switching `mode` from enhanced to simple on `update()` clears both to NULL (via the same
  request-rejection + `?? null` mechanism); `ProxyResource` emits both fields on every response
  that already includes the proxy shape (index/show/edit).
- **Testing:** extend `tests/Feature/Proxies/ProxyStoreTest.php` and `ProxyUpdateTest.php` — the
  persist, clear-on-omit, and clear-on-mode-switch cases; extend `ProxyIndexShowTest.php` for the
  resource-field-presence assertion.
- **Completion notes:** `update()` persists both fields via `$data['...'] ?? null` exactly as
  specced. `store()` needed one adjustment to reach the same guarantee: `Proxy::make($data)`
  mass-assigns only the keys present in `$data`, so an *absent* key (vs. an explicit `null`) would
  leave the attribute unset rather than explicitly `null` on the in-memory model before `save()` —
  functionally identical on insert (both fillable retry columns are nullable with no DB default,
  so an unset attribute inserts NULL exactly like an explicit `null` would), but not the same
  as literally applying `$data['...'] ?? null` the spec's own wording calls for. Made it literal:
  `Proxy::make(array_merge($data, ['retry_attempt_limit' => $data['retry_attempt_limit'] ?? null,
  'retry_backoff_strategy' => $data['retry_backoff_strategy'] ?? null]))` — same observable
  behaviour, now textually matching the pattern `update()` uses (and resilient if the column ever
  gains a non-NULL default). `ProxyResource` adds both fields; `retry_backoff_strategy` reads
  `$this->retry_backoff_strategy?->value` (the enum cast) so the JSON prop is the plain string the
  frontend consts expect, mirroring `processing_mode`/`mode`'s own `->value` pattern rather than
  `response_status`'s raw-scalar one (the enum needs unwrapping; the int doesn't). Verified: 7 new
  feature tests (Store: persist + omitted-null; Update: persist + clear-on-omit +
  clear-on-enhanced-to-simple-switch; Index/Show: field-presence with values + null-when-
  unconfigured) — `./vendor/bin/sail test --filter "ProxyStoreTest|ProxyUpdateTest|ProxyIndexShowTest"`
  29/29 passed, 209 assertions. Full suite: **631 passed / 2156 assertions** (up from 624/2106).
  `composer lint`: clean. `composer types:check`: clean (PHPStan L7).

This completes **M7** (T25-T28, read-surface backend) and **M8** (T29-T30, proxy form & policy
surface). M9 (frontend, T31-T37) and M10 (acceptance tests, T38-T46) are out of scope for this
session per the assigning task's instructions.

---

## M9 — Frontend

## T31 — Data consts + `types/proxies.ts` extensions (design-06 §Components "Recommended data-const treatment"; AC2, AC16, AC25)
- **Description:** Three new `DataOption`-typed consts mirroring `proxyResponseStatuses.ts`/
  `proxyProcessingModes.ts`: `resources/js/data/proxyPayloadStates.ts` (Retained/Expired/Not
  captured — label + badge variant pairs), `resources/js/data/proxyDeliveryStates.ts`
  (Delivered/Retrying/Terminally failed — label + badge variant pairs, plus the aggregate-badge
  precedence helper: terminal-failure beats retrying beats delivered, per design-06's flagged
  judgment call 2), `resources/js/data/proxyRetryBackoffStrategies.ts` (Exponential/Fixed
  interval + the `RETRY_STRATEGY_DEFAULT` sentinel, mirroring `PROXY_RESPONSE_STATUS_DEFAULT_LABEL`).
  `resources/js/types/proxies.ts` extensions: `ProxyPermissions.canReplayProxy`;
  `ProxyListItem`/`ProxyDetail` gain `retry_attempt_limit: number | null`,
  `retry_backoff_strategy: RetryBackoffStrategy | null`; new `WebhookEventListItem`,
  `WebhookEventDetail`, `Delivery`, `DeliveryAttempt` interfaces matching T25's resource shapes
  exactly (never a `body`/`headers` field on any of them).
- **Dependencies:** T25, T26, T27, T28, T30
- **Files:** `resources/js/data/proxyPayloadStates.ts` (new),
  `resources/js/data/proxyDeliveryStates.ts` (new),
  `resources/js/data/proxyRetryBackoffStrategies.ts` (new), `resources/js/types/proxies.ts`
- **Acceptance Criteria:** each data const's value set matches its backend enum's backing values
  exactly (documented cross-reference comment, mirroring `proxyResponseStatuses.ts`'s "MUST stay
  in sync with the PHP validation set" note); `pnpm types:check` passes with the new types wired
  into every consumer added in T32–T38.
- **Testing:** none beyond `pnpm types:check`/`pnpm lint:check` — this is a non-behavioral,
  type/data-only task (no runtime logic to unit test beyond the aggregate-badge precedence
  helper, which gets a small `resources/js/data/proxyDeliveryStates.test.ts` if this project
  adds a JS test runner before T31 lands; otherwise documented as a manual-verification note per
  the walking-skeleton frontend-harness deferral, T31 to state which applies at implementation
  time).
- **Completion notes:** No JS test runner exists yet (walking-skeleton T31/`docs/status.md`
  Backlog follow-ups, still deferred — Vitest not landed), so the aggregate-badge precedence
  helper is verified by `pnpm types:check` (the closed value unions make an out-of-range branch
  a compile error) plus manual inspection; consumers of it (T35/T36) exercise it against real
  page props.

  Implemented all three data consts, mirroring `proxyResponseStatuses.ts`/
  `proxyProcessingModes.ts` exactly: `resources/js/data/proxyPayloadStates.ts`
  (`PROXY_PAYLOAD_STATES` — `retained`/`cleaned`/`never_captured`, each with a `Badge` `variant`
  extending `DataOption`, cross-referenced to `App\Enums\StoredPayloadState`);
  `resources/js/data/proxyDeliveryStates.ts` — two separate sets, since the per-destination and
  aggregate badges use different label vocabularies for the same underlying signal:
  `PROXY_DELIVERY_STATUSES` (per-destination, cross-referenced to `App\Enums\DeliveryStatus`;
  `pending` and `retrying` both render as "Retrying" — design-06 defines no separate `pending`
  badge state, and `failed` always renders "Terminally failed" since the backend only reaches it
  once the retry limit is spent) plus `proxyDeliveryStatusIsTerminal()`, and
  `PROXY_AGGREGATE_DELIVERY_STATES` (`delivered`/`retrying`/`failed`) with
  `proxyAggregateDeliveryState()` — the flagged judgment-call-2 precedence helper
  (terminal-failure beats retrying beats delivered; an all-empty statuses list — not expected,
  every event gets ≥1 original delivery row per T8 — reads as `delivered`, the vacuous default);
  `resources/js/data/proxyRetryBackoffStrategies.ts` (`PROXY_RETRY_BACKOFF_STRATEGIES` —
  `exponential`/`fixed`, cross-referenced to `App\Enums\RetryBackoffStrategy` — plus
  `RETRY_STRATEGY_DEFAULT` sentinel, `RETRY_DEFAULT_ATTEMPT_LIMIT = 5` and
  `RETRY_STRATEGY_DEFAULT_LABEL` display literals mirroring `config/retry.php`'s/
  `RetryPolicy`'s system defaults, and `proxyRetryAttemptLimitDisplay()`/
  `proxyRetryBackoffStrategyDisplay()` — the Show-page `"5 (default)"`/`"Exponential (default)"`
  formatters T33 consumes).

  `resources/js/types/proxies.ts`: added `ProxyPermissions.canReplayProxy`; `ProxyListItem`/
  `ProxyDetail` both gained `retry_attempt_limit: number | null` and
  `retry_backoff_strategy: RetryBackoffStrategy | null`; new `DispatchKind`/`AttemptStatus`
  literal-union types (mirroring their PHP enums), `DeliveryAttempt`, `Delivery`,
  `WebhookEventListItem` interfaces matching `DeliveryAttemptResource`/`DeliveryResource`/
  `WebhookEventResource` (T25) exactly — never a `body`/`headers` field on any of them — plus
  `WebhookEventDetail` as a type alias of `WebhookEventListItem` (T25 is one resource shape
  shared by both the list and detail endpoints, so there is no structural difference to express
  as a second interface; kept as a named alias rather than reusing `WebhookEventListItem`
  directly at call sites so T35/T36 each import the name matching their own page, and either can
  diverge later without disturbing the other). `Delivery.id`/`dispatch_uuid`/`next_attempt_at`/
  `attempt_limit` are `number | string | null` (the T25 legacy-fallback row sets all four to
  `null` for a pre-#6 event); `Delivery.attempts` is `DeliveryAttempt[] | null | undefined` —
  `undefined` (the key is entirely absent from the JSON) on the events **list** page, since T26's
  controller eager-loads `deliveries.destination` but not `deliveries.deliveryAttempts`, so
  `DeliveryResource`'s `whenLoaded('deliveryAttempts')` resolves to Laravel's `MissingValue` and
  the key is dropped from the response; `null` for a legacy-fallback row (explicit, both
  endpoints); a real populated array only on the event **detail** page (T27 eager-loads
  `deliveries.deliveryAttempts`). This three-way nullability is load-bearing type-shape truth,
  not a hedge — T35 must never read `.attempts` at all (list rows don't reliably carry it) and
  T36 always can.

  Verified: `pnpm types:check` (clean), `pnpm lint:check` (clean), `pnpm format:check` (clean).
  Backend unaffected (no PHP files touched): `composer lint` (Pint, passed), `composer
  types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --parallel` (**631 passed / 2156
  assertions**, unchanged from T30's baseline).

## T32 — `ProxyForm.vue`: Retry policy section + Mode help-text fix (Flow F; AC2, AC20; design-06 Screen 5)
- **Description:** New "Retry policy" section between **Processing** and **Response**, rendered
  only when `form.mode === 'enhanced'`: **Attempts** (`Input type="number" min="1" max="10"`,
  blank = default) and **Backoff strategy** (`Select`, `RETRY_STRATEGY_DEFAULT` sentinel +
  Exponential/Fixed options, per T31's const). Switching Enhanced → Simple resets both fields to
  their default-sentinel state the moment the section unmounts (`form.retry_attempt_limit = ''`,
  `form.retry_backoff_strategy = ''`) — a data operation, not a CSS toggle, so no stale value can
  ever be submitted. The existing `mode` field's help text is corrected per the PM-endorsed copy
  constraint (no roadmap numbers, no mapping-exists implication): "Enhanced mode enables
  per-proxy retry configuration below. Automatic retry itself applies to every proxy regardless
  of Mode." (or equivalent final wording within that constraint).
- **Dependencies:** T29, T30, T31
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`
- **Acceptance Criteria:** the section is absent (no fields, no placeholder) when Mode = Simple;
  present with blank/sentinel defaults on create and the proxy's saved values on edit when Mode =
  Enhanced; switching Enhanced → Simple clears in-progress values before submit is possible;
  server validation errors on either field render via `InputError` with the existing
  `aria-describedby`/focus-management wiring; the Mode help text no longer references "#8"/"#5"
  or implies mapping already exists.
- **Testing:** manual verification per the project's current frontend-harness deferral (no
  Vitest/`@vue/test-utils` harness exists yet — `docs/status.md` "Backlog follow-ups"); document
  the manual steps (create Enhanced with values → save → reload shows values; switch to Simple →
  section disappears, submit → values NULL server-side) in the Senior Developer's completion
  notes, mirroring the walking-skeleton T27 precedent. Backend persistence/clearing is already
  proven by T30's PHPUnit coverage.
- **Completion notes:** Implemented as specced. `ProxyForm.vue`: new "Retry policy" `fieldset`
  between **Processing** and **Response**, `v-if="isEnhanced"` (a computed off `form.mode`) —
  absent entirely (no fields, no placeholder) in Simple mode. **Attempts**
  (`Input type="number" min="1" max="10"`, bound to a string form field mirroring
  `response_status`'s string-field idiom, blank = default) and **Backoff strategy** (`Select`,
  `RETRY_STRATEGY_DEFAULT` sentinel + `PROXY_RETRY_BACKOFF_STRATEGIES` options from T31's const,
  via a `retryStrategySelect` computed get/set mirroring `statusSelect`'s sentinel↔`''` mapping
  exactly). A `watch(isEnhanced, ...)` clears both fields to their default-sentinel state
  (`''`) the instant it flips to `false` — a data operation, not a CSS toggle (Flow F step 4);
  `watch` without `immediate: true` never fires on mount, so an Edit page loading an
  already-Enhanced proxy with saved values is untouched. `submit()`'s `form.transform` gained the
  matching blank/sentinel → `null` normalisation for both fields, mirroring `response_status`'s.
  The `mode` field's help text replaced verbatim within the PM copy constraint: "Enhanced mode
  enables per-proxy retry configuration below. Automatic retry itself applies to every proxy
  regardless of Mode." — no roadmap numbers (`#5`/`#8`), no mapping-exists implication.

  **Files beyond T32's stated list, necessary and flagged (not silently done):** T32's Files list
  names only `ProxyForm.vue`, but `initial.retryAttemptLimit`/`initial.retryBackoffStrategy` are
  new **required** fields on `ProxyForm`'s own `initial` prop shape (present with the proxy's
  saved values on edit, per T32's own Acceptance Criteria) — `Create.vue` and `Edit.vue` are the
  only two callers that construct that object literal, so both needed the two new keys threaded
  through or the AC could not be met at all (Create: `null`/`null`; Edit: `props.proxy.retry_attempt_limit`/
  `props.proxy.retry_backoff_strategy`, both already present on `ProxyResource`/`EditProxy` since
  T30). `Edit.vue`'s local `EditProxy` interface gained the two matching fields
  (`RetryBackoffStrategy` imported from `@/types/proxies`). This mirrors the same class of
  necessary-but-unlisted-file precedent already recorded in T9/T24's completion notes — the Files
  list named the primary file, not every caller that had to change to satisfy the AC.

  **Manual verification** (frontend-harness deferral, mirroring walking-skeleton T27): (1) Create
  a proxy with Mode = Enhanced, Attempts = 8, Backoff = Fixed interval → save → reload the Edit
  page → both fields show the saved values (8 / Fixed interval), confirmed via `ProxyResource`'s
  `retry_attempt_limit`/`retry_backoff_strategy` (T30) round-tripping through `Edit.vue` →
  `ProxyForm`'s `initial` prop. (2) On that same Edit page, switch Mode to Simple → the Retry
  policy section disappears immediately (no fields, no placeholder) → Save → the proxy's stored
  `retry_attempt_limit`/`retry_backoff_strategy` are `NULL` server-side (T30's
  `prohibited_if`-plus-`?? null` mechanism, already proven by `ProxyUpdateTest`). (3) A proxy
  created with Mode = Simple never shows the section at all, on both Create and Edit. (4) A
  server-side validation error on either field (simulated via a temporarily invalid value)
  renders through `InputError` with the same `aria-describedby`/first-invalid-field focus
  handling every other field on this form already uses (no new wiring — the existing `onError`
  handler in `submit()` is untouched and already generic over any `[aria-invalid="true"]`
  element). Verified: `pnpm types:check` (clean), `pnpm lint:check` (clean), `pnpm format:check`
  (clean, after one `prettier --write` pass on the edited template). Backend unaffected: `composer
  lint` (Pint, passed), `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test
  --parallel` (**631 passed / 2156 assertions**, unchanged).

## T33 — `proxies/Show.vue`: `Events` button + `Retry policy` card (design-06 Screen 1)
- **Description:** Two additions, neither touching the existing Ingest URL / Response /
  Destinations cards: **(a)** an `Events` button (`variant="outline"`, `as-child` + `Link` to
  `proxyEventRoutes.index(...)`) as the **first** header action, before `Edit`; **(b)** a new
  `Retry policy` card **after** `Destinations`, `dl`/`dt`/`dd` pattern (third use in this app,
  after `design-03`'s Response card), showing **Attempts** and **Backoff** with a `(default)`
  annotation whenever unconfigured, and — for simple-mode proxies — the fixed-default note.
- **Dependencies:** T26, T30, T31
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:** `Events` renders as the first header action for any user who can view
  the page (no separate gate); the Retry policy card renders `5 (default)`/`Exponential
  (default)` for an unconfigured enhanced or any simple-mode proxy, and the configured values
  (e.g. `8`/`Fixed interval`) for a configured enhanced proxy, plus the simple-mode note only for
  simple-mode proxies.
- **Testing:** manual verification (same frontend-harness deferral as T32); document the three
  card states (unconfigured enhanced, configured enhanced, simple) exercised manually.
- **Completion notes:** Implemented as specced. `Show.vue`: **(a)** a new `Events` button
  (`variant="outline"`, `as-child` + `Link` to `proxyEventRoutes.index(...)`) inserted as the
  first header action, before `Edit` — rendered unconditionally (no `v-if`, matching the spec's
  "no separate gate beyond already being able to view this page," unlike `Edit`/`Delete` which
  keep their existing `canUpdate`/`canDelete` guards, both untouched). **(b)** a new `Retry
  policy` `Card` after `Destinations`, third use of the `dl`/`dt`/`dd` pattern (design-03's
  Response card, T33's spec text) — **Attempts**/**Backoff** `dd`s driven by two new `computed`s
  (`retryAttemptsDisplay`/`retryBackoffDisplay`) calling T31's
  `proxyRetryAttemptLimitDisplay()`/`proxyRetryBackoffStrategyDisplay()`, plus the simple-mode-only
  note (`v-if="props.proxy.mode === 'simple'"`). No independent fetch, no loading/error state
  beyond the page-level ones already in place (design-06 Screen 1 States note) — the card renders
  fields already on the Show payload (`ProxyResource`'s `retry_attempt_limit`/
  `retry_backoff_strategy`, T30).

  **Manual verification** (frontend-harness deferral, mirroring T32): (1) **Unconfigured
  enhanced** — an Enhanced proxy with both retry columns NULL shows `5 (default)` /
  `Exponential (default)`, no simple-mode note. (2) **Configured enhanced** — set via T32's form
  (Attempts = 8, Backoff = Fixed interval) → Show renders `8` / `Fixed interval`, no simple-mode
  note. (3) **Simple** — any simple-mode proxy (columns always NULL, T30/T29's
  `prohibited_if`) renders the same `5 (default)` / `Exponential (default)` values plus the
  "Simple-mode proxies use the fixed system default..." note. (4) The `Events` button navigates
  to `/{team}/proxies/{proxy}/events` (T26's route) for any user who can reach this Show page at
  all, independent of `canUpdate`/`canDelete`. Verified: `pnpm types:check` (clean), `pnpm
  lint:check` (clean), `pnpm format:check` (clean). Backend unaffected: `composer lint` (Pint,
  passed), `composer types:check` (PHPStan L7, 0 errors), `./vendor/bin/sail test --parallel`
  (**631 passed / 2156 assertions**, unchanged).

## T34 — `PayloadViewer` composition (Flow C, E; AC25; design-06 §Components)
- **Description:** New small composition (not a new `ui/*` primitive) built from existing tokens:
  a masked placeholder block by default, a **Reveal payload** button (`Eye` icon) that fetches
  `GET .../events/{event}/payload` on click and swaps in the response text (`whitespace-pre-wrap
  break-words`, `max-h-96 overflow-y-auto`), becoming **Hide payload** (`EyeOff` icon) — content
  is **never** included in the page's initial Inertia props (fetch-on-reveal, ADR-017 Decision
  6). Clicking Hide re-masks; navigating away and back always returns to masked (no persisted
  reveal state). `aria-pressed` reflects state; an `aria-live="polite"` `sr-only` region
  announces "Payload revealed"/"Payload hidden" (mirroring `CopyField.vue`'s pattern). Renders
  content as plain text interpolation only — never `v-html`.
- **Dependencies:** T28, T31
- **Files:** `resources/js/components/PayloadViewer.vue` (new)
- **Acceptance Criteria:** on mount, no payload bytes are present anywhere in the component's
  props/DOM; clicking Reveal issues exactly one GET to the payload endpoint and renders the
  response verbatim as text; clicking Hide re-masks without a further request; the button's
  `aria-pressed` and the live region both update on toggle.
- **Testing:** manual verification (frontend-harness deferral); document the reveal/hide flow and
  a DOM inspection confirming no payload text exists pre-reveal. Backend content-shape/hardening
  is proven by T28's PHPUnit coverage.
- **Completion notes:** Implemented as specced — a new small composition, not a `ui/*` primitive,
  built entirely from existing tokens/primitives (`Button`, `Spinner`, `Eye`/`EyeOff` from
  `@lucide/vue`, the same bordered/`font-mono`/`dark:bg-input/30` block tokens the Response-body
  block already uses). Takes a single `url` prop (the caller-built fetch-on-reveal endpoint URL —
  `proxyEventRoutes.payload(...)`'s `.url`) rather than team/proxy/event ids, keeping the
  component decoupled from routing specifics; T36 builds and passes the URL.

  **Reveal:** `fetch(props.url, { headers: { Accept: 'text/plain' } })` on click — the payload is
  fetched fresh every time and is not present in this component's reactive state (nor anywhere
  in the page's Inertia props) until this fetch resolves (ADR-017 Decision 6). `200` → the raw
  text response renders verbatim as **plain text interpolation** (`v-text`, never `v-html`) inside
  a `max-h-96 overflow-y-auto` bordered block (taller than the Response-body's `max-h-48`, per the
  spec's "raw payloads run larger" note); the button becomes **Hide payload** (`EyeOff`),
  `aria-pressed="true"`. **Hide** clears both `revealed` and the held `payload` string and
  re-masks — no persisted reveal state (component-local `ref`s only, reset on remount, i.e.
  navigating away and back). An `aria-live="polite"` `sr-only` region announces "Payload
  revealed"/"Payload hidden" on toggle, mirroring `CopyField.vue`'s exact pattern.

  **States beyond the spec's two (Reveal/Hide), added as defensive completions of the same
  requirement, not new scope:** a `loading` state (`Spinner`, button disabled) while the fetch is
  in flight — this app's established "extend Spinner to all submit buttons" convention (Login.vue
  precedent) applied to the one other async trigger in this feature; and an inline error message
  for a non-`200` response (`410` — the event was cleaned in the race window between page load and
  the click, T28's documented lifecycle response — or any other failure), which does **not** flip
  `revealed` to `true` so Reveal remains available to retry and no error text is ever mistaken for
  payload content. Neither state is speculative UI — both are direct, minimal consequences of
  "fetch on click" that the spec's own Interactions section implies (a real network call can be
  slow or fail) without spelling out.

  **Manual verification** (frontend-harness deferral): (1) On mount, the DOM contains only the
  masked placeholder string ("•••••• hidden — click Reveal to view") — no payload bytes appear in
  the component's props, reactive state, or rendered DOM until Reveal is clicked (inspected via
  Vue devtools + a DOM search for a probe string planted in a manually-triggered test event's
  body). (2) Clicking **Reveal payload** issues exactly one `GET` to the payload endpoint (Network
  tab) and renders the response verbatim; the button becomes **Hide payload**, `aria-pressed`
  flips to `true`, the `sr-only` region reads "Payload revealed". (3) Clicking **Hide payload**
  re-masks with no further request (Network tab shows nothing new), `aria-pressed` flips back,
  region reads "Payload hidden". (4) A manually forced `410` (event cleaned mid-session) renders
  the inline error, leaves the block masked, and Reveal remains clickable. Verified: `pnpm
  types:check` (clean), `pnpm lint:check` (clean), `pnpm format:check` (clean). Backend
  unaffected: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0 errors),
  `./vendor/bin/sail test --parallel` (**631 passed / 2156 assertions**, unchanged). Not yet wired
  into any page — T36 composes it into `proxies/events/Show.vue`'s Payload card.

## T35 — `proxies/events/Index.vue` (Screen 2; Flow A, E; AC15, AC16, AC23)
- **Description:** New page modeled on `proxies/Index.vue`: breadcrumb, `Table` (Received /
  Size / Content type / Payload / Delivery / Actions), pagination via the existing
  `Paginated<T>` pattern. Payload badge (Retained/Expired/Not captured, T31's const), aggregate
  Delivery badge (client-side rollup, T31's precedence helper), `View` + `Replay` actions
  (`Replay` only when Payload = Retained **and** `permissions.canReplayProxy`; a cleaned row
  shows a muted `Expired` label in its place). Empty-state card mirroring the proxies Index
  empty state. FIFO head-of-line `Alert` banner (reusing `TeamInvitationAlert.vue`'s info
  styling) when `fifoHeldByRetry` is true.
- **Dependencies:** T24, T26, T31
- **Files:** `resources/js/pages/proxies/events/Index.vue` (new)
- **Acceptance Criteria:** renders one row per event, newest first, with correct badges per
  state; empty state renders with no events; `Replay` is present only under the stated
  conditions and absent (replaced by a muted `Expired` label) for a cleaned event; the FIFO
  banner renders iff `fifoHeldByRetry` is true.
- **Testing:** manual verification (frontend-harness deferral); document the states exercised.
  Underlying data correctness is proven by T26's PHPUnit coverage.
- **Completion notes:** Implemented as specced, modeled structurally on `proxies/Index.vue`:
  breadcrumb (`Proxies > {Proxy name} > Events`, mirroring `Show.vue`'s three-level breadcrumb
  builder), `Table` with the six named columns, pagination reusing the existing `Paginated<T>`
  link-row pattern verbatim (byte-for-byte the same nav block as `proxies/Index.vue`). Payload
  badge via T31's `proxyPayloadStateOption()`; aggregate Delivery badge via a local
  `aggregateDeliveryBadge()` helper composing T31's `proxyAggregateDeliveryState()` (rollup from
  `event.deliveries.map(d => d.status)`) and `proxyAggregateDeliveryStateOption()` (label/variant)
  — the client-side rollup design-06's flagged judgment call 2 describes. `Received`/`Size` via
  the new `resources/js/lib/format.ts` (`formatTimestamp`/`formatByteSize` — no existing
  formatter anywhere in the app to reuse, design-06 Screen 2's own note; a small shared `lib/`
  helper avoids duplicating the same two formatters in T36, matching the `lib/utils.ts`
  framework-agnostic-helper convention). `View` always renders; `Replay` renders only when
  `event.payload_state === 'retained' && permissions.canReplayProxy` (`canReplay()` helper); a
  **cleaned** row shows the muted "Expired" label in the Replay slot (Flow E); a **never_captured**
  row (vocabulary-complete, not expected in practice per the spec's own note) shows neither —
  design-06 only names the cleaned-row muted-label behavior, so the not-expected third state
  renders an empty Actions slot rather than inventing an unspecified label, the minimal reading of
  an intentionally under-specified corner. Empty state mirrors `proxies/Index.vue`'s exactly (same
  `Card`/heading/helper-text shape), linking `View ingest URL` back to the proxy's Show page (no
  dedicated ingest-URL anchor exists — the whole Show page is "the ingest URL card's location").
  FIFO banner reuses `TeamInvitationAlert.vue`'s exact info styling/`Info` icon inline (not
  extracted into a shared component — one more call site doesn't yet justify one, matching the
  project's minimal-abstraction ethos), gated directly on the server-computed `fifoHeldByRetry`
  boolean (T26 already encodes both the FIFO-mode and awaiting-retry facts, so no redundant
  client-side `processing_mode` check is needed).

  **`Replay` button is a visual stub in this task, wired in T37** (T37's own dependency on T35 and
  its Files list naming this same file confirms the split is intentional): the button renders with
  the correct visibility/gating but no `@click` handler yet — T37 adds the click-to-open-
  `ReplayDialog` behavior in the very next task of this same session, so no dead code lingers
  merged without its wiring.

  **Manual verification** (frontend-harness deferral): (1) one row per event, newest-first
  (server-ordered, `latest('id')`), each badge matching its event's real state (exercised against
  a mix of retained/cleaned events with succeeded/retrying/failed deliveries). (2) empty state
  renders with a proxy that has zero events. (3) `Replay` present only for a retained event with
  `canReplayProxy = true`; absent (replaced by muted "Expired") for a cleaned event; absent (no
  label) for a retained event when `canReplayProxy = false`. (4) FIFO banner renders only when
  `fifoHeldByRetry` is `true` (a FIFO proxy with a live `awaiting_retry` row), absent for every
  Async proxy and every FIFO proxy with no such row. Verified: `pnpm types:check` (clean), `pnpm
  lint:check` (clean), `pnpm format:check` (clean, after one `prettier --write` pass). Backend
  unaffected: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0 errors),
  `./vendor/bin/sail test --parallel` (**631 passed / 2156 assertions**, unchanged).

  **File beyond T35's stated list, flagged:** `resources/js/lib/format.ts` (new) — T35's Files
  list names only `Index.vue`, but design-06 Screen 2 explicitly calls out that no existing
  timestamp/byte-size formatter exists to reuse; T36's Details card needs the identical two
  formatters, so a two-function shared helper (not a per-page duplication) was added under the
  established `lib/` framework-agnostic-helper convention (`docs/standards/coding.md`) rather than
  inlined twice.

## T36 — `proxies/events/Show.vue` (Screen 3; Flow B, C, E; AC4, AC10, AC12, AC15–AC17, AC25)
- **Description:** New page: `Details` card (`dl`/`dt`/`dd`); `Payload` card composing
  `PayloadViewer` (T34) for a retained event, the cleaned-lifecycle message for a cleaned event,
  or the not-captured message — no button in the latter two states; `Delivery` card grouping
  attempts into **Original delivery** and one **Replay — {time}** group per replay (newest
  first), each destination row showing method/URL, status badge (Delivered/Retrying/Terminally
  failed, T31's const), `Attempt N of L`, and a collapsed-by-default `Collapsible` attempt-history
  list; a page-level `Replay` header button (same visibility rule as the Index row action);
  FIFO head-of-line banner when this event's head is currently retrying.
- **Dependencies:** T27, T34, T31
- **Files:** `resources/js/pages/proxies/events/Show.vue` (new)
- **Acceptance Criteria:** the three Payload-card states render correctly with no Reveal control
  in the cleaned/not-captured states; the Delivery card correctly groups Original vs. each
  Replay batch and shows the right status badge/attempt-count per destination row; a terminally
  failed destination's row states retries are exhausted; the header `Replay` button follows the
  same visibility rule as the list.
- **Testing:** manual verification (frontend-harness deferral); document the states exercised.
  Underlying data correctness is proven by T27's PHPUnit coverage.
- **Completion notes:** Implemented as specced: `< Back to events` link, header (`Received
  {timestamp}` + Payload badge + page-level `Replay` button, same visibility rule as the Index row
  action), `Details` card (`dl`/`dt`/`dd`, Received/Size/Content type — third use of the pattern,
  matching T33's own note), `Payload` card composing T34's `PayloadViewer` for a retained event
  (URL built via `proxyEventRoutes.payload(...)`), the cleaned-lifecycle message (Flow E step 2's
  exact copy) for a cleaned event, or the not-captured message — no button in either non-retained
  state. Also added a breadcrumb (`Proxies > {proxy} > Events > {received timestamp}`) alongside
  the design's explicit "< Back to events" link — every other page in this app supplies one via
  the same `defineOptions({ layout: ... breadcrumbs })` convention (T33/T35/`Show.vue` precedent)
  and `AppLayout` always renders the breadcrumb bar, so omitting it here would be the one page
  that doesn't fit the shell, not a deliberate design choice the spec argues against.

  **Delivery card grouping (AC12 traceability).** `deliveryGroups` (a `computed`) buckets the
  flat `event.deliveries` array by `dispatch_uuid` (a pre-#6 legacy-fallback event's rows all
  share `dispatch_uuid: null`, which groups them into one synthetic "Original delivery" batch —
  exactly the single-batch shape the legacy fallback represents), labels the `kind: 'original'`
  group "Original delivery" and each `kind: 'replay'` group "Replay — {time}", and orders Original
  first then replay groups newest-first. **Spec-vs-resource-shape gap found and resolved
  (flagged, not silently patched around):** `DeliveryResource` (T25, already complete/out of this
  session's scope) carries no `created_at` on a `deliveries` row, so there is no literal "{time}"
  field to read for a replay group's label or for newest-first ordering. Resolved with two
  independent, minimal derivations rather than inventing a backend field: **(1)** the displayed
  "{time}" is the earliest `started_at` among the group's attempts across all its destinations,
  falling back to the bare label "Replay" (no time suffix) when the group has no attempts yet
  (e.g. a FIFO replay still queued behind the line — a real, reachable state, not a corner case);
  **(2)** newest-first **ordering** uses each group's highest real `Delivery.id` instead of the
  derived time — `id` is a reliable creation-order proxy (a fresh `Delivery::create()` row always
  gets a strictly higher id) that time-of-first-attempt is not, since backoff can delay a later
  replay's first attempt past an earlier replay's. This is a data-derivation judgment call within
  the Senior Developer's local-implementation-detail authority (no interface, data model, or
  ADR'd decision changed), not a backend gap serious enough to block M9 on — flagging for the
  Reviewer/Principal Engineer to judge whether `DeliveryResource` should eventually gain a real
  `created_at` (T25 is already merged; out of scope to reopen here).

  Per-destination rows: method `Badge`/URL (matching the Show page's Destinations row layout) +
  status `Badge` (T31's `proxyDeliveryStatusOption`) + a text line via `attemptSummaryFor()` —
  `Delivered {time}` for `succeeded` (the last `succeeded` attempt's `started_at`), `Attempt N of
  L — waiting for its next attempt` for `retrying`/`pending` with ≥1 attempt, `Awaiting first
  attempt` for `pending` with zero attempts yet (the same pending/retrying-bucket judgment call
  T31's completion notes already flagged, now given concrete detail-page text since design-06's
  own table has no row for a zero-attempt state), `Attempt L of L — retries exhausted` for
  `failed` (always terminal, per `DeliveryStatus`'s own docblock), and `null` (no text line at
  all) for a legacy-fallback row (`attempt_limit === null` — no attempt history is knowable, only
  the derived status). A `Collapsible` "Show N attempts" (collapsed by default, Reka UI's own
  `aria-expanded` wiring, independent per row since each is its own uncontrolled instance) lists
  each attempt's number/outcome/HTTP status/error summary/time when the delivery has ≥1 attempt
  (legacy rows have none, so no trigger renders for them, matching "an empty reveal is worse than
  no control" precedent). Event-scoped FIFO banner (`isFifoHeldByRetry`, reusing the exact Screen
  2/`TeamInvitationAlert.vue` styling) — see the flagged derivation note below.

  **`ProxyEventController@show` (T27) exposes no dedicated "is this event's FIFO head currently
  retrying" flag, flagged (not silently invented as a fake prop):** derived client-side as
  `proxy.processing_mode === 'fifo' && event.deliveries.some(d => d.status === 'retrying')` — on a
  FIFO proxy, ADR-016's own line invariant is that only one dispatch can be `retrying` at a time
  (a retrying head holds the line), so a `retrying` delivery appearing on *this* event's own
  detail page is, by construction, that held head; no other event's page could show it
  simultaneously. Same reasoning/derivation as T35's list-level `fifoHeldByRetry` prop, just
  scoped down to "does this specific event have the retrying delivery" instead of "does any event
  have one."

  **`Replay` header button is a visual stub in this task, wired in T37** — same split/rationale as
  T35's row action (T37's Files list names this same file for the wiring pass).

  **Manual verification** (frontend-harness deferral): (1) all three Payload-card states
  (retained → `PayloadViewer`; cleaned → the muted lifecycle line, no control; not-captured → the
  muted line, no control — the last exercised via a fixture proxy with a pre-#6-shaped event
  lacking any capture, since "never captured" is vocabulary-complete but not expected per T31/T35's
  own notes). (2) Delivery card correctly grouping one Original + two manually-triggered Replay
  batches (via direct DB fixtures, since T37's real dialog doesn't exist until the next task),
  newest replay first, each row's status badge/attempt-count matching its real per-destination
  state. (3) a terminally failed destination row reads "Attempt L of L — retries exhausted". (4)
  the header `Replay` button follows the same visibility rule as T35's list action (retained +
  `canReplayProxy`). Verified: `pnpm types:check` (clean), `pnpm lint:check` (clean, after fixing
  one `import/order` violation), `pnpm format:check` (clean, after one `prettier --write` pass).
  Backend unaffected: `composer lint` (Pint, passed), `composer types:check` (PHPStan L7, 0
  errors), `./vendor/bin/sail test --parallel` (**631 passed / 2156 assertions**, unchanged).

## T37 — `ReplayDialog` + wiring from Index/Show (Screen 4, Flow D; AC10–AC12, AC14)
- **Description:** New `Dialog`-based component (not `AlertDialog` — design-06's flagged,
  PM-accepted call): destination checklist with tri-state **Select all**, none pre-checked,
  count-bearing **Replay to N destination(s)** confirm (disabled at N = 0 or while submitting),
  the plain-language "sends real traffic again" statement, a conditional FIFO note (join-the-back
  phrasing), and an inline error region for request-level failure (dialog stays open, selection
  retained). On success: dialog closes, a Sonner toast confirms, the underlying page reflects the
  new Replay group on next render. Wired from both the Index row's `Replay` action and the Show
  page's header `Replay` button (T35/T36 both trigger this same component).
- **Dependencies:** T24, T31, T35, T36
- **Files:** `resources/js/components/ReplayDialog.vue` (new),
  `resources/js/pages/proxies/events/Index.vue`, `resources/js/pages/proxies/events/Show.vue`
- **Acceptance Criteria:** Confirm is disabled with 0 selected and enables/updates its count
  label as checkboxes/Select-all toggle; a successful replay closes the dialog, shows the toast,
  and the page reflects the new Replay group without a full navigation; a request-level failure
  keeps the dialog open with an inline error and the prior selection intact; re-opening after a
  close (success or cancel) always resets to nothing-checked; the FIFO note renders only for a
  FIFO proxy.
- **Testing:** manual verification (frontend-harness deferral); document the full flow (open →
  select → confirm → success, and open → confirm → failure → retry) exercised against T24's real
  endpoint. Backend correctness is proven by T24's PHPUnit coverage.
- **Completion notes:** Implemented `ReplayDialog.vue` (plain `Dialog`, per design-06's flagged
  call) — destination `fieldset`/`legend`, a tri-state **Select all** `Checkbox`
  (`false`/`true`/`'indeterminate'`, reka-ui's native tri-state support) plus one `Checkbox` per
  current destination (none pre-checked, `useForm({ destinations: [] })`), a count-bearing
  **Replay to N destination(s)** `Button` (disabled at `N === 0` or `form.processing`), the
  plain-language warning sentence, a conditional FIFO `Alert` (join-the-back phrasing, same info
  styling as the Screen 2/3 banners), and an inline `AlertError` region for a request-level
  failure. `watch(() => props.open, ...)` calls `form.reset()`/`form.clearErrors()` on every
  close (success, Cancel, or Esc/outside-click — `Dialog`'s own close paths all funnel through
  `update:open`), so re-opening always starts nothing-checked, matching the spec's own reasoning
  ("selections are not remembered between opens"). Wired identically from both callers: `Index.vue`
  gained `replayDialogOpen`/`replayEventId` refs and an `openReplay(event)` handler on the row's
  `Replay` button (T35's stub now wired); `Show.vue` gained a `replayDialogOpen` ref and the same
  handler inline on the header `Replay` button (T36's stub now wired) — both pass
  `props.proxy.destinations`/`props.proxy.processing_mode === 'fifo'` straight through.

  **Two spec-vs-reality tensions found and resolved, both flagged rather than silently patched
  around:**

  **(1) `ProxyEventController@index` (T26, already merged) never eager-loaded `destinations` —
  the events Index page's `proxy` prop had no data for this dialog's checklist at all.** T26's
  `'proxy' => ProxyResource::make($proxy)` (no `loadMissing`) meant `ProxyResource`'s conditional
  `'destinations' => DestinationResource::collection($this->whenLoaded('destinations'))` resolved
  to Laravel's `MissingValue` and the key was entirely absent from the JSON on the Index page —
  correctly typed as `ProxyListItem` (no `destinations` field) by T31. AC10 requires the
  checklist to offer the proxy's **current** live destinations; deriving them from
  `event.deliveries[].destination` instead (data already on the page) would have been wrong on
  two counts — a destination added after this event's capture wouldn't appear (deliveries only
  exist for destinations live at capture/replay time), and a since-trashed destination *would*
  appear (deliveries load `withTrashed()`) — the exact opposite of "current." The only correct
  fix is eager-loading `destinations`, so `app/Http/Controllers/ProxyEventController.php`'s
  `index()` gained `$proxy->loadMissing('destinations')` (one line, additive, mirrors T27's own
  `show()` which already does this) and `Index.vue`'s `proxy` prop type changed from
  `ProxyListItem` to `ProxyDetail` (the exact resulting shape — every `ProxyListItem` field plus
  `destinations`). This is judged a load-bearing wiring fix within the Senior Developer's local-
  implementation-detail authority (same class as T24's `childRouteBindingRelationshipName()`
  override) — no interface, data model, or ADR'd decision changed, `ProxyResource`'s conditional
  `destinations` field already existed and is unchanged, and the existing
  `ProxyEventIndexTest` suite (no assertions on the `proxy` prop's shape) stayed green unmodified.
  Flagging for the Reviewer since it touches a completed backend task's file outside this
  session's stated frontend-only scope, but leaving it undone would leave T37's own AC10
  unsatisfiable from the Index page.

  **(2) T24's already-implemented backend is a full PRG redirect to `proxies.show` on success —
  T37's own AC ("the page reflects the new Replay group without a full navigation") cannot be
  literally satisfied without reopening T24's completed scope, which this session's brief places
  out of bounds.** `ProxyEventReplayController@store` (T24, already merged and tested) ends with
  `return to_route('proxies.show', ['proxy' => $proxy->id])` — plan-06 line 281 itself specifies
  "PRG + flash toast" for this endpoint, so this is the Principal-Engineer-designed, already-
  ratified contract, not an oversight. Inertia's `router`/`useForm().post()` has no way to submit
  to this real endpoint and *not* follow a redirect it returns — there is no JSON/no-navigation
  response variant to opt into, and inventing one would mean changing `ProxyEventReplayController`
  (a completed backend task, T24, outside this session's M9-frontend-only brief and outside a
  "local implementation detail"). **Implemented against the real, working contract as-is:**
  `form.post()` to the real endpoint exactly as every other mutating Inertia form in this app does
  (`ProxyForm.vue`, `RemoveMemberModal.vue`); a successful replay **does** close the dialog and
  **does** show the toast (the global `flash.toast` listener, `app.ts`, fires on any navigation
  regardless of destination page) — but the browser then lands on the Proxy **Show** page, not the
  Events Index/Show page the user was on, exactly like every other create/update/delete action in
  this app (`ProxyController`, `DestinationController` all redirect to `proxies.show` on success,
  the same established PRG convention, not a one-off deviation). This is the one clause of T37's
  own AC this implementation cannot satisfy without backend changes outside this session's scope;
  the **request-level-failure** half of the same AC (dialog stays open, inline error, selection
  retained) **is** fully and correctly satisfied as specced, because Inertia's `422`
  `ValidationException` handling (the real, only-implemented failure path — AC15's expired-event
  race, surfaced under the `event` key) redirects **back** to the referring page without ever
  swapping the page component, which is exactly "stays open" from the user's perspective. Flagging
  for the Reviewer/Principal Engineer to judge whether T24 should gain a lighter response for this
  specific caller (e.g. an Inertia partial reload / `back()` instead of `to_route('proxies.show')`)
  or whether the AC should be corrected to match the app's established PRG pattern — this is a
  plan-vs-task-AC tension discovered during implementation, not a defect introduced here.

  **Manual verification** (frontend-harness deferral, against T24's real endpoint, no mocking):
  (1) Confirm is disabled at 0 selected, reads "Replay to 0 destinations"; checking individual
  boxes or **Select all** updates the count live; **Select all** shows `indeterminate` when
  some-but-not-all are checked. (2) A successful replay (retained event, live destinations
  selected) closes the dialog, the success toast renders ("Replay started." — T24's exact flash
  message), and the browser lands on the proxy's Show page (per tension (2) above — verified this
  is the real, current, unavoidable outcome, not a bug introduced by this task). (3) Replaying an
  event that expires in the race window between page load and submit (simulated by manually
  clearing the event's `payload_cleaned_at` mid-session) keeps the dialog open, renders the inline
  `AlertError` with the server's message, re-enables Confirm, and the prior checkbox selection is
  untouched. (4) Closing (Cancel, Esc, or a successful replay) and re-opening always starts with
  nothing checked. (5) The FIFO note renders only when the triggering proxy's `processing_mode`
  is `fifo`, absent for Async. Verified: `pnpm types:check` (clean, after typing the `event`-key
  request error through `form.errors` as `Record<string, string>` since it isn't one of this
  form's own declared fields), `pnpm lint:check` (clean), `pnpm format:check` (clean, after one
  `prettier --write` pass). Backend: `composer lint` (Pint, passed), `composer types:check`
  (PHPStan L7, 0 errors), `./vendor/bin/sail test --parallel` (**631 passed / 2156 assertions**,
  unchanged — the `ProxyEventController` eager-load addition is purely additive and
  `ProxyEventIndexTest`'s 9 cases stayed green unmodified, individually re-verified via
  `./vendor/bin/sail test --filter ProxyEventIndexTest`, 9/9 passed).

  This completes **M9** (T31–T37, frontend). T38–T46 (M10, acceptance tests and quality sweep) are
  out of scope for this session per the assigning task's instructions.

---

## M10 — Acceptance tests and quality sweep

## T38 — Retry engine acceptance tests (AC1–AC3, AC7)
- **Description:** End-to-end proof, over the real wired retry engine (T10–T15), of the
  automatic-retry requirements — complementing the unit-level tests already embedded in T11–T15.
  No new production code expected; fix any wiring gap found.
- **Dependencies:** T10, T11, T13, T14, T15
- **Files:** `tests/Feature/Retry/RetryEngineAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - A failed attempt on a **simple**-mode proxy schedules attempt 2 under the system default
    (limit 5, exponential first delay) — AC1's every-proxy baseline.
  - An **enhanced** proxy with `retry_attempt_limit = 2`/`fixed` stops after attempt 2 with
    constant delays; unset columns fall back to 5/exponential (AC2).
  - Two destinations, one fails: only the failed one is retried; the succeeded one gains no new
    attempts (AC3).
  - Each retry writes a new `DeliveryAttempt` row with incremented `attempt_number`, the same
    `delivery_id`, payload-free, firing the existing `DeliveryAttempted`/outcome events (AC7).
  - A duplicate `RetryDelivery` execution for the same `(delivery_id, attempt_number)` produces
    exactly one attempt row (unique-key dedupe, #4 AC9 parity); `SweepDueRetries` re-drives an
    overdue delivery whose delayed job was lost.
  - Mid-flight policy change: lowering the limit below the executed count terminalizes at the
    next failure; raising it extends the schedule (plan ruling 1).
  - A soft-deleted destination mid-schedule still executes and settles its retry (plan ruling 2).
- **Testing:** the cases above via `Http::fake()`, `Queue::fake()`/`Bus::fake()`, `travel()`.
- **Completion notes:** Added `tests/Feature/Retry/RetryEngineAcceptanceTest.php` (10
  tests), exercising the real `ProcessIngestedWebhook` → `DeliverStep` →
  `DeliverToDestination` → `RetryDelivery`/`SweepDueRetries` chain — no new production
  code needed, all seven bullets hold as implemented. Proxies use
  `ProcessingMode::Fifo` purely to get an inline first attempt (`DeliverStep`'s Fifo
  branch calls `DeliverToDestination::run()` directly, unaffected by `Queue::fake()`),
  orthogonal to the simple/enhanced retry-config axis under test — Async-mode coverage
  of the same engine already exists (`AsyncDispatchAcceptanceTest`). Covered: AC1
  system-default schedule (limit 5, exponential ≈60s first delay) on a simple proxy;
  AC2 enhanced `limit=2`/fixed stopping at attempt 2 with the configured fixed delay,
  and enhanced-with-unset-columns falling back to 5/exponential; AC3 two-destination
  independence (failed one alone retries to its own terminal state, succeeded one gets
  no extra attempts); AC7 incremented `attempt_number` per retry, same `delivery_id`,
  no payload column, `DeliveryAttempted`/`DeliveryFailed`/`DeliveryExhausted` firing
  counts; the unique-key dedupe (`RetryDelivery::handle()` called twice for the same
  `(delivery_id, attempt_number)` yields one attempt-2 row); `SweepDueRetries`
  re-driving an overdue `retrying` delivery whose delayed job was lost; mid-flight
  policy change both directions (lowering the limit below the executed count
  terminalizes at the next failure; raising it before a would-be terminal failure
  extends the schedule, proven via a fresh `RetryDelivery` dispatch for the next
  attempt); a soft-deleted destination mid-schedule still executing and settling via
  `withTrashed()`.
  **Non-blocking observation, no code change:** manually pacing multi-hop `Fixed`-
  strategy cascades exposed that `DeliverToDestination::transition()`'s
  compare-and-set reads its "did I win" signal from the PDO/MySQL default
  affected-rows count, which counts rows whose stored **values actually changed**,
  not rows the `WHERE` **matched**. A `retrying → retrying` continue-schedule CAS
  whose new `next_attempt_at` happens to round to the same stored second as the
  already-persisted value (only possible when two schedule computations land within
  the same wall-clock second) is silently treated as "another settler already won" and
  drops the scheduled dispatch. This is unreachable in production — real retries are
  always genuinely separated by their configured delay via the actual queue's delayed
  execution — and was only surfaced here by the sync-queue test driver's zero-delay
  cascade collapsing successive `now()` calls to milliseconds apart. Worked around in
  these tests with `travel()` pacing (mirroring the Testing note's own guidance) rather
  than a production fix, since the only real fix (`PDO::MYSQL_ATTR_FOUND_ROWS` on the
  `mysql`/`mariadb` connections) would change affected-rows semantics for every
  compare-and-set across #1/#4/#5/#6 — reopening ADR-005/011/015/016's binding CAS
  invariant, not a T38-scoped change. Flagging for principal-engineer awareness only;
  no action taken.
  Verified: `./vendor/bin/sail test --filter RetryEngineAcceptanceTest` (10 passed, 41
  assertions); full suite `./vendor/bin/sail test --parallel` (641 passed, 2197
  assertions); `composer lint` (Pint clean); `composer types:check` (PHPStan level 7,
  0 errors).

## T39 — Terminal state & event acceptance tests (AC4, AC5)
- **Description:** End-to-end proof of the explicit terminal state and its event, complementing
  T13's unit-level cases.
- **Dependencies:** T13, T14
- **Files:** `tests/Feature/Retry/TerminalStateAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - After the limit, the delivery row reads `failed`, `next_attempt_at` NULL, and no further
    attempt is ever created (`travel()` past all schedules, run `SweepDueRetries` — zero new
    rows).
  - `DeliveryExhausted` fires exactly once per exhausted delivery — including under a racing
    duplicate settle — carrying team/proxy/destination/event-reachable state (AC5).
  - A terminal delivery remains visible on the read surface (T25/T27) and the event stays
    replayable while retained (AC4).
- **Testing:** the cases above via `Event::fake()`, `travel()`, and the read-surface resources.
- **Completion notes:** Added `tests/Feature/Retry/TerminalStateAcceptanceTest.php` (3 tests),
  exercising the real `ProcessIngestedWebhook` → `DeliverToDestination`/`RetryDelivery`/
  `SweepDueRetries` chain and the real `ProxyEventController`/`ProxyEventReplayController`
  routes — no production code needed, all three bullets hold as implemented. Covered: an
  enhanced FIFO proxy (`limit=2`/fixed) reaching `failed`/`next_attempt_at = null` at the
  limit, then `travel()`-ing far past any conceivable further schedule and running
  `SweepDueRetries` — zero new `DeliveryAttempt` rows and no `RetryDelivery` push for an
  attempt 3 (the sweeper's own `WHERE status = retrying` query structurally excludes a
  terminal delivery, not merely skips it by chance); `DeliveryExhausted` firing exactly once
  under a realistic racing duplicate settle at the actual job entry point — two
  `RetryDelivery::handle()` calls for the same terminal `(delivery, attempt_number)` (the
  sweeper and the delivery's own still-live delayed job both firing), where the first call
  terminalizes and fires the event and the second reloads a no-longer-`retrying` delivery and
  is a structural no-op via `RetryDelivery`'s own early-return guard — carrying
  team/proxy/destination/event all reachable off the event's `Delivery $delivery` payload
  (`->team_id`, `->proxy`, `->destination`, `->webhookEvent`); a terminal delivery rendering on
  both `GET .../events` (list) and `GET .../events/{event}` (detail) with `status = 'failed'`,
  and a subsequent replay POST to the same still-retained event succeeding (302), proving a
  terminal delivery does not itself block replay eligibility (AC15 governs only the cleaned
  case). No defect found. Verified: `./vendor/bin/sail test --filter TerminalStateAcceptanceTest`
  (3 passed, 37 assertions); full suite `./vendor/bin/sail test --parallel` (644 passed, 2234
  assertions); `composer lint` (Pint clean); `composer types:check` (PHPStan level 7, 0 errors).

## T40 — FIFO composition acceptance tests (AC6)
- **Description:** End-to-end proof of `awaiting_retry` line-holding, settlement, and the stuck-
  hold release, complementing T16–T18's unit-level cases; also proves the existing #4 correctness
  primitives are undisturbed.
- **Dependencies:** T16, T17, T18
- **Files:** `tests/Feature/Retry/FifoRetryCompositionAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - Head's first attempt fails inline → fifo row is `awaiting_retry`, no lease; the next pending
    event is **not** claimed; the sweeper's reaper and nudge both leave the held line alone.
  - Retry succeeds → row `settled`, advancer dispatched, next event claimed — in order.
  - Retry exhausts → row `settled` (never `dead_lettered`), the line advances past the poison
    head; the terminal fact lives on `deliveries` (plan ruling 5).
  - Multi-destination head: line held until the last delivery is terminal; racing settlers
    produce one settle (CAS), no double-advance.
  - Stuck-hold release: an `awaiting_retry` row whose deliveries are all terminal (simulated
    crash) is settled and nudged by the sweep pass.
  - Async proxy: two events' retries interleave freely; neither delays the other.
  - Order key: capture-created rows still process in received order; a replay row on a FIFO
    proxy processes **after** all previously pending events (AC11 join-at-back).
  - The existing #4 suites (claim atomicity, lease reap, idempotent settle) pass green with only
    the deliberate order-key/unique-key updates enumerated in T5/T6/T16.
- **Testing:** the cases above; `Queue::fake()` to prevent self-dispatch recursion inline
  (mirroring `FifoLivenessAcceptanceTest`'s step-by-step-advance pattern).
- **Completion notes:** Added `tests/Feature/Retry/FifoRetryCompositionAcceptanceTest.php` (8
  tests), driving the real `AdvanceProxyFifoQueue` / `RetryDelivery` / `SweepStalledFifoDispatches`
  chain over a `fifoProxyWithPending()` fixture mirroring `FifoLivenessAcceptanceTest`'s shape. No
  production defect found; all eight bullets hold as implemented. Covered: the head's first
  attempt failing inline holding the line (`awaiting_retry`, no lease), the next pending row
  staying unclaimed with zero delivery work, and the sweeper's reaper/nudge both leaving the held
  line untouched; a successful retry settling the row (`settled_at` set) and nudging the advancer
  to claim the next row only after, never before; an exhausted retry settling the row (asserted
  against `FifoDispatchStatus::cases()` directly — no `dead_lettered` case exists at all) with the
  terminal fact on `deliveries` and the line advancing past the poison head; a multi-destination
  head staying held while one delivery is still retrying and settling exactly once (one CAS, one
  nudge) once the last delivery terminalizes; a genuine race against an alternate settler (the
  fifo row already flipped to `settled` by a simulated concurrent process before a second
  delivery's own real completion runs) proving that later completion's CAS is a true no-op; the
  T18 stuck-hold release (an `awaiting_retry` row whose only delivery is already terminal —
  simulating the crash window between a delivery settling and its normal fifo-row transition)
  being settled and nudged by `SweepStalledFifoDispatches`; an Async proxy's two events each
  running their own full retry cascade to their own terminal state with zero `fifo_dispatches`
  rows ever created and each one's attempt count/status completely unaffected by the other having
  run; and the order key — two capture-created rows processing in `id` order, with a replay row
  created afterward processing only once both captures have settled (AC11 join-at-back). The
  existing #4 correctness suites (`FifoLivenessAcceptanceTest`, `AdvanceProxyFifoQueueTest`,
  `SweepStalledFifoDispatchesTest`, `FifoDispatchTest`) all pass green, unmodified, in the full
  suite run below.

  **Test-authoring pitfall found and worked around (no production code affected):** `Http::fake()`
  called a second time in the same test with the array/URL-pattern form does **not** replace the
  previously registered stub — `Factory::fake()`'s array branch calls `stubUrl()` per entry, which
  itself calls `fake()` again with a closure that gets **merged** onto `$stubCallbacks`, never
  cleared; the **first**-registered `'*'` stub wins for every subsequent request. Two tests needed
  "fails once (or twice), then succeeds" within a single test method (the successful-retry and
  multi-destination cases) — fixed by using a single `Http::fakeSequence()->pushStatus(500)->...
  ->whenEmpty(Http::response('ok', 200))` instead of two sequential `Http::fake([...])` calls.
  Documented inline at each use site; noted in project memory (`testing_patterns.md`) for future
  test-writing.

  Verified: `./vendor/bin/sail test --filter FifoRetryCompositionAcceptanceTest` (8 passed, 48
  assertions, re-run 3× with no flakiness); full suite `./vendor/bin/sail test --parallel` (652
  passed, 2282 assertions); `composer lint` (Pint clean); `composer types:check` (PHPStan level 7,
  0 errors).

## T41 — Replay acceptance tests (AC9–AC14)
- **Description:** End-to-end proof of manual replay through the real endpoint (T24) and the
  real pipeline, complementing T24's own controller-level tests.
- **Dependencies:** T21, T24
- **Files:** `tests/Feature/Replay/ReplayAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - Replay of a retained event to a subset dispatches to exactly those destinations; to all via
    select-all; never to a trashed destination or another proxy's destination id (422) (AC10).
  - Replay runs through the real pipeline (`CaptureDispatchedStep` executes idempotently;
    `PipelineContext.dispatchUuid` = the replay uuid) and produces `deliveries` rows `kind =
    replay` traceable to the event, with attempts chained via `delivery_id` (AC11, AC12).
  - Replay works on simple **and** enhanced proxies (AC9).
  - A failed replay delivery retries under the proxy's policy and can terminalize +
    `DeliveryExhausted` (AC13).
  - Upstream response contract untouched: replay never produces an ingest response; ingest
    behaviour is unaffected by any retry state (AC8).
  - Permission: Owner/Admin/Member can all replay, including a Member on a proxy they did not
    create; a non-member 403s/404s; the policy is permission-based, not role-named (AC14).
  - A redelivered replay-processing job creates no duplicate delivery rows or attempts.
- **Testing:** the cases above via a real ingest → replay → assert flow, `Http::fake()`,
  `Queue::fake()`/`Bus::fake()`.
- **Completion notes:** Added `tests/Feature/Replay/ReplayAcceptanceTest.php` (13 tests) driving
  the real `POST .../events/{event}/replay` endpoint end to end.

  **Defect found and fixed (AC10).** A subset-destination replay (e.g. 2 of 3 live destinations
  selected) dispatched to **all three** destinations, not just the two selected — the third,
  unselected destination silently received its own `Delivery` row (wrongly `kind = original`) and
  a real outbound HTTP attempt. Root cause: `ProcessIngestedWebhook::handle()`'s per-live-destination
  `firstOrCreate` backfill loop (introduced by **T8**, titled "original delivery-row creation",
  designed only for the original ingest dispatch where `dispatchUuid` always equals `ingestId`) ran
  **unconditionally** regardless of dispatch identity. **T24** (replay controller) reuses this same
  action for the Async dispatch path, passing a fresh, distinct replay `dispatch_uuid` — the loop
  then "helpfully" backfilled a row for every live destination the replay controller had NOT
  already created, defeating the user's subset selection. Neither T8 nor T24's own tests caught it:
  T8's tests only ever call the single-arg `ProcessIngestedWebhook::run($ingestId)` form (dispatchUuid
  defaults to ingestId, so the loop is always correctly scoped there), and T24's own subset test
  (`ProxyEventReplayControllerTest::test_replaying_a_subset_creates_matching_delivery_rows_...`) uses
  `Queue::fake()`, which captures the dispatch and never actually executes `ProcessIngestedWebhook`'s
  body — the bug is invisible unless the replay's queued job genuinely runs (which this task's
  Testing note explicitly required: a real ingest → replay → assert flow, not a faked one). **Fix**
  (`app/Actions/ProcessIngestedWebhook.php`): gated the backfill loop on `$dispatchUuid ===
  $ingestId` — the original dispatch's own identifying shape (T7/T8) — so a replay's distinct
  `dispatch_uuid` never triggers it; the replay controller's own pre-created rows for the chosen
  subset are untouched and are all `DeliverStep` ever sees for that dispatch. Minimal, scoped to the
  one loop; no interface/behavior change for the original-ingest path (still covered by T8's own
  tests, all still green).

  Also asserted, per the task's flagged open item: a successful replay's redirect target is
  literally `proxies.show` (a full PRG navigation) — the T37/T24 conflict (T37's AC wanted no full
  navigation on a successful replay) is unresolved and is asserted here as the endpoint's real
  current behaviour, not silently changed either direction.

  Covered: AC10 subset/select-all/trashed+foreign-rejection; AC11/AC12 the real pipeline running
  end to end (`CaptureDispatchedStep`'s `webhook_event_id`-unique idempotent update on a
  already-dispatched enhanced-mode event, the replay's own distinct `dispatch_uuid` never equal to
  the original `ingest_id`, `kind = replay`, attempts chained via `delivery_id` and traceable via
  `ingest_id`); AC9 simple/enhanced data-provider; AC13 a `limit=1` replay terminalizing on its own
  first attempt with `DeliveryExhausted` carrying `kind = replay`; AC8 the replay's PRG redirect
  vs. the ingest endpoint's own 202 response, and a fresh ingest against the same proxy succeeding
  normally while the replay's delivery is still `retrying`; AC14 all three roles (data-provided)
  replaying a proxy they did not create, and a non-member 404ing; a redelivered replay-processing
  job (`ProcessIngestedWebhook::run()` called again for the same dispatch_uuid) creating no
  duplicate delivery rows or attempts.

  **Test-authoring notes (no further production changes):** three tests needed adjusting away from
  an initial draft that used `Queue::fake()` while also needing the replay's own queued dispatch to
  actually execute — `Queue::fake()` freezes an Async proxy's `dispatch()->afterCommit()` (both the
  first attempt AND any retry), so a test needing one controlled real attempt uses a FIFO proxy
  (inline first attempt, mirroring `RetryEngineAcceptanceTest`'s established pattern) or simply
  omits `Queue::fake()` when the whole cascade is safe to let run inline (e.g. `limit=1`, or
  `Http::fake` always-200 with no retry concern).

  Verified: `./vendor/bin/sail test --filter ReplayAcceptanceTest` (13 passed, 53 assertions); full
  suite `./vendor/bin/sail test --parallel` (665 passed, 2335 assertions); `composer lint` (Pint
  clean); `composer types:check` (PHPStan level 7, 0 errors).

## T42 — Retention interplay acceptance tests (AC15–AC18)
- **Description:** End-to-end proof that #6's dispatch forms honor the #5 retention contract,
  complementing T19's unit-level H5 cases and #5's existing retention suites.
- **Dependencies:** T19, T20, T14, T24
- **Files:** `tests/Feature/Retention/RetryReplayRetentionInterplayAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - Replay of a **cleaned** event: validation-error path, zero delivery rows, zero attempts,
    zero HTTP sends (AC15, AC17).
  - Race: GC erases between page load and the replay POST — the in-transaction `lockForUpdate`
    re-check rejects; conversely, replay rows committed first hold the erase (the erase's
    compare-and-set affects zero rows) (AC17/AC18).
  - H5: an expired event with a `retrying` delivery is not erased; with only terminal deliveries
    it **is** erased on the next pass; a `pending` delivery holds only within the dispatch
    horizon (AC18).
  - `RetryDelivery` meeting a cleaned parent (the H4-residual race): terminalizes, emits
    `DeliveryExhausted`, sends nothing, logs identifiers only (AC17).
  - The three payload states render distinctly from `payload_cleaned_at` on every #6 read path —
    including `never_captured` for an unknown ingest id — never inferred from `body` (AC16).
- **Testing:** the cases above, reusing #5's `DB::listen()`-based fault-injection technique for
  the race cases.
- **Completion notes:** Added `tests/Feature/Retention/RetryReplayRetentionInterplayAcceptanceTest.php`
  (7 tests), complementing T19's unit-level `PurgeExpiredPayloadsTest` H5 cases and #5's existing
  `RetentionInFlightHoldsAcceptanceTest`/`CleanedStateReaderGuardAcceptanceTest` by driving the
  real `ProcessIngestedWebhook`/`RetryDelivery`/replay-endpoint chain against `PurgeExpiredPayloads`,
  rather than constructing `deliveries` rows directly. No production defect found; all five bullets
  hold as implemented. Covered: a replay POST against a cleaned event 422s with zero `Delivery`
  rows, zero attempts, zero HTTP sends (AC15/AC17); the existing GC-erases-first race (mirroring
  `ProxyEventReplayControllerTest`'s own `DB::listen()` technique, reproduced here as part of this
  suite's own closure) and its converse — a replay's `retrying` delivery committed first holds an
  otherwise-eligible erase, the compare-and-set affecting zero rows (AC17/AC18); H5 proven over a
  real retry cascade end to end: an expired event with a genuine `retrying` delivery survives a GC
  pass, then once that same delivery is driven to its real terminal `failed` state the very next
  GC pass erases it (AC18); H5's `pending`-younger-than-horizon-holds /
  older-than-horizon-does-not-hold pair, mirroring H4's shape for the `deliveries` table; the
  H4-residual race T14's Description names — `RetryDelivery` meeting a cleaned parent — reproduced
  over a genuinely real `retrying` delivery (not a pre-cleaned factory row) with the parent forced
  cleaned directly (H5 closes this window under normal GC operation, so no normal
  `PurgeExpiredPayloads` pass can ever produce it for real; documented in the test as the only way
  to observe the defensive guard), terminalizing, sending nothing further (one recorded request
  total, from the real attempt 1 that already ran), emitting `DeliveryExhausted` once, and logging
  `payload.expired` with identifiers only; the three payload states (`retained`/`cleaned`/
  `never_captured`) rendering distinctly across the events list, event detail, and payload
  endpoint — `never_captured` is structurally unreachable through those three real routes (each
  passes its own row's `ingest_id` to `StoredPayloadLookup`), so it is asserted directly against
  the shared resolver instead, which is what every #6 read path composes through; noted inline
  rather than silently worked around. Two tests needed `Queue::fake()` (mirroring
  `RetryEngineAcceptanceTest`'s documented pattern) to freeze a delayed retry attempt for
  controlled manual invocation — without it the sync queue driver's zero-delay inline cascade
  drains the whole schedule to a terminal state within one call, making the intermediate
  "outstanding retry" state unobservable. Verified: `./vendor/bin/sail test --filter
  RetryReplayRetentionInterplayAcceptanceTest` (7 passed, 53 assertions); full suite
  `./vendor/bin/sail test --parallel` (**672 passed / 2388 assertions**, up from 665/2335);
  `composer lint` (Pint clean); `composer types:check` (PHPStan level 7, 0 errors).

## T43 — Read surface & reveal acceptance tests (AC22, AC25; PRD-05 AC16)
- **Description:** End-to-end proof of the three read routes and the payload endpoint,
  complementing T25–T28's per-task tests.
- **Dependencies:** T25, T26, T27, T28
- **Files:** `tests/Feature/ProxyEvents/ReadSurfaceRevealAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - List and detail props contain **no** `body`/`headers` for any state (AC22/AC25 fetch-on-
    reveal).
  - Payload endpoint: retained ⇒ exact bytes, `text/plain`, `nosniff`, `no-store`; cleaned ⇒
    410; unknown ⇒ 404; cross-team/other-proxy event id ⇒ 404 (scoped binding); unauthenticated
    ⇒ redirect; a member with view permission succeeds (no distinct reveal permission).
  - Events list paginates newest-first with descriptor fields; empty state renders.
  - `fifoHeldByRetry` prop is true iff a FIFO proxy has an `awaiting_retry` row.
  - A pre-#6 event (attempts, no `deliveries`) renders the derived per-destination state with no
    error (ruling 3).
- **Testing:** the cases above, composing the real controllers/resources end to end.
- **Completion notes:** Added `tests/Feature/ProxyEvents/ReadSurfaceRevealAcceptanceTest.php`
  (6 tests), complementing T25-T28's own per-task test files by composing the three read routes
  and the payload endpoint together rather than one route/case at a time. No production defect
  found; all five bullets hold as implemented. Covered: list AND detail together never emit
  `body`/`headers` under either state (retained or cleaned), asserted both via `->missing()` on
  the Inertia props and a raw-response `assertStringNotContainsString()` against a body marker
  (belt-and-suspenders against a future prop leak that `missing()` alone wouldn't structurally
  catch); the payload endpoint's full matrix in one place — retained (exact bytes, the three
  documented headers), cleaned (410), an unknown event id for the proxy (404), another proxy's
  event id on the same team (404), another team's event id entirely (404), and unauthenticated
  (redirect to login, asserted **before** any `actingAs()` call in the same test — the test
  client's authentication persists across requests within one test once set, so ordering here is
  load-bearing, not incidental); a Member who did **not** create the proxy successfully reveals
  its payload (no distinct reveal permission beyond the existing view gate, AC25); the events list
  paginates newest-first with the documented descriptor fields (`method`, `content_type`,
  `byte_size`, `received_at`) and a genuinely proxy-scoped empty state (`events.data` count 0,
  `events.total` 0) renders without error; `fifoHeldByRetry` proven `false` then `true` across a
  **real** retry cascade (`AdvanceProxyFifoQueue::run()` driving a head's first attempt to fail
  for real, landing the `fifo_dispatches` row on `awaiting_retry`) rather than a directly-created
  row, differentiating this from T26's own unit-shaped true/false/Async cases; the legacy fallback
  (ruling 3) proven with **three** destinations spanning all three `delivery_attempts` outcomes
  (succeeded/failed/dispatched) simultaneously, on **both** the list and detail routes, with no
  exception and zero `deliveries` rows created either way — T25/T27's own tests only ever cover
  one destination/outcome at a time. **One derived-data gap flagged per the task notes, asserted
  as current real shape, not silently added:** `DeliveryResource` carries no `created_at` — the
  first test's final assertion (`->missing('event.deliveries.0.created_at')`) makes this explicit
  rather than leaving it merely absent-by-omission. The other named gap (T27 exposing no
  event-scoped FIFO-held flag) is structural — `fifoHeldByRetry` is a page-level, not per-event,
  prop, confirmed by reading `ProxyEventController` — nothing to assert beyond what this suite's
  `fifoHeldByRetry` test already proves at the page level. Verified: `./vendor/bin/sail test
  --filter ReadSurfaceRevealAcceptanceTest` (6 passed, 109 assertions); full suite
  `./vendor/bin/sail test --parallel` (**678 passed / 2497 assertions**, up from 672/2388);
  `composer lint` (Pint clean); `composer types:check` (PHPStan level 7, 0 errors).

## T44 — Proxy form & policy acceptance tests (AC2, AC20)
- **Description:** End-to-end proof of the retry-policy form/persistence surface, complementing
  T29–T30's per-task tests.
- **Dependencies:** T29, T30
- **Files:** `tests/Feature/Proxies/RetryPolicyFormAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - Store/update accept limit 1–10 + strategy on enhanced; reject 0/11/unknown strategy; reject
    any value when `mode = simple`; switching enhanced→simple on update clears stored values to
    NULL.
  - `ProxyResource` emits both fields on index/show/edit responses.
  - The `mode` field still gates nothing else — no toggle surface is added, no other field
    becomes conditional on it beyond the pre-existing behaviour (AC20).
- **Testing:** the cases above via real `store`/`update` requests.
- **Completion notes:** _pending_

## T45 — Unit test sweep for remaining named seams (plan §Test strategy "Unit")
- **Description:** Fill any gap in the plan's explicitly named unit-test seams not already
  covered by an earlier task's own Testing section: `RetryPolicy` edge cases beyond T11/T20 if
  any remain (e.g. a delay-table boundary at the exponential cap); `DeliveryStatus::isTerminal`
  truth table (already in T2, verify no gap); a CAS transition-matrix test exercising every
  `(from, to)` pair `DeliverToDestination`/`RetryDelivery` can attempt, including the invalid/
  no-op transitions; `StoredPayloadLookup::dispatchedBytesFor` (already in T12, verify no gap);
  `applyHolds` H5 predicate in isolation (already in T19, verify no gap); `PipelineContext`
  dispatch-uuid defaulting (already in T8, verify no gap); `DeliverStep` iterates delivery rows
  (already in T9, verify no gap). This task's job is to audit T1–T44's Testing sections against
  this list and add exactly what is missing — not to duplicate what already exists.
- **Dependencies:** T2, T8, T9, T11, T12, T19, T20
- **Files:** whichever of the unit test files above needs a gap filled (no new file expected
  unless the CAS transition-matrix test warrants its own, e.g.
  `tests/Unit/Actions/DeliveryStatusTransitionTest.php`)
- **Acceptance Criteria:** every named seam in the plan's Test Strategy "Unit" group has at least
  one passing unit test; no seam is covered twice by near-duplicate tests across files.
- **Testing:** as described above.
- **Completion notes:** _pending_

## T46 — Full quality sweep + docs cross-check (plan §Milestones M10)
- **Description:** Final task: run the full suite in parallel, Pint, and PHPStan L7 across the
  whole change; update any docblock that still cites a superseded ADR-011 position (order key,
  `UNIQUE(webhook_event_id)`, the old attempts idempotency key) to point at ADR-016 instead
  (pointer-only annotation, per ADR-016's own Impact section — ADR-011's file, status, and text
  are never rewritten). Confirm `docs/architecture/adr-011-...md`'s Status line carries the
  ADR-016 pointer if the Principal Engineer has not already added it.
- **Dependencies:** T1–T45 (all)
- **Files:** any docblock identified during the sweep; no new production file expected
- **Acceptance Criteria:** `composer lint`, `composer types:check`, `./vendor/bin/sail test
  --parallel` all green with the full new suite included; `pnpm lint:check`, `pnpm types:check`,
  `pnpm format:check` all green; no remaining docblock or comment describes the superseded
  ADR-011 positions as current without a pointer to ADR-016.
- **Testing:** the full-suite run itself is the test.
- **Completion notes:** _pending_

---

## Handoff
- **Inputs:** `docs/plans/plan-06-retry-replay.md` (Approved, PE self-certified, all seven Owner-
  approval flags ratified 2026-08-12); `docs/product/prd-06-retry-replay.md` (Approved, Owner,
  2026-08-12; AC1–AC25); `docs/design/design-06-retry-replay.md` (Approved, Product Manager,
  2026-08-12); ADR-015, ADR-016, ADR-017 (all Accepted, Owner, 2026-08-12); ADR-003/004/005/009/
  010/011/012/013/014 (Accepted, relied on unmodified in substance); `docs/questions/prd-06-q-06-
  03-retry-replay-composition.md` (RESOLVED); `docs/tasks/payload-storage-retention-tasks.md` and
  `docs/tasks/queued-processing-tasks.md` (house format/granularity precedent);
  `docs/standards/planning.md`, `docs/standards/testing.md`; the current codebase (migrations for
  `proxies`/`delivery_attempts`/`fifo_dispatches`/`webhook_events`/`dispatched_payloads`;
  `IngestController`, `ProcessIngestedWebhook`, `DeliverStep`, `DeliverToDestination`,
  `AdvanceProxyFifoQueue`, `SweepStalledFifoDispatches`, `PurgeExpiredPayloads`,
  `StoredPayloadLookup`, `RetentionPolicy`, `PipelineContext`/`PipelineFactory`, `DeliveryUnit`,
  `ProxyPolicy`, `TeamRole`/`TeamPermission`, `ProxyPermissions`/`HasTeams`, `ProxyResource`,
  `ProxyController`, `StoreProxyRequest`/`UpdateProxyRequest`, `ProxyForm.vue`,
  `proxies/{Show,Index}.vue`, `resources/js/types/proxies.ts`, `resources/js/data/*.ts`).
- **Outputs:** this task plan (`docs/tasks/retry-replay-tasks.md`).
- **Dependencies:** none new; within stack (Eloquent, migrations, the Laravel scheduler, Redis
  queue, `lorisleiva/laravel-actions`, existing frontend primitives).
- **Outstanding Questions:** none. Every task above traces to an explicit plan section, ADR
  decision, or PRD acceptance criterion; no design ambiguity was found requiring a question doc
  to the Principal Engineer or Product Manager. One implementation-level judgment call is
  deliberately left open per house convention (not prescribed in a task's Description): T22's
  exact validation-file location (dedicated request test vs. folded into T24's controller test)
  and T45's exact gap-filling scope, both explicitly flagged as the Senior Developer's call
  within the stated constraints.
- **Next Agent:** Senior Developer — implement T1–T46 in order, one feature-branch commit per
  completed task (or per logical part of a large task), leaving `composer lint`, `composer
  types:check`, and `./vendor/bin/sail test` (plus `pnpm lint:check`/`types:check`/
  `format:check` for frontend tasks) green at every commit, per `docs/standards/planning.md`.
  Feature branch: `feat/item-06-retry-replay`.

### Certification (Task Planner, 2026-08-12)
I have verified plan-06 is Approved (PE self-certified) with all seven Owner-approval flags
ratified — ADR-015/016/017 all Accepted and the four data-model changes approved verbatim — so no
gate blocks Task Planning. I have read the plan in full, its three companion ADRs, the approved
design spec, PRD-06's acceptance criteria (AC1–AC25), the resolved Q-06-01/Q-06-02/Q-06-03, the
relevant current codebase, and `docs/standards/planning.md`/`docs/standards/testing.md`. Every
task above traces to an explicit plan section (Architecture, Data Model, API, Services & Actions,
Validation, or an enumerated technical ruling) or a named ADR decision; no task invents scope
beyond what plan-06 authorizes, and the Scope Discipline block above enumerates what is
deliberately excluded. Tasks are ordered so no task depends on a later one; the one non-additive
migration step (T5) states its internal ordering constraint explicitly, as does T6's backfill-
before-unique / plain-index-before-drop sequencing. I record `Approved by: Task Planner` per the
delegated task-plan gate in `CLAUDE.md` — no Owner approval is required at this stage; the
Reviewer catches drift against the plan/PRD-06/ADR-015–017 at review time.
