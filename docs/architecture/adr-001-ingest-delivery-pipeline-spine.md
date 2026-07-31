# ADR-001: Ingest→delivery pipeline spine

- **Status:** Proposed
- **Author:** Principal Engineer
- **Date:** 2026-07-30
- **Feature:** cross-cutting (locks Roadmap #1 build-ahead seam; serves #4, #5, #6, #8, #9, #10, #12, #14)

## Question
How should the ingest-to-delivery flow be shaped at item #1 so that queued
dispatch (#4), retry/replay (#6), enhanced-mode storage (#5), and mapping (#8)
slot in later without reworking the flow — realising the vision's
"pipeline-oriented architecture" (a pipeline of steps, **not** a workflow
builder)?

## Decision
Model processing as an **ordered pipeline of discrete `Step` objects** that
operate on a single mutable `PipelineContext` (an in-memory envelope carrying the
received request, resolved proxy, and accumulated state). A `PipelineFactory`
composes the ordered step list for a given proxy from the proxy's mode and config
(see ADR-002); steps are code-defined and composed by configuration, never
user-authored. At item #1 the pipeline is exactly `[DeliverStep]` — a fan-out
terminal step that iterates the proxy's destinations and performs a
fire-and-forget HTTP POST/PUT per destination. Later items register additional
steps at fixed ordered positions without touching `DeliverStep` or the runner.

## Alternatives
- **Monolithic ingest controller that sends inline** — simplest now, but #4/#5/#6/#8 each force a rewrite of the send path; rejected (violates the no-refactor goal).
- **Full workflow/DAG engine with user-defined nodes** — explicitly out of scope per vision ("without building a workflow builder now"); rejected.
- **Per-destination pipelines** — contradicts R3 (mapping is per-proxy, one payload structure to all destinations); rejected. Fan-out is a single terminal step that iterates destinations.

## Reasoning
- The vision names "pipeline-oriented architecture … so new steps can be added
  more easily" as the intended direction; the roadmap #1 build-ahead note makes
  this the Principal Engineer's seam to fix.
- A linear ordered-step pipeline over a shared context is the minimum structure
  that lets later items insert a stage (VerifyStep #10, CaptureRawStep #5,
  NormalizeStep #9, MapStep #8, CaptureDispatchedStep #5, ChangeDetectStep #12)
  as pure additions. `DeliverStep` stays terminal and unchanged.
- Keeping steps code-defined and composed-by-config honours the explicit
  out-of-scope on workflow builders while leaving the "workflow-builder-like in
  spirit" extensibility the vision asks for.

## Impact
- **Easier:** #5/#8/#9/#10/#12 become "add a step + register it in the factory";
  #14 test payloads run the identical pipeline (no mock path).
- **Constrained:** every processing concern must be expressible as a step over
  `PipelineContext`; conditional routing / branching is deliberately excluded
  (matches vision out-of-scope) — if a future item needs branching, that is a new
  ADR superseding this linear model.
- **Coupling:** where and when the pipeline runs (inline now, queued at #4) is
  delegated to the dispatch seam in ADR-005, so this ADR does not fix execution
  location. `DeliverStep` emits a per-destination attempt via ADR-003.

## Note — conditional map selection is an in-step capability of the mapping seam (2026-07-30)

Added 2026-07-30 after the roadmap/vision refinement to item #8 (a proxy holds
multiple maps; one map is selected per incoming event by matching a key against a
value, with a global/default fallback). This ADR already accommodates it with no
change to the spine, the `PipelineContext` contract, or `DeliverStep`:

- **Selection is internal to `MapStep(#8)`.** When `MapStep` runs it reads the
  incoming (already-captured, and normalized-to-JSON by `NormalizeStep(#9)`)
  payload from `PipelineContext`, evaluates the proxy's map-selection condition to
  choose exactly one of the proxy's maps (or the default), applies that single map,
  and writes one reshaped payload back to the context. Input and output of the step
  are unchanged: one context in, one payload out. Everything upstream (ingest
  capture, envelope) and downstream (`DeliverStep`) is untouched.
- **No new day-one seam is required.** The value the condition matches on is part
  of the received payload the `PipelineContext` already carries from the first
  commit; no additional ingest capture, context field, or item-#1 data-model
  change is needed. The proxy's *set of maps* and their selection conditions are
  enhanced-only configuration attached under #8 (ADR-002 keeps `mode` a pure
  selector and enhanced config attaches as its own tables), not built at #1.
- **Boundary holds — this is not conditional routing.** The branch is over *data*
  (which reshaping map to apply), not over pipeline topology or destinations. The
  pipeline stays linear, `DeliverStep` stays the single terminal fan-out, and every
  destination still receives the same resulting structure for a given event (R3).
  The "conditional routing / branching is deliberately excluded" constraint above
  is unaffected; no new superseding ADR is needed.
- **Not decided here.** The precedence/fallback rules (M1) and matching syntax (M2)
  are product/PRD-time decisions for item #8; this note only confirms the
  architecture leaves room for them inside `MapStep`.
