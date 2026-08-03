# Webhook Proxy Service

This is a SaaS solution that allows a team of users to create and manage a subset of incoming and outgoing webhooks.
We ingest, map and replay webhook events to one or more spoecified endpoints while providing failure re-try and related analytics.
Example: stripe webhook recieved, mapping logic is defined to restructure the payload, webhook is replayed to 3 target webhook urls.

## Development Organization

This project uses the **dev-team** plugin. Artifacts under `docs/` are the
source of truth; `docs/status.md` tracks all features — ask the orchestrator
"what's next?".

- Delegate feature work to the matching dev-team agent; never cross roles.
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


## Commands

- Run code style fixer: `composer lint` (uses Laravel Pint)
- Run static analysis: `composer types:check` (PHPStan level 7)
- Run a specific test class: `./vendor/bin/sail test --filter TestClassName`
- Run tests matching a pattern: `./vendor/bin/sail test --parallel --filter "pattern"`

## Project rules

- Never automatically commit or push unless asked.
- Commit messages should be short with additional context added via list items below the header message
- Commits follow Conventional Commits: `type(scope): summary` where type ∈ feat, fix, docs, refactor, chore, test, build, ci. Header short and imperative; context as list items below.
