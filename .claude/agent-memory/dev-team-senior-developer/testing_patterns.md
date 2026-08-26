---
name: testing-patterns
description: Backend test conventions and PHPUnit/Laravel gotchas for this app (roles, policies, actions/jobs, DB::listen fault-injection, resource serialization)
metadata:
  type: project
---

Backend test setup idioms (PHPUnit, `Tests\TestCase`). `createQuietly()`/no-`RefreshDatabase`
rules live in `docs/standards/testing.md`, not repeated here.

- **Attach a user to a team with a specific role:** `$team->members()->attach($user,
  ['role' => TeamRole::Member->value]); $user->switchTeam($team);` — `switchTeam` is
  needed for `current_team`-based checks (`create`, `viewAny`, page-level permissions).
- **Proxies/* feature tests use an `actingUser()` helper** that returns a fresh
  `User::factory()->createQuietly()` switched to its own personal team, where the user is
  **Owner** (unrestricted). Multi-role policy/HTTP matrix tests live in `tests/Feature/Proxies/`
  (ProxyPolicyTest, ProxyAuthorizationTest, ProxyCanFlagsTest, ProxyIndexPermissionsTest are the
  reference set).
- **Proving `authorize()` is wired when no real role can be denied** (all roles currently hold the
  ability): `partialMock(ProxyPolicy::class, fn (MockInterface $m) =>
  $m->shouldReceive('<ability>')->andReturn(false))`, hit the route, assert 403 + no DB write.
  Same technique proves the ABSENCE of a per-row policy call during resource serialization (no
  N+1): `shouldReceive('update')->never()` over a multi-row index render — unmocked `viewAny`
  authorizes the page for real; per-record abilities must get zero calls regardless of row count.
- **Data providers use the PHPUnit `#[DataProvider('method')]` attribute**, NOT the `@dataProvider`
  docblock — the docblock form silently fails to apply (test runs with 0 args → TypeError).
  Provider method must be `public static`.
- **Lorisleiva Actions dispatch assertions:** `AsAction`/`AsJob` expose `MyAction::assertPushed($n)`,
  `assertPushedOn($queue, $n)`, `assertNotPushed()` under `Queue::fake()`; the `assertPushed`
  callback signature is `fn ($action, array $parameters, $job, $queue)` where `$parameters` are the
  `handle()` args. `getJobMiddleware(int $x)` receives the same handle args, so middleware (e.g.
  `WithoutOverlapping("proxy:{$x}")`) can key off them. `PendingDispatch::delay()` sets a public
  `$job->delay` (CarbonInterval) on the `JobDecorator` — read it in the callback to assert scheduled
  delay. See [[laravel_actions_gotchas]] for `AsJob`-only actions having no `::run()`.
- **Schema-default columns read null in-memory until reloaded** — a factory row that omits a
  defaulted column needs `->fresh()`/reload before the default value materialises on the model.
- **Testing a self-dispatching queue action** (recursive `static::dispatch()` under
  `WithoutOverlapping`): drive with `::run()` + `Queue::fake()` so the internal dispatch is
  captured, not recursed inline — under `sync` a real self-dispatch's `WithoutOverlapping` lock
  (held by the in-flight parent) silently drops the nested child. Advance step-by-step, one
  `::run()` per row. To simulate a real worker draining faked jobs whose side effects a later
  assertion depends on: `Queue::pushed($jobDecoratorClass, function ($job) { if
  ($job->decorates(TargetAction::class)) TargetAction::run(...$job->getParameters()); return
  true; })` — runs every currently-pushed job of that action synchronously; safe to re-invoke if
  the target action is itself idempotent.
- **Asserting `Log::` calls:** `Log::spy();` then `Log::shouldHaveReceived('info')->once()
  ->withArgs(fn (string $msg, array $ctx) => $msg === 'x.y' && $ctx === ['id' => $id]);`.
- **Fault-injecting a mid-transaction failure to prove atomicity/rollback, without DDL:**
  `DB::listen(function ($query) { if (str_contains($query->sql, 'update `table_name`')) { throw
  new RuntimeException('...'); } });` before the code under test — `logQuery()` fires synchronously
  still inside the open transaction, so the exception propagates through the real
  `DB::transaction()` wrapper and rolls back everything already run in that closure. Same
  `DB::listen` mechanism (guarded by a captured bool so it fires once, matched on SQL substring)
  reproduces a select-then-act race: insert/mutate the "reappearing" row from inside the callback,
  before the code under test's next statement (a compare-and-set `UPDATE`) runs, to prove the CAS
  affects zero rows. **Never use a schema-altering fault** (`CREATE TRIGGER`/`dropColumn` mid-test)
  to force a failure — DDL causes an implicit `COMMIT` in MySQL/InnoDB, silently committing the
  `RefreshDatabase`-managed test transaction and leaking fixture rows past the test. `DB::listen`
  registration is test-scoped (fresh `Application` per test method) — no manual teardown needed.
- **Testing a pipeline step in isolation with a test-only step ahead of it:** build an anonymous
  class `implements PipelineStep` (mutate `$ctx` in `handle()`, call `$next($ctx)`), then
  `app(Pipeline::class)->send($ctx)->through([$testStep, RealStep::make()])->thenReturn();` — a
  test-local composition, not the wired `PipelineFactory`. Lets a test exercise a case the real
  pipeline doesn't yet produce.
- **Driving a state machine across a mid-flow external change:** mutate the target row directly
  with `$model->forceFill([...])->saveQuietly()` (bypasses `#[Fillable]`, suppresses model events),
  then call the real action under `Queue::fake()` and assert step-by-step.
- **`QUEUE_CONNECTION=sync` (phpunit.xml) means ANY un-faked dispatch — including a `->delay(...)`'d
  one — runs `handle()` synchronously in-process the moment `dispatch()` is called**
  (`SyncQueue::later()` ignores the delay). Before adding a new delayed dispatch inside an existing
  action, audit every pre-existing test that exercises the triggering branch WITHOUT
  `Queue::fake()` — they will now really execute the dispatched job. If the dispatched class is a
  forward reference to a not-yet-implemented sibling task, give it a genuinely empty no-op
  `handle()` so pre-existing tests stay green. Relatedly, "assert it's mid-schedule" assertions need
  `Queue::fake()` to freeze the delayed hop — without it `sync` cascades straight to a terminal
  state within one triggering call.
- **Filling in a real `handle()` body behind a previously-no-op stub that a prior task already
  wired a real (un-faked) delayed `::dispatch()` call to:** audit every pre-existing test exercising
  the triggering branch without `Queue::fake()` — if the new body can re-trigger the same dispatch
  (a cascade), counts inflate past what the test expects. Fix each on its own terms: if the test's
  own subject is unrelated to the cascade, `Queue::fake()` cleanly suppresses it; if the test's
  whole stated purpose IS the un-faked sync-drain behaviour, update the count assertions to the
  real cascade total instead, with an inline comment naming the config default that produced it.
- **`DB::transactionLevel()` is 1, not 0, for the entire body of every test in this suite** —
  `FasterRefreshDatabase` opens one outer transaction per test before the body runs and never
  commits it (only rolls back in teardown). A test asserting "no transaction held at point X" must
  capture the ambient level first (`$ambient = DB::transactionLevel();`) and assert equality
  against that, never a literal `0`.
- **A failed PHPUnit assertion INSIDE application code the test doesn't control** (e.g. inside an
  `Http::fake()` closure running deep inside the action under test) is just a thrown
  `ExpectationFailedException` to that code — if the action has its own `catch (Throwable $e)`
  around the call site, it silently swallows the assertion failure and the test can go on to pass
  on unrelated downstream assertions. It still counts toward PHPUnit's assertion total (a
  suspiciously-low count vs. the number of `assertSame` calls written is the tell) but the test
  goes green. Watch for this whenever a test's assertion count looks lower than its assertion call
  count.
- **Testing a `JsonResource`'s `whenLoaded()`/conditional-key omission directly (no HTTP round
  trip):** call `->resolve(request())`, NOT `->toArray(request())`. `toArray()` leaves
  `MissingValue` objects in place for omitted keys (`assertArrayNotHasKey` then fails — the key
  exists, just holding a `MissingValue`); `resolve()` runs the same `filter()` pass
  Inertia/`toResponse()` does in production.
- **`abort($code)` for a lifecycle/non-error status renders the app's full HTML error-page body**
  in the test env — if an endpoint's contract requires a genuinely empty body on that status, use
  `return response('', $code);` instead of `abort($code)`.
- **`Http::fake(['*' => ...])` called a SECOND time in the same test does NOT replace the first
  stub** — the array/URL-pattern form merges onto `$stubCallbacks`, never clears it; the
  first-registered matching pattern wins for the rest of the test, silently ignoring the later
  fake. For "fails N times then succeeds," use one
  `Http::fakeSequence()->pushStatus(500)->pushStatus(500)->whenEmpty(Http::response('ok', 200))`
  instead of two sequential `Http::fake([...])` calls.
- **The test client's `actingAs($user)` authentication PERSISTS across every subsequent request
  within the same test method**, even without re-chaining `actingAs()` — assert the
  guest/unauthenticated case FIRST in a test method, before any `actingAs()` call, or give it its
  own test.
- **A `Schedule::command(...)` entry runs as a real subprocess** (`Event::run()` ->`start()`
  ->`execute()`), NOT in-process — driving it via `$this->artisan('schedule:run')` inside a test
  spawns a process outside the test's wrapped transaction, so fixture rows are invisible to it and
  vice versa (silent no-op either way). Test `Schedule::command()` entries by asserting only
  schedule metadata (`$schedule->events()` description/expression/command string), and test the
  command's real effect by invoking it directly (`$this->artisan('the:command', [...])`).
  `Schedule::call(fn () => ...)` closures run in-process and `schedule:run` tests their real effect
  directly — no such split needed.
- **Adding a resolution-time gate to an existing resolver** (e.g. reading a per-record column only
  when another column is in a specific state) breaks any pre-existing test whose fixture relies on
  the factory default for the gating attribute while setting only the gated column directly — the
  column silently goes dormant and the old assertion becomes the system default instead. These
  failures scatter outside the changed files' own test suite. After any such gate change, grep for
  every fixture-creation call that sets the gated column without the gating one, not just the files
  the task's Files list names — add the gating attribute explicitly to the fixture (the correct fix
  under the new invariant, not a workaround).
