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

**When a plan carries no outstanding flag**, still write the `Owner-approval flags (✋)` section —
list the gate struck through with its Accepted date (plan-07), never omit the heading, and add a
short *Why no new ADR* paragraph walking each candidate against the ADR bar. The Status line then
reads "Approved (Principal-Engineer self-certified) — **in full**", and the Certification paragraph
says explicitly that there is no carve-out. Where a design spec is approved *with required
corrections held by the Designer*, the plan is written against the **approval note** (which governs
over the spec body) and says so in its header and certification.

**When a plan *does* carry flags** (plan-08 — the first since plan-04): say so in the **Status line
itself**, not only in the Handoff ("self-certified **except** the two items at Owner-approval flags"),
enumerate the whole data-model change set in **one clearly-marked § Data Model block** so the Owner
rules on it at once, and add the mirror of the no-flag case: a short *Why an ADR was warranted here
when the previous item needed none* paragraph. Always pair a data-model gate with an explicit
**"Explicitly *not* in the change set"** list (existing tables/indexes/enum values/backfill, verified
item by item) and a security assessment attached to that gate — the additive-only claim is what makes
the gate a single reversible decision. Certification then names exactly what the Owner must approve
and states that everything else needs no further sign-off; Next Agent reads "Task Planner — **after**
Owner approval of items N".

**When an Owner amendment lands mid-design** (the PRD "Amendment A" pattern), the house response is
a `## Revision A — what the ruling changed here` table (*prior position → now*) at the top of every
artifact it touches, the plan included. Rules observed: revise a **Proposed** ADR in place (nothing
ratified is being rewritten); reverse an **Accepted** ADR only via a **new superseding ADR** with an
enumerated *Positions superseded* table — partial supersession, so the old ADR keeps its file,
status and full text and gains inline `[Pn — SUPERSEDED by ADR-NNN]` blockquotes. When the
superseding ADR is still only **Proposed** at plan time, annotate the Accepted ADR anyway but
phrase it `[Pn — PROPOSED supersession by ADR-NNN (pending Owner approval)]` (plan-05 annotated
ADR-010 while ADR-014 was Proposed; plan-06 did the same to ADR-011 for ADR-016). A RESOLVED question
doc gets an appended `## Amendment A — superseded answers` table, never an edit to its answer. ACs
are never renumbered. The Owner-flag list is restated **in full** in the plan (it is the single place
the Owner reads it) and grows as the amendment ripples.

**The same `## Revision A` shape also covers an Owner ruling landing *post-review*** (plan-07, on a
review Major routed back to the PE because a standing plan ruling forbade the fix). Differences from the
mid-design case: a **plan** ruling is rewritten **in place** with an *(Amended YYYY-MM-DD — Revision A)*
parenthetical — only ratified ADRs get the supersession-by-new-file treatment; the Revision table's rows
name the *review finding* each closes; the original `### Certification` block is **kept** and a dated
`### Re-certification at Revision A` appended below it, naming the authorising Owner ruling; a new
milestone is appended (M7) rather than renumbering; and alternatives the Owner considered and rejected
are recorded **by name**, so they are not re-proposed. Two things a re-ruling must state explicitly even
when the answer is "nothing changes": whether each affected **ADR decision** needs amending (walk them
one by one — "considered, not overlooked" is what the Reviewer checks for), and whether any approved
**copy** needs changing (if it does, that is the PM's call — route it, never rewrite it).
