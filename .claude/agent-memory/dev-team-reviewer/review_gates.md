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

Frontend gates (all runnable, clean exit): `pnpm types:check` (vue-tsc --noEmit),
`pnpm lint:check` (eslint .), `pnpm format:check` (prettier --check resources/),
`pnpm run build` (vite, ~1 s). **The old "build unrunnable, Node 21 < 22" note is dead** —
host Node is now v22.23.2 (sail's is v24), satisfying `engines.node >= 22`. Verified 2026-08-22.

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

**Live-browser tooling:** the project has **no** playwright dependency; the skill at
`/Users/tyson/.claude/skills/playwright` carries its own `node_modules` — run a script with
`cd /Users/tyson/.claude/skills/playwright && node run.js /abs/path/script.js`. Browsers live in
`~/Library/Caches/ms-playwright`, not `~/.cache`.

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
