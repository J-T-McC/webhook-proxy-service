# ADR-004: Upstream-response path decoupled from dispatch outcome

- **Status:** Accepted
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** cross-cutting (locks Roadmap #1 build-ahead seam; serves #3, #4)

## Question
How does the ingest handler produce its HTTP response to the upstream sender so
that #3 (user-defined status/body) and #4 (async dispatch) become configuration
changes, not a rewrite of the ingest handler?

## Decision
The ingest handler resolves the upstream response from proxy configuration via a
`ResponseResolver`, **before and independent of** pipeline dispatch, and the
response is **never** derived from any delivery outcome. At item #1 the resolver
returns a fixed default of **`202 Accepted`** with a minimal body (PRD-01 states
item #1 promises no particular response contract). #3 later replaces the default
by reading `response_status` / `response_body` columns on the proxy; #4 later
moves pipeline dispatch onto a queue (ADR-005) with zero change to the response
path, because the response already does not wait on delivery.

## Alternatives
- **Return after all deliveries complete, reflecting their result** — couples the response to downstream success, contradicts the vision's "return success upstream even if a downstream delivery fails," and blocks #4's async model; rejected.
- **Hard-code a 200 with fixed body and special-case #3 later** — #3 would still be a rewrite of the handler's response construction; a resolver seam avoids that; rejected.

## Reasoning
- Vision: "Decoupled upstream response … Return success upstream even if a
  downstream delivery fails." Roadmap #3/#4 build-ahead: the immediate response is
  the same seam #4 relies on; dispatch must be able to go async without touching
  the response.
- Isolating response construction in a resolver keyed off proxy config makes #3 a
  data/config change and #4 a dispatch-location change — orthogonal to each other
  and to the response.

## Impact
- **Easier:** #3 adds columns + resolver logic; #4 swaps the dispatch seam; neither
  edits the response contract wiring.
- **Constrained:** the handler must not read attempt records/events (ADR-003) to
  build the response; delivery failures surface via analytics/notifications, not
  the synchronous response.
- `202 Accepted` is a default, not a committed contract for #1; #3 owns the final
  user-defined contract. Invalid/unknown ingest tokens return `404` (ADR-006).
