# Planning Standards

> **PLACEHOLDER — not yet ratified.** Scaffolded by the Orchestrator from the
> dev-team plugin seed and reconciled with the conventions already in use in
> `docs/tasks/walking-skeleton-tasks.md`. Owned by the Task Planner's process.
> The Project Owner (or Task Planner, with Owner approval) should ratify or amend
> this before it is treated as binding. Customize thresholds per project.

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
- `composer types:check` (PHPStan level 1) passes.
- `./vendor/bin/sail test` passes (accompanying tests included).
- Completion notes are recorded on the task before handoff to the Reviewer.

## Estimation
_TBD — whether estimates are used at all. Placeholder; ratify with Task Planner._

## Scope discipline
Do not build forward-looking (`LATER`) scaffolding beyond what the approved plan
authorizes; leave named seams as commented stubs only (see the foundational
architecture plan). Requirements come from approved artifacts, never invention.
