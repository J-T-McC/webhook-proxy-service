---
name: review-gates
description: Runnable review gates in this repo/sandbox and the review-doc naming convention
metadata:
  type: reference
---

Backend gates (all runnable): `composer lint` (Pint, emits `{"tool":"pint","result":"passed"}`),
`composer types:check` (PHPStan/Larastan L7), `./vendor/bin/sail test` (PHPUnit;
default output is one JSON line `{"tool":"phpunit",...,"tests":N,"assertions":N}` — read the
task output file, not a verbose summary).

Frontend gates: `pnpm types:check` (vue-tsc --noEmit), `pnpm format:check` (prettier --check
resources/), `pnpm run build` (vite, ~2 s). **The old "build unrunnable, Node 21 < 22" note is
dead** — host Node is v22.23.2 (sail's is v24), satisfying `engines.node >= 22`.

**`pnpm lint:check` (eslint .) NO LONGER exits clean and that is not the branch's fault** — stale
untracked `.claude/worktrees/agent-*` checkouts contribute hundreds of `import/order` errors that
are absent in CI (730 errors / 354 files at review-17). Judge it by the tracked tree, and prove
the noise is entirely external rather than asserting it:
`pnpm lint:check 2>&1 | grep -E '^/Users' | grep -vc '.claude/worktrees'` must print `0`. Then run
`npx eslint resources/` (silent = clean) plus a run scoped to the feature's own files. A completion
note claiming "lint green" is accurate under this reading — do not raise it as a finding.

No JS test framework exists (deferred backlog item T31) — but **a live browser check IS
available and is the right standard for accessible-name findings, and for any client-side
behaviour an AC turns on**; see `codebase_gotchas.md`. At review-07 a live check was what
turned an unverified restore-prefill claim into evidence AND surfaced the one Major nobody's
tests or manual steps covered — when the notes say "manual verification" for a flow, re-drive
the flow, and also drive the *reversal* path the flow's own spec names.

Useful sandbox probes when verifying a claim rather than trusting it:
`git diff main..HEAD -- <path>` + `md5 <file>` vs `git show main:<file> | md5` proves a file is
byte-identical to main; `./vendor/bin/sail exec -T mysql sh -c 'mysql -uroot -ppassword <db> -e "…"'`
reaches MySQL directly (databases: `laravel` dev, `testing` + `testing_test_1..14` parallel);
`./vendor/bin/sail artisan tinker --execute="…"` checks builder/SQL semantics. `timeout` is not
available in this shell — never probe a suspected infinite loop by running it.

`./vendor/bin/sail test --parallel` works and emits `{"tool":"paratest",...}` — use it for
large suites (~4 s vs ~9 s serial).

When a diff stat in a completion note disagrees with yours by a small amount, check whether
the note was written before its own docs commit — usually that, not drift. Say so rather than
raising it.

Severity precedent to stay consistent with: a non-`createQuietly()` factory call is **Minor**
(review-04 #3, re-affirmed at #6 even at 30 sites — the factories set `team_id` explicitly so
the `creating` hook is a no-op); an unimplemented plan §Validation config-sanity invariant is
**Major** (review-05 M-1, review-06 M-2); newly-shipped user-facing copy that promises an
outcome the same screen falsifies on a path the approved design names, with real data loss, is
**Major** even when no AC's literal text is breached and the underlying behaviour is
pre-existing (review-07 Finding 1) — route it to the role whose ruling forbids the fix (there,
the Principal Engineer), not to the Senior Developer.

**Live-check setup corrections (verified 2026-08-29, review-17 re-review) — the old recipe wastes
three round trips without these.** The app serves on **port 80**, not the `APP_URL=http://localhost:8000`
in `.env` (nothing listens on 8000; `curl` port 80 returns 200). Proxy routes are **team-scoped**:
`/{team-slug}/proxies/create`, not `/proxies/create` — get the slug from the team row, or
`artisan route:list --path=proxies` to see the `{current_team}` segment. Seeding a QA user:
`User::factory()->create()` **already creates a personal team** and sets `current_team_id`, so do not
build one — `Team::factory()` fails anyway (`teams` has no `created_by` column). `User` has **no
SoftDeletes**, so `User::withTrashed()` throws; use `find()` + `forceDelete()`, and delete the
`team_members` row plus the team first. Check `public/hot` before trusting a build: when it is absent
`@vite` serves `public/build`, so a host `pnpm build` genuinely is what the browser runs.

**Live-browser tooling:** the project has **no** playwright dependency; the skill at
`/Users/tyson/.claude/skills/playwright` carries its own `node_modules` — run a script with
`cd /Users/tyson/.claude/skills/playwright && node run.js /abs/path/script.js`. Browsers live in
`~/Library/Caches/ms-playwright`, not `~/.cache`.

**Proving a copy/IA pass over a Vue SFC — two techniques that beat reading the diff.**
- *Normalized visible-text diff* settles every copy disposition at once, including the negative
  ("a string ruled CUT still renders somewhere"), which eyeballing cannot. Split the file at
  `<template>`, strip `<!-- -->`, `re.split(r'(<[^>]*>)')`, keep the non-tag runs, collapse
  whitespace, and `difflib.unified_diff` `git show main:<file>` against the working copy. Output is
  exactly the copy delta across the whole branch; every line must map to a design ruling. At
  review-17 this proved all ten cut strings gone, all eight kept strings verbatim, all four tooltip
  bodies verbatim, and the downgrade `Alert` byte-identical — the `Alert` simply never appears in
  the diff, which is the proof.
- *Whitespace-insensitive per-commit diff* separates substance from Prettier reflow. A task that
  adds one wrapper `div` re-indents the entire template: review-17's T1 showed 706 changed lines
  for "Details gets its own Card". `git show <rev> -w --ignore-blank-lines -- <file>` collapsed it
  to six real edits. Run this before believing a large diff hides something, and before believing
  it does not.

**Two verification techniques worth reusing:**
- *Runtime A/B to prove a client fix is causal.* For a one-line frontend fix, "it works now" is
  weak. Use `page.route()` to rewrite the served module and strip the fix out, then re-run the
  same steps: the defect must reproduce. **Nothing on disk changes**, which matters because the
  Reviewer must not modify source (attempting `git show <rev>:file > file` is also blocked by the
  permission classifier — correctly).
- *Proving a comment-only PHP change altered no behaviour.* Strip `T_COMMENT`/`T_DOC_COMMENT`/
  `T_WHITESPACE` via `token_get_all()` from both revisions and compare md5s. Stronger than reading
  the diff, and cheap.

Review docs live in `docs/reviews/`, named `review-<NN>-<feature-slug>.md` (e.g.
`review-02-role-based-collaboration.md`). Format: header (Reviewer/date, Scope, Inputs,
Gates-with-actual-numbers), Summary+recommendation, AC-coverage table, standards checklist,
severity-classified Findings, Recommendation, Handoff.

**Rework is recorded in place, never as a new file** (review-04 precedent, followed at #6):
mark the original recommendation "superseded", append `## Re-review (date)` with a re-run gate
table, one `### Finding — RESOLVED` block per finding stating the evidence, a scope-discipline
check, a `### Re-review recommendation`, and a `- **Project Owner decision / date:** _pending_`
line. Add a `### Re-review handoff` rather than rewriting the original. When the Owner
authorised fixing only *some* findings, tabulate the untouched ones as "what carries forward"
so approval is informed — do not re-raise them as findings.

**Proving a private method's behaviour without touching source:** reflect into it from tinker —
`$c = new ReflectionClass(Foo::class); $m = $c->getMethod('bar'); $m->invoke($c->newInstanceWithoutConstructor(), $args)`.
Turned "I read the code and it discards the field" into decisive evidence at review-10 in one
command, with nothing written to disk. `newInstanceWithoutConstructor()` matters — controllers here
take constructor dependencies you do not want to build.

**Reviewing a REMOVAL milestone is a different job from reviewing an addition: the failure mode is a
survivor, not a defect.** Sweep for the removed vocabulary across `app resources/js routes config
database/factories` in one `grep -rniE`, then classify every hit as (a) deliberately-retained code,
(b) unrelated framework code (Fortify's email verification and 2FA both match `/verif/`), or
(c) a historical comment. Also confirm the *retained* classes an ADR names as most at risk of
over-deletion actually survived — ADR-026 named `StandardWebhooks` by name for this reason.

**A withdrawn upstream section can leave a surviving clause unanchored, and that is a Designer
question, not an implementation defect.** design-10 Screen 4b fixed the Signing card's placement
"alongside the Verification card … before the Destinations table"; Screen 4 was then withdrawn,
leaving two half-clauses that point in different directions. Route the resolution to the Designer.
Check `git show <pre-removal-rev>:<file>` first — the pre-existing sibling often did not honour the
clause either, which makes the deviation inherited rather than introduced.

**Cross-check a required upstream artifact's own status line against `docs/status.md`.** At
review-10 status.md recorded design-10's inbound-withdrawal amendment as approved while the
amendment's own header read "WRITTEN, awaiting Product Manager re-approval". `docs/` governs.
A withdrawal-only amendment whose substance is already Owner-approved via an ADR is a **Minor**
(open paperwork gate), not the Blocker a genuinely missing design spec would be.

**Cheap independent evidence that a rework weakened no existing test: check the suite DELTA accounts
exactly for the new test methods.** At the review-10 re-review, 1016/4809 → 1019/4818 was exactly
the three added methods and nothing else — which proves no test was deleted, skipped or relaxed
without reading the test diff at all. A delta that does not reconcile is the thing to chase.

**Re-verify a fix by re-running the reproduction that raised the finding, then EXTEND it to every
branch of the changed method — not just the branch that was broken.** Finding 4's fix added a
parameter; probing all six branches (existing/no-existing × blank/non-blank name × the
`remove_credential` short-circuit × the defaulted one-arg `store()` call) caught nothing wrong but
is what makes "resolved" a claim rather than a hope. A one-branch re-probe would not have shown that
the safe default survived on `store()`'s path.

**When a fix closes "a surface asserts something the save discards", check the fix did not bump a
timestamp the surface describes.** Screen 3's status line reads "Credential set — changed {date}";
writing `credential_set_at` on a header-name-only edit would have closed the finding while creating
a smaller instance of the same defect class. The right fix persists the sibling field and leaves the
timestamp alone.

**House answer to a retained-but-superseded gate record whose own wording is now wrong:** a
pure-insertion pointer immediately above the stale passage that **quotes the stale wording back at
the reader** and says why it stands unedited — never a rewrite, because a gate record that silently
matches today's requirements is evidence of nothing. design-10's correction B2 ("on **both**
surfaces") is the worked example. Judge the mitigation by whether the pointer is adjacent to the
point of stumble and whether search-arrival paths also carry it.
