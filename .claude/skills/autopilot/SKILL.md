---
name: autopilot
description: Run one unattended pipeline step from .claude/automation/queue.json — pick the next eligible work item, delegate it to the matching dev-team agent, run the gates, open a pull request, and stop before merging. Use when invoked on a schedule, or when the Project Owner asks to advance automated work.
---

# Autopilot

Drives one work item from `.claude/automation/queue.json` as far as an open pull
request, then stops. **You never merge.** A human merges.

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
   is mid-task. Stop — do not stash, do not commit their work.
4. The current branch is not `main`. A previous run may have left work behind.
   Report the branch and stop.
5. There is an open pull request authored by a previous autopilot run. One
   unmerged pull request at a time; a human has to clear it before you queue
   another. Dependabot's pull requests do not count.

Preflight failures are not gate failures. They mean "a human left something in
an unexpected state", so do **not** increment `consecutive_failures` for them.

## Selecting work

From `items`, take the first entry where `eligible` is `true` **and** `state` is
`ready`. If there is none, report "queue empty" and stop — this is a success, not
a failure.

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

**Distinguish a real failure from infrastructure noise.** A gate that fails for a
reason unconnected to the diff — a network timeout, a registry 5xx, a container
that would not start — is not a defect in the work. Retry that gate **once**. If
it fails the same way twice, stop and report it as infrastructure, not as a code
failure, and do not let an agent "fix" the code to make it pass.

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
3. Set the item's `state` to `awaiting_merge`, reset `consecutive_failures` to
   `0`, and commit `queue.json`.
4. Append one line to `.claude/automation/run-log.jsonl`: the item id, the branch,
   the pull request number, the gate results, and the outcome.
5. Notify the Project Owner with the pull request URL and a one-paragraph summary.

**Then stop.** Do not merge. Do not enable auto-merge. Do not mark the item done
— merging is what makes it done, and a human does that.

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
  anything irreversible.
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

Everything written into `docs/` is written normally: full sentences, no dropped
articles. That carve-out exists because these documents are read as evidence long
after the run that produced them, by agents that were not there.
