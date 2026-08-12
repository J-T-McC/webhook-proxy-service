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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
- **Completion notes:** _pending_

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
