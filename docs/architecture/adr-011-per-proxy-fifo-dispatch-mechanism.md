# ADR-011: Per-proxy FIFO dispatch mechanism (claim-based single-advancer) and the `processing_mode` attribute

- **Status:** Proposed — pending Project Owner approval (data-model gate)
- **Author:** Principal Engineer
- **Date:** 2026-08-04
- **Feature:** prd-04-queued-processing (realizes ADR-005 at build time; serves #6)

## Question
Feature #4 moves dispatch onto a Redis queue and adds a per-proxy **Async
(default)** / **FIFO (opt-in)** processing mode. ADR-005 fixed the *seam* (flip
`ProcessIngestedWebhook`/`DeliverToDestination` from `::run` to `::dispatch`) and
recorded a **leading FIFO candidate plus four correctness guardrails (a)–(d)**, but
left three things unresolved that #4 must now decide concretely, each a
significant/hard-to-reverse or data-model choice:

1. **Where the per-proxy ordering/claim state lives.** ADR-005's candidate assumed a
   mutable `status` column *on the event row*. Since ADR-005, **ADR-010 made
   `webhook_events` raw-only and immutable by construction** — it may not carry
   dispatch/claim state. So the "order row" needs a home.
2. **How the queued job carries its input** without serializing the raw body (up to
   the ADR-006 cap) or a full `Proxy` model into every Redis job — ADR-005's "flip
   `::run`→`::dispatch` with no handler change" is too literal once the payload can
   be large.
3. **The `processing_mode` attribute itself** — a new persisted column on `proxies`
   (parallel to how ADR-002 recorded `mode`), which triggers the CLAUDE.md
   data-model Owner-approval gate.

## Decision

**(1) `processing_mode` enum on `proxies`.** A string-backed enum column
`processing_mode` — `async` | `fifo`, **NOT NULL, default `async`** — persists the
per-proxy mode (PRD AC4/AC5). Additive migration; existing #1/#3 rows take the
schema default `async` (no backfill, no observed-behaviour change — AC5). Backed by
`App\Enums\ProcessingMode`, cast on `Proxy`, mirroring `ProxyMode`/ADR-002 exactly.
Orthogonal to `mode` (simple/enhanced): the two are independent selectors.

**(2) FIFO via a claim-based single-advancer over a new sidecar `fifo_dispatches`
table.** Per-proxy FIFO is **event-level** ordered (per-proxy scope; per-`(proxy,
destination)` is the finer future refinement ADR-005 anticipates, not built here). A
new immutable-until-settled ordering entity `fifo_dispatches` holds one row per
received event **for FIFO proxies only** — `proxy_id`, `webhook_event_id` (the
monotonic **order key**), `status` (`pending`|`claimed`|`settled`), and a
`lease_expires_at` claim lease. Async proxies never touch this table, so FIFO never
serializes or blocks Async (AC7). An `AdvanceProxyFifoQueue` action is the **single
advancer**: it **atomically claims the lowest pending row for a proxy iff that proxy
has no live claim** (`SELECT … ORDER BY webhook_event_id … FOR UPDATE` inside a
transaction — the correctness primitive, ADR-005 guardrail **(a)**), processes that
one event to settlement, marks it `settled`, then self-dispatches to advance.
Liveness (guardrail **(b)**) is a **claim lease + a scheduled sweeper**
(`schedule:run`, ~every minute) that re-dispatches the advancer for any proxy with
pending rows and no live claim, and resets orphaned (expired-lease) claims back to
`pending`. `WithoutOverlapping("proxy:{id}")` on the advancer is a thundering-herd
reducer, **not** the guard.

**(3) Pipeline-entry dispatch is by reference; delivery carries its payload.** The
pipeline-entry action is dispatched **by reference** — `ProcessIngestedWebhook::
dispatch($ingestId)` — and rebuilds its `PipelineContext` on the worker from the
durable `webhook_events` row (the same raw-capture ADR-010 designates as the #6
replay source). This avoids serializing a `Proxy` model or a large raw body into
every job, and is loss-free because the pipeline's input **is** the raw captured
event. Per-destination `DeliverToDestination` continues to carry its `DeliveryUnit`
(including the pipeline's *output* payload) so a later mapped payload (#8) flows to
delivery unchanged.

**(4) Exactly-once settlement (guardrail (c)/(d)).** A **`UNIQUE(ingest_id,
destination_id, attempt_number)`** index on `delivery_attempts` plus a
skip-if-already-settled check in `DeliverToDestination` makes delivery idempotent
under the queue's inherent at-least-once redelivery (AC9). This is **redelivery
correctness, not the #6 retry feature.**

## Alternatives
- **Mutex only (`WithoutOverlapping("proxy:{id}")`, no claim row)** — serialises
  orchestration *runs* but does **not** order the queue (a blocked job is released to
  the tail and reordered) and releases its lock at job end, permitting
  double-dispatch (ADR-005 guardrail a). Rejected as the correctness mechanism.
- **Claim/status column on `webhook_events`** — violates ADR-010's raw-only
  immutable invariant (mutating a captured raw row). Rejected; the sidecar table
  keeps ADR-010 intact.
- **Serialize `PipelineContext` (Proxy + raw body) into the job** (ADR-005's literal
  "no handler change") — puts a full model and an up-to-ADR-006-cap body into every
  Redis job. Rejected for the pipeline-entry hop; dispatch-by-reference is used
  instead.
- **Redis `INCR` / a dedicated per-proxy counter as the order key** — splits the
  order source from the DB rows (ADR-005 rejects it for the MVP). Rejected; the
  monotonic `webhook_event_id` is the order key (gaps harmless — MIN of the pending
  set).
- **Global FIFO / one ordered queue for all proxies** — serialises the whole system
  and defeats Async (ADR-005). Rejected.
- **Looping leased per-proxy consumer** (worker lifecycle + discovery) — more
  robust in principle but materially more machinery and is the *partitioned
  transport* shape; ADR-005 reserves it for V3, not the Redis MVP. Deferred.

## Reasoning
- Satisfies ADR-005 (a)–(d) with the least machinery on Redis: one atomic claim, one
  lease column, one scheduled sweeper — no per-proxy daemons or heartbeats.
- Keeps ADR-010 (immutable raw capture), ADR-004 (decoupled response), ADR-003
  (payload-free attempt records) and the ADR-001 spine intact — #4 changes *timing
  and ordering*, not the pipeline, the response path, or the record shape.
- Dispatch-by-reference at the pipeline entry doubles as the #6 replay shape (replay
  re-dispatches from the `webhook_events` row, ADR-010 Impact), so #4 and #6 share
  one mechanism.

## Impact
- **Data-model change (Owner-gated):** new `proxies.processing_mode` column; new
  `fifo_dispatches` table; new `UNIQUE(ingest_id, destination_id, attempt_number)` on
  `delivery_attempts`. All additive; `delivery_attempts` existing data has ≤1 row per
  `(ingest_id, destination_id)` (attempt_number always 1 pre-#4), so the unique index
  is safe to add.
- **Operational (within stack — Redis already in ADR-005/stack):** requires a running
  Redis queue transport, one or more **queue workers**, and the **scheduler**
  (`schedule:run`) for the FIFO sweeper. No new dependency.
- **Easier later:** #6 attaches retry/backoff + dead-letter on the same actions and
  adds a `dead_lettered` `fifo_dispatches` status (excluded from the "lowest pending"
  scan) to bound head-of-line blocking — the guardrail (c) seam is left open, not
  built here.
- **Constrained:** `fifo_dispatches` is FIFO-only ordering/claim state; it is not a
  second payload or outcome store (those remain `webhook_events` and
  `delivery_attempts`). Per-`(proxy, destination)` ordering, dead-letter/bounded HoL,
  and any beyond-Redis transport (V3) are explicitly **not** built here.
