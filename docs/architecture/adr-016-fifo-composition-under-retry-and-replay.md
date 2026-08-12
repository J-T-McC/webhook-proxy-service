# ADR-016: FIFO composition under retry & replay — `awaiting_retry` line-hold state, row-id order key, and per-dispatch ordering rows (partially supersedes ADR-011)

- **Status:** **Accepted (Project Owner, 2026-08-12).** Gates carried and approved with this
  acceptance: a schema change to the existing `fifo_dispatches` table (new column + unique-key
  replacement + enum value, with a small in-place backfill) and the **partial supersession of
  three named positions of ADR-011** (Accepted, Owner 2026-08-04).
- **Author:** Principal Engineer
- **Date:** 2026-08-12
- **Feature:** prd-06-retry-replay (AC6, AC11's FIFO half, AC18's FIFO interplay; closes the
  PRD-04 AC10 head-of-line bound deferred to #6)
- **Relationship to ADR-011:** **partially supersedes** — three named positions only (see
  § Positions superseded). ADR-011 otherwise stands, Accepted and operative: the claim-based
  single-advancer, the atomic `FOR UPDATE` claim, the lease + sweeper liveness net,
  `WithoutOverlapping` as reducer-not-guard, dispatch-by-reference, and the sidecar-table
  placement are all unchanged and relied on here.
- **Companions:** ADR-015 (the retry machinery whose waits this ADR represents in line state) ·
  ADR-017 (the replay dispatch that joins the line)

## Question
PRD-06 AC6 requires that on a FIFO proxy a retrying head **holds its own proxy's line for its
backoff** (the intended ordered-means-waiting semantic) but the line **advances past the head
once it reaches its terminal state** — bounded, never wedged — while Async retries serialize
nothing. PRD-06 AC11 requires a replay on a FIFO proxy to **join the line as the newest work**.
Three mechanical conflicts with the #4 machinery as built:

1. **A backoff wait cannot live inside a claim.** The advancer's claim lease is ~90 s
   (`ingest.fifo_lease_seconds`); a retry schedule spans minutes to hours (ADR-015). Holding the
   claim across the backoff would either stall a worker or be reaped by
   `SweepStalledFifoDispatches` as an orphaned claim — Q-06-03(1)'s "sweeper must respect a
   legitimately-retrying head".
2. **The order key cannot admit a replay.** ADR-011 orders the pending scan by
   `webhook_event_id` and enforces `UNIQUE(webhook_event_id)`. A replayed old event carries a
   *low* event id — a new ordering row would jump the queue, and the unique index forbids the
   row existing at all.
3. **The anticipated `dead_lettered` status has a hidden cost.** ADR-011's Impact sketched a
   `dead_lettered` status excluded from the lowest-pending scan. Under ADR-012's H2 hold ("no
   `fifo_dispatches` row with `status <> 'settled'`"), any never-settling status would hold
   erasure **forever** — a terminal head would immortalize its payload, the exact opposite of
   AC18 ("terminal deliveries hold nothing").

## Decision

**(1) A new `awaiting_retry` status represents "head is between attempts"; the line is held by
state, not by a lease.** Lifecycle: when the advancer's run completes
`ProcessIngestedWebhook::run(...)` and any of the dispatch's `deliveries` are non-terminal
(ADR-015), it transitions its claimed row `claimed → awaiting_retry` (clearing
`claimed_at`/`lease_expires_at`) and does **not** self-dispatch — the line is deliberately held
(AC6's Owner-owned semantic). The advancer's live-claim gate treats **either** a live-lease
`claimed` row **or** any `awaiting_retry` row as "this proxy is busy", so no next event is
claimed while the head waits. When a `RetryDelivery` execution settles the dispatch's last open
delivery (success or terminal failure — ADR-015), it compare-and-sets
`awaiting_retry → settled`, stamps `settled_at`, and dispatches `AdvanceProxyFifoQueue` — the
line advances. All transitions are conditional updates keyed on the prior status; racing
settlers are idempotent.

**(2) No `dead_lettered` status.** A head whose deliveries are all terminal — however they got
there, including exhaustion — settles (`settled`). Reasons: (a) the H2 immortality trap above;
(b) event-level "dead-lettered" is **ambiguous under fan-out** (3 destinations: 1 delivered,
2 exhausted — which is the row?), whereas per-destination terminal state already lives exactly
once, on `deliveries.status = 'failed'` (ADR-015, AC4); (c) a second copy of that fact on the
ordering row is the same no-consumer bookkeeping ADR-013 rejected (`diverged` flag). ADR-011's
Impact *anticipated* `dead_lettered`; the **requirement** it served — terminal heads are excluded
from the lowest-pending scan so the line advances — is met by `settled`, which the scan already
excludes. The `FifoDispatchStatus` docblock's reservation is resolved as *not adopted*, with
this ADR as the record. Consequence: **H2 needs no change** — `settled` remains the only
non-holding status, and terminal heads release their hold on the next GC pass.

**(3) The pending scan orders by the ordering row's own `id`; `dispatch_uuid` becomes the
identity and idempotency key.** `fifo_dispatches` gains `dispatch_uuid` (UUID, NOT NULL,
UNIQUE): the event's `ingest_id` for capture-created rows, the replay's fresh UUID for
replay-created rows (ADR-017). `UNIQUE(webhook_event_id)` is dropped (replaced by a plain FK
index) so one event may hold several ordering rows over its life — original plus replays, each a
distinct dispatch. The advancer's lowest-pending scan changes `ORDER BY webhook_event_id` →
`ORDER BY id`. For capture-created rows the two orders are identical by construction (the
ordering row commits in the same transaction as its event, per proxy, in arrival order); for
replay rows, a fresh `id` **is** "the back of the line at the time of replay" (AC11) — the same
monotonic-key, MIN-of-pending-set, gaps-harmless argument ADR-011 made, on a column that also
admits replays. The advancer passes `($row->webhookEvent->ingest_id, $row->dispatch_uuid)` to
`ProcessIngestedWebhook`, so a replay row drives the replay's delivery subset, not a re-run of
the original (ADR-017).

**(4) Sweeper rules extend, not change.** `SweepStalledFifoDispatches`:
- **(a) Orphaned-claim reaper — unchanged.** Only `claimed` rows past their lease are reaped. An
  `awaiting_retry` row has no lease and is **never** reaped — the legitimately-retrying head is
  structurally invisible to the reaper (Q-06-03(1) answered by construction, not by exception).
- **(b) Idle-proxy nudge — one added predicate.** A proxy with an `awaiting_retry` row is *held*,
  not idle: excluded from the nudge (else the nudge would claim past a waiting head and break
  ordering).
- **(c) New — stuck-hold release.** An `awaiting_retry` row whose dispatch has zero non-terminal
  deliveries (the ADR-015 settler crashed after settling deliveries but before the fifo
  transition) is compare-and-set to `settled` and the advancer nudged. The retry chain itself is
  kept live by `SweepDueRetries` (ADR-015); this closes the one crash window between the two
  state machines.

**(5) Async is untouched by construction.** Async proxies still never own `fifo_dispatches`
rows; retries are per-delivery delayed jobs (ADR-015) with no shared key, lock, or line — one
event's retries delay nothing else (AC6's Async half).

## Positions superseded — exactly three, all ADR-011, all forced by Owner-approved AC6/AC11

| ADR-011 position (verbatim) | Superseded to |
|---|---|
| **P1 — Decision 2:** "`webhook_event_id` (the monotonic **order key**)" (and the migration's "the monotonic order key") | The order key is the ordering row's own `id`. Order-identical for every capture-created row; required so replay rows join at the back (AC11). The MIN-of-pending-set mechanism is unchanged. |
| **P2 — migration/Decision 2:** `UNIQUE(webhook_event_id)` ("the capture-idempotency guard") | Replaced by `UNIQUE(dispatch_uuid)`. Capture idempotency is preserved (a capture-created row's `dispatch_uuid` *is* its event's `ingest_id`); the constraint now also guards replay-row idempotency. `webhook_event_id` keeps a plain index for its FK. |
| **P3 — Decision 4:** "A **`UNIQUE(ingest_id, destination_id, attempt_number)`** index on `delivery_attempts` … makes delivery idempotent" | The idempotency **mechanism** stands; its **key** becomes `UNIQUE(delivery_id, attempt_number)` (ADR-015 Decision 2), because a replay legitimately reuses `(ingest_id, destination_id, 1)`. The old index is dropped. |

Additive, **not** supersessions: the `awaiting_retry` enum value (the ADR-011 enum docblock
explicitly reserved a #6 addition); the advancer's post-run settle-or-hold decision; the sweeper
extensions. ADR-011 keeps its file, status, and full text; its Status line gains a pointer and
the three positions gain inline annotations (the ADR-014/ADR-010 pattern), effective only if
this ADR is Accepted.

## Alternatives
- **Hold the line by extending the claim lease to `next_attempt_at`** — keeps one status but
  overloads "lease" to mean both "worker is processing" and "nobody is processing until T";
  every reaper/nudge predicate then needs to distinguish the two anyway, and a crash between
  lease-extension and retry-scheduling strands the row until a reaper whose semantics were just
  weakened. An explicit state is honest and keeps the reaper's rule one line. Rejected.
- **Re-claim the head on every retry attempt (release between attempts)** — between attempts the
  head would be `pending` again and the scan would… claim it, since it is the lowest row; making
  the scan skip "pending-but-scheduled-later" rows re-invents `awaiting_retry` as a predicate on
  another table. Rejected.
- **`dead_lettered` as anticipated** — see Decision 2: H2 immortality, fan-out ambiguity,
  duplicated fact. Rejected with the trade-offs stated rather than stumbled into.
- **Keep `ORDER BY webhook_event_id` and give replays a synthetic high order key** (e.g. a
  bigint `order_key` column defaulted to `webhook_event_id`, replay rows getting `MAX+1`) — an
  extra column and an extra read-modify-write to reproduce what the auto-increment PK already
  provides. Rejected.
- **Replays bypass the line entirely (run Async on a FIFO proxy)** — violates AC11 verbatim
  ("on a FIFO proxy it joins the line as the newest work") and the approved design's dialog copy.
  Rejected — not ours to reopen.
- **A separate `fifo_replays` ordering table** — two tables, one line: the advancer would need a
  cross-table lowest-pending merge under lock. Rejected.

## Reasoning
- **Every #4 correctness primitive survives untouched.** Single atomic claim under
  `FOR UPDATE`; lease + reaper for crashed workers; `WithoutOverlapping` as a reducer;
  MIN-of-pending ordering; at-most-one in-flight event per proxy. #6 adds one state to the
  row lifecycle (`pending → claimed → awaiting_retry* → settled`) and widens the "busy" gate by
  one predicate — the smallest change that makes hours-long waits representable.
- **AC6's bound is now structural.** The head holds the line exactly while its dispatch has
  non-terminal deliveries; ADR-015's caps bound that at ~32.6 h worst case; terminal state
  settles the row; the scan advances. One poison event delays its line, never wedges it — the
  PRD-04 AC10 deferred bound, closed.
- **Liveness has no new single point of failure.** The happy path is event-driven (settler
  transitions + advancer dispatch); every crash window is covered by an existing or extended
  sweep (claimed-orphan reaper, due-retry sweep, stuck-hold release) — the same
  belt/suspenders posture review-04 already validated.
- **The order-key change is zero-risk for existing behaviour** (provably order-identical for
  capture-created rows) and is the entire mechanism for AC11's join-at-the-back — no new
  machinery, exactly as Q-06-03(4) hoped.

## Impact
- **Data-model change (Owner-gated ✋):** `fifo_dispatches` — new `dispatch_uuid UUID NOT NULL`
  with `UNIQUE(dispatch_uuid)`; drop `UNIQUE(webhook_event_id)`, add plain index
  `(webhook_event_id)`; enum `status` gains `awaiting_retry`. Migration backfills
  `dispatch_uuid` from each row's event `ingest_id` before adding the unique — an in-place
  backfill touching dev/CI data only (no production data exists — Owner-stated basis, ADR-014
  precedent).
- **Supersedes an Accepted ADR (Owner-gated ✋):** the three enumerated ADR-011 positions above.
  The Owner ratifies this ADR as the superseding instrument; ADR-011 is annotated by pointer,
  never rewritten.
- **Code:** `AdvanceProxyFifoQueue` (order key, busy gate, settle-or-hold), `FifoDispatchStatus`
  (+1 case), `SweepStalledFifoDispatches` (+1 predicate, +1 pass), `IngestController` (capture
  path sets `dispatch_uuid = ingest_id`). GC (`PurgeExpiredPayloads`) is untouched by this ADR —
  H2's predicate already treats every non-`settled` status as holding, which is exactly right
  for `awaiting_retry`.
- **Constrained:** `awaiting_retry` may be entered only from `claimed` (advancer) and left only
  to `settled` (retry settler or sweep pass (c)); `fifo_dispatches` remains ordering/claim state
  only — delivery outcomes live on `deliveries` (ADR-015), payloads on the #5 stores; the scan
  must never order by anything but `id` without superseding this ADR.
