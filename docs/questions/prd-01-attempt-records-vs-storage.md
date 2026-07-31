# Question: PRD-01 AC11 wording vs. roadmap attempt-record mandate

- **Status:** Open
- **Raised by:** Principal Engineer
- **Owner (must answer):** Product Manager *(requirement wording)*
- **Raised:** 2026-07-30
- **Gates:** Final wording of PRD-01 AC11 (not the foundational architecture, which
  proceeds on the approved roadmap)
- **Source:** `docs/product/roadmap.md` item #1 build-ahead note vs.
  `docs/product/prd-01-walking-skeleton.md` AC11

## Context
The **approved** roadmap's item #1 build-ahead note states: *"Each delivery attempt
must record its outcome from the first commit so analytics (#11) is built from real
attempt records rather than reconstructed later."* The foundational architecture
(ADR-003) therefore persists a **payload-free `DeliveryAttempt` metadata record**
per destination at item #1.

PRD-01 **AC11** (Draft) reads: *"The proxy operates in simple proxy mode: the
incoming payload is delivered … without being stored, without retry or replay,
without analytics, and without notifications."*

## Question
The two are reconcilable on this reading: AC11's "without being stored" refers to
the **webhook payload** (R2: payload storage is enhanced-mode/#5 only), and
"without analytics" refers to the **analytics feature/UI** (#11) — neither forbids
persisting delivery-attempt **outcome metadata**, which carries no payload. Is that
the intended reading?

If yes, please confirm (optionally tighten AC11's wording so it clearly does not
forbid attempt-metadata records). If no — i.e. AC11 is meant to forbid **any**
delivery-tracking persistence at #1 — that conflicts with the approved roadmap
build-ahead note, and the conflict needs Project Owner resolution before item #1's
per-PRD plan is finalised.

## Impact if unresolved
Non-blocking for the foundational architecture (it follows the approved roadmap).
Blocks only the final, unambiguous wording of PRD-01 AC11 and the item #1 per-PRD
implementation plan.

## Answer
_Pending Product Manager._
