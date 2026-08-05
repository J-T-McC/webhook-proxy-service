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
`pnpm lint:check` (eslint .), `pnpm format:check` (prettier --check resources/).
`pnpm run build` is NOT runnable in this sandbox (Node 21 < required 22) — Vue SFCs are
validated by vue-tsc + eslint instead; note this env limitation rather than treating build as failed.

No JS test framework exists (deferred backlog item T31) — Vue/a11y is inspection-only.

Useful sandbox probes when verifying a claim rather than trusting it:
`git diff main..HEAD -- <path>` + `md5 <file>` vs `git show main:<file> | md5` proves a file is
byte-identical to main; `./vendor/bin/sail exec -T mysql sh -c 'mysql -uroot -ppassword <db> -e "…"'`
reaches MySQL directly (databases: `laravel` dev, `testing` + `testing_test_1..14` parallel);
`./vendor/bin/sail artisan tinker --execute="…"` checks builder/SQL semantics. `timeout` is not
available in this shell — never probe a suspected infinite loop by running it.

Review docs live in `docs/reviews/`, named `review-<NN>-<feature-slug>.md` (e.g.
`review-02-role-based-collaboration.md`). Format: header (Reviewer/date, Scope, Inputs,
Gates-with-actual-numbers), Summary+recommendation, AC-coverage table, standards checklist,
severity-classified Findings, Recommendation, Handoff.
