# ADR-002: Simple/enhanced mode as a first-class proxy attribute from item #1

- **Status:** Accepted
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** cross-cutting (locks Roadmap #1 build-ahead seam; serves #5, #7, #8)

## Question
How is the simple-vs-enhanced mode concept represented at item #1 so that #5 can
gate storage on it, #7 only surfaces a user-facing toggle, and #8 wires mapping
to it — all without re-modelling the proxy?

## Decision
Add a non-nullable `mode` attribute to the `proxies` entity from the first
commit, typed as an enum `('simple','enhanced')` and defaulting to `simple`.
Item #1 only ever creates `simple` proxies, but the column, its default, and the
domain concept exist from day one. The `PipelineFactory` (ADR-001) reads `mode`
as the gate that decides which enhanced-only steps are composed into a proxy's
pipeline. Enhanced-only configuration (retention, mapping definition, retry
strategy) is added by later items as separate related columns/tables keyed to the
proxy — never by widening or reinterpreting `mode`.

## Alternatives
- **Infer mode from presence of enhanced config (e.g. "has mapping ⇒ enhanced")** — makes the boundary implicit and ambiguous; #5/#7 would have to reverse-engineer intent; rejected.
- **Introduce mode only at #7 (the toggle item)** — forces a data-model migration and a re-audit of every gate at #7; directly the refactor the Owner wants to avoid; rejected.
- **Separate `SimpleProxy` / `EnhancedProxy` types** — duplicates the entity and breaks the single ingest/pipeline spine; rejected.

## Reasoning
- Roadmap #1 build-ahead: "The proxy must carry the simple/enhanced mode concept
  from day one so the #7 toggle is a state change and #5's enhanced-only storage
  is a gate, not a re-model."
- A single explicit enum column is the smallest representation that makes #5's
  storage gate and #7's toggle pure state changes over existing structure.

## Impact
- **Easier:** #7 becomes a validated state transition on one column; #5 gates
  capture steps on `mode === 'enhanced'`; #8 gates the map step likewise.
- **Constrained:** any future third mode is an enum addition, not a new axis;
  enhanced sub-features must attach as their own config, keeping `mode` a pure
  selector.
- Item #1 must expose no UI to change mode (that is #7); it is fixed to `simple`.
