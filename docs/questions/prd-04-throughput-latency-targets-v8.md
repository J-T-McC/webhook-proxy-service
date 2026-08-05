# Question: PRD-04 (V8) throughput / latency / delivery-success targets

- **Status:** RESOLVED — deferred by Project Owner (2026-08-04)
- **Raised by:** Product Manager
- **Owner (must answer):** Project Owner *(business/product decision — targets are
  not derivable from any existing doc)*
- **Raised:** 2026-08-04
- **Gates:** Nothing in Feature #4. **Non-blocking** for PRD-04 approval and for
  #4 design/build. Would inform (a) any future V3 transport-beyond-Redis decision
  and (b) analytics targets at #11.
- **Source:** Roadmap Open Question **V8** (`docs/product/roadmap.md`, "Carried
  forward from the vision"); Vision Open Question 8
  (`docs/product/vision.md` — "Specific throughput / latency / delivery-success
  targets — none set yet"); dual-gated to #4 and #11 per `docs/status.md` Open
  questions register.

## Context
Roadmap V8 is gated to this feature (#4) and to #11. It asks what **numeric
throughput / latency / delivery-success targets** the product commits to.

No existing document sets any. The vision states plainly: *"There are no hard
targets set yet, but throughput and processing scalability matter"* and frames the
build as *"a learning project with no timeline or deadline."* PRD-01 already
asserted **no performance targets** for the walking skeleton (PRD-01 Out of Scope,
citing V8). ADR-005 records V8 as *"currently unset"* and treats it as the gate on
the V3 transport-beyond-Redis decision.

Because no vision, PRD, or prior decision implies a number, the Product Manager
cannot derive or invent one — this is a genuine business decision reserved to the
Owner.

## Why #4 does not depend on it
Feature #4's requirements are **functional** — deliveries move to a Redis-backed
queue, and a proxy can process in Async (default) or FIFO (opt-in) mode. Neither
capability depends on a numeric SLA. ADR-005's FIFO/Async correctness properties
(single advancer, liveness sweeper, idempotency, bounded head-of-line blocking)
are correctness guarantees, not throughput targets. So PRD-04 proceeds asserting
**no numeric performance target**, consistent with the vision and PRD-01.

## Question
Does the Owner want to set any of the following now, or continue to defer them?

1. **Throughput** — sustained/peak events ingested-and-fanned-out per unit time
   (per proxy, per team, and/or system-wide)?
2. **Latency** — an acceptable bound from ingest acknowledgement to
   destination-delivery completion (Async), and/or FIFO head-of-line delay?
3. **Delivery-success** — a success-rate objective the product measures itself
   against (this feeds #11 analytics and #13 alerting)?

If deferred, please confirm they stay deferred past #4 (the Product Manager's
default position). Setting even rough targets would (a) let the Principal Engineer
judge whether Redis suffices or the V3 beyond-Redis seam should be exercised, and
(b) give #11 analytics a concrete objective to report against.

## Impact if unresolved
None on PRD-04 approval or #4 design/build — #4 asserts no SLA. Deferral simply
carries V8 forward to its other gate (#11) and leaves the V3 transport decision
resting on ADR-005's existing "gated by V8, do not build Kafka without it" flag.

## Answer
**Project Owner, 2026-08-04 — DEFER, no targets set.** No throughput, latency, or
delivery-success targets are set now. The Owner will determine these later once
real-world testing is underway; optimizations and further findings expected to
emerge at that time. PRD-04 proceeds with **no numeric performance target** (AC13),
consistent with the vision and PRD-01. V8 stays deferred past #4 and carries forward
to its other gate (#11 analytics); the V3 transport-beyond-Redis decision continues
to rest on ADR-005's "gated by V8, do not build Kafka without it" flag.
