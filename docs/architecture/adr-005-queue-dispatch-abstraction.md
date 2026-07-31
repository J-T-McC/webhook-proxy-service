# ADR-005: Queue/dispatch abstraction seam (Redis now, scalable option open)

- **Status:** Accepted
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** cross-cutting (serves #4, #6; keeps V3 open)

## Question
How is pipeline/delivery dispatch structured so that item #1's fire-and-forget
send, #4's FIFO+Async queued dispatch, #6's retry/backoff, and a later
larger-scale queue/streaming choice (V3) all attach without rewriting the
pipeline or steps — while **not** unilaterally picking Kafka?

## Decision
Keep all execution-timing concerns out of the steps and behind a **single
dispatch chokepoint**, realized idiomatically (see the 2026-07-30 correction
below) by **run-sync-or-queue Action classes** (`lorisleiva/laravel-actions`
`AsJob`, proposed in ADR-007), not a bespoke wrapper interface for the common
case:

- `ProcessIngestedWebhook` runs the whole pipeline over one in-memory
  `PipelineContext`; `DeliverToDestination` runs one per-destination delivery unit.
- Each is **one class** run **synchronously at item #1** (`::run(...)`, no jobs
  persisted) or **queued at #4** (`::dispatch(...)->onQueue(...)`); #6 attaches
  retry/backoff on the Action's job. Per-proxy FIFO within Laravel/Redis attaches
  as `onQueue` + a `WithoutOverlapping("proxy:{id}")` job middleware on the Action.

The seam that **remains as a first-party abstraction** is narrower than originally
stated: it guards only a **non-Laravel transport (V3 Kafka/streaming)** — a
partitioned/ordering-key transport that is *not* a Laravel bus job at all. That is
a documented extension point at the two dispatch chokepoint Actions (a thin
first-party publisher introduced only if V3 lands), **not built now**. This ADR
**does not** choose that transport.

> **Correction (2026-07-30) — laravel-actions is the dispatch-timing realization,
> not a rejected alternative.** An earlier revision of this ADR rejected
> `lorisleiva/laravel-actions` on a misread (that it queues each *step*
> independently and pushes timing back into steps, breaking ADR-001's single
> in-memory envelope). That is not the design. The corrected position:
> - **Steps are never queued individually.** They run **in-process** as pipes of
>   the native `Illuminate\Pipeline\Pipeline`, sharing one mutable `PipelineContext`
>   (ADR-001 preserved). Only the *Action's input* is serialized — **once, at the
>   job boundary** — when the pipeline or a delivery is queued at #4. That is the
>   intended dispatch boundary, not a between-steps serialization.
> - **The `AsJob` run-sync-or-queue class IS this seam** for every Laravel-queue
>   transport (sync now, Redis at #4, retry/backoff at #6). Laravel's queue already
>   abstracts sync/database/redis/sqs; a bespoke `Dispatcher` interface over it
>   would re-abstract what the framework and the Action already provide — so it is
>   **not** built for the common case.
> - **Where the thin abstraction still earns its place:** only the **V3
>   non-Laravel transport** and its **ordering-key/partition** model, which
>   `AsJob` cannot express (it is bound to Laravel's bus / `ShouldQueue` /
>   `SerializesModels`). That residual seam stays localized to the two chokepoint
>   Actions. See ADR-007 for the dependency decision.

## Alternatives
- **Steps call Laravel `Queue`/`dispatch()` directly** — leaks execution timing into every step; #4/#6/V3 changes ripple across steps; rejected.
- **A bespoke `Dispatcher` interface wrapping Laravel's queue for the common case** (the original decision here) — re-abstracts what Laravel's bus and a run-sync-or-queue Action already provide for every Laravel-queue transport; the extra indirection earns nothing at #1/#4/#6. **Superseded by the correction above:** the `AsJob` Action is the common-case realization; a first-party abstraction is reserved for the V3 non-Laravel transport only.
- **Queue each *step* as its own job (misread of `lorisleiva/laravel-actions`)** — this was never the proposal and is *not* the chosen design; it would push timing into steps and serialize between steps, breaking ADR-001's single in-memory envelope. See the correction above: steps run in-process as native `Pipeline` pipes; **only** `ProcessIngestedWebhook`/`DeliverToDestination` are dispatchable, serialized once at the job boundary.
- **Pick Kafka now** — the vision lists Kafka/streaming as an open option (V3) gated by unset throughput targets (V8); the Owner has not chosen it and this ADR must not; rejected as premature.
- **Commit to Redis as the permanent transport** — forecloses V3; the seam exists precisely to keep it open; rejected.

## Reasoning
- Constraints fix Redis as the MVP queue while V3 keeps Kafka/streaming open;
  roadmap #4 build-ahead: "dispatch must expose per-attempt steps rather than a
  single fire-and-forget send," and the FIFO/Async design "must accommodate a
  later scalable queue/streaming choice beyond Redis (V3)."
- A driver-agnostic dispatcher is the standard way to make the transport a
  configuration/driver choice rather than an architectural commitment.
- **Why not just native `dispatch()`?** The seam's day-one, transport-independent
  payoff is decoupling *execution timing* (inline now, queued at #4) from the
  steps — which ADR-001 already delegates here; that alone justifies one thin
  interface across #4/#6/#14 regardless of transport. Native Laravel dispatch
  **is** the MVP implementation of that interface: Laravel's queue already
  abstracts sync/database/redis/sqs, so the seam is *not* re-abstracting the
  backend. Its only additional value is a transport Laravel's job-queue model does
  not fit cleanly (Kafka/streaming: partitions, offsets, ordering keys) — the open
  V3 option, not built now. Framed this way the seam is a one-interface
  indirection the pipeline already needs, not speculative infrastructure.
- **Guard against over-engineering (YAGNI):** the interface is shaped by *current*
  needs — dispatch a unit of work, sync vs Redis, carry an ordering key for FIFO
  (#4) — and is deliberately **not** pre-modelled for streaming semantics we have
  no targets for (V8 unset). If V3 lands it is a new driver that **may require
  extending the interface** (e.g. surfacing a partition/ordering key); the honest
  claim is that such a change stays localized to the seam, not that the interface
  is already Kafka-shaped.

## Impact
- **Easier:** #4 = flip `ProcessIngestedWebhook`/`DeliverToDestination` from `::run`
  to `::dispatch` + `onQueue`/`WithoutOverlapping` job middleware (no new bespoke
  driver); #6 = retry/backoff configured on the Action's job; a V3 transport = a
  thin first-party publisher introduced at the two chokepoint Actions, changing no
  step, no `PipelineContext`, and no controller.
- **Depends on ADR-007** (laravel-actions adoption) for the `AsJob` realization; if
  the Owner rejects ADR-007, the same seam is realized with plain `ShouldQueue`
  jobs at the identical two chokepoints (more boilerplate, same shape).
- **Constrained / open (Project Owner):**
  - **V3 (transport beyond Redis)** stays an Owner decision, gated by **V8**
    (throughput/latency targets, currently unset). Flag: do not implement Kafka
    without that decision.
  - **FIFO semantics (a #4 design detail, called out so the seam anticipates it;
    not decided here).** The meaningful ordering **scope is per-proxy** — the unit
    that owns an ingest stream (optionally per-`(proxy, destination)` for delivery
    ordering); **global FIFO is rejected** — it serialises the whole system and
    defeats the Async half and the throughput goal. Whatever mechanism #4 picks
    (a leased per-proxy single-consumer over a durable list/partition — the
    SQS-FIFO-message-group / Kafka-partition model — vs. Laravel
    `WithoutOverlapping` as a per-proxy mutex, vs. a completion-triggered Redis
    buffer chain) must satisfy: **(a) single advancer per key** (atomic pop /
    mutex) or concurrent completions reorder or duplicate; **(b) liveness under
    failure** — a self-clocking "dispatch the next on completion" chain stalls the
    whole per-proxy line if a job dies before firing its trigger
    (crash/SIGKILL/deploy), so it needs a scheduled watchdog/reaper, or better a
    looping leased consumer that does not depend on each job enqueuing the next;
    **(c) idempotent dispatch** — queues are at-least-once, so dedupe by
    ingest/attempt key; **(d) a deliberate FIFO-vs-retry decision** — strict
    ordering means a failing head blocks its proxy's line for the full backoff (#6)
    (head-of-line blocking), which must be chosen, not stumbled into.
    `ShouldBeUnique` (dedupe, not ordering) and rate limiting (throughput, not
    ordering) do **not** provide FIFO. **Where the ordering key lives:** within
    Laravel/Redis it attaches directly to `DeliverToDestination` — `onQueue(...)`
    plus a `WithoutOverlapping("proxy:{id}")` (or per-`(proxy,destination)`) job
    middleware — so no bespoke `Dispatcher` interface is needed for the Redis
    FIFO-by-mutex option. A **partition/offset ordering-key model** (SQS-FIFO
    message group / Kafka partition) is the one thing `AsJob` cannot express and is
    exactly the residual first-party seam reserved for the **V3 non-Laravel
    transport**; choosing among all these is #4's (mutex) / V3's (partitioned).

  - **Leading candidate for #4's per-proxy FIFO (2026-07-30, sharpened by a
    Project Owner proposal; still a #4-design-time record, not a build spec).**
    Shape: stamp each ingested event with a **monotonic order key per proxy**; a
    `FifoOrchestration` Action selects the **lowest not-yet-settled** key for that
    proxy and dispatches exactly that one, early-returning if none; the finishing
    unit self-dispatches the orchestration again. This is sound and is the leading
    Redis-era candidate **only with the four guardrails below** — the raw
    self-clocking chain plus `WithoutOverlapping` alone does **not** satisfy the
    (a)-(d) constraints above:
    - **(a) Single advancer - the atomic claim is the correctness primitive, not
      the mutex.** `WithoutOverlapping("proxy:{id}")` only serialises orchestration
      *runs*; it releases its lock at job end, so between one orchestration
      releasing and the dispatched unit settling, a re-dispatched orchestration
      re-reads the same lowest key and **double-dispatches** it. The guarantee comes
      from a **conditional status transition on the order row** (`UPDATE ... WHERE
      id=? AND status='pending'` to `claimed`, claim-then-dispatch), so "lowest
      unprocessed" excludes in-flight rows and only one racer wins. `WithoutOverlapping`
      is then a useful contention/thundering-herd reducer, not the guard. Order is
      preserved because at most one row per proxy is claimed at a time.
    - **(b) Liveness - do not trust the self-dispatch.** If the finishing unit is
      SIGKILL/OOM/deploy-killed before it self-dispatches (or exhausts retries), the
      chain breaks and the proxy line **stalls forever** - the hole flagged above.
      Fix with a **claim lease** (`claimed_at`) plus a **scheduled sweeper/watchdog**
      that re-dispatches `FifoOrchestration` for any proxy with pending rows and no
      live (unexpired-lease) claim. Keep the self-dispatch as the low-latency happy
      path; the sweeper is the safety net and the orphaned-claim reaper. This is the
      **least machinery** for full liveness on Redis - one scheduled command, no
      per-proxy daemons or heartbeats. A **looping leased consumer** (a per-proxy
      worker that leases-processes-loops without enqueuing its own successor) is more
      robust in principle but is materially more machinery (worker lifecycle,
      discovery, lease renewal) and is the **partitioned-transport shape** - reach
      for it with **V3** (Kafka partition / SQS-FIFO group), not for the Redis MVP.
    - **(c) FIFO-vs-retry / poison head.** Strict order means a failing head blocks
      its proxy line for the **full backoff** (#6) - head-of-line blocking is the
      **intended, Owner-owned** FIFO semantic, but it must be **bounded**: after max
      attempts the head moves to a **terminal/dead-letter** state (replayable under
      #6, alertable under #13) so one poison event does not wedge the proxy
      permanently. "Not-yet-settled" for ordering = **not delivered *and* not
      dead-lettered**; dead-lettered rows are excluded from the "lowest" scan so the
      line advances. The sweeper must respect a legitimately-retrying (leased) head
      and not reap it as orphaned.
    - **(d) Idempotency lives in two layers (at-least-once).** The atomic claim
      dedupes duplicate *orchestrations* (they cannot double-dispatch a key). Queue
      redelivery of the *delivery unit itself* is still at-least-once and no claim
      prevents it, so `DeliverToDestination` must also dedupe against an existing
      settled **attempt record** (ADR-003) keyed by `(proxy, order-key, destination,
      attempt)`.
    - **Order-key mechanics.** Prefer the **durable event row's monotonic id**,
      selecting `MIN(id) WHERE proxy_id=? AND status='pending'`; **gaps are harmless
      by construction** because the scan never assumes contiguity - it takes the MIN
      of the actual pending set, so failed/rolled-back inserts do not break ordering.
      A dedicated **per-proxy counter** is only warranted if strict *commit-order*
      FIFO is required (global auto-increment ids are assigned in insert order but
      may commit out of order); **Redis `INCR` is rejected** for the MVP - it splits
      the order source from the DB rows and adds a second system to keep consistent.
    - **Scope - confirmed.** This is **per-proxy FIFO as an opt-in proxy mode**, with
      **Async remaining the default parallel path**; consistent with the per-proxy
      ordering scope and global-FIFO rejection recorded above. The order key / claim
      / orchestration key becomes per-`(proxy, destination)` if delivery-level
      ordering is later chosen - the same shape at a finer scope.
