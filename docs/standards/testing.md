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

## Database refresh (active)

Test classes **must not** declare the `RefreshDatabase` (or `FasterRefreshDatabase`)
trait themselves. Database migration + per-test transaction rollback is provided
**globally** by the base `Tests\TestCase`, which uses `FasterRefreshDatabase`,
which in turn uses Laravel's `RefreshDatabase`.

- **Rule:** no `use RefreshDatabase;` / `use FasterRefreshDatabase;` in individual
  test classes. Extend `Tests\TestCase` and you get rollback for free.
- **Sole owner:** `tests/FasterRefreshDatabase.php` is the only place the
  `RefreshDatabase` trait is used. **Do not** remove `use RefreshDatabase;` from
  it — that trait supplies `beginDatabaseTransaction()` / `refreshTestDatabase()`;
  stripping it silently disables rollback and tests pollute each other
  (duplicate-key failures across cases).
- **Rationale:** one place to configure DB behavior; no per-class boilerplate; no
  risk of a class forgetting the trait and leaking state.

## Test layout and naming (active)

> Adopted 2026-08-30, replacing no prior written rule. The layout below is what
> the suite was reorganised to; it is recorded here so that the next test lands
> in an obvious place instead of starting a fourth naming convention.

### Where a test goes

`tests/Unit/` **mirrors the application namespace.** A test for
`App\Services\RetryPolicy` is `tests/Unit/Services/RetryPolicyTest.php`, a test
for `App\Policies\ProxyPolicy` is `tests/Unit/Policies/ProxyPolicyTest.php`. If
you cannot name the single application class under test, the test is not a unit
test and does not belong here. A test that issues an HTTP request, drains a
queued job, or asserts on rendered Inertia props is a feature test even when it
targets one class.

`tests/Feature/` **groups by feature area, not by application namespace.** This
follows Laravel's own starter kits, which ship
`tests/Feature/Auth/AuthenticationTest.php` rather than
`tests/Feature/Http/Controllers/Auth/AuthenticationTest.php`. Mirroring the
namespace was considered and rejected here for a concrete reason: a feature test
such as `tests/Feature/Ingest/IngestFanOutTest.php` exercises `IngestController`,
`ProcessIngestedWebhook`, `DeliverToDestination` and three models in one pass, so
there is no single application class whose directory could own it. Under a
mirroring rule that choice is arbitrary, and the next person chooses differently.

### Naming a feature test

A feature test class falls into exactly one of two kinds. Decide which before
naming it.

1. **Action-scoped** — it covers one controller action. Name it for the
   controller and the action: `ProxyIndexTest`, `ProxyShowTest`, `ProxyStoreTest`,
   `ProxyUpdateTest`, `ProxyDestroyTest`. A form-page test lives with the action
   it submits to, so the `create` page test belongs in `ProxyStoreTest` and the
   `edit` page test in `ProxyUpdateTest`. Single-action controllers keep the
   controller name: `ProxyPauseControllerTest`, `ProxySigningControllerTest`.

2. **Concern-scoped** — it deliberately crosses several actions because the
   crossing is the point. `SecretAbsenceSweepTest` sweeps index, show, edit,
   events and payload for a leaked secret value; splitting it by action would
   destroy exactly what it proves. Name it for the concern, and say in the class
   docblock why it spans routes.

Do not create a third kind. A class named for neither an action nor a stated
concern — `ProxyControllerPagePropsTest` was one — gives no answer to "does my
new test go here?", and the answer stops being obvious for everyone after you.

### Suffixes

The class name ends in `Test` and nothing else. Do not add `Acceptance`,
`Integration` or `Unit` to the name: the directory already says which suite it is
in, and a second vocabulary only raises the question of which name to use. The
one legitimate reason to extend a name is disambiguation — when two tests would
otherwise collide, name each for what distinguishes it, as with
`tests/Unit/Services/WebhookEventCaptureTest.php` (the service in isolation)
against `tests/Feature/Ingest/IngestEventCaptureTest.php` (capture through the
ingest endpoint).

The class name and the file name must match, and no two test classes may share a
short name across directories. Two classes both named `DeliverStepTest` existed
in `tests/Unit/Actions` and `tests/Unit/Pipeline`; they were not duplicates, but
nothing in either name said so, and `--filter DeliverStepTest` ran both. The
Pipeline one is now `tests/Feature/Delivery/DeliverStepFanOutTest.php`.

### Size

Prefer several action-scoped classes over one class per controller.
`ProxyController` is covered by roughly 160 test methods; a single
`ProxyControllerTest` would be an unreadable file, a standing merge conflict, and
slower in CI, because paratest shards by class file and a single large class
cannot be split across workers.

## Scope

- Applies going forward to all item-#1 tests and every new/modified test.
- Pre-existing kit tests (`tests/Feature/Auth/*`, `tests/Feature/Settings/*`,
  `tests/Feature/Teams/*`) are **not** churned by this rule; they may be
  migrated opportunistically when touched for other reasons. Note that
  some kit tests intentionally rely on model events (e.g. `Team` slug-suffix
  dedup in `tests/Feature/Teams/TeamTest.php`) and must keep `create()` where
  the event is the behavior under test.
</content>
</invoke>
