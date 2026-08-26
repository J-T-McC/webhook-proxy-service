---
name: codebase-gotchas
description: Non-obvious authorization/serialization traps to check when reviewing proxy work
metadata:
  type: reference
---

- **`ProxyController::store()` has no `$this->authorize('create', …)` call** — it relies on
  `EnsureTeamMembership` middleware + the fact that every TeamRole holds `CreateProxy`. The
  `create()` form action DOES authorize. No denial vector today, but it deviates from the
  "controller calls authorize" standard and from `StoreProxyRequest`'s own docblock. Pre-existing
  from item #1. Re-check if a future role is added that omits `CreateProxy`.
- **`ProxyResource` `can:{update,delete}` flags call the policy per row** (`$user->can(...)` →
  `hasTeamPermission($proxy->team, …)`). The index query does not `with('team')` and `teamRole`
  is not memoized, so the index page is an N+1 (bounded by page size 15). Watch for this pattern
  spreading to other resources.
- **`createQuietly()` suppresses model `creating` hooks** — so `HasCreator`/`BelongsToCurrentTeam`
  auto-assignment does NOT fire in factory-built records; tests set `created_by`/`team_id`
  explicitly. The auto-assign hooks are covered separately by real `new Model()->save()` tests.
- **`max:` on a string rule counts multibyte CHARACTERS, not bytes** — so a validation rule
  built from a `*_max_bytes` config (e.g. `response_body` → `max:config('ingest.response_body_max_bytes')`)
  lets a UTF-8 value exceed the intended byte cap by up to ~4×. Flag as a Minor whenever a byte-named
  cap feeds a string `max:` rule; a byte-exact check needs a custom rule.
- **`WithoutOverlapping` job middleware defaults to no TTL** (`expiresAfter = 0`) — on an ungraceful
  worker crash (SIGKILL/OOM) the Redis lock leaks forever. In the FIFO advancer (`AdvanceProxyFifoQueue`)
  this permanently stalls a proxy's line: the sweeper reaps the DB claim but its re-dispatched advancer
  is gated by the same leaked lock. Whenever `WithoutOverlapping` guards a self-dispatching/scheduled
  job, require an explicit `->expireAfter(...)` (align to the claim lease). Raised as Major in review-04.
- **`(int) env('SOME_KEY', $default)` in a config file has NO lower bound** — a blank line
  (`KEY=`) or a non-numeric value casts to `0`, silently replacing the default (verified in this
  repo: both blank and non-numeric resolve to `0`). Wherever a config int drives a destructive or
  looping operation, check for a clamp/reject at the resolution point. Two concrete shapes in
  `config/retention.php`: a `days` value of 0 makes a retention cutoff equal `now()` (mass
  irreversible erasure), and a batch-size of 0 makes Laravel emit a literal `LIMIT 0` (0 rows),
  which hangs any `do { … } while (count($ids) === $batchSize)` loop forever. Raised as Major in
  review-05; the house remedy adopted there is the pattern to expect elsewhere — a fail-loud
  `RuntimeException` naming key + value at the *single* seam that reads the key, plus threading
  the validated value in as a parameter so the loop body is structurally unreachable rather than
  guarded from inside. When checking such a guard is total, grep the key repo-wide (must have one
  read site) and confirm no callee re-reads config.
- **A guard test whose failure mode is an infinite loop HANGS instead of failing.** Tests that
  prove a loop is never entered (`DB::listen` query-count === 0) are genuine, but if the guard
  regresses the suite spins forever rather than reporting red. Worth a Nit whenever you see one.
- **Laravel releases the `withoutOverlapping()` scheduler mutex in a `finally`**
  (`Event::runCommandInForeground()`), and `Schedule::command()` runs in a child process — so a
  scheduled command that throws does NOT leave a stuck lock blocking later runs. Check this before
  objecting to a "fail loudly" posture in a scheduled job.
- **`DB::listen()` is a legitimate fault-injection / race seam and is NOT tautological.**
  `Connection::run()` dispatches `QueryExecuted` synchronously *after* the statement executes but
  *before* the caller returns, so a listener can (a) throw to fail a specific statement mid-transaction
  and prove real rollback, or (b) mutate state in the exact window between a `SELECT` and a following
  `UPDATE` to prove a compare-and-set. Match on `$query->sql`. Caveat to record: under the suite's
  `FasterRefreshDatabase` the inner transaction is a savepoint, so it proves `ROLLBACK TO SAVEPOINT`.
- **`ApplyTeamScope` registers `TeamScope` on only three models** (`Proxy`, `Destination`,
  `DeliveryAttempt`) and only for the duration of a team-scoped request — it is NOT a global model
  scope. `WebhookEvent`/`DispatchedPayload` are never scoped, so worker-path Eloquent queries on
  them need no `withoutGlobalScope()`; a factory that adds one is defensive, not required.
- **`Actions::registerCommands()` (laravel-actions) registers only classes declaring a
  `commandSignature` property** — adding it to `routes/console.php` to make `Schedule::command()`
  resolve does not accidentally expose other `AsAction` classes as Artisan commands. Verify with
  `artisan list` when it first appears.
- **`ORDER BY id LIMIT n` on a GC/batch selection adds `Using filesort`** even when the intended
  composite index is chosen — MySQL materialises and sorts the whole candidate set before the LIMIT.
  Ordering by the index's trailing column instead keeps the same plan without the filesort. Verify
  optimizer claims with a populated scratch table + `ANALYZE`; an EXPLAIN on an empty table picks
  `PRIMARY` and is worthless evidence.
- **A partial config-sanity guard is the recurring shape of this repo's config bug.** The
  house remedy (fail-loud `RuntimeException` naming key + value at the single read seam) tends
  to get applied to the keys a task's Description enumerates and skipped on the rest, with a
  docblock explicitly calling the remainder "engineering constants… read plainly". Whenever a
  resolver class implements it, enumerate EVERY `config('<ns>.*')` int it reads and check each
  one — the unguarded ones are where the zero-value collapse lives (found in `RetryPolicy`:
  a blank `*_max_delay_seconds` makes `min($delay, 0)` zero out every backoff). Probe with
  `artisan tinker --execute="config([...]); …"` rather than arguing it theoretically.
  **Enumerate by config NAMESPACE, not by class** — grep `(int) config(` across `app/` in one
  pass. Scoping the sweep to the resolver class misses sibling keys read elsewhere (missed
  `retry.sweep_grace_seconds` in `SweepDueRetries` this way at review-06).
- **Severity test for an unguarded config int: does zero COLLAPSE an invariant, or merely
  remove a margin?** Collapse (backoff → 0, cutoff → `now()`, `LIMIT 0` loop) breaches an AC or
  destroys data ⇒ **Major**. Losing an anti-race grace period whose race is already arbitrated
  by a unique key, with the residual cost already accepted in the plan's Risk list ⇒ **Minor**.
  Say which invariant survives, or the grading looks inconsistent with the neighbouring Major.
- **`reka-ui` `CheckboxRoot` renders `<button role="checkbox">`** (`as` defaults to `"button"`),
  so wrapping it in `<Label>` gives it NO accessible name — HTML-AAM's name chain for `button`
  is aria-labelledby → aria-label → subtree → title, and the indicator icon is the whole
  subtree. Same trap applies to any Reka primitive rendering a button with a role. Require an
  explicit `aria-label`/`aria-labelledby`; `aria-label` on `<Checkbox>` DOES reach the button
  (CheckboxRoot reads `$attrs["aria-label"]`; the wrapper SFC is single-root with no
  `inheritAttrs: false`) — confirmed live, name computes correctly. `Login.vue`'s "Remember me"
  still has the un-named-checkbox bug (pre-existing, unfixed as of 2026-08-22). a11y is the one
  class of frontend defect the automated gates cannot catch — everything else is covered by
  `vue-tsc` + server-side Inertia prop assertions.
- **Never accept a source trace as proof of a computed accessible name — do the live check.**
  An accessible name is computed by the browser from HTML-AAM's chain; the whole reason such
  findings arise is that markup which reads correctly to a competent reviewer computes to
  empty. Accepting a trace re-runs the reasoning that produced the defect. Recipe that works
  here: `pnpm run build` FIRST (checked-in `public/build` is routinely months stale and a live
  check against it proves nothing — grep the bundle for your new string to confirm freshness;
  `/public/build` is gitignored so rebuilding is safe), then Playwright headless →
  `locator.ariaSnapshot()` plus `getByRole('checkbox', { name, exact: true })` counts and an
  `{ name: '', exact: true }` count to prove zero unnamed controls. `page.accessibility.snapshot()`
  is REMOVED in the installed Playwright — use `ariaSnapshot()`.
- **Logging into the local app for a live check.** Forging a Laravel session cookie does not
  work (tried: encrypter + `CookieValuePrefix` + DB session row → still bounced to `/login`).
  What works: temporarily swap user 1's password, drive the real login form, then restore the
  saved hash. Trap: `User::casts()` has `'password' => 'hashed'`, so `Hash::make()` +
  `forceFill` **double-hashes** and login fails silently — assign the PLAIN string. Also
  `waitForURL('**/dashboard')`, not `waitForLoadState('networkidle')`, which races an Inertia
  redirect and reads the stale URL. Dev DB is `laravel` on sail; `QUEUE_CONNECTION=redis` with
  no worker container running, so exercising a write flow enqueues without sending real
  outbound HTTP. Revert everything after (rows, queue keys `laravel-database-queues:*`, password).
- **A design spec can be internally non-univocal** — check the Flows section against the
  Screens section before ruling a Flow/plan clash a genuine spec conflict. design-06's Flow D
  said "no navigation away" while its own Screen 4 delegated the mechanism to the implementer;
  the real defect was the redirect *destination*, not the redirect. Resolve inside the spec's
  own text where possible rather than escalating an amendment.
- **A Post/Redirect/Get target must be the page that owns the affordance** (`back()` or the
  originating route), never a parent page — `to_route('proxies.show')` from an events-page
  action strands the user away from the state they just changed.
- **Authorization idiom:** every proxy/team decision is a Policy gating on `TeamPermission` via
  `$user->hasTeamPermission($team, …)`; a role literal (`role === Member`) in a policy/controller
  is a standards violation (permission-based, never role-based). Ownership is a second axis modeled
  as `-any` bundle permissions, not a role check.
