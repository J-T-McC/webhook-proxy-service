# Autopilot

An experiment in letting the dev-team agents advance work without a human typing
each step. It drives one work item as far as an open pull request and stops.

Started 2026-08-27 by the Project Owner.

## What it does and does not do

It picks one item from `queue.json`, delegates it to the matching dev-team agent,
runs every gate, opens a pull request, and notifies you. **It does not merge**, it
does not push to `main`, and it does not approve anything.

That boundary is deliberate for the first phase. The point of running it this way
is to find out whether the review agent is genuinely catching problems or
rubber-stamping — and the pull requests it produces are the evidence. Once a few
of them have been read and the answer is clear, `stop_before_merge` can be
turned off with something better than optimism behind the decision.

## The parts

| Path | What it is |
|---|---|
| `.claude/skills/autopilot/SKILL.md` | The driver. Preflight, selection, gates, failure handling, and the list of things that always stop a run. |
| `.claude/automation/queue.json` | The work queue, plus the policy: gates, forbidden paths, forbidden actions, failure limits. |
| `.claude/automation/run-log.jsonl` | One line per run. Append-only. |

`docs/status.md` remains the source of truth for everything. `queue.json` is a
work queue, not a second status file — where they disagree, status.md wins.

## Nothing is eligible by default

An item runs only if you set `eligible: true` on it. That flag is the entire
blast radius, and the driver is told never to set it itself. Adding work means
editing `queue.json` by hand, which is the intended amount of friction.

The queue currently opts in four small chores and one review. It deliberately
excludes item #10 (security, Owner-gated by nature), anything needing an approval
gate, and anything that adds a dependency.

## Stopping it

- **Immediately:** set `"halted": true` in `queue.json` with a `halt_reason`. The
  driver checks this before doing anything else.
- **Permanently:** remove the schedule.
- **By itself:** two consecutive failures halts it and it will not run again
  until a human clears the flag.

## Things it is told never to do

Beyond merging: touching `.env`, CI workflow files, this directory, or the
roadmap; changing repository settings; approving anything on your behalf; editing
an approved PRD, design, plan or ADR to make a task pass; adding a production
dependency; or rerunning CI more than twice for one commit.

## What it is explicitly warned about

Two failure modes specific to this repository, both learned the expensive way:

- **A green suite is weak evidence for queue and delivery changes.**
  `QUEUE_CONNECTION=sync` runs jobs inline under test, so the FIFO acceptance
  suite passes whether or not parallel dispatch is correct. ADR-020's review
  recorded this rather than papering over it, and the driver is required to
  repeat the caveat in any pull request touching that path instead of reporting
  "tests pass" as if it settled the matter.
- **Infrastructure noise is not a code defect.** A CDN timeout during
  `vite build` failed CI on PR #20, a branch with no frontend changes. A human
  saw "unrelated, rerun"; an unattended driver would have started editing code to
  fix a network problem. That specific cause is gone — the font is vendored as of
  `85a496f` — but the class is not, so the driver retries a suspect gate once and
  then reports infrastructure rather than letting an agent "fix" it.

## The backstop it does not provide

Branch protection on `main` requiring CI to pass is **not** configured by this
setup and cannot be — it is a repository setting. Without it, "stops before
merge" is a convention the driver follows rather than a rule the platform
enforces. Set it before running this unattended:

```
gh api -X PUT repos/J-T-McC/webhook-proxy-service/branches/main/protection \
  -H 'Accept: application/vnd.github+json' \
  -f 'required_status_checks[strict]=true' \
  -f 'required_status_checks[contexts][]=ci' \
  -f 'enforce_admins=false' \
  -f 'required_pull_request_reviews=' \
  -f 'restrictions='
```

Squash merges are already the convention here, so any bad merge that does land
reverts as a single commit.
