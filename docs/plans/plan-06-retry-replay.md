# Technical Plan: Retry & replay — item #6

- **Status:** Approved (Principal-Engineer self-certified) — **except** the **seven** items under
  **Handoff → Owner-approval flags (✋)**, which are **not** self-certified: **ADR-015**,
  **ADR-016**, **ADR-017**, the new **`deliveries`** table, the **`proxies` retry-policy
  columns**, the **`delivery_attempts` schema change** (idempotency-key replacement), and the
  **`fifo_dispatches` schema change** (identity/order-key change, with partial supersession of
  Accepted ADR-011 positions). Sections depending on those are contingent on Owner approval and
  do not proceed to Task Planning until it is given.
- **Author:** Principal Engineer
- **PRD:** `docs/product/prd-06-retry-replay.md` — **Approved** (Project Owner, 2026-08-12),
  including the Owner rulings from Q-06-01/Q-06-02 rendered into AC1, AC2, AC9, AC14, AC20,
  AC22, AC25.
- **Design spec:** `docs/design/design-06-retry-replay.md` — **Approved** (Product Manager,
  2026-08-12; design gate delegated per `CLAUDE.md`). This plan builds the three surfaces it
  specifies and changes none of them.
- **ADRs:** **ADR-015** (delivery retry mechanism), **ADR-016** (FIFO composition; partially
  supersedes ADR-011 — three enumerated positions), **ADR-017** (replay dispatch + payload read
  surface). All three **Proposed — awaiting Project Owner approval**.
- **Approved by / date:** Principal Engineer, 2026-08-12. The seven flagged items remain pending
  Owner approval.

## Overview
#6 closes the four gaps PRD-06 names on top of #4/#5, with three additions and one surface:

**(a) Automatic retry (AC1–AC8).** A failed destination delivery is re-attempted on a bounded
backoff schedule — every proxy, both modes, system default 5 attempts / exponential; per-proxy
attempt limit (1–10) and strategy (exponential | fixed) configurable on **enhanced** proxies
only. Delivery state lives on a new **`deliveries`** entity (one row per dispatch × destination);
retries execute as **delayed, by-reference queue jobs** with a scheduled sweeper as the liveness
net and the attempts unique key as the dedupe arbiter — the ADR-011 pattern, reapplied
(ADR-015). Exhaustion lands in an **explicit terminal `failed` state** and emits
**`DeliveryExhausted`** — the #13 hook (AC4/AC5).

**(b) FIFO composition (AC6; closes the deferred PRD-04 AC10 bound).** A retrying FIFO head
holds its own line via a new `awaiting_retry` ordering-row state (no lease abuse, sweeper-safe);
once the head's deliveries are all terminal the row settles and the line advances — delayed,
never wedged. The pending scan's order key becomes the ordering row's own `id`, which is what
lets a replay join the line at the back (ADR-016).

**(c) Manual replay (AC9–AC14).** A replay is a **new dispatch identity** (`dispatch_uuid`) with
pre-created delivery rows for the chosen destinations, dispatched **by reference through the
same pipeline** as a live event — Async immediately, FIFO at the back of the line. Replays land
on the same attempt-record/event stream, distinguishable via `deliveries.kind = 'replay'` and
traceable through `webhook_event_id` (AC12); a failed replay delivery retries under the same
policy (AC13). Gated by a new `proxy:replay` permission held by all three roles, no ownership
limit (AC14, ADR-017/ADR-009).

**(d) The stored-event surface (AC15–AC17, AC25; PRD-05 AC16 discharged).** Per-proxy events
list, event detail with delivery history grouped original/replay, and a masked payload viewer
whose content is **fetched only on the explicit Reveal action** (Q-06-03(5) → option b) from a
hardened, read-permission-gated endpoint. Cleaned events are visibly expired, never replayable,
and structurally unable to dispatch (H5 + the ADR-014 Decision 7 guard on every path).

Nothing changes in the ingest/response contract (ADR-004 — the sender cannot observe retries or
replays, AC8), in payload capture or erasure semantics (#5; holds grow by one, additively), or
in the #4 correctness primitives (atomic claim, lease/sweeper, exactly-once settlement — the
idempotency key moves grain, the mechanism is identical).

## Q-06-03 — answered (the PRD routed it to technical design)

Full record: `docs/questions/prd-06-q-06-03-retry-replay-composition.md` (**RESOLVED**,
2026-08-12). Summary:

**(3) first, because it gates the plan: the Owner-gate list is SEVEN items** — three ADRs and
four data-model changes (one new table, three changes to existing tables), each with its exact
ask under **Handoff → Owner-approval flags (✋)**. **Not** tripped: no new Composer/pnpm
dependency, no stack change, no irreversible operation, no backfill of invented data (the one
backfill derives `fifo_dispatches.dispatch_uuid` from each row's own event — a mechanical
identity, dev/CI data only). Replay traceability is modeled **structurally** — `deliveries.kind`
+ `dispatch_uuid` + `webhook_event_id` (ADR-015/017) — rather than as ADR-003's sketched
`replay_of_id` attempt column: one record stream, attempts unchanged in shape except the
`delivery_id` FK. Retry policy persists as two nullable `proxies` columns, NULL = system
default, resolved solely by `App\Services\RetryPolicy`.

**(1) FIFO composition — confirmed, at the anticipated seams.** A retrying head holds only its
own proxy's line, exactly while its dispatch has non-terminal deliveries (`awaiting_retry`
state); the sweeper cannot reap it (it reaps only `claimed` rows past a lease; `awaiting_retry`
has no lease); on exhaustion the head **settles** — the anticipated `dead_lettered` status is
deliberately **not adopted** (event-level dead-letter is ambiguous under fan-out, duplicates
`deliveries.status = 'failed'`, and under ADR-012's H2 would immortalize the payload — ADR-016
Decision 2 records the trade-off); `settled` is already excluded from the lowest-pending scan,
so the line advances. Async retries are per-delivery delayed jobs sharing no key, lock, or line
— they serialize nothing.

**(2) Retention holds and the cleaned guard — confirmed, additively.** One new hold **H5** joins
H0–H4 in the shared `applyHolds()` builder, so it is re-asserted inside the erase `UPDATE`
automatically (ADR-012 Decision 1): an event is held while any delivery is `retrying`, or
`pending` within the existing dispatch horizon (the H4 shape reapplied so a lost first-attempt
job cannot immortalize a payload). A **terminal delivery holds nothing** (AC18). Every #6 read
or dispatch path guards on `payload_cleaned_at`, never `body IS NULL`: the list/detail/payload
endpoints via the `StoredPayloadState` mapping, the replay endpoint via an in-transaction
`lockForUpdate` re-check, the retry executor via an explicit guard that terminalizes cleanly,
and the pipeline entry via its existing #5 guard. No policy the Q-06-01 caps permit exceeds
**≈ 32.6 h** of schedule — two orders of magnitude inside the 30-day window; a guard test pins
this.

**(4) Replay dispatch path — confirmed, no new machinery.** Replay is
`ProcessIngestedWebhook::dispatch($ingestId, $replayUuid)` — the same by-reference entry, same
pipeline, same steps — plus pre-created delivery rows scoping `DeliverStep` to the chosen
destinations. On FIFO it is one more ordering row whose fresh `id` *is* "back of the line"; the
single-advancer's claim/lease/scan mechanics are untouched (only the scan's ORDER BY column
changes, provably order-identical for all capture-created rows — ADR-016).

**(5) Reveal mechanism — option (b), fetch-on-reveal,** as the Designer recommended: content is
never present in props/DOM until explicitly requested; the payload endpoint responds
`text/plain` + `nosniff` + `no-store`, logs identifiers only, returns 410 for cleaned (lifecycle,
never error). Grounds and hardening in ADR-017 Decision 6. Bound by PRD-05 AC16 (team-scoped,
proxy read permission) and PRD-06 AC14/AC22/AC25.

## Architecture

Five concerns, mapped to ACs. **No change to the ingest handler, the response contract
(AC8), capture placement, erasure semantics, or the attempt-record shape.**

**A. Delivery state & the retry engine (AC1–AC5, AC7, AC13; ADR-015).**
- `ProcessIngestedWebhook` — after its existing cleaned guard — creates the dispatch's
  `deliveries` rows (original: one per live destination, `firstOrCreate` under
  `UNIQUE(dispatch_uuid, destination_id)`; replay rows pre-exist), then runs the pipeline with
  the dispatch identity on the context.
- `DeliverStep` iterates the dispatch's delivery rows and executes **attempt 1** per row —
  Async-queued / FIFO-inline exactly as today; `DeliveryUnit` gains `deliveryId`.
- `DeliverToDestination` keeps its create-or-resume attempt handling (key now
  `(delivery_id, attempt_number)`) and, after settling an attempt, transitions the delivery row
  by **compare-and-set**: success ⇒ `succeeded`; failure at the policy limit ⇒ `failed`
  (terminal) + `DeliveryExhausted` emitted exactly once (the CAS is the once-guard); failure
  below the limit ⇒ `retrying` + `next_attempt_at` + a delayed
  `RetryDelivery::dispatch($deliveryId, $n+1)` on the webhooks queue.
- `RetryDelivery` (new) executes attempts ≥ 2 **by reference**: reload delivery (skip unless
  still `retrying`), guard `payload_cleaned_at` (cleaned ⇒ terminalize with an error summary,
  emit `DeliveryExhausted`, send nothing — AC17), resolve bytes as the recorded dispatched
  output (`dispatched_payloads.body ?? webhook_events.body`, via `StoredPayloadLookup` — the
  single-resolver rule, ADR-013 Decision 3), rebuild the `DeliveryUnit`, run
  `DeliverToDestination::run()`. **Retry re-sends; replay re-processes** — recorded in ADR-015.
- `SweepDueRetries` (new, scheduled every minute) re-dispatches `RetryDelivery` for `retrying`
  rows whose `next_attempt_at` passed more than a grace period ago — liveness for dropped
  delayed jobs; the unique attempt key arbitrates double-fires.

**B. FIFO composition (AC6; ADR-016).** The advancer, after its run, settles the row only when
the dispatch has no non-terminal deliveries; otherwise it transitions `claimed →
awaiting_retry` (no lease, no self-dispatch — the line is held). Its busy-gate treats a live
`claimed` lease **or** an `awaiting_retry` row as "proxy busy". The retry path, on settling a
dispatch's last open delivery for a FIFO proxy, compare-and-sets `awaiting_retry → settled` and
nudges the advancer. `SweepStalledFifoDispatches` gains: exclude held proxies from the idle
nudge, and settle-and-nudge any `awaiting_retry` row whose dispatch has zero non-terminal
deliveries (the one crash window between the two state machines). The pending scan orders by
`id`. Async proxies are untouched by construction.

**C. Replay dispatch (AC9–AC14; ADR-017).** The replay endpoint (permission `proxy:replay`,
all three roles, single-axis policy): validates the chosen destination ids against the proxy's
**current live** destinations (AC10); in one transaction re-checks the event retained under
`lockForUpdate` (race-free against GC — ADR-017 Decision 3), mints `dispatch_uuid`, creates the
replay's `deliveries` rows, and for FIFO inserts the ordering row; after commit dispatches
`ProcessIngestedWebhook($ingestId, $replayUuid)` (Async) or nudges the advancer (FIFO).
`CaptureDispatchedStep` runs unchanged and idempotently on replay; the upstream response path is
never traversed (AC8/AC11).

**D. The read surface (AC15–AC17, AC25; ADR-017).** Three GETs and the replay POST, all in the
team-scoped group with scoped bindings (cross-team ⇒ 404): events list (descriptors + payload
state + per-destination delivery state; never content), event detail (adds grouped delivery
history with attempts; never content), and the payload endpoint (the **only** content-bearing
response in #6 — fetch-on-reveal, `text/plain` + `nosniff` + `no-store`, 410 when cleaned, 404
when never captured, access logged identifiers-only). Payload state everywhere reads
`payload_cleaned_at` via the `StoredPayloadState` mapping.

**E. GC hold H5 (AC18).** Added to `PurgeExpiredPayloads::applyHolds()` — therefore present in
both the selection query and the erase `UPDATE`'s own `WHERE` with no further work:
`NOT EXISTS (deliveries WHERE webhook_event_id = webhook_events.id AND (status = 'retrying' OR
(status = 'pending' AND created_at > now() − dispatch_horizon)))`. Terminal statuses never hold.
This also narrows review-05's carried Minor (the partial-fan-out Async hold gap): scheduled
retries now hold explicitly where H3 only covered in-flight attempts.

### Technical rulings (named, recorded — not silent design)
Each stays inside the approved artifacts' assumptions; none reinterprets a requirement.

1. **Mid-flight retry-policy change: live-read at each scheduling decision.** The PRD fixes the
   knobs but is silent on a policy edited while a delivery is mid-retry. Ruling: `RetryPolicy`
   is consulted at every failure (limit and delay), so an edit applies to the *next* decision —
   a raised limit extends a live schedule, a lowered one terminalizes at the next failure.
   Consistent with AC11's "current configuration" posture and the plan-04 mid-flight-mode
   precedent; no snapshot state to migrate.
2. **A destination soft-deleted mid-schedule keeps its delivery.** Retries and already-created
   replay deliveries load the destination `withTrashed` and run to their natural terminal state.
   This matches the existing Async first-attempt behaviour (a queued `DeliveryUnit` already
   delivers to a just-deleted destination — `SerializesModels` restores trashed models) and
   keeps delivery state honest; new *selection* (replay dialog, original fan-out) uses live
   destinations only (AC10).
3. **Pre-#6 events render from attempt records; no backfill.** Events with zero `deliveries`
   rows (captured before #6) get presentation-only per-destination state derived from their
   latest attempts (`succeeded → Delivered`, `failed → Failed`, `dispatched → Retrying`), and
   are not retroactively retried. Dev/CI data only (no production data exists — Owner-stated);
   fail-safe rendering, zero fabricated rows (architecture.md: no destructive backfills).
4. **`pending` presents as "Retrying".** The design's per-destination vocabulary is
   Delivered/Retrying/Terminally failed; the transient `pending` (first attempt not yet settled)
   maps to the Retrying presentation in the frontend data-const. Server state stays exact;
   presentation collapses one transient — flagged here so the Reviewer sees it was chosen.
5. **`dead_lettered` is not adopted** — ADR-016 Decision 2 (fan-out ambiguity, duplication of
   `deliveries.status`, H2 immortality). The `FifoDispatchStatus` docblock reservation is
   resolved *against* adoption, on record.
6. **Replay refreshes the stored dispatched output (post-#8 note).** `CaptureDispatchedStep`'s
   `updateOrCreate` means a replay re-records the event's dispatched output under current
   config. Contentless today (pre-#9 divergence is impossible); recorded so #8 inherits a stated
   semantic instead of an accident.

## Data Model

**Four changes: one new table, three changes to existing tables.** MySQL 8.0 / InnoDB. No
change to `teams`, `webhook_events`, `dispatched_payloads`, or `destinations`. **All four
require Owner approval** (flags 4–7).

### `deliveries` — per-dispatch × destination delivery state (AC4, AC13, AC18; ADR-015)
New migration + `Delivery` model. Payload-free, no soft delete. One row per
(dispatch, destination); a **dispatch** = the original processing (identity = the event's
`ingest_id`) or one replay (identity = a fresh UUID).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `team_id` | `foreignId()->constrained()` (restrict) | set explicitly on worker/ingest paths (team-unscoped), mirrors siblings |
| `proxy_id` | `foreignId()->constrained()` (restrict) | |
| `destination_id` | `foreignId()->constrained()` (restrict) | survives destination soft-delete (ruling 2) |
| `webhook_event_id` | `foreignId()->constrained()` (restrict) | AC12 traceability; H5 correlation; events are never deleted (ADR-012), so restrict is safe |
| `dispatch_uuid` | `uuid` NOT NULL | = event `ingest_id` (original) / fresh UUID (replay batch) |
| `kind` | `enum('original','replay')` NOT NULL | explicit, never inferred (AC12) |
| `status` | `enum('pending','retrying','succeeded','failed')` NOT NULL default `'pending'` | `succeeded`/`failed` terminal; **`failed` is AC4's terminal state** |
| `next_attempt_at` | `timestamp` NULL | non-NULL iff `retrying` |
| `created_at`/`updated_at` | timestamps | |
| indexes | **`UNIQUE(dispatch_uuid, destination_id)`**; `(webhook_event_id, status)`; `(status, next_attempt_at)` | creation idempotency; H5 + detail reads; due-retry sweep |

Model: `Delivery` — `BelongsToCurrentTeam`; `belongsTo` proxy/destination/webhookEvent;
`hasMany(DeliveryAttempt)`; casts `kind`/`status` to new enums, `next_attempt_at` datetime.
Status transitions only via compare-and-set on the query builder (never blind `save()`).

### `proxies` — retry policy columns (AC2; ADR-015)
| Column | Type | Notes |
|---|---|---|
| `retry_attempt_limit` | `TINYINT UNSIGNED NULL` | 1–10; NULL = system default (5). Always NULL on simple-mode proxies. |
| `retry_backoff_strategy` | `VARCHAR NULL`, cast `RetryBackoffStrategy` | `exponential`\|`fixed`; NULL = default (exponential). Always NULL on simple-mode proxies. |

Additive; existing rows take NULL (= system default — AC1's "wherever nothing is configured").
Added to `#[Fillable]`; `ProxyResource` emits both raw values.

### `delivery_attempts` — attempt→delivery linkage and idempotency-key replacement (AC7, AC12; ADR-015/016 P3)
| Change | Detail |
|---|---|
| `delivery_id` | new `foreignId()->nullable()->constrained('deliveries')` (restrict). NULL **only** for pre-#6 rows; every new attempt carries it. |
| `UNIQUE(delivery_id, attempt_number)` | the new exactly-once key (replay-safe grain). NULL `delivery_id` rows do not collide (MySQL unique-NULL semantics) and never gain siblings. |
| drop `UNIQUE(ingest_id, destination_id, attempt_number)` | cannot survive replay (attempt 1 legitimately recurs per `(ingest_id, destination_id)`). Supersedes ADR-011 P3 — Owner-gated. Plain `ingest_id` index and all other indexes are kept. |

Rows remain payload-free, append-only, always retained (ADR-003 — AC7).

### `fifo_dispatches` — dispatch identity, order key, hold state (AC6, AC11; ADR-016 P1/P2 + additive)
| Change | Detail |
|---|---|
| `dispatch_uuid` | new `uuid` NOT NULL; **backfilled** from each row's event `ingest_id` inside the migration (mechanical identity; dev/CI data only), then `UNIQUE(dispatch_uuid)` added. |
| drop `UNIQUE(webhook_event_id)` | one event may hold original + replay ordering rows. Add plain index `(webhook_event_id)` **before** dropping the unique (the FK requires an index — migration ordering matters on MySQL). |
| `status` enum | gains `'awaiting_retry'` (enum `MODIFY`, value appended — metadata-only on MySQL 8). |

The `(proxy_id, status, webhook_event_id)` composite is retained (the scan filter is unchanged;
only its ORDER BY column moves to `id`).

### Explicitly **not** changed
| Table | Why |
|---|---|
| `webhook_events` | Still immutable-while-retained; #6 adds no column — delivery state lives on the sidecar, per the ADR-010/014 invariant. |
| `dispatched_payloads` | Read (retry source resolution via `StoredPayloadLookup`) — never written outside `CaptureDispatchedStep`/GC. |
| `teams` | No retention/policy column; unchanged. |

## API

All new routes sit in the existing team-prefixed group (`auth`, `verified`,
`EnsureTeamMembership`, `ApplyTeamScope`), with `->scopeBindings()` so `{event}` resolves
through the proxy relation and any cross-team id 404s. `Proxy` gains a `webhookEvents(): HasMany`
relation for the scoped binding.

| Method & path | Name | Controller | Gate | Notes |
|---|---|---|---|---|
| GET `/{team}/proxies/{proxy}/events` | `proxies.events.index` | `ProxyEventController@index` | `view, $proxy` | Paginated (15, `latest('id')`); rows via `WebhookEventResource` with delivery-state summaries; `permissions` (incl. `canReplayProxy`) + `fifoHeldByRetry` props |
| GET `/{team}/proxies/{proxy}/events/{event}` | `proxies.events.show` | `ProxyEventController@show` | `view, $proxy` | Detail + deliveries (grouped client-side by `dispatch_uuid`/`kind`) + attempts; never payload content |
| GET `/{team}/proxies/{proxy}/events/{event}/payload` | `proxies.events.payload` | `ProxyEventPayloadController` (invokable) | `view, $proxy` | Fetch-on-reveal. Retained ⇒ raw bytes, `text/plain; charset=utf-8`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`; Cleaned ⇒ **410**; NeverCaptured ⇒ 404. Logs `payload.revealed` identifiers-only. |
| POST `/{team}/proxies/{proxy}/events/{event}/replay` | `proxies.events.replay` | `ProxyEventReplayController@store` | `replay, $proxy` | `ReplayEventRequest`; PRG + flash toast; expired ⇒ `ValidationException` (dialog inline error, AC15's lifecycle framing) |

**Resources** (`$wrap = null`):
- `WebhookEventResource` — `id`, `received_at`, `byte_size`, `content_type`, `method`,
  `payload_state` (`retained|cleaned|never_captured` from the `StoredPayloadState` mapping),
  `deliveries` (when loaded). **Never** `body`/`headers` (AC22/AC25).
- `DeliveryResource` — `id`, `dispatch_uuid`, `kind`, `status`, `next_attempt_at`,
  `attempt_limit` (effective, via `RetryPolicy` — the "of L" in "Attempt N of L"),
  `destination: {http_method, url}` (withTrashed), `attempts` (when loaded).
- `DeliveryAttemptResource` — `attempt_number`, `status`, `http_status`, `error_summary`,
  `started_at`, `duration_ms`.

**Existing endpoints:** `ProxyController` store/update accept the two new fields (Validation
below) and clear them when `mode = simple`; `ProxyResource` adds `retry_attempt_limit`,
`retry_backoff_strategy`. No other endpoint changes.

## Services & Actions

- **`App\Services\RetryPolicy`** (new) — the single resolver of retry policy (AC2):
  `attemptLimitFor(Proxy): int` (column ?? config default, hard-clamped to [1, cap]);
  `strategyFor(Proxy): RetryBackoffStrategy`; `delayBefore(Proxy, int $attemptNumber):
  CarbonInterval`; `worstCaseSpan(): CarbonInterval` (the AC18 guard-test seam). Config-sanity
  guards on entry (the `PurgeExpiredPayloads` RuntimeException posture). No other consumer reads
  the columns or `config('retry.*')`.
- **`App\Services\StoredPayloadLookup`** (existing) — gains `dispatchedBytesFor(WebhookEvent):
  string`: the retry-source resolution (`dispatched_payloads.body ?? webhook_events.body`),
  callable only for a retained event — keeping ADR-013 Decision 3's "only place NULL is
  interpreted" true.
- **`App\Actions\RetryDelivery`** (new, `AsJob`, `$tries = 1`) — executes attempts ≥ 2 by
  reference (Architecture A). Skips stale jobs (status no longer `retrying`); terminalizes
  cleanly on a cleaned parent (AC17).
- **`App\Actions\SweepDueRetries`** (new, scheduled every minute) — re-dispatches
  `RetryDelivery` for overdue `retrying` rows (grace `config('retry.sweep_grace_seconds')`);
  next attempt number = `MAX(attempt_number) + 1`; unique-key dedupe arbitrates races.
- **`App\Actions\DeliverToDestination`** (modified) — attempt create-or-resume keyed on
  `(delivery_id, attempt_number)`; post-settle delivery CAS transition + retry scheduling +
  `DeliveryExhausted` emission + FIFO `awaiting_retry → settled` completion check
  (Architecture A/B). Transition logic runs only when `send()` settles an attempt — never on a
  resume-skip.
- **`App\Actions\ProcessIngestedWebhook`** (modified) — `handle(string $ingestId, ?string
  $dispatchUuid = null)`; creates original delivery rows after the cleaned guard; passes the
  dispatch identity into the context. Existing guard, `firstOrFail`, trashed-proxy load
  unchanged.
- **`App\Actions\DeliverStep`** (modified) — iterates the dispatch's delivery rows (destination
  eager, withTrashed); builds `DeliveryUnit` with `deliveryId`, `attemptNumber: 1`.
- **`App\Actions\AdvanceProxyFifoQueue`** (modified) — busy-gate includes `awaiting_retry`;
  scan `orderBy('id')`; runs with `($event->ingest_id, $row->dispatch_uuid)`; post-run
  settle-or-hold decision (Architecture B).
- **`App\Actions\SweepStalledFifoDispatches`** (modified) — nudge excludes held proxies; new
  stuck-hold release pass (ADR-016 Decision 4c).
- **`App\Events\DeliveryExhausted`** (new) — `{ public readonly Delivery $delivery }`; emitted
  exactly once per terminal transition (CAS-guarded); no listener at #6 (AC5/AC19).
- **`App\Enums`** — `RetryBackoffStrategy { Exponential, Fixed }`; `DeliveryStatus { Pending,
  Retrying, Succeeded, Failed }` (+`isTerminal()`); `DispatchKind { Original, Replay }`;
  `FifoDispatchStatus` + `AwaitingRetry`; `TeamPermission` + `ReplayProxy = 'proxy:replay'`.
- **`App\Enums\TeamRole`** (modified) — Admin and Member arms add `ReplayProxy` (Owner inherits
  via `cases()`); no `-any` case (AC14).
- **`App\Policies\ProxyPolicy`** (modified) — `replay(User, Proxy)`: single-axis permission
  check, no ownership composition.
- **`App\Data\ProxyPermissions`** + `HasTeams::toProxyPermissions()` (modified) — add
  `canReplayProxy`.
- **`App\Http\Controllers`** — `ProxyEventController` (index/show), `ProxyEventPayloadController`
  (invokable), `ProxyEventReplayController` (store; the transaction per Architecture C).
- **`config/retry.php`** (new) — `default_attempt_limit` 5, `max_attempt_limit` 10,
  `exponential_base_seconds` 60, `exponential_multiplier` 5, `exponential_max_delay_seconds`
  21600, `fixed_interval_seconds` 300, `sweep_grace_seconds` 120. Env-overridable for dev/test
  only; the limit/strategy defaults and caps are **product values** (Owner ruling Q-06-01b), the
  curve constants are engineering constants bounded by the AC18 guard test.
- **`routes/console.php`** — one new `Schedule::` entry (`SweepDueRetries`, every minute, beside
  the FIFO sweeper).
- **`IngestController`** (modified, one line) — capture-path `FifoDispatch::create` sets
  `dispatch_uuid = $ingestId`.
- **Frontend** (per design-06, no new primitives): `proxies/events/Index.vue` + `Show.vue`;
  `PayloadViewer` composition (fetch-on-reveal, `aria-pressed`, re-masks on navigation);
  `ReplayDialog` (checklist, select-all tri-state, count-bearing confirm, FIFO note, inline
  error); `ProxyForm.vue` retry-policy section (enhanced-only mount/unmount with value clearing)
  + the PM-endorsed Mode help-text correction (no roadmap numbers, no mapping implication);
  `proxies/Show.vue` Events button + Retry policy card; data consts
  (`proxyPayloadStates.ts`, `proxyDeliveryStates.ts`, `proxyRetryBackoffStrategies.ts`,
  `DataOption`-typed); `types/proxies.ts` extensions.

## Validation

- **`StoreProxyRequest` / `UpdateProxyRequest`:**
  - `retry_attempt_limit` → `['nullable', 'integer', 'min:1', 'max:10',
    'prohibited_if:mode,simple']` — the 1–10 bound is AC2's cap; simple-mode proxies may never
    carry a value (AC2), mirroring the `prohibited_if` idiom of `response_body`/204.
  - `retry_backoff_strategy` → `['nullable', Rule::enum(RetryBackoffStrategy::class),
    'prohibited_if:mode,simple']`.
  - Controller persists `$data['…'] ?? null` so an omitted/cleared field resets to the default
    sentinel (matches the design's Enhanced→Simple clearing flow, server-authoritative).
- **`ReplayEventRequest`:** `destinations` → `['required', 'array', 'min:1']`;
  `destinations.*` → integer, distinct, and each must be one of the **proxy's current live**
  destinations (a scoped `Rule::exists` — AC10; no ad-hoc URLs, no trashed targets).
- **System invariants (binding, mirrors plan-05 §Validation):**
  - Delivery status moves only by **compare-and-set** keyed on the prior status; zero rows
    affected means another settler won — skip, never re-emit, never re-schedule.
  - `DeliveryExhausted` fires **iff** the terminal CAS affected a row — exactly once.
  - **Never guard on `body === null`**; the cleaned signal is `payload_cleaned_at`, on every #6
    path (executor, replay endpoint, payload endpoint, entry — ADR-014 Decision 7).
  - H5 is expressed once, inside `applyHolds()`, so selection and erase assert it identically.
  - The replay endpoint's retained re-check runs **inside** its transaction under
    `lockForUpdate`, after which the delivery rows are inserted — never check-then-insert
    without the lock.
  - `RetryPolicy` clamps the limit into `[1, max_attempt_limit]` regardless of column content;
    config-sanity RuntimeExceptions on non-positive constants (review-05 M-1 posture).
  - The payload endpoint never serializes content into logs, resources, or props; identifiers
    only.

## Risks

1. **Destinations now receive up to `limit` sends per delivery.** At-least-once amplifies:
   ambiguous failures (timeout after the destination processed) re-send real traffic. This is
   the Owner-approved feature (PRD Users: "delivery remains at-least-once"), bounded by the
   caps; the plan adds no dedupe tokens (out of scope, #10 territory). **Accepted by
   requirement.**
2. **The idempotency-key migration is the one non-additive schema step.** Dropping
   `UNIQUE(ingest_id, destination_id, attempt_number)` momentarily relies on
   `UNIQUE(delivery_id, attempt_number)` being created in the same migration; order the adds
   before the drop. Legacy NULL `delivery_id` rows are inert (never gain siblings).
   **Owner-flagged (✋ flag 6); mechanically safe, no data mutated.**
3. **Two state machines must agree (deliveries ↔ fifo rows).** A crash between "last delivery
   settled" and "fifo row settled" strands the line. **Closed** by sweep pass (c) (ADR-016
   Decision 4c) + the every-minute cadence; covered by a dedicated test. Residual: one sweep
   period of added line latency in the crash case — acceptable.
4. **Delayed jobs are droppable.** Redis delayed sets survive restarts but jobs can be lost
   (flush, horizon kill). `SweepDueRetries` re-drives from durable `next_attempt_at`; dedupe by
   unique key. The schedule's precision degrades to ~1 min in the loss case — inside AC24's
   "no numeric targets". **Non-blocking.**
5. **A stuck `pending` delivery could hold GC** (lost first-attempt job). H5's horizon bound
   (the H4 shape) caps the hold at `dispatch_horizon_minutes` for Async; FIFO pending rows are
   H2's concern and are driven by the sweeper. **Closed by design; test per hold.**
6. **The payload endpoint is a new sensitive egress.** Mitigations are structural: read
   permission + team scope + scoped binding, fetch-on-reveal, `no-store`, `nosniff`,
   `text/plain`, text-node rendering (no `v-html`), identifiers-only logging, 410/404 split.
   **Owner-flagged (✋ flag 3 / ADR-017).**
7. **Retry source resolution depends on ADR-013 semantics.** `dispatched_payloads.body` NULL
   means "output == input" *only* while the parent is uncleaned; the resolver lives inside
   `StoredPayloadLookup` behind the retained guard, so misreads are structurally prevented.
   **Non-blocking.**
8. **FIFO throughput under a retrying head.** A held line delays everything behind it for up to
   ~32.6 h worst-case — the Owner-owned ordered-means-waiting semantic (AC6), surfaced honestly
   in the UI (banner + form help text per design). Not a defect; recorded so nobody "fixes" it.
9. **Enum `MODIFY` on `fifo_dispatches.status`.** Appending a value is metadata-only on MySQL
   8.0; reordering/removing would rewrite the table — the migration must append. **Implementation
   note; MySQL-only per house precedent.**
10. **Growth: `deliveries` accrues one row per dispatch × destination, forever.** Same class as
    D1 (retained records, Owner-accepted out of scope at #5); payload-free rows; no cap or prune
    asserted here. **Deferred with D1.**

## Dependencies

- **No new packages; no stack change** (`docs/stack/stack.md` untouched). Eloquent, migrations,
  the Laravel scheduler and Redis queue (both already required by #4), delayed dispatch
  (framework-native), `lorisleiva/laravel-actions` (ADR-007).
- **ADR-015 / ADR-016 / ADR-017** (all Proposed, this plan) — gate the retry engine, the FIFO
  composition + schema changes, and the replay/read surface respectively.
- **Accepted and relied on:** ADR-003 (attempt records/events — extended, not reshaped), ADR-004
  (AC8), ADR-005 (the dispatch chokepoints), ADR-009 incl. Amendments (permission mechanism +
  display/enforcement split), ADR-010/014 (capture immutability, cleaned signal, reader guard),
  ADR-011 (all positions except the three ADR-016 supersedes), ADR-012 (holds — H5 additive, as
  its Decision 4 anticipated), ADR-013 (retry-source semantics).
- PRD-06 (Approved 2026-08-12) · design-06 (Approved 2026-08-12) · Q-06-01/Q-06-02 (Owner-
  resolved) · Q-06-03 (resolved by this plan). Features #4 and #5 — Done and merged.

## Implementation Notes

- **Only the settling path transitions `deliveries.status`, always by CAS.** No model
  `save()` on status; no transition without the prior-status predicate; skipped CAS ⇒ do
  nothing further (no event, no schedule).
- **Attempts ≥ 2 belong to `RetryDelivery` exclusively**; `DeliverStep` executes attempt 1
  only. Never have the pipeline "catch up" retries.
- **Retries re-send the recorded dispatched output; replays re-process raw through the
  pipeline.** Do not re-run the pipeline for a retry; do not bypass it for a replay.
- **Never carry payload bytes in a delayed job.** `RetryDelivery` is by-reference; only the
  immediate first-attempt Async fan-out carries bytes (existing #4 posture).
- **Guard on `payload_cleaned_at` on every new path** — executor, replay endpoint (in-
  transaction, `lockForUpdate`), payload endpoint, resources. Never on `body IS NULL`
  (ADR-014 Decision 7, binding; the failure mode is dispatching an empty payload).
- **H5 lives inside `applyHolds()`** so the erase `UPDATE` re-asserts it automatically. GC still
  writes only `webhook_events` + `dispatched_payloads` (ADR-012 Decision 5).
- **`awaiting_retry` is entered only from `claimed` (advancer) and left only to `settled`
  (retry settler / sweep pass (c)).** The reaper touches only `claimed` rows past their lease.
- **Migration ordering:** add the new unique/plain indexes before dropping the ones they
  replace (both `delivery_attempts` and `fifo_dispatches`); backfill `dispatch_uuid` before its
  unique; append — never reorder — the enum value. MySQL-only, per the #3/#5 precedent.
- **`RetryPolicy` is the only reader** of the proxy columns and `config('retry.*')`; the AC18
  guard test compares `worstCaseSpan()` against `RetentionPolicy`'s window (assert well under —
  ≤ 3 days).
- **Never log payload content** — not on the retry path, the replay path, the payload endpoint,
  or any error summary (`error_summary` stays a truncated exception message, never a body).
  Identifiers and counts only (coding.md never-log list, binding).
- **Frontend:** affordances derive client-side (`permissions.canReplayProxy` + payload state) —
  no per-row policy calls (ADR-009 Amendment B / architecture.md Authorization). Reveal renders
  fetched content as text only; re-masks on navigation; `aria-pressed` + `sr-only` live region
  per design. Mode help-text final copy obeys the PM constraint (no roadmap numbers; no
  mapping-exists implication).
- Pint + PHPStan L7 green per task; Conventional Commits with context list items (CLAUDE.md);
  tests use `createQuietly()` and no per-class `RefreshDatabase` (testing.md).

## Test strategy
Backend PHPUnit (`./vendor/bin/sail test`), `Http::fake()` for delivery, `Queue::fake()` /
`Bus::fake()` for dispatch + delay assertions, `Event::fake()` for the domain stream,
`travel()` for schedules and windows. Mapped to acceptance criteria:

**Retry engine (AC1–AC3, AC7):**
- A failed attempt on a **simple**-mode proxy schedules attempt 2 with the system default
  (limit 5, exponential first delay) — AC1's every-proxy baseline.
- An **enhanced** proxy with `retry_attempt_limit = 2` stops after attempt 2; with
  `fixed` strategy, delays are the constant interval; unset columns fall back to 5/exponential
  (AC2).
- Exponential delays follow min(base·5^(n−2), cap); `worstCaseSpan()` for limit 10 is ≤ 3 days
  and far inside `RetentionPolicy::windowFor()` (AC2 caps, AC18 bound).
- Two destinations, one fails: only the failed one is retried; the succeeded one gains no new
  attempts (AC3).
- Each retry writes a new attempt row with incremented `attempt_number`, same `delivery_id`,
  payload-free, and fires `DeliveryAttempted` + outcome events (AC7).
- Redelivery/duplicate `RetryDelivery` for the same (delivery, N): exactly one attempt row
  (unique-key dedupe); a `dispatched` crash row is re-driven on the same row (AC7 / #4 AC9
  parity).
- `SweepDueRetries` re-dispatches an overdue `retrying` delivery whose delayed job was lost;
  does not touch future-due or terminal rows.
- Mid-flight policy change: lowering the limit below the executed count terminalizes at the
  next failure; raising it extends (ruling 1).
- A soft-deleted destination mid-schedule: the retry still executes and settles (ruling 2).

**Terminal state & event (AC4, AC5):**
- After the limit, the delivery row reads `failed`, `next_attempt_at` NULL, and **no further
  attempt is ever created** (travel past all schedules, run sweeps — zero new rows).
- `DeliveryExhausted` fires exactly once per exhausted delivery — including under a racing
  duplicate settle — and carries team/proxy/destination/event-reachable state (AC5).
- A terminal delivery remains visible on the surface and the event stays replayable while
  retained (AC4).

**FIFO composition (AC6):**
- Head's first attempt fails inline → fifo row is `awaiting_retry`, no lease; the next pending
  event is **not** claimed; the sweeper's reaper and nudge both leave the held line alone.
- Retry succeeds → row `settled`, advancer dispatched, next event claimed — in order.
- Retry exhausts → row `settled` (no `dead_lettered`), line advances past the poison head;
  deliveries carry the terminal fact (ruling 5).
- Multi-destination head: line held until the last delivery is terminal; racing settlers
  produce one settle (CAS) and no double-advance.
- Stuck-hold release: an `awaiting_retry` row whose deliveries are all terminal (simulated
  crash) is settled and nudged by the sweep pass (Risk 3).
- Async proxy: two events' retries interleave freely; neither delays the other (AC6 Async
  half).
- Order key: capture-created rows still process in received order; a replay row on a FIFO proxy
  processes **after** all previously pending events (AC11 join-at-back).
- Existing #4 suites (claim atomicity, lease reap, idempotent settle) pass unmodified except
  where they assert the old unique key / order column — updated deliberately, enumerated in the
  task plan.

**Replay (AC9–AC14):**
- Replay of a retained event to a subset dispatches to exactly those destinations; to all via
  select-all; never to a trashed destination or another proxy's destination id (422) (AC10).
- Replay runs through the pipeline (`CaptureDispatchedStep` executed, idempotent; context
  `dispatchUuid` = replay uuid) and produces `deliveries` rows `kind = 'replay'` traceable to
  the event, with attempt rows chained via `delivery_id` (AC11, AC12).
- Replay works on simple **and** enhanced proxies (AC9).
- A failed replay delivery retries under the proxy's policy and can terminalize + emit
  `DeliveryExhausted` (AC13).
- Upstream response contract untouched: replay never produces an ingest response; ingest
  behaviour unchanged by any retry state (AC8).
- Permission: Owner/Admin/Member can all replay, including a Member on a proxy they did not
  create (no ownership limit); a non-member 403s/404s; policy is permission-based
  (`proxy:replay`) not role-named (AC14).
- Redelivered replay processing job: no duplicate delivery rows (unique on
  `(dispatch_uuid, destination_id)`), no duplicate attempts.

**Retention interplay (AC15–AC18):**
- Replay of a **cleaned** event: 422/410 path, zero delivery rows, zero attempts, zero HTTP
  sends (AC15, AC17).
- Race: GC erases between page load and replay POST — the in-transaction `lockForUpdate`
  re-check rejects; conversely, replay rows committed first hold the erase (compare-and-set
  affects zero rows) (AC17/AC18).
- H5: an expired event with a `retrying` delivery is not erased; with only terminal deliveries
  it **is** erased on the next pass (terminal holds nothing); a `pending` delivery holds only
  within the dispatch horizon (AC18).
- Retry executor meeting a cleaned parent (H4-residual simulation): terminalizes, emits
  `DeliveryExhausted`, sends nothing, logs identifiers only (AC17).
- The three payload states render distinctly from `payload_cleaned_at` — including
  `never_captured` for an unknown ingest id — never inferred from `body` (AC16).

**Read surface & reveal (AC25, PRD-05 AC16):**
- List and detail props contain **no** `body`/`headers` for any state (AC25 fetch-on-reveal;
  AC22).
- Payload endpoint: retained ⇒ exact bytes, `text/plain`, `nosniff`, `no-store`; cleaned ⇒ 410;
  unknown ⇒ 404; cross-team/other-proxy event id ⇒ 404 (scoped binding); unauthenticated ⇒
  redirect; member with view permission succeeds (no distinct reveal permission).
- Events list paginates newest-first with descriptor fields; empty state renders (design
  Screen 2).
- `fifoHeldByRetry` prop true iff a FIFO proxy has an `awaiting_retry` row.
- Pre-#6 event (attempts, no deliveries): detail renders derived per-destination state, no
  error (ruling 3).

**Proxy form & policy columns (AC2, AC20):**
- Store/update accept limit 1–10 + strategy on enhanced; reject 0/11/unknown strategy; reject
  any value when `mode = simple` (`prohibited_if`); switching enhanced→simple on update clears
  stored values to NULL.
- `ProxyResource` emits the two fields; Show-page card states derive (design Screen 1 table).
- Mode field still gates nothing else — no toggle surface added (AC20).

**Unit:**
- `RetryPolicy` — limit clamp, strategy default, delay table per strategy, `worstCaseSpan`,
  config-sanity exceptions.
- `DeliveryStatus::isTerminal`; CAS transition helper matrix (from × to).
- `StoredPayloadLookup::dispatchedBytesFor` — NULL output ⇒ raw bytes; diverged output ⇒
  dispatched bytes; refuses/never called on cleaned.
- `applyHolds` H5 predicate in isolation (retrying / young-pending / old-pending / terminal).
- `PipelineContext` dispatch-uuid defaulting; `DeliverStep` iterates delivery rows.

## Milestones (task-breakdown-ready)

Ordered; each independently verifiable and green. M1 is blocked on Owner flags 4–7; M2+ on the
ADR flags.

- **M1 — Schema & vocabulary.** Migrations (deliveries; proxies columns; attempts linkage +
  key swap; fifo identity + status), `Delivery` model, enums (`RetryBackoffStrategy`,
  `DeliveryStatus`, `DispatchKind`, `FifoDispatchStatus+`), model casts/fillables,
  `config/retry.php`, `IngestController` `dispatch_uuid` stamp. *Verify:* migrations up/down on
  MySQL; existing suite green.
- **M2 — Dispatch identity through the pipeline.** `PipelineContext.dispatchUuid`,
  `ProcessIngestedWebhook` (param + original delivery-row creation), `DeliverStep` iterates
  deliveries, `DeliveryUnit.deliveryId`, `DeliverToDestination` new attempt key (no retry logic
  yet). *Verify:* #1/#3/#4/#5 behaviour unchanged end-to-end; attempt rows carry `delivery_id`.
- **M3 — Retry engine.** `RetryPolicy` service, `DeliverToDestination` settle transitions +
  scheduling + `DeliveryExhausted`, `RetryDelivery`, `SweepDueRetries` + schedule entry.
  *Verify:* AC1–AC5, AC7 test groups.
- **M4 — FIFO composition.** Advancer (busy-gate, order key, settle-or-hold), retry-side fifo
  settlement, `SweepStalledFifoDispatches` extensions. *Verify:* AC6 group + #4 suites.
- **M5 — Retention holds & guards.** H5 in `applyHolds`, executor cleaned guard, AC18 bound
  test. *Verify:* retention-interplay group; #5 GC suite green.
- **M6 — Replay backend.** `ReplayProxy` permission + bundles + policy + DTO,
  `ReplayEventRequest`, `ProxyEventReplayController` (transaction, FIFO/Async branch).
  *Verify:* AC9–AC14 group.
- **M7 — Read surface backend.** Routes + scoped bindings, `Proxy::webhookEvents`,
  `ProxyEventController`, payload endpoint + hardening headers + access log, resources
  (event/delivery/attempt incl. legacy fallback), `fifoHeldByRetry`. *Verify:* read-surface
  group.
- **M8 — Proxy form & policy surface.** Request rules, controller persistence/clearing,
  `ProxyResource` fields. *Verify:* form group.
- **M9 — Frontend.** Events Index/Show pages, `PayloadViewer` (fetch-on-reveal), `ReplayDialog`,
  Show-page additions, `ProxyForm` retry section + Mode help-text fix, data consts, TS types.
  *Verify:* pnpm lint/types/format; manual flows A–G per design-06.
- **M10 — Quality sweep.** Full suite parallel, Pint, PHPStan L7, docs cross-check (docblocks
  citing superseded ADR-011 positions updated to point at ADR-016).

## Handoff
- **Inputs:** Approved PRD-06 (incl. rendered Owner rulings); approved design-06; resolved
  Q-06-01/Q-06-02; **Q-06-03 (answered by this plan — RESOLVED)**; ADR-003/004/005/009/010/011/
  012/013/014 (Accepted); **ADR-015/016/017 (Proposed, this plan)**; plans 03–05; current code
  (`IngestController`, `ProcessIngestedWebhook`, `DeliverStep`, `DeliverToDestination`,
  `AdvanceProxyFifoQueue`, `SweepStalledFifoDispatches`, `PurgeExpiredPayloads`,
  `StoredPayloadLookup`, `RetentionPolicy`, `PipelineContext`/`PipelineFactory`, `DeliveryUnit`,
  models + the four affected migrations, `ProxyPolicy`, `TeamRole`/`TeamPermission`,
  `ProxyResource`, `ProxyController`, proxy Vue pages); `docs/standards/` (architecture, coding,
  testing, planning, documentation).
- **Outputs:** this plan; **ADR-015**, **ADR-016**, **ADR-017** (all Proposed); the annotated
  **ADR-011** (pointer-only, pending-approval phrasing); the answered **Q-06-03**.
- **Dependencies:** none new; within stack. All seven flagged items must be Owner-approved
  before the corresponding work starts.
- **Outstanding Questions:** **none.** Q-06-01/Q-06-02 were Owner-resolved (2026-08-12);
  Q-06-03 is answered in full above and RESOLVED in its doc. No requirement returns to the
  Product Manager: every PRD-06 AC is feasible as stated.

### Owner-approval flags (✋) — the complete current list: SEVEN items
The plan's self-certification does **NOT** cover any of these.

1. **ADR-015 — the automatic-retry mechanism.**
   *Ask:* approve the per-dispatch `deliveries` state entity; the compare-and-set transition
   discipline; delayed by-reference retry jobs with a scheduled sweeper net and unique-key
   dedupe; the explicit terminal `failed` state and the once-only **`DeliveryExhausted`** event
   (#13's hook); the backoff constants and their structural AC18 bound (~32.6 h worst case);
   and hold **H5** joining ADR-012's list additively. Includes the behavioural fact that every
   existing proxy starts retrying failed deliveries (default 5 attempts) on deploy — the AC1
   Owner ruling made operational.
2. **ADR-016 — FIFO composition, carrying the partial supersession of Accepted ADR-011.**
   *Ask:* approve the `awaiting_retry` line-hold state and sweeper rules; the decision **not**
   to adopt the anticipated `dead_lettered` status (terminal heads settle; reasons enumerated);
   and the **three enumerated ADR-011 position supersessions** — order key `webhook_event_id` →
   row `id`; `UNIQUE(webhook_event_id)` → `UNIQUE(dispatch_uuid)`; attempts idempotency key
   `(ingest_id, destination_id, attempt_number)` → `(delivery_id, attempt_number)`. ADR-011
   keeps its file, status, and text; annotations are pointer-only.
3. **ADR-017 — replay dispatch and the payload read surface (security-sensitive).**
   *Ask:* approve replay as a dispatch identity through the unchanged pipeline
   (`PipelineContext` gains `dispatchUuid`); the `proxy:replay` permission case granted to all
   three roles with no ownership axis (the Owner's own Q-06-02c ruling, mechanized per ADR-009);
   and the **first user-facing egress of stored payload content** — the fetch-on-reveal payload
   endpoint gated by the proxy read permission, `text/plain` + `nosniff` + `no-store`,
   identifiers-only logging, 410 for cleaned.
4. **Data-model change — new table `deliveries`.**
   *Ask:* approve the exact shape in *Data Model* above, verbatim — including the restrict FK to
   `webhook_events` and the acknowledged unbounded, payload-free record growth (same class as
   deferred concern D1).
5. **Data-model change — two columns on the EXISTING `proxies` table.**
   *Ask:* approve, verbatim: `retry_attempt_limit TINYINT UNSIGNED NULL` and
   `retry_backoff_strategy VARCHAR NULL` (enum-cast), NULL = system default, always NULL for
   simple-mode proxies. Additive; no backfill.
6. **Data-model change — the EXISTING `delivery_attempts` table.**
   *Ask:* approve `delivery_id` (nullable restrict FK; NULL only on pre-#6 rows, no backfill)
   and the **replacement of the ADR-011-approved unique index** with
   `UNIQUE(delivery_id, attempt_number)` — the one non-additive index step, required for replay
   attempt numbering; the exactly-once mechanism itself is unchanged.
7. **Data-model change — the EXISTING `fifo_dispatches` table.**
   *Ask:* approve `dispatch_uuid UUID NOT NULL` + `UNIQUE(dispatch_uuid)` (backfilled from each
   row's own event `ingest_id` — mechanical, dev/CI data only), dropping
   `UNIQUE(webhook_event_id)` in favour of a plain FK index, and appending the
   `awaiting_retry` enum value.

**Not tripped:** no new Composer/pnpm dependency, no stack change, no irreversible/destructive
operation, no change to `teams`, `webhook_events`, `dispatched_payloads`, or `destinations`, no
retention-window or erasure-semantics change. V3 (transport) and V8 (targets) are **not**
reopened.

### Certification (Principal Engineer, 2026-08-12)
I have verified PRD-06 is Owner-approved and design-06 is PM-approved (the mandatory design
gate for the PRD's UX Direction); read ADR-001–014, plans 03–05, and the affected code; and
answered Q-06-03 in full (recorded in its doc, RESOLVED). Every section above traces to PRD-06
acceptance criteria and the approved design; the named technical rulings stay inside the
upstream artifacts' assumptions. `docs/stack/stack.md` is unchanged and no new dependency is
introduced.

**I self-certify this plan** under the delegated plan gate in `CLAUDE.md` — **except the seven
Owner-approval flags above, which self-certification does not and cannot cover**: three ADRs
(one carrying partial supersession of an Accepted ADR, one security-sensitive) and four
data-model changes. No work depending on a flagged item may start before the Owner rules on it.
Nothing in this plan changes a requirement, reinterprets the PRD or the design spec, or reopens
Q-06-01, Q-06-02, the retention contract, D1, V3, or V8.

- **Next Agent:** **Task Planner** — after Owner approval of items 1–7 (the schema is M1 and
  everything depends on it, so no unflagged work meaningfully precedes the ruling).
