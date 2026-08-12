# ADR-015: Automatic delivery retry — per-dispatch `deliveries` state, policy columns on `proxies`, delayed-job scheduling with a sweeper net, and the explicit terminal state

- **Status:** **Accepted (Project Owner, 2026-08-12).** Gates carried and approved with this
  acceptance: two data-model changes to existing tables (`proxies`, `delivery_attempts` — the
  latter replaces an ADR-011-approved unique index), one new table (`deliveries`), and a new
  domain event that #13 will consume.
- **Author:** Principal Engineer
- **Date:** 2026-08-12
- **Feature:** prd-06-retry-replay (AC1–AC8, AC13, AC18; realizes the ADR-005 "#6 attaches
  retry/backoff on the Action's job" seam and ADR-003's terminal-event hook for #13)
- **Companions:** ADR-016 (FIFO composition; carries the ADR-011 supersessions this design
  requires) · ADR-017 (replay dispatch and the payload read surface)

## Question
PRD-06 requires every failed destination delivery to be retried automatically on a bounded
backoff schedule (AC1–AC3), per a per-proxy policy of exactly two knobs — attempt limit (1–10,
default 5) and strategy (exponential default / fixed interval), enhanced-configurable only
(AC2) — ending in an **explicit, directly represented terminal failed state** (AC4) that emits
a domain event for #13 (AC5), with every retry landing on the same ADR-003 attempt-record/event
stream under the same exactly-once settlement as #4 (AC7), and with outstanding retries holding
GC erasure while terminal deliveries hold nothing (AC18). Replay deliveries must be subject to
the identical machinery (AC13). Three concrete questions:

1. **Where does per-(event, destination) delivery state live?** AC4 forbids inferring terminal
   from the absence of further attempts; `webhook_events` is immutable-while-retained (ADR-010
   P1 as narrowed by ADR-014) and may not carry it; `delivery_attempts` is an append-only
   per-attempt fact record (ADR-003) and modelling "current state + next-attempt schedule" on it
   would make outcome rows mutable scheduling rows.
2. **How is a retry executed hours later** without holding a worker, without serializing payload
   bytes into long-lived Redis delayed jobs, and without losing the chain when a delayed job is
   dropped?
3. **How does the retry policy persist**, given AC2's fixed two-knob shape and the Q-06-01 caps?

## Decision

**(1) A new `deliveries` table — one row per dispatch × destination — owns delivery state.**
A **dispatch** is one processing of an event toward a destination set: the *original* (at
ingest) or one *replay* (ADR-017). Each dispatch is identified by a caller-minted
**`dispatch_uuid`** — equal to the event's `ingest_id` for the original, a fresh UUID per
replay — which makes creation idempotent under at-least-once redelivery via
`UNIQUE(dispatch_uuid, destination_id)`. Columns: `team_id`/`proxy_id`/`destination_id`/
`webhook_event_id` (all restrict FKs), `dispatch_uuid`, `kind` (`original`|`replay` — explicit,
never inferred), `status` (`pending`|`retrying`|`succeeded`|`failed`), `next_attempt_at`
(non-NULL iff `retrying`), timestamps. `succeeded` and `failed` are terminal; **`failed` IS the
AC4 terminal state — a stored fact, never a derivation.** Payload-free, no soft delete: this is
delivery-progress state, exactly parallel to how `fifo_dispatches` is ordering state (ADR-011)
— neither a payload store nor an outcome-history store.

Original-dispatch rows are created by `ProcessIngestedWebhook` (pipeline entry) after its
cleaned-state guard, one per live destination, `firstOrCreate` under the unique key; replay rows
are created by the replay endpoint (ADR-017). `DeliverStep` iterates the dispatch's `deliveries`
rows (not `$proxy->destinations` directly) and executes **attempt 1** per row; attempts ≥ 2 are
exclusively the retry executor's.

**(2) `delivery_attempts` gains `delivery_id`; the idempotency key moves to
`UNIQUE(delivery_id, attempt_number)`.** The ADR-011 key `UNIQUE(ingest_id, destination_id,
attempt_number)` cannot survive replay — a replay to the same destination legitimately restarts
at attempt 1 for the same `(ingest_id, destination_id)`. The new key preserves the identical
exactly-once mechanism (create-or-resume on a unique index, ADR-011 Decision 4) at the correct
grain. `delivery_id` is nullable only for pre-#6 rows (no backfill; NULLs do not collide under
MySQL unique semantics). Attempt rows remain payload-free, append-only, ADR-003-shaped —
`attempt_number` now actually increments. The index replacement is enumerated as an ADR-011
position supersession in **ADR-016**.

**(3) Retry policy persists as two nullable columns on `proxies`; NULL means "system
default".** `retry_attempt_limit` (`TINYINT UNSIGNED NULL`, 1–10) and `retry_backoff_strategy`
(`VARCHAR NULL`, cast to `App\Enums\RetryBackoffStrategy { Exponential, Fixed }`). Simple-mode
proxies always hold NULL/NULL (validation rejects, and the controller clears, values when
`mode = simple`) — the system default applies to them fixed and silently (AC1/AC2).
`App\Services\RetryPolicy` is the **single resolver** (the `RetentionPolicy` pattern):
`attemptLimitFor(Proxy)` (config default 5, hard-clamped to the cap 10),
`strategyFor(Proxy)` (default exponential), `delayBefore(Proxy, int $attemptNumber)`. No other
consumer reads the columns or `config('retry.*')`.

**(4) Backoff schedule (config constants, not user knobs).** Delay before attempt *N* (N ≥ 2):
- **Exponential (default):** `min(base × multiplier^(N−2), per-delay cap)` — defaults 60 s base,
  ×5 multiplier, 6 h cap → 1 m, 5 m, 25 m, ~2 h, then 6 h flat. Worst case (limit 10):
  **≈ 32.6 hours** total.
- **Fixed interval:** constant 300 s → worst case 45 minutes.

Both worst cases sit far inside the 30-day retention window — the AC18/Q-06-01 "bounded well
inside" cap holds **structurally** (no configuration reaches even 2 days), and a test asserts
`RetryPolicy::worstCaseSpan()` stays under a small fraction of `RetentionPolicy`'s window so a
future constant change trips loudly.

**(5) Scheduling is a conditional status transition + a delayed by-reference job + a scheduled
sweeper — the ADR-011 belt/suspenders/dedupe pattern, reapplied.** When `DeliverToDestination`
settles an attempt it transitions the delivery row with a **compare-and-set** (`WHERE status IN
('pending','retrying')`):
- success → `succeeded`;
- failure with `attempt_number ≥ limit` → `failed` (terminal); the CAS affecting a row is what
  emits **`DeliveryExhausted`** — exactly once per delivery (AC5);
- failure below the limit → `retrying` + `next_attempt_at = now() + delayBefore(N+1)`, then
  `RetryDelivery::dispatch($deliveryId, N+1)->delay(...)->onQueue(webhooks)`.

`RetryDelivery` (new `AsJob` action, `$tries = 1`) executes **by reference**: it reloads the
delivery, skips unless still `retrying`, **guards the parent event's `payload_cleaned_at`
(ADR-014 Decision 7)**, resolves the send bytes as *the recorded dispatched output* —
`dispatched_payloads.body ?? webhook_events.body`, via a new `StoredPayloadLookup` method so
ADR-013 Decision 3's single-resolver rule holds — rebuilds the `DeliveryUnit` (headers from the
captured row, method from the destination, `deliveryId`, `attemptNumber = N`), and runs
`DeliverToDestination::run()`. No payload bytes ever sit in a delayed Redis job. A scheduled
**`SweepDueRetries`** (every minute, beside the FIFO sweeper) re-dispatches `RetryDelivery` for
any `retrying` delivery whose `next_attempt_at` passed more than a grace period ago — the
liveness net for dropped delayed jobs. Double-fires (sweeper + delayed job) are arbitrated by
the `UNIQUE(delivery_id, attempt_number)` create-or-resume, exactly as #4 arbitrates redelivery.

**Retry re-sends; replay re-processes.** This asymmetry is deliberate and principled: a retry is
a further attempt of the *same* delivery, so it re-sends the recorded dispatched output
(byte-identical to raw until #8/#9 fill a transform seam); a replay is a *new* dispatch and
re-processes the raw payload through the current pipeline (AC11, ADR-017).

**(6) Terminal exhaustion emits `DeliveryExhausted`, on the same stream.** A new event class
beside `DeliveryAttempted`/`DeliverySucceeded`/`DeliveryFailed` (ADR-003), carrying the
`Delivery` model (team, proxy, destination, event all reachable — AC5). No listener at #6; #13
subscribes later. Per-attempt records and events are unchanged and fire for every retry (AC7).

**(7) GC hold H5 registers additively on ADR-012's named list.** An event is held from erasure
while it has a delivery with `status = 'retrying'`, **or** `status = 'pending'` younger than
the existing `retention.dispatch_horizon_minutes` (the H4 shape, reapplied: a pending row whose
first-attempt job was permanently lost must not immortalize a payload — after the horizon it
stops holding; FIFO pending rows remain covered by H2's non-settled `fifo_dispatches` hold
regardless of age). Terminal deliveries (`succeeded`, `failed`) hold **nothing** (AC18).
H5 joins H0–H4 in `PurgeExpiredPayloads::applyHolds()` and is therefore re-asserted inside the
erase `UPDATE` automatically (ADR-012 Decision 1's compare-and-set). ADR-012 anticipated exactly
this ("#6 attaches replay/dead-letter holds to the same list"); nothing there is reversed.

## Alternatives
- **Laravel job retries (`$tries`/`backoff()`) on `DeliverToDestination`** — the literal reading
  of ADR-005's seam. Rejected: FIFO runs the action inline (`::run`), where no job retry exists;
  a failed HTTP response is a recorded outcome, not an exception, so retrying would mean
  throwing on business outcomes; `$tries` is static per class while the limit is per-proxy;
  job-level retries re-run attempt 1's identity instead of incrementing `attempt_number`
  (breaking ADR-003/AC7's per-attempt record shape); and the schedule would live invisibly in
  Redis with no durable state to satisfy AC4/AC18 or the UI.
- **Scheduling state on `delivery_attempts` (e.g. `next_attempt_at` on the failed row)** — makes
  immutable outcome facts mutable scheduler rows, and still leaves per-delivery status
  (AC4's terminal) derived rather than represented. Rejected.
- **Terminal state inferred (last attempt failed ∧ count = limit)** — directly violates AC4
  ("never inferred from the absence of further attempts"), and breaks the moment the limit
  changes on the proxy. Rejected.
- **Carry the payload in the delayed retry job (`DeliveryUnit` as-is)** — up to the ADR-006 cap
  of decrypted payload parked in Redis for hours, widening the exposure review-05 already flagged
  for `failed_jobs`; and a cleaned event's bytes could outlive erasure inside the queue,
  weakening the AC17 guard from structural to temporal. Rejected: dispatch-by-reference is the
  ADR-011 house shape.
- **Scheduler-only execution (cron scans `next_attempt_at`, no delayed jobs)** — one-minute
  granularity for every retry and a thundering scan as volume grows; the delayed job gives
  precision, the sweeper gives liveness — same division of labour as ADR-011's self-dispatch +
  sweeper. Rejected as the sole mechanism, kept as the net.
- **An event-listener on `DeliveryFailed` schedules the retry** — decouples on paper, but the
  transition must be atomic with the attempt settlement (CAS on the delivery row) and the
  listener adds an at-least-once hop with its own redelivery ambiguity for zero gained
  flexibility. Rejected; the settling code path schedules.
- **A `retry_policies` table / JSON policy column** — AC2 fixes the shape at exactly two scalar
  knobs with system caps; a table or document models flexibility the PRD explicitly rules out at
  #6. Rejected (two nullable columns mirror the `response_status`/`response_body` precedent).
- **Snapshot the policy onto the dispatch/delivery at creation** — stable mid-flight, but AC11's
  "current configuration" posture and simplicity favour live-read; see the plan's mid-flight
  policy-change ruling. Rejected.
- **An `attempt_count` counter column on `deliveries`** — denormalized state to keep consistent;
  the executor derives the next attempt number from `MAX(attempt_number)+1` and the unique key
  arbitrates races; the UI derives counts from the attempts it already loads. Rejected.

## Reasoning
- **The sidecar-state pattern is already ratified twice.** `webhook_events` may not carry
  mutable state (ADR-010/ADR-014), so #4 put ordering state in `fifo_dispatches`; #6 puts
  delivery state in `deliveries` for the same reason with the same FK/team shape. One new
  entity, no change to what any existing table means.
- **CAS transitions make every hard guarantee cheap.** Exactly-once `DeliveryExhausted`
  emission, no double-scheduling, no post-terminal resurrection, and safe sweeper/delayed-job
  racing all fall out of `UPDATE … WHERE status IN (…)` + the attempts unique key — the two
  primitives #4 already proved under this queue.
- **By-reference retries inherit the retention contract.** The executor's read hop lands on the
  same guarded path as the pipeline entry: H5 (+H2 under FIFO) holds erasure while work is
  outstanding, the erase re-asserts holds atomically, and the ADR-014 Decision 7 guard makes a
  raced clean a logged no-op that terminalizes the delivery — never an empty-body send (AC17).
- **The caps do the AC18 arithmetic structurally.** With limit ≤ 10 and per-delay cap 6 h, no
  expressible policy exceeds ~32.6 h of schedule — two orders of magnitude inside the window —
  so "a retry policy can never make a payload immortal" needs no runtime coupling between the
  retry and retention subsystems, only a guard test.
- **#11 and #13 get one stream, richer** (roadmap build-ahead): attempt records gain true
  attempt numbers and a delivery FK; the terminal event is the exact hook the roadmap promised
  #13; nothing parallel, nothing reconstructed.

## Impact
- **Data-model change (Owner-gated ✋):** `proxies` + `retry_attempt_limit`,
  `retry_backoff_strategy`; new `deliveries` table (shape above, verbatim in plan-06);
  `delivery_attempts` + `delivery_id` FK and unique-key replacement (drops the ADR-011-ratified
  index — supersession enumerated in ADR-016). All additive apart from the index swap; **no
  backfill** (pre-#6 attempt rows keep `delivery_id NULL`; pre-#6 events get no synthetic
  delivery rows).
- **Behavioural change, Owner-visible:** every proxy — simple and enhanced, existing and new —
  starts retrying failed deliveries up to 5 times by default (AC1, Owner ruling Q-06-01a).
  Destinations may receive up to `limit` sends per delivery; delivery remains at-least-once.
- **Operational:** one new scheduled entry (`SweepDueRetries`, every minute) beside the FIFO
  sweeper; delayed jobs on the existing Redis queue; no new dependency, no stack change.
- **Easier later:** #13 subscribes to `DeliveryExhausted`; #11 aggregates real multi-attempt
  records; #7's mode toggle exposes fields that already exist; ADR-017 reuses `deliveries` for
  replay wholesale (AC13 costs nothing — replay deliveries enter the identical state machine).
- **Constrained:** `deliveries` is delivery-progress state only — never a payload store, never
  an outcome-history store (attempts remain that); only the settling path and the guards may
  transition `status`, always by CAS; `RetryPolicy` is the only reader of the policy columns and
  `config('retry.*')`; the executor may never send without the `payload_cleaned_at` guard.
