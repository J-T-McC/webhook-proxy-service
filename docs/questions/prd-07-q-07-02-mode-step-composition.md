# Question Q-07-02: Mode-gated step composition, in-flight mode resolution, and extensibility (technical)

- **Status:** OPEN
- **Raised by:** Product Manager
- **Owner (must answer):** Principal Engineer *(technical feasibility/design — the
  Product Manager does not resolve these; if any PRD-07 requirement proves
  infeasible as stated, it returns to the Product Manager as a requirement
  question, not a silent design change)*
- **Raised:** 2026-08-21
- **Gates:** **Non-blocking** for PRD-07 requirement approval; gates **technical
  design** for feature #7.
- **Source:** `docs/product/prd-07-enhanced-mode-toggle.md` (AC6, AC9, AC10, AC11,
  AC15); roadmap #7 build-ahead ("Principal Engineer owns step composition");
  ADR-001 (pipeline spine); ADR-002 (`mode` as the pure selector the
  `PipelineFactory` reads); ADR-011/016 (dispatch-by-reference; FIFO claim state);
  ADR-012/014 (retention holds; cleaned-signal guard); ADR-015 (retry policy
  resolution).

## Context
Step composition is explicitly the Principal Engineer's per the roadmap #7
build-ahead. PRD-07 therefore asserts only **observable** requirements and leaves
mechanism open. This question asks the Principal Engineer to confirm, at #7
technical design, that those requirements land on the seams ADR-001/ADR-002
already reserved — and to surface any Owner-gated change early.

## Question
Confirm at technical design:

1. **Governed-step wiring (PRD-07 AC6, AC15).** The complete set of enhanced-only
   behaviour as of #7 — dispatched-output capture (PRD-05 AC12) and per-proxy
   retry-policy configurability (PRD-06 AC2) — is gated on the single ADR-002
   `mode` selector, with no second gate, no inferred mode, and no widening of the
   attribute. Confirm that adding a later enhanced-only step (#8 mapping, #9
   normalisation, #12 change detection) is an addition to the composed step list
   only, requiring no change to the mode attribute, the toggle, or the gate — the
   extensibility PRD-07 AC15 asserts and the roadmap build-ahead requires.

2. **Which mode value governs an in-flight event (AC9).** PRD-07 requires the mode
   **in force when the event is processed** to govern that event's pipeline —
   derived from ADR-002 (the `PipelineFactory` composes from `mode` at pipeline
   build time), PRD-05 AC12 ("when a proxy **is** in enhanced mode") and PRD-06
   AC11 (replay runs through the proxy's **current** configuration). Confirm this
   holds under queued dispatch, where composition happens after ingest: that an
   event captured under one mode and dispatched under another composes cleanly,
   and that no code path snapshots a stale mode or re-reads it inconsistently
   between steps of the same event.

3. **Switch safety across #4/#6 state (AC10, AC11).** A mode change while work is
   outstanding must not lose, error, duplicate, or strand an event: confirm it
   composes with ADR-011/016 FIFO claim state and dispatch-by-reference, with
   Async in-flight jobs, with scheduled retries and in-flight replays, and with
   the ADR-012 retention holds and the ADR-014 cleaned-signal guard. Specifically:
   a downgrade mid-retry must not orphan a delivery mid-policy, and an upgrade
   must not make a partially-processed event inconsistent in a way that surfaces
   as an error rather than as the acceptable per-event variance AC11 permits.

4. **Retry-policy resolution across a switch (AC6, AC7).** Confirm that a
   simple-mode proxy resolves the fixed system default (PRD-06 AC2) regardless of
   any persisted policy value, so the answer to **Q-07-01(b)** — whether a saved
   policy is discarded or preserved dormant — changes only persistence, never the
   resolved behaviour of a simple-mode proxy.

5. **Owner gates and data-model impact.** Whether any of the above is a data-model
   change or otherwise carries a `CLAUDE.md` Owner approval gate at plan time. The
   Product Manager's expectation is that #7 needs **none** — `mode` already exists
   (ADR-002) and is already settable through the existing create/edit endpoints —
   but that assessment is the Principal Engineer's. If Q-07-01 resolves to erasing
   stored dispatched output on downgrade (Option B), assess and surface the
   additional gate that introduces against PRD-05's lifecycle.

Mechanism, composition strategy, and where the gate is evaluated are the Principal
Engineer's, not resolved here.

## Impact if unresolved
None for requirement approval — PRD-07 asserts observable outcomes only. Technical
design for #7 should not begin without these confirmations, since every one of
them concerns a seam an earlier ADR reserved rather than built.

## Answer
- **Answered By:**
- **Answered:**

*(To be completed by the Principal Engineer at #7 technical design.)*
