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
available and is the right standard for accessible-name findings**; see `codebase_gotchas.md`.

Useful sandbox probes when verifying a claim rather than trusting it:
`git diff main..HEAD -- <path>` + `md5 <file>` vs `git show main:<file> | md5` proves a file is
byte-identical to main; `./vendor/bin/sail exec -T mysql sh -c 'mysql -uroot -ppassword <db> -e "…"'`
reaches MySQL directly (databases: `laravel` dev, `testing` + `testing_test_1..14` parallel);
`./vendor/bin/sail artisan tinker --execute="…"` checks builder/SQL semantics. `timeout` is not
available in this shell — never probe a suspected infinite loop by running it.

`./vendor/bin/sail test --parallel` works and emits `{"tool":"paratest",...}` — use it for
large suites (~4 s vs ~9 s serial).

Severity precedent to stay consistent with: a non-`createQuietly()` factory call is **Minor**
(review-04 #3, re-affirmed at #6 even at 30 sites — the factories set `team_id` explicitly so
the `creating` hook is a no-op); an unimplemented plan §Validation config-sanity invariant is
**Major** (review-05 M-1, review-06 M-2).

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
