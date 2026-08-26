---
name: config-sanity-guards
description: where to place fail-loudly validation for numeric config knobs (team-keyed resolver vs. action/command entry) and how to test blank/non-numeric env rejection
metadata:
  type: feedback
---

When a plan/PRD states a config-sanity invariant for numeric env-backed config (e.g. "must be a
positive integer, never allow a resolved value of zero or less"), placement of the guard should
follow the value's own resolution seam, not one central validator file:

- If the codebase already has a single designated resolver method for that value (e.g.
  `RetentionPolicy::windowFor(Team)` for `retention.days` — team-keyed, V5/V6 extension point),
  guard **there**. Every consumer already converges on it, so one guard covers all call sites.
- If a value is read only by one action/command and by nothing else (e.g. `purge_batch`,
  `dispatch_horizon_minutes` in `PurgeExpiredPayloads`), guard once at **entry** (top of `handle()`),
  before any side-effecting work starts, and thread the validated value down as a parameter rather
  than re-reading `config()` deeper in the call stack. This makes an unsafe value structurally
  unreachable in a loop terminator, not just defensively checked inside it.
- Fail loudly (throw, e.g. plain `RuntimeException` naming the config key and the bad value) rather
  than silently substituting the documented default — a silent fallback can mask an operator's
  genuinely different intended value, especially ahead of an irreversible operation (GC/erasure).
- Test the exact failure modes named in review/plan text, not just "0" and "-1": reproduce a blank
  env value and a non-numeric env value via `putenv('KEY=')` / `putenv('KEY=not-a-number')` +
  `require base_path('config/....php')`, mirroring the existing `*ConfigTest` pattern in this repo,
  then `Config::set(...)` the resolved (already-cast-to-0) value and assert the guard rejects it.
- For an "infinite loop terminator" claim (e.g. `while (count($ids) === $batchSize)` with
  `$batchSize === 0`), the strongest proof is not letting the loop run — assert via `DB::listen()`
  that the query inside the loop body never executes before the guard's exception propagates.

See [[laravel-actions-gotchas]] for the related `AsCommand`/`Actions::registerCommands()` gotcha in
the same feature.

**Guard every numeric config key a formula reads, not just the ones a task/plan named at
authoring time** — review-06 Major 2 (`RetryPolicy::exponentialDelaySeconds()`) found two of six
`config('retry.*')` keys deliberately excluded from the guard as "engineering constants," but a
blank/non-numeric env value for either still casts to `0` via a bare `(int) config(...)`, and
`min($delay, 0)` (or any multiply/divide-by-config formula) silently collapses the whole
computation to zero or infinity rather than throwing. When adding a config-sanity guard, audit
every `config('...')` read inside the same formula/method, not just the subset the originating
task text enumerates — the exclusion itself is usually the defect, not a deliberate scope
boundary.
