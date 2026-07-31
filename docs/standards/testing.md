# Testing Standards

> Adopted by Project Owner (2026-07-31). Applies to all new and modified tests.
> Pre-existing kit tests may be migrated opportunistically (see Scope below).

## Quiet factory creation (active)

Tests **must** create model-factory records with `createQuietly()` (and
`createManyQuietly()` where a plural create is used) instead of `create()` /
`createMany()`.

- **Rule:** `Model::factory()->createQuietly(...)`, not
  `Model::factory()->create(...)`, for any factory record built during test
  setup.
- **Rationale:** deterministic test setup with no incidental model-event side
  effects. `create()` fires Eloquent model events (`creating`/`created`/etc.)
  and any listeners/observers registered on them; those firings are irrelevant
  to — and can pollute — the behavior under test (e.g. faked events, queued
  jobs, cascade side effects). `createQuietly()` suppresses them, so a test
  exercises exactly the production path it asserts on and nothing else.

### One interaction to know: `BelongsToCurrentTeam` and quiet creation

`App\Concerns\BelongsToCurrentTeam` auto-assigns `team_id` via a `creating`
model-event hook. `createQuietly()` suppresses model events, so that hook does
**not** fire during quiet creation. This is safe here because the factories are
self-sufficient: they set `team_id` explicitly in their `definition()` —
`ProxyFactory` via `Team::factory()`, and `DestinationFactory` /
`DeliveryAttemptFactory` by deriving it from the parent proxy. `TeamFactory`
likewise sets `slug` explicitly, so `Team`'s slug-generating `creating` hook is
also not relied upon under quiet creation.

Consequences to preserve:

- **Do not** remove or weaken the production `creating` auto-assign behavior in
  `BelongsToCurrentTeam`. Only factories are made self-sufficient. Any factory
  that would otherwise depend on the hook to populate `team_id` must set it
  explicitly (default to the authenticated/current team or a created team).
- The production auto-assign hook must remain covered by tests that use a real
  `new Model(...)->save()` (not a factory), so that suppressing events in
  factory setup never hides a regression in the hook itself. See
  `tests/Feature/TeamScopingTest.php`
  (`test_creating_a_proxy_auto_assigns_the_current_team`,
  `test_creating_a_destination_and_attempt_auto_assigns_the_current_team`).
- Cross-team isolation tests must set distinct `team_id`s explicitly so they
  genuinely prove scoping and cannot pass by accident of a wrong default.

## Scope

- Applies going forward to all item-#1 tests and every new/modified test.
- Pre-existing kit tests (`tests/Feature/Auth/*`, `tests/Feature/Settings/*`,
  `tests/Feature/Teams/*`, example tests) are **not** churned by this rule; they
  may be migrated opportunistically when touched for other reasons. Note that
  some kit tests intentionally rely on model events (e.g. `Team` slug-suffix
  dedup in `tests/Feature/Teams/TeamTest.php`) and must keep `create()` where
  the event is the behavior under test.
</content>
</invoke>
