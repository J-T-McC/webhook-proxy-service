# Documentation Standards

## Active conventions
- One document per feature per type; always start from the dev-team plugin's `templates/`.
- Naming: `<feature-slug>-<type>.md`; ADRs `adr-<NNN>-<slug>.md`; questions `<feature-slug>-q<NNN>.md`.
- Every artifact carries Status, Author, Approval, and a Handoff section (plugin `workflow/handoffs.md`).
- Link, never duplicate. If a section exceeds roughly a page, split the feature.
- Approvals are recorded in the artifact **and** `docs/status.md`.

## Write dense
Agents re-read these documents many times; every word is paid for repeatedly.
- One line per fact. Tables and bullets over prose; sentence fragments are fine.
- No filler ("it is important to note…"), no restating upstream content — link it.
- Say it once: if a fact lives in another artifact or section, reference it.
- Dense ≠ cryptic: names, criteria, and decisions stay explicit and testable.

## Placeholders (customize per project)

> **Status: Proposed — pending Project Owner approval.** The two subsections below
> (Diagrams, Document lifecycle) are owned by the Principal Engineer (proposes) and
> Project Owner (approves). *Document lifecycle* mostly **codifies practice this
> repo already follows** (ADR/question/fix handling, ADR-009 Amendment A); rules
> with no existing precedent are tagged **Proposed default (no prior precedent)**.
> *Diagrams* has **no observed precedent at all** — no diagram exists anywhere in
> `docs/` today — so it is a proposed default in full. The ratified sections above
> (*Active conventions*, *Write dense*) are unaffected by this Status.

### Diagrams

**Observed practice:** none. No Mermaid, PlantUML, or image diagram appears
anywhere in `docs/` today; every artifact communicates with prose, tables,
bullets, and inline code fences (e.g. ADR-009's PHP snippets). The rules below are
therefore a **Proposed default (no prior precedent)** in full.

- **Tool/format — Mermaid in fenced ```mermaid code blocks.** Text-based (diffs and
  reviews like prose), renders natively on the repo host, no build step or binary
  assets. The placeholder itself suggested this; adopt it as the default. No
  external diagramming tool, no committed image files.
- **Where diagrams live — inline in the owning artifact**, next to the prose they
  serve (a plan section, an ADR's Reasoning/Impact). One diagram has one home; per
  *Write dense / link don't duplicate*, never copy a diagram into a second doc —
  link to the artifact that owns it.
- **When a diagram is warranted — only when it carries what prose and tables
  cannot cheaply.** Reach for one for multi-actor sequences or non-trivial
  state/branch topology (e.g. the ingest → fan-out pipeline, delivery-attempt state
  transitions). For everything else prefer prose, a table, or one-line-per-fact
  bullets — a diagram that merely restates a list is duplication and costs re-read
  budget. Default to no diagram; add one only when it strictly out-explains the
  text it sits beside.

### Document lifecycle

Retain history; **never delete a superseded document.** State changes are recorded
by editing the artifact's Status and by updating the `docs/status.md` index, not by
removal. Rules below **codify observed practice** except where tagged.

- **ADR status lifecycle (codifies observed + plugin template):** `Proposed →
  Accepted → Superseded by ADR-<NNN>`. Values in use today: **Accepted**
  (ADR-001..008) and **Proposed** (ADR-009). A superseded ADR **stays in place**
  with `Status: Superseded by ADR-<NNN>` and a pointer to its successor; the
  successor names what it replaces. *No ADR has reached Superseded yet* — ADR-009
  only forward-references the possibility — so the superseding mechanics are a
  **Proposed default (no prior precedent)**.
- **Amend-in-place vs. new ADR (codifies observed — ADR-009 Amendment A):** an
  **additive clarification or extension that does not reverse the decision** is
  folded into the same ADR as a dated `## Amendment <X>` section, with the Date line
  flagging the amendment (ADR-009 Amendment A did exactly this — "extends, not
  replaces" — while the ADR was still Proposed). A change that **reverses or
  replaces** an already-**Accepted** decision is a **new ADR that supersedes** the
  old one, never an in-place rewrite of ratified history.
- **Questions retained, not deleted (codifies observed):** a resolved question
  keeps its file with `Status: Resolved (<date>)` and an Answer section pointing to
  the artifact that decided it (e.g. `prd-02-permission-mechanism-selection.md` →
  ADR-009). The question record is the durable trace of *why* a decision was asked
  for; it is never removed once answered.
- **Fixes retained (codifies observed):** bug/chore fix records live permanently in
  `docs/fixes/<slug>.md` (Problem / Cause / Fix / Verification / Follow-ups). They
  are the standing record for flat, non-pipeline work and are not pruned.
- **`docs/status.md` is the index (codifies observed):** every phase transition,
  approval, and supersession is reflected there **and** in the artifact
  (per *Active conventions*). status.md never drops a feature or foundational row —
  a completed or superseded item stays listed with its terminal state.
- **Archival (Proposed default — no prior precedent):** resolved/superseded/fixed
  documents **stay in their current folder in place** — do not move them to a
  separate `archive/` tree. Status fields plus the status.md index carry lifecycle
  state; relocating files would break the cross-links artifacts depend on. Revisit
  only if a folder's live-vs-historical ratio makes it hard to scan.
