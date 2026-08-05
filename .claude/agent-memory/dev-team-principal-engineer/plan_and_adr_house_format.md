---
name: plan-and-adr-house-format
description: How this project's technical plans and ADRs deviate from the dev-team plugin templates, and how the Owner gate is carved out of PE self-certification
metadata:
  type: reference
---

Technical plans live at `docs/plans/plan-NN-<slug>.md` (not the template's
`<feature-slug>-plan.md`); ADRs at `docs/architecture/adr-NNN-<slug>.md`. Both start from the
plugin templates but the house format adds two things the template lacks, established by
plan-03/plan-04 and expected by the Task Planner and Reviewer:

- **A `## Test strategy` section** between Implementation Notes and Handoff, with tests grouped
  by AC and named per acceptance criterion. Not in the plugin template.
- **`Handoff → Owner-approval flags (✋)`** — a numbered list of exactly what needs Project
  Owner sign-off, each item stating its precise shape (e.g. the verbatim column definition).

**Self-certification is partial, and the Status line says so.** The PE writes
`Status: Approved (Principal-Engineer self-certified) — **except** <the flagged items>`, and the
Next Agent line reads "Task Planner — after Owner approval of items N". Never certify a plan
whole when it carries a flag. Triggers observed so far: any ADR, any new table/column/index,
any irreversible operation, any new at-rest copy of sensitive data. Data-model changes are
**never** PE-self-certified (see [[project-structure]]).

Also house convention: plans that rule on something the PRD/design left silent get an explicit
named ruling section (plan-04's "Mid-flight mode change — technical ruling") stating why it stays
inside the upstream artifact's assumptions and therefore does **not** route back upstream.

**When an Owner amendment lands mid-design** (the PRD "Amendment A" pattern), the house response is
a `## Revision A — what the ruling changed here` table (*prior position → now*) at the top of every
artifact it touches, the plan included. Rules observed: revise a **Proposed** ADR in place (nothing
ratified is being rewritten); reverse an **Accepted** ADR only via a **new superseding ADR** with an
enumerated *Positions superseded* table — partial supersession, so the old ADR keeps its file,
status and full text and gains inline `[Pn — SUPERSEDED by ADR-NNN]` blockquotes. A RESOLVED question
doc gets an appended `## Amendment A — superseded answers` table, never an edit to its answer. ACs
are never renumbered. The Owner-flag list is restated **in full** in the plan (it is the single place
the Owner reads it) and grows as the amendment ripples.
