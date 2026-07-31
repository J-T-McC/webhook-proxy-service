# Fix: team-scope-global-to-middleware

- **Date:** 2026-07-31
- **Author:** Senior Developer
- **Reported by:** Project Owner

## Problem
Team read-scoping was registered as an always-on Eloquent **global scope** on the
three team-owned models (Proxy, Destination, DeliveryAttempt) via the
`BelongsToCurrentTeam` trait boot. Because it fired on every query for those models
regardless of route context, it constrained default framework routes that carry no
team context (e.g. the settings routes, which use a `{team}` param, not
`{current_team}`), breaking them. The ingest path and token service had to work
around the always-on scope with `withoutGlobalScope(TeamScope::class)` calls.

Expected: the current-team constraint should apply **only** on the team-scoped
route group (`{current_team}` prefix), and nowhere else.

## Cause
The scope was global (model-level), so it applied everywhere the models were
queried instead of only where a team context exists.

## Fix
Moved team scoping from an always-on global model scope to a request-scoped
middleware applied selectively on the team-scoped route group.

- `app/Concerns/BelongsToCurrentTeam.php` — removed the
  `static::addGlobalScope(new TeamScope)` registration; kept the `creating`
  `team_id` auto-assignment (AC5/AC15) untouched.
- `app/Http/Middleware/ApplyTeamScope.php` — **new.** Registers `TeamScope` on the
  three models for the duration of the request, then strips **only** `TeamScope` in
  a `finally` block (via `Model::getAllGlobalScopes()` / `setAllGlobalScopes()`),
  preserving the SoftDeletes scope. Removal matters because Eloquent global scopes
  live in a shared static and would otherwise leak into later requests in the same
  process (tests, queue workers, Octane).
- `bootstrap/app.php` — registered `ApplyTeamScope` **before** `SubstituteBindings`
  in the middleware priority list
  (`$middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ApplyTeamScope::class)`).
- `routes/web.php` — added `ApplyTeamScope::class` to the `{current_team}` group
  middleware (after `EnsureTeamMembership`, which switches `current_team_id`).
- `app/Http/Controllers/IngestController.php` and
  `app/Services/IngestTokenService.php` — removed the now-redundant
  `withoutGlobalScope(TeamScope::class)` calls (the ingest path is naturally
  unscoped now; SoftDeletes / `withTrashed()` behavior unchanged).

### Middleware-ordering solution
Route-model binding must still 404 on cross-team ids, which requires the scope to be
active when `SubstituteBindings` runs its lookup query. Laravel sorts a route's
middleware by the framework middleware-priority list, and `SubstituteBindings` is
already in it. Registering `ApplyTeamScope` immediately **before**
`SubstituteBindings` in that list (via `prependToPriorityList`) guarantees the
ordering deterministically regardless of assignment order on the group. Ordering
relative to `EnsureTeamMembership` is not load-bearing: `TeamScope` reads
`Auth::user()->current_team_id` lazily at query-execution time, by which point
`EnsureTeamMembership` has already switched the team.

## Verification
- `composer lint` (Pint): passed.
- `composer types:check` (PHPStan level 7): passed, 0 errors.
- `./vendor/bin/sail test`: full suite **185 passed** (636 assertions).
- Reworked `tests/Feature/TeamScopingTest.php` to the new model:
  - team-owned models carry **no** global read scope by default;
  - default queries span all teams (unscoped) outside the middleware;
  - inside `ApplyTeamScope` queries filter to the current team and cross-team
    `find()` returns null;
  - fail-closed sentinel (team_id `?? 0`) for an authenticated team-less user;
  - the scope is **removed** after the request (process isolation).
- Route-level proof carried by existing tests:
  `ProxyIndexShowTest::test_cross_team_show_returns_404` (route-model binding 404s
  cross-team — proves the ordering-before-SubstituteBindings contract),
  `ProxyIndexShowTest::test_index_lists_only_the_current_teams_proxies` (filtering),
  `ProxyDestroyTest` / `DestinationDestroyTest` (cross-team 404 on destroy),
  `IngestControllerTest` (token ingest resolves across teams, unknown/soft-deleted
  still 404), and the settings/team suites (non-team routes unaffected).

## Follow-ups
None.
