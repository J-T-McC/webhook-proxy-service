# ADR-007: Adopt `lorisleiva/laravel-actions` for pipeline steps and dispatchable pipeline/delivery actions

- **Status:** Accepted
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** cross-cutting (realizes the ADR-001 step contract as a convenience and the ADR-005 dispatch-timing seam; serves #1, #4, #6, #8, #9, #10, #12, #14)

## Question
Should the project take `lorisleiva/laravel-actions` as a first-party dependency
to realize (a) pipeline **steps** and (b) the **dispatch-timing seam** — rather
than hand-rolled step classes plus a bespoke `Dispatcher` interface and
hand-written `ShouldQueue` jobs? This is a **new dependency**, so it is recorded
here for Project Owner approval. (The runner itself — Laravel's first-party
`Illuminate\Pipeline\Pipeline` — needs no ADR; only the third-party adoption does.)

## Decision
Adopt `lorisleiva/laravel-actions`, used two ways, behind one first-party guard:

1. **Each pipeline `Step` is an Action** (`AsObject`): invokable, container-resolved
   via `::make()`, independently unit-testable (`MapStep::make()->handle($ctx, $next)`),
   and run **in-process** as a pipe by the native `Pipeline`. Steps are **not**
   dispatched or queued individually.
2. **Two dispatchable Actions carry execution timing** (`AsJob`):
   - `ProcessIngestedWebhook` — runs the *whole* pipeline over one `PipelineContext`.
     One class, run **synchronously at #1** (`::run(...)`) or **queued at #4**
     (`::dispatch(...)`). This single class **is** the ADR-005 dispatch-timing seam
     (pipeline level).
   - `DeliverToDestination` — one per-destination delivery unit (writes the
     `DeliveryAttempt` + emits events, ADR-003). Run **sync at #1** (`::run(...)`),
     **queued per destination at #4** (`::dispatch(...)->onQueue(...)`) for
     independent retry/backoff (#6). This is the ADR-005 seam at the delivery level.
3. **First-party guard.** The step contract is a **first-party** `PipelineStep`
   interface we own — steps implement it and *additionally* use the Action traits.
   The package supplies convenience (`::make`/`::run`/`AsJob`), never the core
   type. Removing the package leaves steps as plain invokable classes.

## Alternatives
- **Hand-rolled `Step` classes + bespoke `Dispatcher` interface + hand-written `ShouldQueue` jobs** (the prior Appendix A shape) — more first-party code; the run-sync-or-queue behaviour is re-implemented per class; the `Dispatcher` interface re-abstracts what Laravel's bus already provides for every Laravel-queue transport. Rejected as unnecessary indirection for the common case (see ADR-005 correction).
- **Plain invokable POPO steps + native `ShouldQueue` jobs, no package** — zero third-party lock-in, but loses the unified `::make`/`::run`/`::dispatch` surface and the *one class* run-sync-or-queue property; more boilerplate for the #14 test-payload reuse. This is the **fallback if the Owner rejects this ADR** — the Appendix A shape still holds with class renames.
- **laravel-actions as the step *contract* itself** (no first-party interface; steps typed only against package types) — couples the load-bearing pipe contract to a third party; rejected in favour of the first-party `PipelineStep` interface above.

## Reasoning
- **Testability is the driving reason** (Project Owner). An Action step is unit-tested
  in isolation (`MapStep::make()->handle($ctx, fn ($c) => $c)`); `ProcessIngestedWebhook`
  and `DeliverToDestination` are exercised synchronously in tests via `::run(...)` and
  asserted as queued in prod paths via `Queue::fake()` + `::dispatch(...)` — **one class,
  both timings**, which is exactly the #14 "run the identical pipeline, no mock path" goal.
- **Idiomatic realization of existing ADRs.** Native `Pipeline` passes **one** passable
  through in-process pipes (`handle($passable, Closure $next)`) — this *matches*, not
  conflicts with, ADR-001's single mutable `PipelineContext`. The `AsJob` run-sync-or-queue
  class *is* ADR-005's timing seam, not a competitor to it.
- **Serialization objection resolved.** Steps are **not** serialized between steps; only
  the Action's *input* (`ProcessIngestedWebhook`'s `PipelineContext`, or
  `DeliverToDestination`'s unit) is serialized **once at the job boundary** when queued at
  #4 — precisely the intended dispatch boundary. Within a job the steps share the
  in-memory context. This removes the ADR-001/ADR-005 concern that step-as-job breaks the
  single in-memory envelope.
- **Maturity.** `lorisleiva/laravel-actions` is widely used and tracks Laravel releases.

## Impact
- **Easier:** steps and dispatchable actions get uniform `::make/::run/::dispatch`;
  #14 reuses the same classes sync; #4 = `::dispatch()` + `onQueue`/job middleware;
  #6 = retry/backoff configured on the Action's job.
- **Cost / lock-in (honest):**
  - A hard third-party dependency to track across Laravel upgrades.
  - `ProcessIngestedWebhook` / `DeliverToDestination` couple to the package's `AsJob`.
    The coupling is **shallow** — each is replaceable with a plain `ShouldQueue` job
    with no change to steps, `PipelineContext`, `PipelineFactory`, or the controller.
  - Steps stay behind the first-party `PipelineStep` interface, so step lock-in is minimal.
- **What laravel-actions does NOT provide (the seam it does not close):** a
  **non-Laravel transport (V3 Kafka/streaming)**. `AsJob` produces a Laravel bus job
  (`ShouldQueue`/`SerializesModels`); it cannot publish to a partitioned non-Laravel
  transport, and Laravel's queue has no native FIFO **ordering-key** primitive. Per-proxy
  FIFO *within* Laravel/Redis is expressible on the Action (`onQueue` +
  `WithoutOverlapping("proxy:{id}")` job middleware); a partitioned/ordering-key transport
  is not. The thin V3 transport seam therefore remains — localized to the two dispatch
  chokepoint Actions — and is documented (not built) in **ADR-005**.
- **Approval gate:** do not add the dependency or build against it until the Project
  Owner approves this ADR. If rejected, fall back to plain invokable steps + native
  `ShouldQueue` jobs (Appendix A shape, renamed).
