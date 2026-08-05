# Technical Plan: Queued processing (FIFO & Async) — item #4

- **Status:** Approved (Principal-Engineer self-certified) — **except** the
  data-model change and **ADR-011**, which are **flagged for Project Owner approval**
  (see Owner-approval flags). Sections depending on the new columns/table are
  contingent on that approval; the plan does **not** proceed to Task Planning on the
  data-model parts until the Owner approves.
- **Author:** Principal Engineer
- **PRD:** `docs/product/prd-04-queued-processing.md` (Approved — Project Owner, 2026-08-04)
- **Design spec:** `docs/design/design-04-queued-processing.md` (Approved — Product Manager, 2026-08-04)
- **Approved by / date:** Principal Engineer, 2026-08-04 (ADR-011 + data-model pending Owner)

## Overview
Item #4 moves destination delivery off the synchronous ingest request and onto a
**Redis queue** (ADR-005), and adds a per-proxy **Async (default) / FIFO (opt-in)**
processing mode. The ingest hot path is unchanged through the #3 guarantees: the raw
payload is still captured **synchronously, committed, before** the response (ADR-010),
and the upstream response is still resolved from proxy config **before and
independent of** delivery (ADR-004). The single change at the end of the handler is
the **dispatch flip**: instead of `ProcessIngestedWebhook::run($ctx)` inline, the
handler dispatches processing to the queue **after the capture commit** and returns
immediately (AC1–AC3). **Async** (default) dispatches the pipeline per event and fans
out one queued `DeliverToDestination` job per destination — parallel, unordered
(AC5). **FIFO** enqueues a per-proxy **ordering row** and a single-advancer
(`AdvanceProxyFifoQueue`) that processes that proxy's events one at a time in received
order (AC6), never touching or blocking any other proxy (AC7). Delivery stays
idempotent under the queue's inherent at-least-once redelivery via a new unique
constraint (AC9), and keeps emitting the same payload-free `DeliveryAttempt` records
and events (ADR-003, AC8). This plan realizes ADR-005 and records the concrete
mechanism in **ADR-011** (per-proxy FIFO claim-based single-advancer, the
`processing_mode` attribute, dispatch-by-reference, delivery idempotency). It invents
no requirements; the FIFO/idempotency mechanics are the Principal Engineer's per
Q-04-01 (`docs/questions/prd-04-q-04-01-fifo-async-composition.md`, resolved).

## Architecture

The seams are already in place: ADR-004 `ResponseResolver`, ADR-010 pre-dispatch
capture, ADR-005 dispatch-timing on the two Actions, ADR-003 attempt records. #4
exercises the dispatch seam for the first time and adds the FIFO single-advancer. **No
change to the pipeline shape, the response contract, the capture placement, or the
attempt-record shape.**

**Ingest hot path (`IngestController`) — revised only at the final dispatch step
(AC1–AC3, AC5, AC6, ADR-004/005/010):**
1. Resolve proxy by SHA-256 token hash; `abort(404)` on miss — unchanged (ADR-006).
2. Mint one `ingest_id`; read raw method/headers/body — unchanged.
3. **Capture** the raw payload synchronously (committed); on failure `abort(500)` and
   dispatch nothing — unchanged (ADR-010, AC of #3 preserved). Retains the returned
   `WebhookEvent`.
4. Resolve the upstream response from proxy config — unchanged (ADR-004).
5. **NEW — dispatch by mode, after the capture commit (`afterCommit`):**
   - **Async:** `ProcessIngestedWebhook::dispatch($ingestId)`.
   - **FIFO:** create the `fifo_dispatches` ordering row (`pending`), then
     `AdvanceProxyFifoQueue::dispatch($proxy->id)`.
6. Return the already-resolved response — reached without waiting on any delivery
   (AC1). Capture committed before this dispatch, so the #3 guarantee is unbroken.

**Async processing (worker):** `ProcessIngestedWebhook` rebuilds the
`PipelineContext` from the durable `webhook_events` row (dispatch-by-reference — see
Services), runs the native `Pipeline` (`[DeliverStep]` for both proxy modes at #4),
and `DeliverStep` **dispatches** one `DeliverToDestination` job per destination onto
the Redis queue → parallel, independent, unordered (AC5, AC10).

**FIFO processing (worker):** `AdvanceProxyFifoQueue($proxyId)` **atomically claims**
the proxy's lowest `pending` ordering row iff the proxy has **no live claim**
(single-advancer, ADR-011/ADR-005 (a)); rebuilds the context from that event's
`webhook_events` row; runs the same pipeline with `DeliverStep` delivering
**inline** (so the event is fully settled when the job completes); marks the row
`settled`; then **self-dispatches** to advance to the next event. A **scheduled
sweeper** re-dispatches the advancer for any proxy with pending rows and no live
claim, and reaps orphaned (expired-lease) claims — liveness under worker
crash/deploy (ADR-005 (b)). Ordering scope is **per-proxy** and never touches another
proxy's rows (AC7).

**No parallel path (AC8, ADR-003).** Under both modes `DeliverToDestination` writes
exactly the same payload-free `DeliveryAttempt` + emits the same events; the response
still never reads them (ADR-004). `webhook_events` (payload), `delivery_attempts`
(outcome) and `fifo_dispatches` (FIFO ordering only) are joined solely by
`ingest_id` / ids — no reconstructed or duplicate stream.

## Data Model

Three changes, **all requiring Owner approval as data-model changes** (see flags).
MySQL 8.0 / InnoDB. All additive and reversible for local dev; no destructive
backfill.

### `proxies` — add `processing_mode` (AC4, AC5; ADR-011)
New migration `add_processing_mode_to_proxies_table`:

| Column | Type | Notes |
|---|---|---|
| `processing_mode` | **`enum('async','fifo')` NOT NULL, default `'async'`**, `after('mode')` | Persisted per-proxy mode. NOT NULL + schema default `async` means every existing #1/#3 row is `async` with **no backfill** and no observed-behaviour change (AC5). Mirrors the existing `mode` enum exactly (ADR-002 precedent). |

- **Exact shape proposed for Owner approval:** type `ENUM('async','fifo')`;
  nullability **NOT NULL**; default **`'async'`**; allowed values **`async`, `fifo`**
  only. Backed by `App\Enums\ProcessingMode` (`Async='async'`, `Fifo='fifo'`), cast on
  `Proxy` (`'processing_mode' => ProcessingMode::class`), added to `Proxy`
  `#[Fillable]`.

### `fifo_dispatches` — per-proxy FIFO ordering/claim state (AC6, AC7; ADR-011)
New table + `FifoDispatch` model. **One row per received event, for FIFO proxies
only.** Holds ordering/claim state — **not** a payload or outcome store (those stay
`webhook_events` / `delivery_attempts`).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | FK → teams, `constrained()` | team-scoped like sibling tables; set explicitly on the team-unscoped ingest path. |
| `proxy_id` | FK → proxies, `constrained()` | the FIFO proxy; the scan key. |
| `webhook_event_id` | FK → webhook_events, `constrained()`, **UNIQUE** | the **monotonic order key** (ADR-005: prefer the durable event id, `MIN(...)` over the pending set; gaps harmless). UNIQUE = one ordering row per event, and makes the ingest-side insert idempotent under a future at-least-once ingest replay. |
| `status` | `enum('pending','claimed','settled')` NOT NULL default `'pending'` | claim lifecycle. `dead_lettered` is a **#6** addition (bounded HoL), not built here. |
| `claimed_at` | timestamp, nullable | set on claim; audit/lease anchor. |
| `lease_expires_at` | timestamp, nullable | claim lease; the sweeper treats `claimed` rows past this as orphaned. |
| `settled_at` | timestamp, nullable | set when the event is fully delivered. |
| `created_at` / `updated_at` | timestamps | |
| indexes | `UNIQUE(webhook_event_id)`, `(proxy_id, status, webhook_event_id)` | the composite index serves both the "lowest pending for a proxy" scan and the "any live/pending claim for a proxy" check cheaply. |

- **Model.** `FifoDispatch` uses `BelongsToCurrentTeam` (future team-scoped reads),
  `belongsTo(Proxy)`, `belongsTo(WebhookEvent)`; casts `status =>
  FifoDispatchStatus::class` (`App\Enums\FifoDispatchStatus`), `claimed_at` /
  `lease_expires_at` / `settled_at` => `datetime`. No `SoftDeletes`.

### `delivery_attempts` — add idempotency unique index (AC9; ADR-011)
New migration `add_idempotency_unique_to_delivery_attempts_table`:

| Change | Notes |
|---|---|
| **`UNIQUE(ingest_id, destination_id, attempt_number)`** | Makes settlement exactly-once under at-least-once redelivery (AC9). Safe on existing data: pre-#4 there is ≤1 attempt per `(ingest_id, destination_id)` (`attempt_number` always `1`), so no duplicate blocks the index. Replaces reliance on the plain `(ingest_id)` index for the dedupe path; keep the existing indexes. |

## API

**No route changes.** The public ingest endpoint keeps its guards
(`EnsureIngestIsSecure`, `EnforceIngestBodyLimit`, `throttle:ingest`) and its
contract; the management resource routes are unchanged.

- **Ingest response (AC2/AC3).** Identical to #3 — `ResponseResolver::resolve($proxy)`
  returns the configured 2xx/body (or the `202` default), resolved before dispatch and
  never from delivery outcome. Moving delivery to the queue changes nothing the
  upstream sender observes.
- **Management form props (design-04).** `ProxyResource` gains **`processing_mode`**
  (`'async'|'fifo'`) so the shared Create/Edit form pre-fills it and the Index/Show
  badges render it. No other prop shape change.

### Frontend (design-04 — settled, do not redesign)
- **`resources/js/data/proxyProcessingModes.ts`** — new `DataOption`-typed const
  (`{value:'async',label:'Async'}`, `{value:'fifo',label:'FIFO'}`) with a
  label-lookup helper, per the ratified `data/` + `DataOption` convention
  (`coding.md`; design-04 recommendation). Single source for the form select, Show
  badge, and Index column labels.
- **`ProxyForm.vue`** — a `Processing` `Select` in the Details section **below Mode,
  above Response status** (design-04 Screen 2): `Label for="processing_mode"`,
  `SelectTrigger id="processing_mode" class="w-full sm:w-64"`, two `SelectItem`s
  (`async` default-selected on create, `fifo`), the design's help-text copy
  (`id="processing-help"`), `InputError` (`id="processing-error"`),
  `aria-describedby="processing-help processing-error"` and `:aria-invalid`. Bound to
  `form.processing_mode` (default `'async'`); no side effect on any other field.
- **`Index.vue`** — a `Processing` column (`Badge variant="secondary"`) after `Mode`,
  before `Ingest URL` (design-04 Screen 1).
- **`Show.vue`** — a second `Badge variant="secondary"` beside the Mode badge in the
  header (design-04 Screen 3).
- **`types/proxies.ts`** — add `processing_mode: ProcessingMode` to `ProxyListItem`
  and `ProxyDetail`; export the `ProcessingMode` union derived from the new data const.

## Services

- **`IngestController`** (existing) — replace the final `ProcessIngestedWebhook::
  run($ctx)` with the mode branch in Architecture step 5, dispatched `afterCommit`.
  Keep the `WebhookEvent` returned by capture to populate the FIFO ordering row. The
  controller no longer builds `PipelineContext` (that moves to the worker — see
  below).
- **`ProcessIngestedWebhook`** (existing, `AsAction`) — change `handle` to accept the
  **`string $ingestId`** and rebuild the `PipelineContext` from the durable
  `webhook_events` row (proxy loaded by id **including trashed**, so an event accepted
  before a later soft-delete still delivers), then run the pipeline. Dispatch-by-
  reference keeps the job payload tiny and loss-free (the pipeline input **is** the raw
  captured event) and avoids serializing a `Proxy` model or a large body (ADR-011).
  Keep a thin private `runPipeline(PipelineContext)` if useful for unit tests.
- **`DeliverStep`** (existing) — branch on the proxy's `processing_mode`:
  - **Async:** `DeliverToDestination::dispatch($unit)->onQueue(<webhooks>)
    ->afterCommit()` per destination (parallel).
  - **FIFO:** `DeliverToDestination::run($unit)` per destination (inline, so the
    advancing job settles the whole event before advancing). One destination failing
    still does not abort the loop (AC10) — `DeliverToDestination` catches its own
    transport errors, unchanged. `DeliveryUnit` continues to carry the pipeline's
    output payload/headers so a later mapped payload (#8) flows to delivery unchanged.
- **`DeliverToDestination`** (existing, `AsAction`) — add **idempotency** (AC9): guard
  the `DeliveryAttempt` create against `UNIQUE(ingest_id, destination_id,
  attempt_number)`; if a row already exists in a **terminal** state
  (`succeeded`/`failed`) this is a redelivery of an already-settled unit → **skip**
  (no second HTTP send, no duplicate settled record, no duplicate event). A row still
  in `dispatched` (a prior attempt that crashed mid-flight) is re-driven to settlement
  on the same row. Otherwise unchanged — payload-free record, same events, same
  ADR-008 forward-header filter, same 15s timeout, `$tries = 1` (no #6 retry/backoff).
- **`AdvanceProxyFifoQueue`** (new, `App\Actions`, `AsJob`) — the FIFO single-advancer
  for one `proxyId`:
  1. **Atomic claim** in a short `DB::transaction`: `lockForUpdate` a live claim check
     (`status='claimed' AND lease_expires_at > now()` for the proxy) → if present,
     early-return (another advancer/self-dispatch owns the line). Else
     `lockForUpdate()->orderBy('webhook_event_id')->first()` the lowest `pending` row →
     if none, early-return → else set `status='claimed'`, `claimed_at=now()`,
     `lease_expires_at=now()+lease`. Claiming is the correctness primitive (ADR-005
     (a)); the transaction/`FOR UPDATE` serialises concurrent advancers so at most one
     row per proxy is in flight → order preserved.
  2. **Process outside the claim transaction:** `ProcessIngestedWebhook::run($event->
     ingest_id)` (inline pipeline, inline delivery because the proxy is FIFO).
  3. Mark the row `settled` (`settled_at=now()`).
  4. **Self-dispatch** `AdvanceProxyFifoQueue::dispatch($proxyId)` to advance (the
     low-latency happy path). Add `WithoutOverlapping("proxy:{proxyId}")` job
     middleware as a herd reducer (not the guard).
- **`SweepStalledFifoDispatches`** (new, `App\Actions` `AsCommand` or a scheduled
  closure) — the liveness net (ADR-005 (b)): (a) reset any `claimed` row whose
  `lease_expires_at < now()` back to `pending` (orphaned claim reaper — a worker died
  mid-event); (b) for every proxy that has `pending` rows and **no** live claim,
  `AdvanceProxyFifoQueue::dispatch($proxyId)`. Scheduled in `routes/console.php`
  (~`everyMinute`), matching the existing `Schedule::` pattern.
- **`ResponseResolver` / `PipelineFactory` / capture / `WebhookEventCapture`** —
  **unchanged.**

## Validation

Server-authoritative via the existing `StoreProxyRequest` / `UpdateProxyRequest`
(both get the same rule), mirroring `mode`:

- `processing_mode` — `['required', Rule::enum(ProcessingMode::class)]`. The form
  always submits `async` or `fifo`; any other value rejected. (Column is NOT NULL
  default `async`, so an absent value would still resolve to the default, but the
  form always sends one.)
- Controller `store`/`update` include `processing_mode` in the persisted attributes
  (already flows via `#[Fillable]` on create; add to the explicit `update([...])`
  array alongside `name`/`mode`).
- All existing rules (`name`, `mode`, `response_status`, `response_body`,
  `destinations.*`) unchanged.

## Mid-flight mode change — technical ruling (Designer's flagged question)

**Ruling: mode is bound per event at enqueue time; changing `processing_mode` on Save
is a routine config change affecting only subsequently-received events. No draining,
no in-flight cancellation, no data migration — and no UI warning is warranted.**

- **Async → FIFO:** in-flight Async per-destination jobs continue in parallel (they
  were enqueued as Async). New events create `fifo_dispatches` rows and are ordered
  from then on. During the brief transition window a new FIFO event may be delivered
  while an older in-flight Async event is still delivering — acceptable: the ordering
  guarantee (AC6) applies to events **received while the proxy is in FIFO mode**.
- **FIFO → Async:** already-enqueued `fifo_dispatches` rows keep draining **in order**
  via the advancer/sweeper (both operate on `fifo_dispatches` rows, not the proxy's
  current mode), so no accepted event is lost or reordered among its FIFO peers. New
  events dispatch on the Async path. The FIFO queue empties and stays empty.
- **Why safe / why no warning:** the change never violates the guarantee for events
  clearly received under one mode; it never drops or duplicates an accepted event
  (capture already committed; delivery is idempotent, AC9); and it matches the design's
  and PRD's framing of the choice as a **persisted proxy property, not a live
  operational toggle**. This stays **within** the design spec's stated assumption
  ("this spec assumes none is needed for a routine settings save"), so it does **not**
  return to the Designer. If the Owner later wants the transition-window semantics
  surfaced to users, that is a scoped Product-Manager/Designer follow-up, not a #4
  blocker.

## Risks
1. **Ops surface — Redis, workers, scheduler (within stack, ADR-005).** #4 requires a
   running Redis queue transport, ≥1 queue worker, and the scheduler
   (`schedule:run`) for the FIFO sweeper. No new dependency (Redis is in the stack /
   ADR-005; `QUEUE_CONNECTION`/queue routing is config). Tests run with the `sync`
   queue driver (jobs execute inline) and `Queue::fake()`. **Flag — deployment/runbook
   item; not a code blocker.** Concrete `QUEUE_CONNECTION=redis`, a dedicated
   `webhooks` queue name, worker count, and sweeper interval are config-driven defaults
   pinned at implementation (no SLA — V8 unset).
2. **FIFO head-of-line blocking is minimal at #4, real at #6.** With no retry/backoff
   (#4), a failing FIFO delivery settles as `failed` immediately, so the line advances
   after one attempt — no long HoL block. #6 introduces retry/backoff and a
   `dead_lettered` state to **bound** HoL; the `fifo_dispatches.status` seam is left
   open for it. **Non-blocking.**
3. **Order key vs strict commit order (ADR-005).** The `webhook_event_id` order key is
   assigned in insert order; under concurrent ingest, commit order may differ slightly
   from id order. This is the ADR-005-accepted MVP tradeoff (a per-proxy commit-order
   counter is only warranted if strict commit-order FIFO is later required); "in the
   order received" is satisfied to id-assignment granularity. **Non-blocking.**
4. **Large-body job payloads for Async delivery.** Async per-destination jobs carry the
   payload (raw body at #4) in the `DeliveryUnit`. Realistic webhook bodies are small;
   the ADR-006 ingest cap is a high placeholder already flagged for tuning before
   public MVP (plan-03 Risk 3). The pipeline-entry hop avoids this via
   dispatch-by-reference; delivery carries the payload deliberately so a mapped payload
   (#8) flows through. **Non-blocking; inherits the existing ADR-006 cap-tuning flag.**
5. **Sweeper/advancer redelivery races.** The atomic claim (`FOR UPDATE` + live-claim
   check) makes concurrent advancers, a self-dispatch, and a sweeper re-dispatch all
   converge on at most one in-flight event per proxy; the delivery unique index
   (AC9) absorbs any delivery-level redelivery. Covered by tests below. **Non-blocking.**

## Dependencies
- **No new packages.** Uses Laravel's queue (Redis driver, already configured),
  `lorisleiva/laravel-actions` (ADR-007, Accepted), Eloquent, migrations, the native
  `Pipeline`, the scheduler. **Stays within `docs/stack/stack.md` — no stack change.**
- **ADR-011** (Proposed, this plan) — the FIFO mechanism + `processing_mode` +
  dispatch-by-reference + delivery idempotency; gates the data-model + FIFO work.
- **ADR-005** (Accepted) — the dispatch seam this feature realizes; guardrails (a)–(d).
- **ADR-004 / ADR-010 / ADR-003** (Accepted) — response decoupling, pre-dispatch
  capture, payload-free records; #4 preserves all three unchanged.
- **ADR-007** (Accepted) — `::run`/`::dispatch` realization on the two Actions.
- PRD-04 (Approved 2026-08-04), design-04 (Approved 2026-08-04).

## Implementation Notes
- **Dispatch strictly after the capture commit** (`->afterCommit()` and/or dispatch
  only after the capture insert commits). Never dispatch or return the 2xx before
  capture commits (ADR-010/#3 AC5/AC6 preserved). For FIFO, the `fifo_dispatches` row
  must be committed before `AdvanceProxyFifoQueue` runs — insert it in/with the same
  commit as capture and dispatch the advancer `afterCommit`.
- **One `ingest_id` per request** remains the single correlator across
  `webhook_events`, `delivery_attempts`, and (via `webhook_event_id`)
  `fifo_dispatches`. Do not introduce a second key.
- **The atomic claim is the FIFO correctness primitive** — `FOR UPDATE` inside a
  transaction, live-claim check + lowest-pending scan + status flip. `WithoutOverlapping`
  is a herd reducer only. Do **not** rely on it for ordering or dedupe.
- **Do the HTTP delivery outside the claim transaction** — never hold the row lock
  across the outbound send.
- **Delivery idempotency:** guard on `UNIQUE(ingest_id, destination_id,
  attempt_number)`; skip an already-terminal attempt (no second send/record/event);
  re-drive a stuck `dispatched` row on the same record. Keep `$tries = 1` — #4 adds
  **no** retry/backoff/replay (that is #6). Queue-inherent at-least-once redelivery is
  a correctness concern handled by idempotency, not a retry feature.
- **Rebuild context from `webhook_events` on the worker** (proxy loaded by id including
  trashed). `body` decrypts transparently via the ADR-010 cast; `headers` cast to
  array. Never log the raw body or token on the worker path (coding.md never-log list).
- **FIFO liveness:** the sweeper resets expired-lease `claimed` rows to `pending` and
  re-dispatches idle-but-pending proxies; it must **not** reap a legitimately-leased
  (unexpired) claim. Lease duration and sweep interval are config-driven defaults
  (e.g. `ingest.fifo_lease_seconds`, ~90s; sweep ~every minute) — no SLA asserted.
- **Async never enqueues onto a FIFO proxy's line and vice-versa** — `fifo_dispatches`
  rows exist only for FIFO proxies, so Async's parallel path carries no FIFO machinery
  (AC7).
- Pint (`composer lint`) + PHPStan L7 (`composer types:check`) green; short
  Conventional-Commit messages with context list items (CLAUDE.md).

## Test strategy
Backend PHPUnit (`./vendor/bin/sail test`), `Http::fake()` for delivery,
`Queue::fake()` to assert dispatch, and the `sync` queue driver to run jobs inline.
Map to ACs:

**Queued dispatch & preserved #3 guarantees (feature, ingest path):**
- A valid ingest **dispatches** processing and returns its response without running
  delivery inline — `Queue::fake()` asserts `ProcessIngestedWebhook` (Async) /
  `AdvanceProxyFifoQueue` (FIFO) is queued; no `DeliveryAttempt` exists yet at request
  return (AC1).
- Response status/body is identical to #3 and independent of delivery — fake a
  destination to 500/throw after draining the queue; the ingest response is unchanged
  (AC2, ADR-004).
- Capture still commits before the response and before any dispatch; a capture failure
  returns 500 and **dispatches nothing** (`Queue::assertNothingPushed()`) (AC3,
  ADR-010).

**Async mode (default):**
- A default (no explicit mode) proxy is `async`; existing #1/#3 rows read `async`
  (AC5). Draining the queue delivers to **every** destination, each a separate job,
  with exactly one payload-free `DeliveryAttempt` + events per destination (AC5, AC8).
- One destination job failing does not affect the others (AC10).

**FIFO mode:**
- A FIFO proxy's events are delivered in received order — enqueue several events, drain
  with a single worker, assert delivery order matches receive order (AC6).
- **Per-proxy isolation:** a FIFO proxy and a second (Async or FIFO) proxy process
  concurrently; the first never blocks the second — assert the second delivers while
  the first's line is mid-advance (AC7).
- **Single-advancer / ordering under contention:** two `AdvanceProxyFifoQueue` runs for
  the same proxy claim at most one event; assert no two events are in `claimed` at once
  and order holds (ADR-005 (a)).
- **Liveness:** simulate an orphaned claim (`claimed`, expired lease); the sweeper
  resets it to `pending` and re-dispatches; the line advances (ADR-005 (b)).

**Idempotency (AC9):**
- Re-running an already-settled `DeliverToDestination` (same `ingest_id, destination_id,
  attempt_number`) produces **no** second HTTP send, **no** duplicate settled record,
  **no** duplicate event — assert one `DeliveryAttempt` and `Http::assertSentCount`
  unchanged. The `UNIQUE` index rejects a duplicate insert.

**Mid-flight mode change:**
- Switching `async → fifo` (and back) persists and validates; already-enqueued
  `fifo_dispatches` rows continue to drain in order after a `fifo → async` switch
  (assert the sweeper/advancer still settle them); new events follow the new mode.

**Management form (feature, Inertia):**
- Create/update persist `processing_mode`; edit pre-fills it; `ProxyResource` exposes
  it; an invalid value is rejected (validation). Existing proxies default to `async`.

**Unit:**
- `ProcessingMode` / `FifoDispatchStatus` enum backing values.
- The claim logic: lowest-pending selection, live-claim short-circuit, lease/reap.
- `DeliverToDestination` skip-if-settled branch.

## Handoff
- **Inputs:** Approved PRD-04, Approved design-04, ADR-005/004/010/003/007 (Accepted),
  ADR-011 (Proposed, this plan), plan-01/plan-03 + current ingest/delivery code
  (`IngestController`, `ProcessIngestedWebhook`, `DeliverStep`, `DeliverToDestination`,
  `PipelineContext`, `WebhookEvent`, `Proxy`), and
  `docs/questions/prd-04-q-04-01-fifo-async-composition.md` (resolved).
- **Outputs:** this plan; ADR-011; the resolved Q-04-01 answer.
- **Dependencies:** none new; within-stack. ADR-011 + the data-model change must be
  Owner-approved before the FIFO/data-model work starts.
- **Outstanding Questions:** none block the queued-dispatch/Async half or the form.
  **Q-04-01 resolved** here + in ADR-011. **V8** (throughput/latency SLA) remains
  Owner-deferred (no SLA asserted — AC13). **V3** (beyond-Redis transport) remains
  deferred by ADR-005 (Redis MVP; seam open, not built).
- **Owner-approval flags (✋):**
  1. **ADR-011** — per-proxy FIFO claim-based single-advancer, dispatch-by-reference,
     delivery idempotency, and the `processing_mode` attribute. Requires Owner approval
     as the significant/architectural decision record.
  2. **Data-model change** — new `proxies.processing_mode` column
     (`ENUM('async','fifo')` NOT NULL default `'async'`); new `fifo_dispatches` table;
     new `UNIQUE(ingest_id, destination_id, attempt_number)` on `delivery_attempts`.
     Requires Owner approval per the CLAUDE.md data-model gate — **not** self-certified.
  No new dependency and no stack change.
- **Next Agent:** Task Planner — **after** Owner approval of ADR-011 + the data-model
  change. The one UI change (a Processing select on the existing form + read-only
  badge/column) is settled in design-04; no Designer round-trip is needed (the
  mid-flight ruling above stays within the design's stated assumption).
