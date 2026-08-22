---
name: review-rework-convention
description: how this project records a Senior Developer fix for a Reviewer finding on pipeline (reviewed) work
metadata:
  type: project
---

For pipeline work that already has completion notes in `docs/tasks/<feature>-tasks.md` (i.e. a
Reviewer finding on already-implemented, task-planned work — NOT a fast-path fix, which uses
`docs/fixes/<slug>.md` instead), the established precedent (set by review-04's M-1 fix, followed
for review-06's three Majors) is:

- Append a **`- **Rework (review-NN finding <name>).**`** bullet directly to the **completion
  notes of the task that owns the touched code** — never a separate document. Identify "owns" by
  which task's Files list names the changed file(s); if the finding's fix spans work originally
  split across two tasks (e.g. a controller fixed under task A but flagged inside task B's own
  completion notes as an open tension), add the full rework note under the owning task (A) and a
  short pointer note under the other (B) referencing it — don't duplicate the full write-up.
- The rework bullet states: what the finding found (quote/paraphrase the defect), the fix, the
  new/changed tests, and a **Verified:** line with exact gate output (lint, types:check, targeted
  test count/assertions, full-suite count/assertions) — matching the same rigor as the task's
  original completion notes, not an abbreviated aside.
- `docs/status.md` is orchestrator-owned — a Senior Developer doing review rework does not touch
  it, even to note the fix; the Orchestrator updates the feature's status row after the Reviewer
  re-reviews.
