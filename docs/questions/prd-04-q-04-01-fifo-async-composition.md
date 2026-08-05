# Q-04-01: FIFO/Async mechanism selection and composition (Principal Engineer, technical)

- **Status:** Resolved (Principal Engineer, 2026-08-04)
- **Raised in:** `docs/product/prd-04-queued-processing.md` (Open Questions) — a
  technical question the PM deferred to the Principal Engineer at #4 technical design.
  Non-blocking for PRD approval; gates technical design.
- **Resolved by:** `docs/plans/plan-04-queued-processing.md` +
  `docs/architecture/adr-011-per-proxy-fifo-dispatch-mechanism.md`.

## Question (three parts)
(i) Does flipping `ProcessIngestedWebhook`/`DeliverToDestination` from `::run` to
`::dispatch` compose with #3's synchronous capture-before-response (ADR-010) and the
ADR-004 response path without coupling either to delivery? (ii) Does the chosen
concrete Redis FIFO mechanism satisfy ADR-005 guardrails (a)–(d), so AC6/AC7/AC9 hold?
(iii) Is the per-proxy `processing_mode` attribute a data-model change needing Owner
approval at plan time (CLAUDE.md data-model gate)?

## Resolution

**(i) The dispatch flip composes — confirmed.** The ingest handler already captures
the raw payload synchronously and commits it (ADR-010), then resolves the response
from proxy config (ADR-004), **before** the dispatch line. #4 changes only that final
line — `::run($ctx)` becomes an `afterCommit` dispatch (Async: dispatch the pipeline;
FIFO: enqueue an ordering row + dispatch the advancer). Because capture is already
committed and the response is already resolved before dispatch, neither the
capture-before-response guarantee nor the decoupled-response guarantee is touched; the
response still never reads delivery outcome. The pipeline entry is dispatched **by
reference** (`ingest_id`, rebuilt from the durable `webhook_events` row on the worker)
to avoid serializing a `Proxy` model or a large raw body — a refinement of ADR-005's
literal "no handler change," recorded in ADR-011.

**(ii) The FIFO mechanism satisfies (a)–(d) — confirmed.** Per-proxy, event-level FIFO
via a claim-based **single-advancer** (`AdvanceProxyFifoQueue`) over a new sidecar
**`fifo_dispatches`** ordering table (a sidecar is required because ADR-010 made
`webhook_events` immutable, so the "order row"/claim state cannot live there):
- **(a) Single advancer** — an atomic claim (`SELECT … ORDER BY webhook_event_id …
  FOR UPDATE` + live-claim check, in a transaction) claims the lowest `pending` row
  for a proxy **iff no live claim exists**, so at most one event per proxy is in flight
  → order preserved. `WithoutOverlapping` is a herd reducer, not the guard.
- **(b) Liveness** — a claim **lease** (`lease_expires_at`) plus a **scheduled sweeper**
  re-dispatches idle-but-pending proxies and reaps orphaned (expired-lease) claims, so
  a crash/deploy mid-event does not stall the line. The self-dispatch is the low-latency
  happy path; the sweeper is the safety net.
- **(c) Bounded HoL / poison head** — at #4 (no retry) a failing delivery settles
  immediately, so the line advances after one attempt; the bounded-retry/dead-letter
  state is a #6 addition (a `dead_lettered` `fifo_dispatches` status excluded from the
  scan). The seam is left open, not built.
- **(d) Idempotency (two layers)** — the atomic claim dedupes duplicate *orchestrations*;
  a new **`UNIQUE(ingest_id, destination_id, attempt_number)`** on `delivery_attempts`
  plus a skip-if-already-settled check dedupes at-least-once redelivery of the
  *delivery* itself (AC9). This is redelivery correctness, not the #6 retry feature.

**(iii) Yes — `processing_mode` is a data-model change requiring Owner approval.** It
is **not** self-certified. Proposed exact shape: column `processing_mode`,
`ENUM('async','fifo')`, **NOT NULL**, default **`'async'`**, allowed values
**`async` | `fifo`**. Additive migration; existing #1/#3 rows take the default `async`
with no backfill (AC5). #4 also adds two further Owner-gated data-model items: the new
`fifo_dispatches` table and the `delivery_attempts` unique index. All three, plus the
mechanism itself, are recorded in **ADR-011** (Proposed) for the Owner gate.

See the plan and ADR-011 for full detail, including the mid-flight mode-change ruling
(mode bound per event at enqueue; no draining, no UI warning; stays within the design
spec's stated assumption).
