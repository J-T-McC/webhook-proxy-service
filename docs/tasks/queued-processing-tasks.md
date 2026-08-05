# Task Plan: Queued processing (FIFO & Async) — item #4

- **Status:** Approved (Task-Planner-certified)
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-04-queued-processing.md` (Approved — Principal Engineer
  self-certified, 2026-08-04; ADR-011 + data-model change ratified by Owner 2026-08-04)
- **PRD:** `docs/product/prd-04-queued-processing.md` (Approved — Owner, 2026-08-04, 13 ACs) ·
  **Design:** `docs/design/design-04-queued-processing.md` (Approved — Product Manager, 2026-08-04)
  · **ADRs:** ADR-011 (Accepted, 2026-08-04), ADR-001/003/004/005/007/010 (Accepted, unchanged)
- **Approved by:** Task Planner (task-plan gate; no further Owner approval required at this stage —
  the Reviewer catches drift against the plan/PRD-04/ADR-011 at review time)

> **Scope / conventions.** Every task traces to plan-04 and PRD-04's ACs (AC1–AC13). Sequencing
> follows the plan's own layering: **data model** (T1–T6) → **dispatch seam / worker / FIFO
> mechanism** (T7–T12) → **backend acceptance tests proving the mechanism** (T13–T18) →
> **management-form persistence** (T19–T20) → **frontend** (T21–T25). Migrations and enums always
> precede the code that reads them; the FIFO claim/advancer/sweeper trio (T10–T11) is built and
> proven (T15–T16) before the ingest controller wires FIFO dispatch to it (T12), so the controller
> never dispatches to an incomplete mechanism.
>
> Every task must leave `composer lint` (Pint) and `composer types:check` (PHPStan L7) green, and
> `./vendor/bin/sail test` green with its own tests included (CLAUDE.md, `docs/standards/planning.md`).
> Backend tests run under `QUEUE_CONNECTION=sync` (already set in `phpunit.xml`) so dispatched jobs
> execute inline unless a task explicitly `Queue::fake()`s to assert dispatch without draining.
> Frontend-touching tasks (T23–T25) additionally require `pnpm types:check` (vue-tsc), `pnpm
> lint:check` (ESLint), and `pnpm format:check` (Prettier) green.
>
> **No new dependency, no stack change.** Uses only Eloquent, migrations, Laravel's queue (Redis
> driver, already in ADR-005/stack), `lorisleiva/laravel-actions` `AsAction`/`AsJob`/`AsCommand`
> (already adopted, ADR-007), the existing native `Illuminate\Pipeline\Pipeline`, and the scheduler
> (plan §Dependencies).
>
> **Scope discipline (plan §Overview / PRD Out of Scope) — do NOT build in this feature:**
> retry/backoff/replay/dead-letter as a user feature (#6 — the `fifo_dispatches.status` seam is left
> open, not extended), per-`(proxy, destination)` FIFO ordering (later refinement, not #4), payload
> storage/retention/GC (#5), any enhanced-mode pipeline step (mapping #8, verification #10,
> change-detection #12), a scalable transport beyond Redis (V3), and any numeric throughput/latency
> SLA (V8 unset, AC13). Do not build forward-looking scaffolding beyond named commented stubs
> (`docs/standards/planning.md` Scope discipline).
>
> **Load-bearing ordering invariant (ADR-010/#3 AC5/AC6, unchanged; plan §Implementation Notes).**
> In `IngestController`, capture must still commit **before** the response is resolved/returned and
> **before** any dispatch (Async or FIFO). T12 is the only task that changes the dispatch step; no
> task may move capture after the response or after dispatch. For FIFO, the `fifo_dispatches` row
> must be committed in/with the same commit as capture, and `AdvanceProxyFifoQueue` dispatched only
> `afterCommit()`.
>
> **The atomic claim is the FIFO correctness primitive (ADR-011/ADR-005 (a); plan §Implementation
> Notes).** `FOR UPDATE` inside a transaction — live-claim check, lowest-pending scan, status flip —
> is what guarantees at most one in-flight event per proxy. `WithoutOverlapping` job middleware
> (T10) is a thundering-herd reducer only; no task may rely on it for ordering or dedupe. The
> outbound HTTP send must happen **outside** the claim transaction (never hold the row lock across
> a network call).
>
> **Operational note (plan §Risks 1, non-blocking).** Running #4 in production needs a live Redis
> queue transport, ≥1 queue worker, and the scheduler (`schedule:run`) for the sweeper (T11).
> Flipping `QUEUE_CONNECTION` to `redis` and provisioning workers is a deployment/runbook concern,
> not a code task — no task here changes the local/testing default queue driver (tests keep running
> on `sync`, already pinned in `phpunit.xml`).

---

## T1 — `ProcessingMode` enum (ADR-011 Decision 1)

- **Description:** Backed string enum for the per-proxy processing mode, mirroring `ProxyMode`
  exactly (`App\Enums\ProxyMode`, ADR-002 precedent): `App\Enums\ProcessingMode` with cases
  `Async = 'async'` and `Fifo = 'fifo'`.
- **Dependencies:** none
- **Files:** `app/Enums/ProcessingMode.php` (new)
- **Acceptance Criteria:** the enum exposes exactly two cases, `Async` (`'async'`) and `Fifo`
  (`'fifo'`); no other cases.
- **Testing:** extend `tests/Unit/Enums/DomainEnumsTest.php` with a case asserting the exact case
  set and backing values, mirroring the existing `ProxyMode`/`HttpMethod`/`AttemptStatus` cases.
- **Completion notes:** Added `app/Enums/ProcessingMode.php` (cases `Async='async'`, `Fifo='fifo'`),
  mirroring `ProxyMode`. Extended `DomainEnumsTest` with an exact case-set assertion. `composer
  lint`, `composer types:check`, `sail test --filter DomainEnumsTest` green.

## T2 — `FifoDispatchStatus` enum (ADR-011 Decision 2)

- **Description:** Backed string enum for the FIFO ordering-row claim lifecycle:
  `App\Enums\FifoDispatchStatus` with cases `Pending = 'pending'`, `Claimed = 'claimed'`,
  `Settled = 'settled'`. `dead_lettered` is explicitly a **#6** addition, not built here (plan
  §Data Model → `fifo_dispatches`).
- **Dependencies:** none
- **Files:** `app/Enums/FifoDispatchStatus.php` (new)
- **Acceptance Criteria:** the enum exposes exactly three cases (`pending`, `claimed`, `settled`);
  no `dead_lettered` or other case.
- **Testing:** extend `tests/Unit/Enums/DomainEnumsTest.php` with a case asserting the exact case
  set and backing values.
- **Completion notes:** Added `app/Enums/FifoDispatchStatus.php` (cases `Pending`/`Claimed`/`Settled`);
  no `dead_lettered` (deferred to #6, noted in the class docblock). Extended `DomainEnumsTest` with an
  exact case-set assertion. All three checks green.

## T3 — FIFO lease + webhooks-queue config keys (plan §Implementation Notes, §Risks 1)

- **Description:** Two config-driven defaults the FIFO mechanism needs, following the existing
  `config/ingest.php` key pattern (`url`, `max_body_bytes`, `rate_limit_per_minute`,
  `response_body_max_bytes`): `ingest.fifo_lease_seconds` (env `INGEST_FIFO_LEASE_SECONDS`, default
  `90`) — the claim lease duration the advancer sets and the sweeper treats as the orphan cutoff;
  `ingest.webhooks_queue` (env `INGEST_WEBHOOKS_QUEUE`, default `'webhooks'`) — the dedicated queue
  name Async per-destination jobs dispatch onto (plan §Services → `DeliverStep`). No sweep-interval
  config key — the sweeper's schedule cadence is a fixed `everyMinute()` call in `routes/console.php`
  (T11), not a tunable.
- **Dependencies:** none
- **Files:** `config/ingest.php`, `.env.example`
- **Acceptance Criteria:** `config('ingest.fifo_lease_seconds')` returns `90` by default and the env
  override when set; `config('ingest.webhooks_queue')` returns `'webhooks'` by default and the env
  override when set; both documented inline in `config/ingest.php` and added as commented keys in
  `.env.example`, matching the existing ingest-guard placeholder pattern.
- **Testing:** extend `tests/Unit/Config/IngestConfigTest.php` with default + env-override cases for
  both new keys, mirroring the existing `response_body_max_bytes` test (T1 of plan-03).
- **Completion notes:** Added `ingest.fifo_lease_seconds` (env `INGEST_FIFO_LEASE_SECONDS`, default
  90) and `ingest.webhooks_queue` (env `INGEST_WEBHOOKS_QUEUE`, default `'webhooks'`) to
  `config/ingest.php` with inline docs; added commented keys to `.env.example`. Four new
  default+override cases in `IngestConfigTest` (8 pass). All three checks green.

## T4 — `proxies.processing_mode` migration + `Proxy` model (AC4, AC5; ADR-011 Decision 1)

- **Description:** Per plan §Data Model → `proxies`. New migration
  `add_processing_mode_to_proxies_table` adds `processing_mode` as
  `enum('async','fifo')` **NOT NULL**, **default `'async'`**, `after('mode')` — mirrors the existing
  `mode` enum exactly (ADR-002 precedent); no backfill, existing #1/#3 rows read `async` with no
  behaviour change (AC5). `Proxy` model: add `'processing_mode' => ProcessingMode::class` (T1) to
  `casts()`, add `processing_mode` to `#[Fillable]`, add `@property ProcessingMode $processing_mode`
  to the docblock.
- **Dependencies:** T1
- **Files:** `database/migrations/*_add_processing_mode_to_proxies_table.php` (new),
  `app/Models/Proxy.php`
- **Acceptance Criteria:** migration applies cleanly (`up`/`down` both exercised); the column is
  `NOT NULL` with schema default `'async'` (verify via `information_schema.COLUMNS` —
  `IS_NULLABLE = 'NO'`, `COLUMN_DEFAULT = 'async'`); an existing (pre-migration / factory-made,
  #1/#3-simulating) proxy row reads `processing_mode === ProcessingMode::Async` after migrating with
  no backfill statement run; `processing_mode` round-trips through the enum cast when set to `fifo`.
- **Testing:** extend `tests/Unit/Models/ProxyTest.php` — schema assertion for `NOT NULL`/default
  `'async'`; a factory-made proxy (no explicit `processing_mode`) reads `Async`; setting `fifo` and
  reloading round-trips through the cast.
- **Completion notes:** Added migration `2026_08_04_000003_add_processing_mode_to_proxies_table`
  (`enum('async','fifo')` NOT NULL default `'async'`, `after('mode')`, mirroring `mode`). Proxy
  model: added `processing_mode` cast to `ProcessingMode::class`, to `#[Fillable]`, and a
  `@property` docblock line. Three new `ProxyTest` cases: schema NOT NULL/default assertion,
  no-backfill factory row reads `Async`, `fifo` round-trips the cast. Verified up/down cleanly
  (`migrate:rollback`/`migrate`, then `migrate:fresh`). All three checks green.

## T5 — `fifo_dispatches` table + `FifoDispatch` model + factory (AC6, AC7; ADR-011 Decision 2)

- **Description:** Per plan §Data Model → `fifo_dispatches`. New table, one row per received event
  **for FIFO proxies only**: `id` bigint PK; `team_id` FK → teams (`constrained()`), set explicitly
  on the team-unscoped ingest path (mirrors `webhook_events`/`delivery_attempts`); `proxy_id` FK →
  proxies (`constrained()`); `webhook_event_id` FK → webhook_events (`constrained()`), **UNIQUE**
  (the monotonic order key); `status` `enum('pending','claimed','settled')` NOT NULL default
  `'pending'`; `claimed_at` nullable timestamp; `lease_expires_at` nullable timestamp;
  `settled_at` nullable timestamp; `timestamps()`. Indexes: `UNIQUE(webhook_event_id)`,
  `(proxy_id, status, webhook_event_id)` composite (serves both the lowest-pending scan and the
  live-claim check). No `SoftDeletes`, no payload/outcome column (ordering/claim state only).
  Model `FifoDispatch`: `BelongsToCurrentTeam`, `belongsTo(Proxy)`, `belongsTo(WebhookEvent)`; casts
  `'status' => FifoDispatchStatus::class` (T2), `'claimed_at'/'lease_expires_at'/'settled_at' =>
  'datetime'`. Factory mirrors `WebhookEventFactory`'s team-unscoped `team_id` derivation pattern
  (`Proxy::withoutGlobalScope(TeamScope::class)`).
- **Dependencies:** T2
- **Files:** `database/migrations/*_create_fifo_dispatches_table.php` (new),
  `app/Models/FifoDispatch.php` (new), `database/factories/FifoDispatchFactory.php` (new)
- **Acceptance Criteria:** migration applies cleanly (`up`/`down` exercised); `webhook_event_id` has
  a single-column `UNIQUE` index (a second row for the same event is rejected at the DB level); the
  `(proxy_id, status, webhook_event_id)` composite index exists; `status` defaults to `pending`
  with no schema value outside `pending`/`claimed`/`settled`; the table has no soft-delete column;
  `status` round-trips through the enum cast; `claimed_at`/`lease_expires_at`/`settled_at` cast to
  `Carbon` instances when set and stay `null` when unset; `team_id`/`proxy_id`/`webhook_event_id`
  relationships resolve correctly.
- **Testing:** `tests/Unit/Models/FifoDispatchTest.php` (new) — schema assertions above (unique
  index, composite index, default status, no soft-delete column), enum cast round-trip, nullable
  timestamp casts, and the three `belongsTo` relations resolving to the correct records.
- **Completion notes:** Added migration `2026_08_04_000004_create_fifo_dispatches_table` (bigint PK,
  `team_id`/`proxy_id`/`webhook_event_id` restrict FKs, `status` enum default `pending`, three
  nullable timestamps, single-column `UNIQUE(webhook_event_id)` + `(proxy_id, status,
  webhook_event_id)` composite, no soft delete). `FifoDispatch` model with `BelongsToCurrentTeam`,
  `proxy`/`webhookEvent` relations, enum + datetime casts. `FifoDispatchFactory` anchors on the
  webhook event and derives `proxy_id`/`team_id` from it (team-unscoped, mirroring
  `WebhookEventFactory`) so all three references stay consistent. 8 `FifoDispatchTest` cases green.
  Gotcha: `foreignId(...)->constrained()->unique()` isn't valid (ForeignKeyDefinition has no
  `unique()`) — declared the unique separately via `$table->unique('webhook_event_id')`. All three
  checks green.

## T6 — `delivery_attempts` idempotency unique index (AC9; ADR-011 Decision 4)

- **Description:** Per plan §Data Model → `delivery_attempts`. New migration
  `add_idempotency_unique_to_delivery_attempts_table` adds **`UNIQUE(ingest_id, destination_id,
  attempt_number)`**, keeping all existing indexes (`ingest_id`, `(team_id, created_at)`,
  `(proxy_id, status)`). Safe on existing data: pre-#4 there is ≤1 attempt per
  `(ingest_id, destination_id)` (`attempt_number` always `1`), so no duplicate blocks the index.
- **Dependencies:** none
- **Files:** `database/migrations/*_add_idempotency_unique_to_delivery_attempts_table.php` (new)
- **Acceptance Criteria:** migration applies cleanly (`up`/`down` both exercised) against a database
  seeded with existing (pre-#4-shaped) `delivery_attempts` rows, with no migration failure; a second
  insert with the same `(ingest_id, destination_id, attempt_number)` triple is rejected at the DB
  level; the pre-existing `ingest_id`, `(team_id, created_at)`, and `(proxy_id, status)` indexes are
  unaffected.
- **Testing:** extend `tests/Unit/Models/DeliveryAttemptTest.php` — schema assertion the composite
  unique index exists; a duplicate-triple insert throws a DB-level unique-constraint exception; the
  three pre-existing indexes are still present.
- **Completion notes:** Added migration
  `2026_08_04_000005_add_idempotency_unique_to_delivery_attempts_table` adding
  `UNIQUE(ingest_id, destination_id, attempt_number)`, keeping all three existing indexes. Two new
  `DeliveryAttemptTest` cases: composite-unique-alongside-existing schema assertion, and a
  duplicate-triple insert rejected at the DB level (`QueryException`). Migration comment records the
  safe-on-existing-data reasoning (pre-#4 `attempt_number` always 1, ≤1 attempt per pair). All three
  checks green.

## T7 — `ProcessIngestedWebhook`: dispatch-by-reference rebuild (ADR-011 Decision 3)

- **Description:** Per plan §Services. Change `handle()` to accept **`string $ingestId`** instead
  of `PipelineContext`: look up the `WebhookEvent` by `ingest_id` (`firstOrFail`), load its proxy
  **including trashed** (`Proxy::withTrashed()->findOrFail($event->proxy_id)`, so an event accepted
  before a later soft-delete still delivers), rebuild a `PipelineContext` from the event's
  `method`/`headers`/`body` (decrypts transparently via the ADR-010 cast) and the loaded proxy, then
  run the pipeline exactly as today (`app(Pipeline::class)->send($ctx)->through($this->factory->
  stepsFor($ctx->proxy))->thenReturn()`). Never log the raw body or token on this path
  (`docs/standards/coding.md` never-log list). Keep a thin private `runPipeline(PipelineContext)` if
  useful for unit tests (plan's own suggestion).
- **Dependencies:** none
- **Files:** `app/Actions/ProcessIngestedWebhook.php`
- **Acceptance Criteria:** calling `ProcessIngestedWebhook::run($ingestId)` for an existing
  `webhook_events` row runs the same pipeline steps as before, producing the same delivery outcome
  as passing an equivalent `PipelineContext` directly; a proxy soft-deleted **after** its event was
  captured still delivers (trashed-inclusive load); an unknown `ingestId` raises (no silent no-op).
- **Testing:** unit test (`tests/Unit/Actions/ProcessIngestedWebhookTest.php`, new or extend the
  existing `tests/Feature/Ingest/ProcessIngestedWebhookTest.php`) — rebuild-from-`ingestId` produces
  the same pipeline execution as the prior `PipelineContext`-based call; the soft-deleted-proxy case;
  the unknown-`ingestId` case (raises, does not silently return).
- **Completion notes:** `handle()` now takes `string $ingestId`: looks up the `WebhookEvent`
  (`firstOrFail`), loads the proxy `withTrashed()`, rebuilds the `PipelineContext` from the event's
  `method`/`headers`/`body` (body decrypts via the ADR-010 cast), and runs the pipeline via a thin
  private `runPipeline()` seam. Rewrote `ProcessIngestedWebhookTest` (3 cases: rebuild-from-ingest_id
  delivers once per live destination, soft-deleted-after-capture proxy still delivers, unknown
  ingest_id raises `ModelNotFoundException`). **Caller kept green:** the signature change forced the
  sole caller (`IngestController`) to pass `$ingestId` and drop its now-unused `PipelineContext`
  construction — a minimal same-behaviour (`::run` inline) edit so the suite stays green; T12 does the
  mode-branch/afterCommit rework. All 30 ingest feature tests + all three checks green.

## T8 — `DeliverStep`: branch on `processing_mode` (AC5, AC6, AC10; plan §Services)

- **Description:** Per plan §Services. `DeliverStep::handle()` keeps iterating the proxy's live
  destinations, but the per-destination call branches on `$ctx->proxy->processing_mode` (T4):
  **Async** → `DeliverToDestination::dispatch($unit)->onQueue(config('ingest.webhooks_queue'))
  ->afterCommit()` (T3 config key; parallel, queued); **FIFO** → `DeliverToDestination::run($unit)`
  (inline, unchanged from today — so the advancing job settles the whole event before advancing,
  ADR-011). One destination failing/erroring never aborts the loop in either branch (AC10) —
  unchanged, `DeliverToDestination` catches its own transport errors.
- **Dependencies:** T4, T3
- **Files:** `app/Actions/DeliverStep.php`
- **Acceptance Criteria:** for an Async proxy, each destination's delivery is **dispatched** onto
  the configured webhooks queue (not run inline) — `Queue::fake()` + `DeliverToDestination::
  assertPushedOn(config('ingest.webhooks_queue'), ...)` per destination; for a FIFO proxy, each
  destination's delivery runs **inline** (no queue push) — `Queue::fake()` +
  `DeliverToDestination::assertNotPushed()`, with `Http::fake()` confirming the send actually
  happened synchronously; a destination throwing/failing does not prevent the loop from reaching the
  next destination in either mode.
- **Testing:** `tests/Unit/Actions/DeliverStepTest.php` (new or extend) — the two branches above,
  plus the one-destination-fails-others-still-run case for each mode.
- **Completion notes:** `DeliverStep::handle()` now branches on `$ctx->proxy->processing_mode`:
  Async → `DeliverToDestination::dispatch($unit)->onQueue(config('ingest.webhooks_queue'))
  ->afterCommit()`; FIFO → `DeliverToDestination::run($unit)` inline. Loop still never aborts on a
  single failure (Async dispatch is fire-and-forget; FIFO relies on `DeliverToDestination`'s own
  try/catch). New `DeliverStepTest` (4 cases: async pushes 3 onto the webhooks queue via
  `assertPushedOn`, fifo runs inline with `assertNotPushed` + `Http::assertSentCount`, plus the
  one-fails-others-run case per mode). Default (async) proxies now dispatch rather than run inline;
  under the `sync` test driver they still execute, so all 30 ingest feature tests stay green. All
  three checks green.

## T9 — `DeliverToDestination`: idempotency guard (AC9; ADR-011 Decision 4)

- **Description:** Per plan §Services and §Implementation Notes. Guard against the queue's
  inherent at-least-once redelivery using the T6 unique index: before creating a new
  `DeliveryAttempt`, check for an existing row keyed on `(ingest_id, destination_id,
  attempt_number)`. If one exists in a **terminal** state (`succeeded`/`failed`), this is a
  redelivery of an already-settled unit — **skip** (no HTTP send, no new/duplicate `DeliveryAttempt`
  row, no duplicate event). If one exists still in `dispatched` (a prior attempt that crashed
  mid-flight), re-drive **that same row** to settlement instead of creating a new one. Otherwise,
  proceed exactly as today (create the `dispatched` row, send, update to
  `succeeded`/`failed`). Handle the race where two redeliveries attempt the insert concurrently by
  treating a unique-constraint violation on create the same as "already exists" (re-query and apply
  the same skip/re-drive rule) rather than letting the exception propagate. `$tries = 1` unchanged —
  #4 adds no retry/backoff (that is #6).
- **Dependencies:** T6
- **Files:** `app/Actions/DeliverToDestination.php`
- **Acceptance Criteria:** calling `DeliverToDestination::run($unit)` twice for the same
  `(ingest_id, destination_id, attempt_number)` after the first call **succeeded** results in
  exactly one `DeliveryAttempt` row, exactly one outbound HTTP send (`Http::assertSentCount(1)`),
  and exactly one `DeliverySucceeded` event; the same after the first call **failed** results in
  exactly one row, one send, one `DeliveryFailed` event; a row left `dispatched` (simulated
  mid-flight crash) is re-driven to a terminal state on the **same row id** (not a new row) on a
  second call; the `UNIQUE(ingest_id, destination_id, attempt_number)` index rejects a raw duplicate
  insert at the DB level.
- **Testing:** extend `tests/Unit/Actions/DeliverToDestinationTest.php` (or equivalent existing
  test) with the four cases above, using `Http::fake()`/`Event::fake()`.
- **Completion notes:** Refactored `DeliverToDestination::handle()`: look up the existing attempt by
  `(ingest_id, destination_id, attempt_number)` first; a terminal row → skip (no send/row/event); a
  `dispatched` row → re-drive the SAME row via a private `send()` helper; otherwise create the
  `dispatched` row and send. A `QueryException` on create (concurrent-redelivery race on the unique
  index) is caught, re-queried, and routed through the same skip/re-drive rule; a non-unique query
  error re-throws. `$tries = 1` made explicit (no retry/backoff — that is #6). Four new
  `DeliverToDestinationTest` cases (redelivery-after-success no-op, redelivery-after-failure no-op,
  dispatched-row re-driven on same id, raw-duplicate rejected) plus the four pre-existing cases stay
  green (8 total). All three checks green.

## T10 — `AdvanceProxyFifoQueue` action (AC6, AC7; ADR-011 Decision 2, ADR-005 (a))

- **Description:** Per plan §Services. New `App\Actions\AdvanceProxyFifoQueue`, `AsJob`, the FIFO
  single-advancer for one `int $proxyId`:
  1. **Atomic claim** in a short `DB::transaction`: `lockForUpdate()` a live-claim check
     (`status='claimed' AND lease_expires_at > now()` for the proxy) — if present, early-return
     (another advancer/self-dispatch already owns the line). Else `lockForUpdate()->orderBy(
     'webhook_event_id')->first()` the lowest `pending` row for the proxy — if none, early-return —
     else set `status='claimed'`, `claimed_at=now()`, `lease_expires_at=now()+config(
     'ingest.fifo_lease_seconds')` (T3).
  2. **Outside the claim transaction**, process: `ProcessIngestedWebhook::run($event->webhookEvent
     ->ingest_id)` (T7; inline pipeline, inline delivery because the proxy is FIFO — T8's FIFO
     branch).
  3. Mark the claimed row `status='settled'`, `settled_at=now()`.
  4. **Self-dispatch** `AdvanceProxyFifoQueue::dispatch($proxyId)` to advance to the next pending
     row. Add `WithoutOverlapping("proxy:{$proxyId}")` job middleware (`getJobMiddleware`) as a
     thundering-herd reducer — **not** the ordering guard (that is step 1's transaction).
- **Dependencies:** T5, T7, T8, T3
- **Files:** `app/Actions/AdvanceProxyFifoQueue.php` (new)
- **Acceptance Criteria:** given a FIFO proxy with several `pending` `fifo_dispatches` rows,
  repeated dispatch/self-dispatch of `AdvanceProxyFifoQueue` settles them one at a time, lowest
  `webhook_event_id` first, each fully delivered (its destinations' `DeliveryAttempt`s exist) before
  the next row is claimed; running the action for a proxy with no `pending` rows or an already-live
  claim is a no-op (no row claimed, no self-dispatch loop); the HTTP delivery happens **outside**
  the claim transaction (no destination call executes while the row lock is held — verified by
  asserting the claim transaction has committed before `ProcessIngestedWebhook::run` is invoked).
- **Testing:** `tests/Unit/Actions/AdvanceProxyFifoQueueTest.php` (new) — sequential settlement in
  `webhook_event_id` order for 3+ pending rows; no-op on an empty/already-claimed queue; the
  claim-commits-before-delivery ordering (e.g. asserting via a spy/mock boundary or by checking the
  row's `status` is already `claimed` and its transaction closed before the fake HTTP call fires).
- **Completion notes:** New `AdvanceProxyFifoQueue` (`AsAction`/`AsJob`). Private `claimNext()` runs
  the atomic claim in one short `DB::transaction`: `lockForUpdate` live-claim check (`claimed` +
  `lease_expires_at > now()`) → early-return if held; else `lockForUpdate()->orderBy('webhook_event_id')
  ->first()` the lowest `pending` row → early-return if none; else flip to `claimed` with `claimed_at`
  + `lease_expires_at = now()+config('ingest.fifo_lease_seconds')`. Delivery
  (`ProcessIngestedWebhook::run`) runs OUTSIDE the transaction (row lock never held across the send),
  then the row is settled and `static::dispatch($proxyId)` advances the line. `getJobMiddleware(int
  $proxyId)` adds `WithoutOverlapping("proxy:{$proxyId}")` (thundering-herd reducer only). New
  `AdvanceProxyFifoQueueTest` (4 cases): sequential settlement in webhook_event_id order for 3 rows
  (each delivered before the next is claimed, self-dispatch asserted 3×), no-op on empty queue, no-op
  on an already-live claim, and claim-commits-before-delivery (`DB::transactionLevel()===0` + row
  already `claimed` inside the `Http::fake` closure). Testing note: exercised via `::run` +
  `Queue::fake()` so the self-dispatch is captured rather than recursing inline under the `sync`
  driver (the `WithoutOverlapping` lock intentionally does not double as the ordering guard). All
  three checks green.

## T11 — `SweepStalledFifoDispatches` + schedule registration (ADR-005 (b), ADR-011 Decision 2)

- **Description:** Per plan §Services. New `App\Actions\SweepStalledFifoDispatches` (`AsCommand`
  recommended for direct testability via `Artisan::call`/action-level unit test; a scheduled
  closure is the plan's stated alternative if preferred) performing, in order: (a) reset every
  `claimed` `fifo_dispatches` row whose `lease_expires_at < now()` back to `pending` (orphaned-claim
  reaper — a worker died mid-event) — must **not** touch a `claimed` row whose lease has not yet
  expired; (b) for every **distinct** `proxy_id` that has ≥1 `pending` row and **no** live claim
  (`status='claimed' AND lease_expires_at > now()`), dispatch `AdvanceProxyFifoQueue::dispatch(
  $proxyId)` (T10). Register in `routes/console.php` on the existing `Schedule::` pattern,
  `everyMinute()`, with a `->description(...)`.
- **Dependencies:** T5, T10, T3
- **Files:** `app/Actions/SweepStalledFifoDispatches.php` (new), `routes/console.php`
- **Acceptance Criteria:** a `claimed` row with an expired `lease_expires_at` is reset to `pending`
  (with `claimed_at`/`lease_expires_at` cleared) after a sweep; a `claimed` row with an unexpired
  lease is untouched; a proxy with `pending` rows and no live claim gets exactly one
  `AdvanceProxyFifoQueue` dispatch per sweep (not one per pending row); a proxy with an already-live
  claim gets no dispatch; the schedule entry is registered and runs `everyMinute()`.
- **Testing:** `tests/Unit/Actions/SweepStalledFifoDispatchesTest.php` (new) — the reap case, the
  unexpired-claim-untouched case, the re-dispatch-idle-proxies case (`Queue::fake()` +
  `AdvanceProxyFifoQueue::assertPushed(1, ...)`), and the already-claimed-no-dispatch case; a
  console/schedule test (or `Schedule::events()` inspection) confirming the `everyMinute()`
  registration.
- **Completion notes:** New `SweepStalledFifoDispatches` (`AsAction`): (a) mass-updates every
  `claimed` row with `lease_expires_at < now()` back to `pending` (clearing `claimed_at`/
  `lease_expires_at`); (b) selects distinct `proxy_id`s that have ≥1 `pending` row and no live claim
  (`whereNotIn` subquery on `claimed` + unexpired lease) and dispatches one `AdvanceProxyFifoQueue`
  per proxy. Registered in `routes/console.php` via `Schedule::call(fn () =>
  SweepStalledFifoDispatches::run())->everyMinute()->description('Sweep stalled FIFO dispatches')`
  (chose the scheduled-closure alternative over `AsCommand` — `::run()` is equally unit-testable and
  avoids console-kernel command registration). New `SweepStalledFifoDispatchesTest` (5 cases): reap
  expired claim, leave unexpired claim untouched, one-dispatch-per-idle-proxy (two pending rows → one
  push), no dispatch when a live claim exists, and `Schedule::events()` inspection confirming the
  `* * * * *` everyMinute registration. All three checks green.

## T12 — `IngestController`: dispatch-by-mode after capture commit (AC1–AC3, AC5, AC6; ADR-011)

- **Description:** Per plan §Architecture step 5. Replace the final
  `ProcessIngestedWebhook::run($ctx)` call with the mode branch, dispatched **`afterCommit()`**:
  keep the `WebhookEvent` returned by `WebhookEventCapture::capture()` (already captured
  synchronously before the response, unchanged — ADR-010/#3 AC5/AC6). **Async:**
  `ProcessIngestedWebhook::dispatch($ingestId)->afterCommit()` (T7). **FIFO:** create the
  `fifo_dispatches` ordering row (`status='pending'`, `webhook_event_id` = the captured event's id,
  `proxy_id`/`team_id` from `$proxy`) **committed in/with the same commit as capture**, then
  `AdvanceProxyFifoQueue::dispatch($proxy->id)->afterCommit()` (T10). The controller **no longer
  builds a `PipelineContext`** — that responsibility moved to `ProcessIngestedWebhook` (T7). Return
  the already-resolved `ResponseResolver` response, reached without waiting on any dispatch or
  delivery (AC1/AC2, ADR-004 unchanged).
- **Dependencies:** T4, T5, T7, T10
- **Files:** `app/Http/Controllers/IngestController.php`
- **Acceptance Criteria:** a successful ingest to an **Async** proxy dispatches
  `ProcessIngestedWebhook` (assert via `Queue::fake()`) and returns its resolved response without
  any `DeliveryAttempt` existing yet (AC1); a successful ingest to a **FIFO** proxy commits one
  `fifo_dispatches` row (`status='pending'`) and dispatches `AdvanceProxyFifoQueue` (`Queue::fake()`)
  before returning; capture still commits before both the response and any dispatch — a capture
  failure still returns `HTTP 500` and dispatches **nothing** (`Queue::assertNothingPushed()`)
  (AC3, ADR-010 unchanged); the resolved response is identical to #3's behaviour regardless of mode
  (AC2).
- **Testing:** extend `tests/Feature/Ingest/IngestControllerTest.php` — Async-mode dispatch
  assertion + no-`DeliveryAttempt`-at-return; FIFO-mode `fifo_dispatches` row + `AdvanceProxyFifoQueue`
  dispatch assertion; capture-failure-dispatches-nothing for both modes; response identity check
  against a faked-failing destination (drain after response, assert response unchanged).
- **Completion notes:** Controller now wraps capture in a `DB::transaction`; for FIFO it creates the
  `fifo_dispatches` `pending` row (keyed to the captured event) in the SAME commit as capture. After
  the commit it resolves the response, then dispatches by mode `afterCommit()`: FIFO →
  `AdvanceProxyFifoQueue::dispatch($proxy->id)`, Async → `ProcessIngestedWebhook::dispatch($ingestId)`.
  Capture-failure path unchanged in spirit — the transaction rolls back, returns 500, dispatches
  nothing. Four new `IngestControllerTest` cases (async dispatches ProcessIngestedWebhook + no
  DeliveryAttempt/fifo row at return; FIFO commits one pending row + dispatches the advancer; FIFO
  capture-failure 500 + `Queue::assertNothingPushed()`). Response-identity (AC2) is covered
  end-to-end in T13. Under the `sync` driver (no `Queue::fake`), `afterCommit` dispatch fires
  immediately post-commit so existing inline-delivery tests stay green (38 ingest+delivery tests
  pass). All three checks green.

---

## T13 — Queued dispatch & preserved #3 guarantees acceptance tests (AC1–AC3, ADR-004/ADR-010)

- **Description:** End-to-end feature tests over the real `POST/PUT /ingest/{token}` route proving
  the queued-dispatch half is wired correctly end-to-end (not just true at the controller-unit
  level, T12). No new production code expected; fix any wiring gap here.
- **Dependencies:** T12
- **Files:** `tests/Feature/Ingest/QueuedDispatchAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - A valid ingest to an Async or FIFO proxy **dispatches** processing and returns its response
    without running delivery inline — no `DeliveryAttempt` exists at request return, for both modes
    (AC1).
  - The response status/body is identical to #3 and independent of delivery outcome — fake a
    destination to 500/throw, drain the queue, assert the ingest response (captured before draining)
    was unaffected (AC2, ADR-004).
  - Capture still commits before the response and before any dispatch; a capture failure returns
    500 and dispatches nothing for both modes (`Queue::assertNothingPushed()`) (AC3, ADR-010).
- **Testing:** the cases above using `Queue::fake()` for dispatch assertions and `Http::fake()` for
  destination outcomes.
- **Completion notes:** New `QueuedDispatchAcceptanceTest`, `#[DataProvider('modes')]` over
  async+fifo (repo uses the PHPUnit attribute, not the `@dataProvider` docblock). Four ×2 = 8 cases:
  ingest dispatches processing + zero `DeliveryAttempt` at return (AC1); response is the configured
  `200/ACK` independent of a 500-faking destination and of a throwing destination, drained inline
  under the sync driver (AC2, ADR-004); capture failure returns 500 + `Queue::assertNothingPushed()`
  for both modes (AC3, ADR-010). No production change needed — wiring already correct from T12. All
  three checks green.

## T14 — Async fan-out acceptance tests (AC5, AC8, AC10)

- **Description:** End-to-end proof that draining the queue for an Async proxy fans out correctly,
  complementing T8's unit-level branch test.
- **Dependencies:** T8, T9, T12
- **Files:** `tests/Feature/Ingest/AsyncDispatchAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - A proxy with no explicit `processing_mode` is `async`; existing #1/#3-shaped (factory) rows
    read `async` (AC5).
  - Draining the queue after ingest delivers to **every** destination, each as a separate queued
    job, producing exactly one payload-free `DeliveryAttempt` and the corresponding events per
    destination (AC5, AC8, ADR-003 unchanged).
  - One destination's job failing (faked 500/throw) does not prevent the others from succeeding
    (AC10).
- **Testing:** the cases above using `Queue::fake()` to assert per-destination dispatch, then
  `Queue::assertPushed(...)`-driven manual `handle()` invocation or draining under the `sync` queue
  driver, plus `Http::fake()` for outcomes.
- **Completion notes:** New `AsyncDispatchAcceptanceTest` (4 cases): a factory proxy (reloaded)
  reads `Async` (AC5); `ProcessIngestedWebhook::run` under `Queue::fake` pushes one
  `DeliverToDestination` per destination onto the webhooks queue (`assertPushedOn(..., 3)`); draining
  inline (sync) yields exactly one payload-free `DeliveryAttempt` + `DeliverySucceeded` per
  destination, with a schema check that no payload/body column exists (AC5/AC8, ADR-003); one
  destination faked to 500 leaves the other two succeeded, 1 failed (AC10). Gotcha recorded: the
  schema-default `processing_mode` only materialises after a model reload (`fresh()`). No production
  change. All three checks green.

## T15 — FIFO ordering + per-proxy isolation acceptance tests (AC6, AC7)

- **Description:** End-to-end proof that a FIFO proxy's events settle in received order and never
  block or are blocked by another proxy, complementing T10's unit-level advancer test.
- **Dependencies:** T10, T12
- **Files:** `tests/Feature/Ingest/FifoOrderingAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - Several events ingested in sequence for a FIFO proxy are delivered to their destinations in the
    **order they were received** — assert delivery order (e.g. via `DeliveryAttempt.started_at` or
    call order on a faked HTTP client) matches receive order after draining (AC6).
  - **Per-proxy isolation:** a FIFO proxy and a second proxy (Async or FIFO) are ingested and
    processed concurrently; the first proxy's line advancing never delays or blocks the second's
    delivery (AC7).
- **Testing:** the cases above, draining both proxies' queued work and asserting order/isolation via
  `Http::fake()` call sequencing and `fifo_dispatches`/`DeliveryAttempt` timestamps.
- **Completion notes:** New `FifoOrderingAcceptanceTest` (3 cases): (1) three sequentially-ingested
  raw bodies (`evt-1..3`) settle and are delivered in receive order — asserted via `Http::recorded()`
  body sequence + 3 `settled` rows (AC6); (2) per-proxy isolation — proxy A's line frozen at a live
  claim still lets proxy B ingest and deliver immediately, and A's claim is left untouched (AC7); (3)
  two FIFO proxies with interleaved ingests each deliver only their own events, each in order. Under
  the sync driver each ingest drains its own advance inline, and the `WithoutOverlapping` reducer
  intentionally drops the (redundant) self-dispatch — sequential ingests still yield in-order
  delivery because each advancer claims its proxy's lowest-pending row. Raw bodies posted via
  `$this->call(...)` so the forwarded request body equals what was sent. No production change. All
  three checks green.

## T16 — FIFO claim contention + liveness/sweeper acceptance tests (ADR-005 (a)/(b))

- **Description:** End-to-end proof of the claim's correctness under contention and the sweeper's
  liveness net, complementing T10/T11's unit-level tests.
- **Dependencies:** T10, T11
- **Files:** `tests/Feature/Ingest/FifoLivenessAcceptanceTest.php` (new)
- **Acceptance Criteria:**
  - **Single-advancer under contention:** two `AdvanceProxyFifoQueue` runs dispatched for the same
    proxy claim at most one event between them — assert no two `fifo_dispatches` rows are ever
    `claimed` simultaneously for that proxy, and delivery order still holds (ADR-005 (a)).
  - **Liveness:** an orphaned claim (a `claimed` row with an expired `lease_expires_at`, simulating a
    crashed worker) is reset to `pending` by `SweepStalledFifoDispatches` (T11) and the line advances
    on the next sweep/advancer run (ADR-005 (b)).
- **Testing:** the cases above — simulate concurrent advancer dispatch (e.g. two direct `::run()`
  calls against the same pre-seeded claim state) and a manually-expired lease row, then assert via
  `fifo_dispatches` row state and delivery outcome.
- **Completion notes:** New `FifoLivenessAcceptanceTest` (4 cases). Contention (ADR-005 (a)): (1) a
  second advancer early-returns on an existing live claim without claiming the next row; (2) the
  strong contention proof — a concurrent `AdvanceProxyFifoQueue::run` is fired from inside the
  `Http::fake` closure (i.e. WHILE advancer #1's event is claimed and in flight, outside the claim
  transaction); it asserts exactly one row is `claimed` at that instant and the concurrent run does
  NOT claim row 2 (the atomic claim, not `WithoutOverlapping`, is what enforces this); (3) two runs
  on a single pending row deliver it exactly once. Liveness (ADR-005 (b)): (4) an orphaned claim
  (expired lease) is reaped to `pending` by `SweepStalledFifoDispatches`, the idle proxy is nudged,
  and the next advancer settles the reaped row. The T16 tripwire (split rather than shrink) was not
  needed — all cases fit one file cleanly. No production change. All three checks green.

## T17 — Idempotency acceptance tests (AC9)

- **Description:** End-to-end proof of exactly-once settlement under simulated queue redelivery,
  complementing T9's unit-level test.
- **Dependencies:** T9
- **Files:** `tests/Feature/Ingest/DeliveryIdempotencyAcceptanceTest.php` (new)
- **Acceptance Criteria:** re-running an already-settled `DeliverToDestination` job for the same
  `(ingest_id, destination_id, attempt_number)` — simulating the queue's at-least-once redelivery —
  produces **no** second HTTP send, **no** duplicate settled `DeliveryAttempt` record, and **no**
  duplicate event (assert exactly one row and `Http::assertSentCount` unchanged after the second
  run); the `UNIQUE` index rejects a duplicate raw insert.
- **Testing:** the case above via `Http::fake()`/`Event::fake()`, driving the job's `handle()` (or
  its queued dispatch) twice for the same unit.
- **Completion notes:** New `DeliveryIdempotencyAcceptanceTest` (2 cases): a real async ingest drains
  one successful delivery, then a reconstructed `DeliveryUnit` with the SAME `(ingest_id,
  destination_id, attempt_number)` is re-run to simulate at-least-once redelivery — asserts still one
  row, one `Http` send, one `DeliverySucceeded` event, row still `Succeeded` (AC9); plus a raw
  duplicate insert rejected by the unique index. No production change (T9's guard already handles it).
  All three checks green.

## T18 — Mid-flight mode-change acceptance test (plan §Mid-flight mode change ruling)

- **Description:** End-to-end proof of the plan's technical ruling: switching `processing_mode`
  mid-flight is a routine config change with no draining/cancellation, and no accepted event is
  lost, duplicated, or reordered among its own-mode peers.
- **Dependencies:** T12, T15
- **Files:** `tests/Feature/Proxies/ProcessingModeSwitchAcceptanceTest.php` (new)
- **Acceptance Criteria:** switching a proxy `async → fifo` (and back `fifo → async`) persists and
  validates (per T19/T20); events already enqueued as `fifo_dispatches` rows before a `fifo → async`
  switch continue to drain **in order** via the advancer/sweeper after the switch (assert the
  sweeper/advancer still settle them); events ingested **after** the switch follow the new mode
  (Async events fan out in parallel; new FIFO events start a fresh ordered line).
- **Testing:** the case above — ingest events under one mode, switch the proxy's mode via the
  update endpoint, ingest more events, drain, and assert the pre-switch events settle per their
  original mode's semantics while post-switch events follow the new mode.
- **Completion notes:** New `ProcessingModeSwitchAcceptanceTest` (4 cases): switch persists both
  directions; pre-switch FIFO ordering rows still drain in receive order via the advancer after a
  `fifo → async` switch (asserted via `settled_at` order + delivery now dispatched, not inline);
  post-switch ingests follow the new mode (`fifo → async` → no new fifo row, `ProcessIngestedWebhook`
  dispatched; `async → fifo` → fresh pending ordering row + advancer dispatched). The switch is
  applied at the model level (`$proxy->update([...])`) — T18's dependency list is T12/T15 only, and
  endpoint-level persistence/validation is T19/T20; noted in the class docblock. No production
  change. All three checks green.

---

## T19 — `StoreProxyRequest`/`UpdateProxyRequest`: `processing_mode` validation (AC4)

- **Description:** Per plan §Validation. Add an identical rule to both FormRequests, mirroring the
  existing `mode` rule: `processing_mode` → `['required', Rule::enum(ProcessingMode::class)]` (T1).
  No change to existing `name`/`mode`/`response_status`/`response_body`/`destinations.*` rules.
- **Dependencies:** T4
- **Files:** `app/Http/Requests/StoreProxyRequest.php`, `app/Http/Requests/UpdateProxyRequest.php`
- **Acceptance Criteria:** an invalid `processing_mode` value (e.g. `'random'`, an integer, empty
  string) is rejected under the `processing_mode` error key on both Store and Update; `'async'` and
  `'fifo'` are both accepted; an absent value is rejected (required — the form always submits one,
  per design-04); existing validation cases remain green.
- **Testing:** extend `tests/Feature/Proxies/ProxyRequestValidationTest.php` with data-provider
  cases for `processing_mode` on both Store and Update (invalid value rejected, both valid values
  accepted, absent rejected).
- **Completion notes:** Added `'processing_mode' => ['required', Rule::enum(ProcessingMode::class)]`
  to both `StoreProxyRequest` and `UpdateProxyRequest` (mirrors the `mode` rule). Extended
  `ProxyRequestValidationTest` (3 new methods ×2 request classes) — invalid value rejected (unknown
  string / integer / empty string), both `async`/`fifo` accepted, absent rejected as required; also
  added `processing_mode` to that test's `validData()` default. Ripple from the new required field:
  updated the store/update payload builders in `ProxyStoreTest`, `ProxyUpdateTest`, and
  `ProxyAuthorizationTest` to include `processing_mode` so their existing HTTP-level cases stay green.
  Full Proxies suite (86) + all three checks green.

## T20 — `ProxyController` store/update: persist `processing_mode` (AC4, AC5)

- **Description:** Per plan §Validation. `store()` already mass-assigns validated data onto
  `Proxy::make($data)`; once `processing_mode` is `#[Fillable]` (T4) and validated (T19) it persists
  automatically — verify explicitly. `update()`'s explicit `$proxy->update([...])` array gains
  `'processing_mode' => $data['processing_mode']` alongside `name`/`mode`/`response_status`/
  `response_body`.
- **Dependencies:** T19
- **Files:** `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:** creating a proxy with an explicit `processing_mode` persists it exactly;
  creating without one is rejected by validation (T19 — required, no silent default at the
  controller); updating a proxy's `processing_mode` (`async ↔ fifo`) persists the new value; a
  proxy created before this feature (factory-simulated, schema-default `async`) is unaffected until
  explicitly changed.
- **Testing:** extend `tests/Feature/Proxies/ProxyStoreTest.php` and
  `tests/Feature/Proxies/ProxyUpdateTest.php` with cases for `async`, `fifo`, and the `async ↔ fifo`
  switch on update.
- **Completion notes:** `store()` unchanged — `Proxy::make($data)` already mass-assigns
  `processing_mode` now that it is `#[Fillable]` (T4) + validated (T19); verified explicitly by test.
  `update()` gained `'processing_mode' => $data['processing_mode']` in its explicit update array.
  New `ProxyStoreTest` cases: explicit `async`/`fifo` persists; absent value rejected (`assertInvalid`
  + no row). New `ProxyUpdateTest` case: `async -> fifo -> async` switch persists across two updates.
  14 store+update tests + all three checks green.

## T21 — `proxyProcessingModes.ts` data const (design-04 recommendation)

- **Description:** Per design-04 §Components "Recommended data-const treatment" and the `data/` +
  `DataOption` convention ratified during #3 (`docs/standards/coding.md`,
  `resources/js/data/proxyResponseStatuses.ts` precedent). New
  `resources/js/data/proxyProcessingModes.ts` exporting `PROXY_PROCESSING_MODES` — a
  `DataOption<string>`-typed `as const` array (`{value: 'async', label: 'Async'}`,
  `{value: 'fifo', label: 'FIFO'}`), the derived `ProcessingMode` value union
  (`'async' | 'fifo'`), and a label-lookup helper (`proxyProcessingModeLabel`) — the single source
  for the form select, Show badge, and Index column labels.
- **Dependencies:** none
- **Files:** `resources/js/data/proxyProcessingModes.ts` (new)
- **Acceptance Criteria:** the const exposes exactly two options (`async`/`Async`,
  `fifo`/`FIFO`), verbatim-matching the design's labels; the label-lookup helper returns the correct
  label for each value; `pnpm types:check` green.
- **Testing:** none automated (no JS test framework, per `docs/standards/coding.md` /
  role-based-collaboration precedent) — `pnpm types:check` verifies the derived type/shape compiles;
  covered indirectly by T23–T25's manual verification.
- **Completion notes:** _pending_

## T22 — `ProxyResource` + `types/proxies.ts`: expose `processing_mode` (design-04 §API)

- **Description:** Per plan §API → Management form props. Add `processing_mode` (`'async'|'fifo'`)
  to `ProxyResource::toArray()` so the shared Create/Edit form, Show badge, and Index column can all
  read it. Add `processing_mode: ProcessingMode` to both `ProxyListItem` and `ProxyDetail` in
  `resources/js/types/proxies.ts`, importing the `ProcessingMode` union from T21's data const (same
  re-export pattern `ProxyResponseStatus` already uses).
- **Dependencies:** T4, T20, T21
- **Files:** `app/Http/Resources/ProxyResource.php`, `resources/js/types/proxies.ts`
- **Acceptance Criteria:** the index/show/edit Inertia payload includes `processing_mode` reflecting
  the DB value (`'async'` or `'fifo'`) for every proxy, including the Index list (needed for T25's
  column); `pnpm types:check` green.
- **Testing:** extend `tests/Feature/Proxies/ProxyIndexShowTest.php` asserting `processing_mode`
  appears with the correct value on index/show for an `async` and a `fifo` proxy.
- **Completion notes:** _pending_

## T23 — `ProxyForm.vue` + `Create.vue` + `Edit.vue`: Processing select field (design-04 Screen 2, AC4)

- **Description:** Per design-04 Screen 2. Add a `Processing` field to the Details section of
  `resources/js/pages/proxies/ProxyForm.vue`, directly below `Mode` and above `Response status`: a
  two-option `Select` (`Label for="processing_mode"`, `SelectTrigger id="processing_mode"
  class="w-full sm:w-64"`, `SelectItem value="async"` → "Async", `SelectItem value="fifo"` →
  "FIFO"), sourced from T21's data const, defaulting to `async` on create. Help text
  (`id="processing-help"`) states the design's verbatim tradeoff copy; `InputError`
  (`id="processing-error"`) under `form.errors.processing_mode`; `aria-describedby="processing-help
  processing-error"` and `:aria-invalid` on the trigger, matching the `response_status` field's
  fully-wired pattern. No side effect on any other field (unlike `response_status`/204). Wire
  `Create.vue`'s `initial` to default `processingMode: 'async'`, and `Edit.vue`'s `initial` (and its
  `EditProxy` interface) to pass through the resource's `processing_mode` (T22).
- **Dependencies:** T22, T20
- **Files:** `resources/js/pages/proxies/ProxyForm.vue`, `resources/js/pages/proxies/Create.vue`,
  `resources/js/pages/proxies/Edit.vue`
- **Acceptance Criteria (manual — no JS test framework, per `docs/standards/coding.md` /
  role-based-collaboration/plan-03-T7 precedent):** creating a proxy leaves Processing at Async by
  default unless changed; choosing FIFO on create persists and displays on Edit; editing an existing
  proxy's Processing value persists the change; an invalid round-tripped value surfaces the server
  validation error (T19) under the field with correct `aria-invalid`/focus-on-error behaviour
  (matching `name`/`response_status`); the help text matches design-04's tradeoff copy verbatim;
  keyboard reachability confirmed (Tab/Enter/Arrow keys); light and dark palettes checked.
- **Testing:** none automated; the manual walkthrough above is the acceptance gate, recorded in
  Completion notes. `pnpm types:check` / `lint:check` / `format:check` green.
- **Completion notes:** _pending_

## T24 — `Show.vue`: Processing badge (design-04 Screen 3, PM Ruling 1)

- **Description:** Per design-04 Screen 3 / PM design-gate Ruling 1 (Approved — header badge, not a
  card). Add a second `Badge` (`variant="secondary"`) beside the existing Mode badge in
  `resources/js/pages/proxies/Show.vue`'s header row, showing `Async` or `FIFO` verbatim-matched to
  T21's labels (via the label-lookup helper). No new helper paragraph; read-only, no click behavior.
- **Dependencies:** T22
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria (manual — no JS test framework):** the Processing badge renders immediately
  after the Mode badge for both an Async and a FIFO proxy, with the exact label text from T21; no
  layout regression on narrow viewports (header row wraps like the existing Mode badge); light/dark
  palettes checked.
- **Testing:** none automated; manual walkthrough recorded in Completion notes. `pnpm types:check` /
  `lint:check` / `format:check` green.
- **Completion notes:** _pending_

## T25 — `Index.vue`: Processing column (design-04 Screen 1, PM Ruling 2)

- **Description:** Per design-04 Screen 1 / PM design-gate Ruling 2 (Approved — keep the column).
  Add a `Processing` column to `resources/js/pages/proxies/Index.vue`'s table, positioned
  immediately after `Mode` and before `Ingest URL` (`Name | Mode | Processing | Ingest URL |
  Actions`), rendering the same `Badge variant="secondary"` pattern as the `Mode` column, sourced
  from T21's label-lookup helper.
- **Dependencies:** T22
- **Files:** `resources/js/pages/proxies/Index.vue`
- **Acceptance Criteria (manual — no JS test framework):** the Processing column appears in the
  correct position with the correct badge label for both Async and FIFO proxies across a paginated
  list; the existing horizontally-scrollable table behavior on narrow viewports is unaffected; no
  regression to the existing Delete-confirmation flow (T27 rework note, item #1) since this task
  only adds a `TableCell`; light/dark palettes checked.
- **Testing:** none automated; manual walkthrough recorded in Completion notes. `pnpm types:check` /
  `lint:check` / `format:check` green.
- **Completion notes:** _pending_

---

## Handoff

- **Inputs:** `docs/plans/plan-04-queued-processing.md` (Approved), PRD-04 (Approved, 13 ACs),
  ADR-011 (Accepted, incl. the data-model gate), ADR-001/003/004/005/007/010 (Accepted, unchanged),
  design-04 (Approved, incl. both PM design-gate rulings), `docs/standards/planning.md`; grounding
  reads of `app/Http/Controllers/IngestController.php`, `app/Actions/{ProcessIngestedWebhook,
  DeliverStep,DeliverToDestination}.php`, `app/Pipeline/{PipelineContext,PipelineFactory,
  DeliveryUnit}.php`, `app/Models/{Proxy,WebhookEvent,DeliveryAttempt}.php`,
  `app/Http/Requests/{Store,Update}ProxyRequest.php`, `app/Http/Controllers/ProxyController.php`,
  `app/Http/Resources/ProxyResource.php`, `config/ingest.php`, `config/queue.php`,
  `routes/console.php`, `resources/js/pages/proxies/{ProxyForm,Create,Edit,Show,Index}.vue`,
  `resources/js/types/{proxies,data}.ts`, `resources/js/data/proxyResponseStatuses.ts`,
  `database/migrations/2026_07_30_00000{1,3}_create_*.php`,
  `database/migrations/2026_08_04_000002_create_webhook_events_table.php`.
- **Outputs:** this task plan (`docs/tasks/queued-processing-tasks.md`).
- **Dependencies:** Data model: T1→T4, T2→T5, T6 independent. Mechanism: T4/T3→T8; T6→T9; T5/T7/T8/
  T3→T10; T5/T10/T3→T11; T4/T5/T7/T10→T12. Backend acceptance: T12→T13; T8/T9/T12→T14; T10/T12→T15;
  T10/T11→T16; T9→T17; T12/T15→T18. Form: T4→T19→T20. Frontend: T21 independent; T4/T20/T21→T22→
  {T23 (also ←T20), T24, T25}. No task depends on a later task.
- **Outstanding Questions:** none blocking implementation. Q-04-01 (FIFO/Async mechanism selection)
  is resolved by ADR-011. V8 (throughput/latency SLA) remains Owner-deferred, non-blocking — no task
  here asserts a performance number. V3 (beyond-Redis transport) remains deferred by ADR-005 — no
  task builds it. The plan's Owner-approval flags (ADR-011, the `processing_mode` column, the
  `fifo_dispatches` table, and the `delivery_attempts` unique index) were all approved 2026-08-04
  per `docs/status.md`.
- **Next Agent:** Senior Developer.
