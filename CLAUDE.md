# Webhook Proxy Service

This is a SaaS solution that allows a team of users to create and manage a subset of incoming and outgoing webhooks.
We ingest, map and replay webhook events to one or more spoecified endpoints while providing failure re-try and related analytics.
Example: stripe webhook recieved, mapping logic is defined to restructure the payload, webhook is replayed to 3 target webhook urls.

## Development Organization

This project uses the **dev-team** plugin. Artifacts under `docs/` are the
source of truth; `docs/status.md` tracks all features — ask the orchestrator
"what's next?". `docs/status.md` carries only what routing needs (phase, owner,
blockers, approvals, artifact pointer); a ruling's reasoning belongs in the
artifact that made it. `docs/status-history.md` is a frozen pre-compaction
archive — do not read it to route work, and do not maintain it.

- Delegate feature work to the matching dev-team agent; never cross roles.
- Consult vs. delegate: questions/discussion → invoke the matching dev-team
  role skill in-conversation (read-only; resulting changes still go to the
  agent). Routing and status.md upkeep → orchestrator skill in-conversation;
  spawn agents only to produce or change artifacts.
- Small work is flat, no gates: bugs/chores → senior-developer (fix + tests +
  record in `docs/fixes/`); doc corrections → the owning role updates the doc.
  Only decision-changing work goes through the pipeline.
- Owner approval only for PRDs, releases, and major decisions (new deps,
  stack/data-model changes, security, irreversible). Other gates are delegated:
  design → product-manager, plan → principal-engineer self-certified, tasks →
  none. The product-manager answers requirement questions as the Owner's proxy.
- Missing info: search `docs/` → ADRs/answered questions → question doc to
  the upstream agent → Project Owner. Never invent requirements.
- Stack: `docs/stack/stack.md` · Standards: `docs/standards/` — ask, don't
  guess, where placeholders remain.
- Past-session context (prior work, discussions, rationale) not captured in
  `docs/` is searchable via the **mem-search** skill — consult it before
  asking an upstream agent. `docs/` remains authoritative where they conflict.

## Reading the workspace

Several `docs/` artifacts are very large — task plans and PRDs run to hundreds of
kilobytes, and `docs/status.md` is dense prose. Reading four of them from the top
costs more context than the work they inform. Read them the way you would read a
reference manual, not a novel.

- **Never read a task plan, PRD, plan or `docs/status.md` from the top when you
  already know which part you need.** Use `grep -n` to find the milestone, task
  or acceptance-criterion heading, then read that line range only.
- **Task plans are split per milestone** — see `docs/tasks/README.md`. Read the
  index for orientation and only the milestone file you are working in. Shipped
  milestones are history; they do not inform an unstarted task.
- **When delegating, name the range.** Tell the agent which file and which lines
  or which milestone file, and tell it explicitly not to read the others. An
  agent given no bound reads everything.
- Read a whole artifact when you genuinely need the whole artifact: a review
  gate against every acceptance criterion, or an amendment that touches the
  document as a whole. The rule is against reading it by default, not against
  reading it.


## Commands

- Run code style fixer: `composer lint` (uses Laravel Pint)
- Run static analysis: `composer types:check` (PHPStan level 7)
- Run a specific test class: `./vendor/bin/sail test --filter TestClassName`
- Run tests matching a pattern: `./vendor/bin/sail test --parallel --filter "pattern"`

## Project rules

- Never push or merge unless asked. Per-task commits on a feature branch during
  implementation are pre-authorized (see `docs/standards/planning.md`); any other
  commit requires an explicit ask.
- Commit messages should be short with additional context added via list items below the header message
- Commits follow Conventional Commits: `type(scope): summary` where type ∈ feat, fix, docs, refactor, chore, test, build, ci. Header short and imperative; context as list items below.
- When prompting to run a command, keep its description to a very short phrase saying what it does — enough to approve or deny at a glance, no restating the command or explaining why.

## Communication style

Agent-to-agent and agent-to-Owner messages are terse. Drop articles (a/an/the),
filler (just/really/basically/actually/simply), pleasantries and hedging.
Fragments are fine. Prefer the short synonym. No tool-call narration, no
decorative tables or emoji, no dumping long raw error logs — quote the shortest
decisive line. Never invent abbreviations (`cfg`, `impl`, `req`): they tokenize
the same as the full word, so they save nothing and cost clarity.

This compresses **prose**, never substance. Technical terms, file paths, class
and method names, AC and ADR numbers, column and package names, CLI commands,
commit-type keywords and exact error strings all stay verbatim.

**Carve-outs — these are always written normally, never compressed:**

- **Everything under `docs/`.** PRDs, designs, plans, ADRs, tasks, reviews and
  question docs are the source of truth and are read as evidence long after the
  conversation that produced them. A ruling has to survive being read literally
  by an agent that was not there. `#7` turned on `plan-07` ruling 4
  distinguishing *mount-seeded persisted* values from *in-session typed* ones —
  compressed to "keep values", the data-loss defect ships.
- **Code, comments, commit messages and PR bodies.**
- **Security warnings, irreversible-action confirmations, and any multi-step
  sequence where dropped conjunctions could reorder the steps.** Write those in
  full, then resume.

Compressing the message is not compressing the thinking. If terseness would make
a technical point ambiguous, write the longer sentence.
