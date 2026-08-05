# PRD: Queued processing (FIFO & Async)

- **Status:** Approved
- **Author:** Product Manager
- **Date:** 2026-08-04
- **Approved by / date:** Project Owner, 2026-08-04
- **Backlog item:** Roadmap #4 (`docs/product/roadmap.md`)

## Feature
A proxy's deliveries are dispatched through a Redis-backed queue instead of inline
within the ingest request, and a proxy can process its events in **Async mode**
(default — parallel, no ordering guarantee) or **FIFO mode** (opt-in — the proxy's
events are delivered in the order received).

## Problem
Today (#1/#3) delivery is **fire-and-forget inline**: the ingest handler captures
the raw payload (ADR-010), returns the decoupled upstream response (ADR-004), and
loops the destinations with a synchronous HTTP send in the same request. Two gaps
follow:

1. **No processing seam for reliability and scale.** Retry/backoff (#6), analytics
   events (#11), and enhanced-mode steps (#5 storage, #8 mapping) all need to hook
   into dispatch, but an inline loop has nowhere for them to attach and no way to
   absorb load spikes. The vision names "throughput and processing scalability" as
   a core concern and "pipeline-oriented architecture" as the direction.
2. **No ordering control.** Some upstream streams require in-order delivery; others
   want maximum parallelism. The vision requires supporting **both FIFO and Async**
   processing. Inline fan-out offers neither as a configurable mode.

Item #4 moves dispatch onto a queue (the seam every later reliability item attaches
to) and lets a proxy choose ordered (FIFO) or parallel (Async) processing, without
changing the already-decoupled upstream response (#3) or the durable
capture-before-response guarantee (#3/ADR-010).

## Goals
- Destination delivery is dispatched through a queue; the ingest request returns
  its (decoupled, #3) upstream response **without waiting** on any destination
  delivery to complete.
- The #3 guarantees are preserved unchanged under queuing: raw capture still
  commits **before** the upstream response (ADR-010), and the upstream response is
  still resolved from proxy config, never from delivery outcome (ADR-004).
- A proxy can be configured to process in **Async** (default) or **FIFO** (opt-in)
  mode. Existing #1/#3 proxies default to Async — no migration surprise.
- FIFO ordering is **per-proxy**: one proxy's ordering never serializes or blocks
  another proxy's processing; different proxies process concurrently.
- The payload-free per-destination delivery-attempt records and domain events
  (ADR-003) continue to be emitted under queued dispatch, so #6 retry, #11
  analytics, and #13 notifications keep their single source with no parallel path.
- The transport is **Redis** (the MVP queue per the vision Known Constraints); the
  seam for a later scalable transport beyond Redis (V3) stays open but is **not**
  built here (see Open Questions V3).

## Users
- **Team member** — configures a proxy's processing mode (Async default / FIFO
  opt-in) and benefits from queued, ordered-or-parallel delivery.
- **Upstream sender** — a system actor posting webhooks to an ingest URL; its
  experience is unchanged (still receives the immediate #3 response); queuing is
  invisible to it.

## User Stories
- As a team member, I want my proxy's deliveries handled by a queue rather than
  inside the ingest request, so a slow or failing destination cannot delay the
  acknowledgement my upstream sender receives.
- As a team member whose upstream sends order-sensitive events, I want to opt a
  proxy into FIFO processing, so its events are delivered in the order they were
  received.
- As a team member with high-volume, order-insensitive traffic, I want Async
  processing by default, so deliveries fan out in parallel for throughput.
- As the product (system), I want queued dispatch to keep emitting the same
  delivery-attempt records and events as inline dispatch (ADR-003), so analytics
  (#11), retry (#6), and notifications (#13) consume one stream with no second path.

## UX Direction *(minor UI on the existing proxy form)*
The only user-facing addition is a **per-proxy processing-mode selection** on the
**existing proxy create/edit form**: **Async** (the default) versus **FIFO**
(opt-in). The experience must make clear that:
- **Async is the default** and means parallel delivery with **no ordering
  guarantee** — the right choice for most (order-insensitive, higher-throughput)
  traffic.
- **FIFO is an opt-in** that delivers the proxy's events **in the order received**,
  and that ordered delivery is **necessarily more serialized/slower** than Async
  (an honest tradeoff, not a free upgrade).
- The choice is a property of the proxy, orthogonal to the existing
  simple/enhanced mode selector (ADR-002) — a proxy can be simple-or-enhanced and
  independently Async-or-FIFO.

Direction only. Field layout, control type, copy, default-state presentation, and
whether this sits beside the existing mode selector are the Designer's.

## UX gate (mandatory — do not repeat the #3 miss)
This PRD contains a UX Direction section, so **#4 has a user-facing surface** and
**must clear the UX Design (Designer) gate before Technical Design.** On #3 the
Designer gate was missed for a similarly minor form addition; that must not repeat.
Per the mechanical routing rule, a PRD with a UX Direction section routes to the
**Designer**, no exceptions (see Handoff → Next Agent).

## Acceptance Criteria
1. **Queued dispatch.** When a webhook is posted to a proxy's ingest URL, its
   destination deliveries are performed via a queue, not synchronously inside the
   ingest request. The ingest request completes and returns its response without
   waiting for any destination delivery to finish.
2. **#3 response guarantee preserved.** The upstream response is still returned
   immediately, resolved from proxy configuration and never from — nor waiting on —
   any delivery outcome (ADR-004). Moving delivery to the queue changes nothing
   about the status/body the upstream sender receives.
3. **Capture-before-response preserved.** The raw incoming payload is still durably
   committed **before** the upstream response is returned (ADR-010). Queuing does
   **not** move capture after the response; a capture-write failure still returns
   HTTP 500 and dispatches nothing (PRD-03 AC5/AC6 unchanged).
4. **Per-proxy processing mode configurable.** A proxy can be configured in one of
   two processing modes — **Async** or **FIFO**. The mode is a persisted property
   of the proxy, settable at create and edit time.
5. **Async is the default.** A proxy with no explicit processing mode is Async.
   Proxies created under #1/#3 are treated as Async (no migration surprise, no
   change to their observed behavior). In Async mode a proxy's destinations are
   delivered in parallel with **no ordering guarantee** between events.
6. **FIFO delivers in received order.** When a proxy is in FIFO mode, that proxy's
   events are delivered to its destinations in the **order they were received**.
   (Ordering scope is per-proxy; whether delivery-level ordering is per-proxy or
   per-`(proxy, destination)` is an ADR-005 design detail, not asserted here.)
7. **FIFO is per-proxy — no cross-proxy blocking.** A FIFO proxy's ordering
   constraint applies only to that proxy. It never serializes the whole system and
   never blocks or delays processing of any **other** proxy; different proxies
   (Async or FIFO) process concurrently. (Global FIFO is rejected — ADR-005.)
8. **Attempt records/events unchanged under queuing.** Queued dispatch still emits
   exactly **one payload-free delivery-attempt record per destination per attempt**
   and the corresponding domain events (ADR-003), identifying proxy and destination
   and capturing outcome/status, team-scoped. Analytics (#11), retry (#6), and
   notifications (#13) consume these — no parallel or reconstructed path.
9. **Exactly-once settlement under at-least-once delivery.** A single received
   event produces **exactly one settled attempt record per destination**, even
   though the queue may redeliver a job (queues are at-least-once). Queue
   redelivery of a job must **not** produce duplicate deliveries-of-record or
   duplicate settled attempt records for the same `(event, destination)`.
   (Idempotency mechanism is ADR-005's; only the observable no-duplication outcome
   is asserted here.)
10. **Independent destinations preserved.** As in #1, one destination failing does
    not prevent delivery to the proxy's other destinations for the same event
    (subject to FIFO ordering when enabled — a failing FIFO head may hold the
    proxy's line per ADR-005's bounded head-of-line-blocking semantics; the
    concrete bound is a #6/PE concern, not asserted here).
11. **Scope boundary — no retry/replay feature.** #4 introduces the queue and the
    Async/FIFO modes only. **User-configurable backoff, failed-delivery retry, and
    manual replay are not introduced** — they are #6 (which depends on this queue).
    Any at-least-once queue redelivery handling (AC9) is a correctness concern, not
    the #6 retry feature.
12. **Scope boundary — transport is Redis.** The queue transport is Redis (vision
    Known Constraints). No scalable-beyond-Redis transport (Kafka/streaming) is
    built (V3 — see Open Questions).
13. **Scope boundary — no performance SLA.** #4 asserts **no** numeric throughput,
    latency, or delivery-success target (V8 unset — see Open Questions). The ACs
    above are functional; none asserts a performance number.

## Out of Scope
Each points to the roadmap item that owns it.

- **Retry, configurable backoff, manual replay, dead-letter as a user feature** —
  roadmap #6 (depends on this queue). #4 stops at queued dispatch + Async/FIFO
  modes. Bounded FIFO head-of-line blocking and poison-head/dead-letter handling
  are ADR-005 design concerns the Principal Engineer owns; the user-facing replay
  and backoff configuration are #6.
- **Payload storage retention & garbage collection** — roadmap #5. #3 already
  pulled the capture half forward (ADR-010); #4 does not touch retention/GC.
- **Analytics dashboards / stats presentation** — roadmap #11. #4 keeps emitting the
  attempt records/events #11 will read (ADR-003), but adds no analytics surface.
- **Notifications** — roadmap #13.
- **Enhanced-mode toggle / mapping / storage steps** — roadmap #7/#8/#5. Processing
  mode (Async/FIFO) is orthogonal to simple/enhanced mode (ADR-002); #4 does not
  add enhanced-mode pipeline steps.
- **Scalable transport beyond Redis (Kafka/streaming)** — V3; the ADR-005 seam
  stays open but nothing is built. Gated by V8.
- **Numeric throughput / latency / delivery-success targets** — V8; none asserted
  (question raised to the Owner, non-blocking — see Open Questions).

## Open Questions
- **V3 — Scalable queue/streaming choice beyond Redis. RESOLVED FOR #4 (by ADR-005,
  Accepted, + vision Known Constraints).** #4's transport is **Redis** (the MVP
  queue per `docs/product/vision.md` Known Constraints). ADR-005 already keeps the
  beyond-Redis seam open at the two dispatch chokepoint Actions and explicitly
  **rejects picking Kafka now** ("gated by V8… do not implement Kafka without that
  decision"). Choosing a non-Laravel/streaming transport is therefore **out of
  scope for #4** and stays open, gated by V8. This is a technical decision already
  made by the Principal Engineer in ADR-005 — the Product Manager does not reopen
  or re-decide it; PRD-04 aligns with it. Not a #4 blocker.
- **V8 — Throughput / latency / delivery-success targets. RAISED TO THE OWNER,
  NON-BLOCKING** (`docs/questions/prd-04-throughput-latency-targets-v8.md`). No
  vision/PRD/prior decision sets any numeric target (the vision states none are
  set; PRD-01 asserted none; ADR-005 records V8 as unset), so the Product Manager
  cannot derive or invent one — it is a genuine business decision for the Owner.
  #4's functional requirements do not depend on it (AC13), so PRD-04 proceeds
  asserting no SLA; the question asks the Owner whether to set targets or keep them
  deferred. Does **not** gate PRD-04 approval or #4 design/build; it primarily
  lands at #11 and would inform a future V3 decision.
- **Q-04-01 (Principal Engineer, technical) — FIFO/Async mechanism selection and
  composition. Non-blocking for requirement approval; gates technical design.**
  ADR-005 already records the design at length (per-proxy FIFO opt-in with Async
  default; the atomic-claim single-advancer, lease + scheduled sweeper for
  liveness, two-layer idempotency, and bounded/dead-lettered head-of-line blocking
  guardrails (a)–(d); Redis `WithoutOverlapping`/claim vs. the V3 partitioned
  model). This question confirms, at #4 technical design, that: (i) flipping
  `ProcessIngestedWebhook`/`DeliverToDestination` from `::run` to `::dispatch`
  composes with the #3 synchronous capture-before-response (ADR-010) and the
  ADR-004 response path without coupling either to delivery; (ii) the chosen
  concrete Redis FIFO mechanism satisfies ADR-005 (a)–(d) so AC6/AC7/AC9 hold; and
  (iii) whether the per-proxy `processing_mode` attribute is a data-model change
  needing Owner approval at plan time (CLAUDE.md data-model gate). Feasibility and
  mechanism choice are the Principal Engineer's, not resolved here.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#4 line + build-ahead note; #5/#6/#11
  scope boundaries; Open Questions V3, V8; Resolved Decisions),
  `docs/product/vision.md` ("Processing / dispatching — FIFO & Async";
  "Pipeline-oriented architecture"; Known Constraints — Redis MVP queue, Kafka
  open; Open Questions 3 and 8),
  `docs/architecture/adr-005-queue-dispatch-abstraction.md` (the dispatch seam;
  per-proxy FIFO opt-in / Async default; FIFO correctness guardrails; V3 seam
  reserved, gated by V8),
  `docs/architecture/adr-001-ingest-delivery-pipeline-spine.md` (pipeline steps;
  where/when the pipeline runs is delegated to ADR-005),
  `docs/architecture/adr-003-delivery-attempt-records-and-events.md` (payload-free
  attempt records + events; no parallel path),
  `docs/architecture/adr-010-raw-payload-capture.md` (capture commits before the
  response, before dispatch; must stay pre-dispatch when #4 makes dispatch async),
  `docs/product/prd-03-decoupled-upstream-response.md` (decoupled response +
  capture-before-response this item must preserve),
  `docs/product/prd-01-walking-skeleton.md` (ingest → fan-out spine this extends).
- **Outputs:** this PRD; `docs/questions/prd-04-throughput-latency-targets-v8.md`.
- **Dependencies:** Roadmap #1 (Done) and #3 (Done). ADR-005 (Accepted) is the seam
  #4 flips from sync to queued; #3's decoupled response (ADR-004) and durable
  capture (ADR-010) are the invariants #4 must preserve. #4 does **not** depend on
  #5 or #6.
- **Outstanding Questions:** V3 — resolved for #4 by ADR-005 (Redis MVP; beyond-Redis
  deferred), non-blocking. V8 — raised to the Owner, non-blocking (no SLA asserted).
  Q-04-01 (Principal Engineer) — non-blocking for requirement approval; gates
  technical design.
- **Next Agent:** **Designer.** This PRD carries a UX Direction section (per-proxy
  Async/FIFO selection on the existing proxy form), so it routes to the Designer and
  must clear the UX Design gate **before** Technical Design — the gate missed on #3.
  After design approval, the substance (queued dispatch, FIFO/Async mechanics) is
  the Principal Engineer's, carrying Q-04-01.
