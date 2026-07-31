# ADR-003: Per-delivery-attempt records and domain-event emission from item #1

- **Status:** Accepted
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** cross-cutting (locks Roadmap #1 build-ahead seam; serves #6, #11, #13)

## Question
How do we record what happened on each delivery so that analytics (#11) reads
real attempt records — decoupled from retained payloads that expire under #5's
30-day retention — and so #6 retry and #13 notifications consume the same stream,
without a parallel or reconstructed path?

## Decision
From the first commit, `DeliverStep` persists a **`DeliveryAttempt` record per
destination per attempt** and emits a corresponding **domain event** on Laravel's
event bus (`DeliveryAttempted`, and its terminal `DeliverySucceeded` /
`DeliveryFailed`). A `DeliveryAttempt` holds only **outcome metadata** — never
the webhook payload: `team_id`, `proxy_id`, `destination_id`, an `ingest_id`
(UUID correlating one received webhook's fan-out set), `status`, `http_status`,
`error_summary`, `attempt_number`, `started_at`, `duration_ms`. Analytics (#11)
aggregates from these records; retry (#6) and notifications (#13) subscribe to the
events. Attempt records are retained on their own lifecycle, independent of
payload retention (#5).

## Alternatives
- **Reconstruct stats from stored payloads later (#11)** — impossible once payloads expire under #5's 30-day GC; the roadmap explicitly calls this out; rejected.
- **Log-only (write to app log), parse later** — not queryable, not team-scoped, loses data on rotation; violates "no lost data"; rejected.
- **Emit events but persist nothing at #1** — #11 would still have nothing durable to aggregate and #1's "record outcome from first commit" mandate is unmet; rejected. (Events are transient; the record is the durable source of truth.)

## Reasoning
- Roadmap #1 build-ahead: "Each delivery attempt must record its outcome from the
  first commit so analytics (#11) is built from real attempt records rather than
  reconstructed later," and #11: stats "kept separate from retained payloads so
  they remain long-lived and trendable."
- Splitting durable **record** (for #11 queries) from transient **event** (for #6
  reactive retry and #13 severity-keyed notifications) gives both consumers one
  source without a second path — #13's dispatch keys off event severity, #6 off
  failure events, #11 off the records.
- Payload-free by construction satisfies the vision's requirement that analytics
  be decoupled from retained payloads and sidesteps sensitive-data concerns (#10)
  in the analytics path.

## Impact
- **Easier:** #11 is a read/aggregate over an existing table; #6 and #13 attach as
  event listeners; #6 replays reuse the same record shape (add a nullable
  `replay_of_id` later).
- **Constrained:** `DeliveryAttempt` must remain payload-free; any field that
  could carry payload/sensitive data belongs to #5's storage entity, not here.
- **Scope note (flagged, not decided):** persisting attempt *metadata* at #1 is
  distinct from payload storage (#5) and analytics *features* (#11), but PRD-01
  AC11 reads "without being stored … without analytics." This ADR follows the
  approved roadmap's explicit mandate; the wording tension is raised to the PM in
  `docs/questions/prd-01-attempt-records-vs-storage.md` for reconciliation.
