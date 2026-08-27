# ADR-011: Per-proxy FIFO dispatch mechanism (claim-based single-advancer) and the `processing_mode` attribute

- **Status:** Accepted — Project Owner, 2026-08-04 (data-model gate approved: `proxies.processing_mode` column, `fifo_dispatches` table, `delivery_attempts` UNIQUE constraint). **Three positions (P1-P3) carry a partial supersession by ADR-016 (Accepted, Project Owner 2026-08-12) — see the inline notes at Decisions 2 and 4. Two further positions (P4, P5) carry a partial supersession by ADR-020 (**Accepted, Project Owner 2026-08-26**) — see the inline notes at Decisions 2 and 3. Everything else stands, Accepted and operative.**
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

> **[P4 — SUPERSEDED by ADR-020 (Accepted, Project Owner 2026-08-26).]** "Processes that one
> event **to settlement**, marks it `settled`, then self-dispatches to advance" becomes: the
> advancer *initiates* the event's delivery — fanning out to every destination in parallel on
> the webhooks queue, in FIFO mode as well as Async — and the settle-and-advance decision is
> made by whichever actor completes the dispatch's last delivery, via the
> `awaiting_retry → settled` compare-and-set ADR-016 Decision 1 already built. The guarantee
> this position served is unchanged: event 2 is still not claimed until every one of event 1's
> deliveries has reached a terminal state. Only the actor changes. Everything else in this
> Decision — the sidecar table, the atomic `FOR UPDATE` claim as the correctness primitive, the
> lease plus scheduled sweeper as the liveness net, and `WithoutOverlapping` as a
> thundering-herd reducer rather than the guard — is untouched and relied on by ADR-020.

> **[P1, P2 — SUPERSEDED by ADR-016 (Accepted, Project Owner 2026-08-12).]** P1: the order key
> becomes the `fifo_dispatches` row's own `id` (order-identical for capture-created rows;
> admits #6 replay rows at the back of the line). P2: `UNIQUE(webhook_event_id)` is replaced by
> `UNIQUE(dispatch_uuid)` (capture idempotency preserved; one event may hold original + replay
> ordering rows). The claim-based single-advancer, lease/sweeper, and sidecar placement are
> unchanged.

**(3) Pipeline-entry dispatch is by reference; delivery carries its payload.** The
pipeline-entry action is dispatched **by reference** — `ProcessIngestedWebhook::
dispatch($ingestId)` — and rebuilds its `PipelineContext` on the worker from the
durable `webhook_events` row (the same raw-capture ADR-010 designates as the #6
replay source). This avoids serializing a `Proxy` model or a large raw body into
every job, and is loss-free because the pipeline's input **is** the raw captured
event. Per-destination `DeliverToDestination` continues to carry its `DeliveryUnit`
(including the pipeline's *output* payload) so a later mapped payload (#8) flows to
delivery unchanged.

> **[P5 — SUPERSEDED by ADR-020 (Accepted, Project Owner 2026-08-26).]** "Per-destination
> `DeliverToDestination` continues to carry its `DeliveryUnit` (including the pipeline's
> *output* payload) so a later mapped payload (#8) flows to delivery unchanged" becomes: the
> per-destination delivery job carries `(deliveryId, attemptNumber)` only, and the pipeline's
> output payload is **resolved on the worker** from `dispatched_payloads.body`, falling back to
> `webhook_events.body` under ADR-013 Decision 2's divergence gate — the same resolution
> `RetryDelivery` has used for attempts 2..N since #6, and the rule ADR-015 Decision 5 already
> states. The **guarantee is preserved**: a mapped payload still reaches the destination
> unchanged, now by reading the store that records what was dispatched rather than by a second
> copy travelling in the queue message. The **first half of this Decision — pipeline-entry
> dispatch by reference, rebuilding the `PipelineContext` from the durable capture — is not
> superseded** and is relied on by ADR-020.

**(4) Exactly-once settlement (guardrail (c)/(d)).** A **`UNIQUE(ingest_id,
destination_id, attempt_number)`** index on `delivery_attempts` plus a
skip-if-already-settled check in `DeliverToDestination` makes delivery idempotent
under the queue's inherent at-least-once redelivery (AC9). This is **redelivery
correctness, not the #6 retry feature.**

> **[P3 — SUPERSEDED by ADR-016 (Accepted, Project Owner 2026-08-12).]** The idempotency
> **mechanism** stands; the key becomes `UNIQUE(delivery_id, attempt_number)` (ADR-015),
> because a #6 replay legitimately reuses `(ingest_id, destination_id, 1)`. The old index is
> dropped by the #6 migration.

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
