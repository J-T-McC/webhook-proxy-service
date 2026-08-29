# Task plans

A feature's task plan lives in `docs/tasks/<feature-slug>/`:

- `index.md` — the plan header (Status, Technical Plan, PRD, Design, Questions,
  Authorities, Approved by), the scope and conventions blockquote, every binding
  constraint, and a milestone table giving each milestone's subject, task range,
  file and state.
- One file per milestone, prefixed by its physical position in the plan —
  `m01-…`, `m02-…`. The prefix follows the plan's own ordering, which is not
  always the milestone label's numeric order; where a plan sequences a later
  milestone earlier, the file prefix follows the plan, and the index table names
  both.

Read the index for orientation, then only the milestone file you are working in.
A shipped milestone is history: it does not inform an unstarted task, and reading
it costs context that the work does not.

## Single-file plans are deliberate

`retry-replay-tasks.md`, `analytics-tasks.md` and the other single-file task plans
belong to features that have shipped. They are frozen history with no reader to
serve, so splitting them would be churn with no benefit. They stay as they are.
New task plans are written split from the start.
