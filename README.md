# Webhook Proxy Service

Webhooks arrive at one URL and need to reach several places. This service sits in
the middle: it ingests an incoming webhook, stores the payload, and fans it out to
every destination you've configured — with retries, replay, delivery history, and
per-proxy analytics.

Laravel 13 + Inertia + Vue 3, queued through Horizon, MySQL for storage.

## The actual point of this repo

This was an experiment in agentic orchestration: can a team of AI agents take a
product from an empty directory to a working, tested application — doing the
product thinking, the architecture, the planning, the implementation and the
review, each as a separate role with its own remit?

The setup is a plugin defining seven agents — orchestrator, product manager,
designer, principal engineer, task planner, senior developer, reviewer — that hand
work between each other through documents rather than through conversation. An
agent can't skip a gate, can't grade its own homework, and can't invent a
requirement: if something is undecided it writes a question document and blocks.
Approvals that genuinely need a human stay with the human.

I acted as Project Owner. Approving requirements, ruling on trade-offs, occasionally
telling everyone a feature was cancelled.

## Where the interesting part lives

`docs/` is the whole record — not documentation written after the fact, but the
artifacts the work actually flowed through:

- `docs/product/` — PRDs with numbered acceptance criteria
- `docs/design/` — UX specs
- `docs/architecture/` — 27 ADRs, including the ones that reverse earlier calls
- `docs/plans/` and `docs/tasks/` — technical plans, broken into ordered tasks
- `docs/reviews/` — independent review against the acceptance criteria
- `docs/questions/` — where an agent stopped and asked instead of guessing
- `docs/status.md` — routing state for every feature

The failures are in there too. Features cancelled mid-flight, a review gate that
got skipped, deferred findings that never came back. That's more honest about how
this works than a clean history would be.

## Running it

```bash
cp .env.example .env      # set DB_CONNECTION=mysql and uncomment the DB_* lines
composer install
./vendor/bin/sail up -d
composer setup            # migrates, installs pnpm deps, builds assets
./vendor/bin/sail test
```

MySQL is required — a few migrations use MySQL-only DDL deliberately, so SQLite
won't get you a schema. Details and the rest of the stack are in `docs/stack/stack.md`.
