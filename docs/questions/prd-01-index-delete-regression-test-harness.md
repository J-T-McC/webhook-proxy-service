# Question: Regression test for the Index-table delete fix needs a frontend test harness

- **Status:** RESOLVED — **Option B** (Project Owner, 2026-07-31)
- **Raised by:** Senior Developer
- **Owner (must answer):** Principal Engineer *(test-infrastructure / dependency
  decision)*
- **Raised:** 2026-07-31
- **Resolved:** 2026-07-31
- **Gates:** Only deliverable #2 (regression test) of the Index-delete bug fix.
  The code fix itself is complete and verified (see below); it is **not** gated.

## Resolution (Project Owner, 2026-07-31)
**Option B — ship the fix now with documented manual verification; defer the
frontend test harness (Vitest + `@vue/test-utils`) to the backlog.** No frontend
test tooling is introduced by this surgical bug fix, and no duplicative PHP test
is added. The delete fix ships on its already-green verification (see below), with
a documented manual-verification step recorded against the Index task (T27
completion notes, `docs/tasks/walking-skeleton-tasks.md`). The automated frontend
regression guard is explicitly deferred to the deferred/backlog frontend harness
task **T31** (see `docs/status.md` item-#1 routing detail and T31 in the task
plan), which is where this specific Index-delete row-delete regression test is to
be written once a Vue/JS test runner exists. This question is closed; no upstream
gate reopens.
- **Source:** Index-delete defect (status.md, 2026-07-31) + task constraints
  ("minimal, surgical", "Do NOT change dependencies") + `docs/standards/testing.md`

## Context
The Index-table Delete defect is fixed in
`resources/js/pages/proxies/Index.vue`. Root cause was a **Vue template wiring
race**: the dialog's `open` state and its target data were the same ref
(`deleteTarget`). reka-ui's `AlertDialogAction` auto-dismisses on click, which
synchronously fires `@update:open(false)` → `deleteTarget = null`, racing/beating
`confirmDelete()`, which then hits its `if (!target) return` guard and never calls
`router.delete`. The fix decouples an `open` boolean from the target data, matching
the working pattern in `resources/js/pages/proxies/Show.vue`.

Verified green: `pnpm lint:check`, `pnpm types:check` (vue-tsc), `pnpm format:check`,
`composer lint` (Pint), `composer types:check` (PHPStan), and
`./vendor/bin/sail test --filter ProxyDestroyTest` (3 passed).

## Question
The task and status.md ask for "a regression test proving row-delete removes the
proxy," to run under `./vendor/bin/sail test`, and note the backend
`ProxyDestroyTest` "didn't catch this." These pull in opposite directions:

1. This defect is **frontend-only** — it lives in the interaction between the
   template's `AlertDialogAction` auto-close and `confirmDelete()`. It only
   manifests in a rendered DOM. A PHP/PHPUnit (sail) test cannot exercise it; the
   only thing a sail test can assert is the `DELETE proxies.destroy` route, which
   `ProxyDestroyTest` **already** covers identically. A new PHP test would be
   duplicative theater that would not have caught (and would not guard against)
   this regression.
2. The repo has **no** frontend test harness — no Vitest / Jest / `@vue/test-utils`
   / DOM environment; `package.json` has no `test` script. A genuine regression
   test requires mounting `Index.vue` with the real reka-ui `AlertDialog`,
   simulating row-Delete → confirm, and asserting `router.delete` fires.
3. Standing up that harness (new devDependencies: a test runner + `@vue/test-utils`
   + a DOM env, plus config, `@/` alias resolution, Inertia/wayfinder mocks, and a
   `test:js` script) is an org-level infrastructure decision and **directly
   conflicts** with this task's stated constraints ("minimal, surgical", "Do NOT
   change dependencies"). It is not something I should introduce unilaterally.

**Which do you want?**
- **(A)** Approve a minimal frontend test harness (Vitest + `@vue/test-utils` +
  DOM env + `test:js` script) as scope for this fix (or a fast follow-up), so I can
  write a component test that genuinely reproduces/guards the bug. This also
  establishes frontend testing as a reusable capability. Note: it would **not** run
  under `./vendor/bin/sail test` (that is PHPUnit); CI wiring would need updating.
- **(B)** Accept that no automated test guards this specific frontend wiring for
  now — record it as a documented manual verification step — because introducing
  frontend tooling is out of scope for a surgical bug fix. I would add a brief note
  and leave the deliverable explicitly deferred to a harness task.

## Impact if unresolved
The code fix ships regardless. Only the automated regression guard is blocked:
under current constraints I can produce either a duplicative PHP test that does not
actually guard the defect (not recommended) or nothing until direction is given.
