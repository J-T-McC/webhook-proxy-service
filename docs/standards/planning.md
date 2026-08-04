# Planning Standards

> **Status: Proposed — pending Project Owner approval.** Owned by the Task
> Planner (proposes) and Project Owner (approves/ratifies). Sections below
> codify patterns already in use in `docs/tasks/walking-skeleton-tasks.md`;
> rules with no existing precedent are tagged **Proposed default (no prior
> precedent)** so the Owner ratifies the observed patterns and decides the
> genuinely new ones.

## Task sizing (active)
- One sitting, one concern, independently verifiable — small and ordered.
- No task may depend on a later task; sequence dependencies flow forward only.
- Every task traces to a technical-plan element (and its ACs / flows); every plan
  element maps to at least one task.

## What every task lists
- **Scope / description** — the single concern the task addresses.
- **Files** — the concrete files created or changed.
- **AC-trace** — the PRD acceptance criteria / design flows / plan element the
  task satisfies.
- **Verify step** — a concrete, runnable check that proves the task is done.
- **Testing** — behavioral tasks are accompanied by tests; non-behavioral tasks
  (e.g. dependency install, config) state why no test is warranted.

## Definition of done (this project)
Per `CLAUDE.md`, a task is done only when it leaves the tree green:
- `composer lint` (Laravel Pint) passes.
- `composer types:check` (PHPStan level 7) passes.
- `./vendor/bin/sail test` passes (accompanying tests included).
- Completion notes are recorded on the task before handoff to the Reviewer.
- The Senior Developer commits per completed task on the feature branch (or per
  logical part of a large task) — small, working, checks-green commits, one
  Conventional Commit each. Push, PR, and merge remain Owner-gated.

## Estimation

**No numeric time or point estimates (codifies observed).** `docs/tasks/walking-skeleton-tasks.md`
(T1–T30) carries no time/point/t-shirt field on any task — only Description,
Dependencies, Files, Acceptance Criteria, Testing, and Completion notes. This
project does not track velocity or burn-down; ordering and dependency chains
(see Task sizing) are what drive sequencing, not size estimates.

The only "size" signal is the Task sizing rule itself: a task that cannot fit
one sitting / one concern is too big and must be split, not estimated. That
constraint is the sizing control — a separate estimate would duplicate it
without adding information the Task Planner or Reviewer acts on.

**Proposed default (no prior precedent):** if the Task Planner or Reviewer
finds a task plan drifting past one-sitting scope in practice, an optional
S/M/L flag *may* be added per task purely as a can't-fit-in-one-sitting
tripwire (prompting a split), never as a velocity/estimation input and never
tracked in aggregate. This has no precedent in `docs/tasks/` today and is not
adopted unless the Owner ratifies it; until then, do not add size flags to
task plans.

## Scope discipline
Do not build forward-looking (`LATER`) scaffolding beyond what the approved plan
authorizes; leave named seams as commented stubs only (see the foundational
architecture plan). Requirements come from approved artifacts, never invention.
