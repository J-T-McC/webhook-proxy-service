# ADR-005: Queue/dispatch abstraction seam (Redis now, scalable option open)

- **Status:** Proposed
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** cross-cutting (serves #4, #6; keeps V3 open)

## Question
How is pipeline/delivery dispatch structured so that item #1's fire-and-forget
send, #4's FIFO+Async queued dispatch, #6's retry/backoff, and a later
larger-scale queue/streaming choice (V3) all attach without rewriting the
pipeline or steps — while **not** unilaterally picking Kafka?

## Decision
Route all execution-timing concerns through a **`Dispatcher` seam** (a thin
interface backed by Laravel's queue/bus abstraction), not through the steps
themselves. `DeliverStep` hands each per-destination unit of work to the
`Dispatcher`; the pipeline runner hands the pipeline to the `Dispatcher`. At item
#1 the driver is **synchronous fire-and-forget** (Laravel `sync` /
after-response), persisting no jobs. #4 swaps the driver to **Redis** and
introduces `Job` classes carrying FIFO-vs-async semantics; #6 attaches
retry/backoff at the job level. The `Dispatcher` interface is kept
**driver-agnostic** so a future scalable transport can back it without changing
pipeline or step code. This ADR **does not** choose that transport.

## Alternatives
- **Steps call Laravel `Queue`/`dispatch()` directly** — leaks execution timing into every step; #4/#6/V3 changes ripple across steps; rejected.
- **Pick Kafka now** — the vision lists Kafka/streaming as an open option (V3) gated by unset throughput targets (V8); the Owner has not chosen it and this ADR must not; rejected as premature.
- **Commit to Redis as the permanent transport** — forecloses V3; the seam exists precisely to keep it open; rejected.

## Reasoning
- Constraints fix Redis as the MVP queue while V3 keeps Kafka/streaming open;
  roadmap #4 build-ahead: "dispatch must expose per-attempt steps rather than a
  single fire-and-forget send," and the FIFO/Async design "must accommodate a
  later scalable queue/streaming choice beyond Redis (V3)."
- A driver-agnostic dispatcher is the standard way to make the transport a
  configuration/driver choice rather than an architectural commitment.

## Impact
- **Easier:** #4 = implement Job classes + point the seam at Redis; #6 = job-level
  retry/backoff; a V3 transport = a new driver behind the same interface.
- **Constrained / open (Project Owner):**
  - **V3 (transport beyond Redis)** stays an Owner decision, gated by **V8**
    (throughput/latency targets, currently unset). Flag: do not implement Kafka
    without that decision.
  - **FIFO semantics** — strict per-proxy ordering under Redis with multiple
    workers needs an ordering strategy (e.g. per-proxy single-consumer or ordering
    key). This is a #4 design detail, called out here so the seam anticipates it;
    not resolved at #1.
