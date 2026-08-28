---
name: autopilot
description: Run one unattended pipeline step from .claude/automation/queue.json — pick the next eligible work item, delegate it to the matching dev-team agent, run the gates, and open a pull request. Use when invoked on a schedule, or when the Project Owner asks to advance automated work.
---

# Autopilot

Drives one work item from `.claude/automation/queue.json` to an open pull request,
and — while the trial grant in `policy.merge_authority` is in force — merges it once
the gates and CI are green.

You are the driver, not the worker. You route, verify and record. The dev-team
agents do the work, because role separation is what makes their output reviewable
— an agent that both writes and reviews produces agreement, not review.

## One run does one item

Do not batch. Do not start a second item because the first was quick. A run that
finishes early is a good run; the schedule will fire again.

## Before anything else — the preflight

Stop immediately, change nothing, and report if any of these hold:

1. `halted` is `true` in `queue.json`. Report `halt_reason` and stop.
2. `consecutive_failures` is at or above `policy.max_consecutive_failures`. Stop.
3. The working tree is dirty, or `git status` shows anything uncommitted. Someone
   is mid-task. Do not stash, and do not commit their work. This is the one
   preflight condition with an escape hatch — see § When the checkout is busy.
4. The current branch is not `main` **and** the working tree is dirty. A previous
   run may have left work behind: report the branch and stop. A *clean* checkout
   sitting on another branch is not a blocker — switch to `main` and carry on.

Preflight failures are not gate failures. They mean "a human left something in
an unexpected state", so do **not** increment `consecutive_failures` for them.

### Reconcile the queue before you trust it

`queue.json` lives in the repository, so a state change written on a feature branch
is invisible on `main` until that branch merges. Reconcile first:

1. For every item whose `state` is `awaiting_merge` and which carries a
   `pull_request` number, run `gh pr view <n> --json state`. `MERGED` means set the
   item to `done`. `CLOSED` without merging means set it back to `ready`.
2. Treat any item with an **open** pull request against it as in flight, whatever
   `queue.json` says on `main`. Never select one.

Without this, a run reads `main`, sees an item it already finished still marked
`ready`, and does the whole thing a second time. GitHub is the authority on what is
in flight; `queue.json` on `main` lags it by one merge.

### When the checkout is busy

Another agent may hold the primary checkout — a Product Manager writing a PRD, a
long review in progress. The run is still legitimate; only the tree is occupied.
Use a git worktree:

```
git worktree add /tmp/autopilot-<item-id> -b <branch> main
```

**The worktree path must be outside the repository.** A worktree placed inside the
repo is gitignored but *not* ignored by ESLint, Prettier, PHPStan or Pint, so every
gate that walks the tree lints the second checkout and fails on files that have
nothing to do with the change. This has already happened once: 365 ESLint errors,
none of them in the project's own source. Remove the worktree once the branch is
pushed.

A worktree has no `vendor/` and no `node_modules/` of its own, so an item whose
gates need a real dependency tree cannot run in one. Those items wait for the
primary checkout.

## Selecting work

From `items`, take the first entry where `eligible` is `true`, `state` is `ready`,
and no open pull request references it (see § Reconcile the queue before you trust
it). If there is none, report "queue empty" and stop — this is a success, not a
failure.

Never promote an item to `eligible: true` yourself. Eligibility is the Project
Owner's opt-in and it is the only thing bounding your blast radius.

## Running the item

1. Branch from `main`. Name it for the item: `chore/<id>`, `docs/<id>`,
   `fix/<id>` as its `kind` suggests.
2. Set the item's `state` to `in_progress` in `queue.json` and commit that
   alone, so a crashed run is visible in git rather than silent.
3. Delegate to the dev-team agent named in `role`, via the Agent tool. Pass it
   the item's `title`, `acceptance`, `note` and `source`. **Do not do the work
   yourself** — not even a one-line change that looks faster than delegating.
4. Read what the agent produced. If it went outside the item's scope, or touched
   anything in `policy.forbidden_paths`, revert the branch and record a failure.

## The gates

Run every command in `policy.gates`. All must pass. Nothing is skipped because it
"can't be affected by this change" — that judgement is exactly what an unattended
run is worst at.

**Run them yourself against the committed tree.** An agent's report that the gates
passed is a claim, not evidence, and the agent has an interest in the answer.

**Distinguish a real failure from infrastructure noise.** A gate that fails for a
reason unconnected to the diff — a network timeout, a registry 5xx, a container
that would not start — is not a defect in the work. Retry that gate **once**. If
it fails the same way twice, stop and report it as infrastructure, not as a code
failure, and do not let an agent "fix" the code to make it pass.

Before blaming the diff, check whether the failing paths are even part of it. A
gate that reports errors only in files the change never touched is describing the
environment, not the work.

Two known traps in this repository:

- **A green suite is weak evidence for queue and delivery changes.**
  `QUEUE_CONNECTION=sync` runs jobs inline under test, so the FIFO acceptance
  suite passes whether or not parallel dispatch is correct. If the diff touches
  `app/Actions/DeliverStep.php`, `AdvanceProxyFifoQueue`, `DeliveryUnitResolver`
  or anything in the delivery path, say so explicitly in the pull request body
  rather than reporting "tests pass" as though it settled the question.
- **The suite only runs under `./vendor/bin/sail test`.** The full migration set
  contains MySQL-only DDL that SQLite rejects.

## Finishing

1. Commit with a Conventional Commits message: `type(scope): summary`, short
   imperative header, context as list items below. Write it normally — the
   repository's terse-communication rule explicitly carves out commit messages.
2. Push the branch and open a pull request against `main`. The body states what
   changed, what the acceptance criterion was, which gates ran and their results,
   and anything you could not verify. Never claim verification you did not do.
3. Set the item's `state` to `awaiting_merge`, record the `pull_request` number,
   reset `consecutive_failures` to `0`, and commit `queue.json`.
4. Append one line to `.claude/automation/run-log.jsonl`: the item id, the branch,
   the pull request number, the gate results, and the outcome. **Append only** —
   never rewrite an existing line. When a rebase conflicts here, the resolution is
   always to keep both sides in run order; there is no case where a logged run
   should disappear.
5. **Merge, while `policy.merge_authority` says the trial grant is in force.** Wait
   for CI to report success on the pull request itself — a green local gate run is
   not the same thing — then squash-merge and delete the branch, rebasing onto
   `main` first if it has moved. Never merge on a red or pending check, and never
   enable auto-merge as a way around waiting for one.
6. Notify the Project Owner with the pull request URL and a short summary of what
   changed and what the evidence for it was.

If `policy.merge_authority` is absent or has been withdrawn, stop after step 4,
leave the pull request open, and say plainly in the notification that it is waiting
on a human.

## On failure

Set the item's `state` back to `ready`, increment `consecutive_failures`, append
the failure to the run log with the decisive error line — the shortest line that
identifies the problem, not the whole log — and commit `queue.json`. Leave the
branch in place so it can be inspected; do not force-push over it or delete it.

Notify the Owner. If `consecutive_failures` reaches
`policy.max_consecutive_failures`, set `halted` to `true` with a `halt_reason`
and say clearly in the notification that the driver is now halted and will not
run again until a human clears it.

## Things that always stop the run

Stop, report, and change nothing further if any of these appear. None of them
are yours to decide:

- The work turns out to need an Owner approval gate — a PRD, a release, a new
  dependency, a stack or data-model change, anything security-related, or
  anything irreversible. **The trial's merge grant does not reach these.** It lets
  you land work that is already authorised; it does not let you authorise work.
- An approved artifact (PRD, design, plan, ADR) is wrong, or conflicts with the
  code. Raise a question document to the owning role following the
  `docs/questions/` convention and stop. **Never edit an approved artifact to
  make a task pass.**
- The item is materially bigger than its queue entry describes.
- Anything requires a credential, or would send data to a third party.
- You are about to guess at a requirement. `CLAUDE.md` is explicit: never invent
  requirements.

## Keeping the record straight

`docs/status.md` is maintained by the Orchestrator and is the source of truth.
When a run changes something status.md tracks, update it through the orchestrator
skill in the same run — but remember `queue.json` is only a work queue, not a
second status file. If the two disagree, `docs/status.md` wins.

Record deviations, not just outcomes. If you departed from this document — batched
two items, drove from the main conversation rather than the skill, accepted an
agent's work that went wider than its brief — write that into the run log with the
reason. The trial's value is in the deviations; a log that only records successes
teaches nothing.

Everything written into `docs/` is written normally: full sentences, no dropped
articles. That carve-out exists because these documents are read as evidence long
after the run that produced them, by agents that were not there.
