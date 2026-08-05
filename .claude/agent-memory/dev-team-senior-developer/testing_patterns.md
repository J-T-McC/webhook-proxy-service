---
name: testing-patterns
description: Backend test conventions for team-role/proxy authorization tests in this Laravel app
metadata:
  type: project
---

Backend test setup idioms (PHPUnit, `Tests\TestCase`):

- **Attach a user to a team with a specific role:** `$team->members()->attach($user,
  ['role' => TeamRole::Member->value]); $user->switchTeam($team);` — `switchTeam` is
  needed for `current_team`-based checks (`create`, `viewAny`, page-level permissions).
- **Proxies/* feature tests use an `actingUser()` helper** that returns a fresh
  `User::factory()->createQuietly()` switched to its own personal team, where the user is
  **Owner** (unrestricted under the ownership policy) — this is why item-#1 proxy tests keep
  passing after role-based authz landed.
- **Never `RefreshDatabase`/`FasterRefreshDatabase` in a test class** and always
  `createQuietly()` (see `docs/standards/testing.md`) — both enforced by convention, not lint.
- **Multi-role policy/HTTP matrix tests** live in `tests/Feature/Proxies/` (ProxyPolicyTest,
  ProxyAuthorizationTest, ProxyCanFlagsTest, ProxyIndexPermissionsTest are the reference set).
- Run one class: `./vendor/bin/sail test --filter ClassName` or `./vendor/bin/sail test path/to/File.php`.
- **Proving a controller's `authorize()` is wired when no real role can be denied** (all
  roles currently hold the ability, e.g. proxy `CreateProxy`): `partialMock(ProxyPolicy::class,
  fn (MockInterface $m) => $m->shouldReceive('<ability>')->andReturn(false))`, hit the route,
  assert 403 + no DB write. Honest — proves the gate is invoked without fabricating a contrived
  role. Pair with a permitted-actor success test.
- **Data providers use the PHPUnit `#[DataProvider('method')]` attribute**, NOT the `@dataProvider`
  docblock — the docblock form silently fails to apply (test runs with 0 args → TypeError). Provider
  method must be `public static`.
- **Lorisleiva Actions dispatch assertions:** `AsAction`/`AsJob` expose `MyAction::assertPushed($n)`,
  `assertPushedOn($queue, $n)`, `assertNotPushed()` under `Queue::fake()`; the `assertPushed`
  callback signature is `fn ($action, array $parameters, $job, $queue)` where `$parameters` are the
  `handle()` args (`$parameters[0]` = first arg). `getJobMiddleware(int $x)` receives the same handle
  args, so middleware (e.g. `WithoutOverlapping("proxy:{$x}")`) can key off them.
- **Schema-default columns read null in-memory until reloaded** — a factory row that omits a
  defaulted column (e.g. `proxies.processing_mode`) needs `->fresh()`/reload before the default value
  materialises on the model.
- **Testing a self-dispatching queue action** (e.g. `AdvanceProxyFifoQueue`): drive with `::run()` +
  `Queue::fake()` so the internal `static::dispatch()` is captured, not recursed inline. Under the
  `sync` driver a real self-dispatch runs through `WithoutOverlapping`, whose lock (held by the
  in-flight parent) drops the nested child — so never rely on inline recursion to drain a FIFO line
  in a test; advance step-by-step. To prove the atomic `FOR UPDATE` claim blocks a concurrent
  advancer, fire a nested `::run()` from inside an `Http::fake` closure (i.e. while the first row is
  claimed and mid-send) and assert no second row gets claimed.
- **Asserting `Log::` calls:** `Log::spy();` then, after the code runs,
  `Log::shouldHaveReceived('info')->once()->withArgs(fn (string $msg, array $ctx) => $msg ===
  'x.y' && $ctx === ['id' => $id]);` — works even though `Log::` had zero prior usage in `app/`
  before item #5 (first precedent: `CaptureDispatchedStep`'s post-clean guard and
  `ProcessIngestedWebhook`'s cleaned-state guard, both logging `payload.expired` with identifiers
  only per the never-log list in `docs/standards/coding.md`).
- **Fault-injecting a mid-transaction failure to prove atomicity/rollback, without DDL:**
  `DB::listen(function ($query) { if (str_contains($query->sql, 'update `table_name`')) { throw new
  RuntimeException('...'); } });` before calling the code under test. `Connection::logQuery()` fires
  the `QueryExecuted` event synchronously right after a statement executes but still inside the open
  transaction, so the exception propagates out through the real `DB::transaction()` wrapper, which
  rolls back everything already run in that transaction (including an earlier statement in the same
  closure) before rethrowing — a real rollback through real transaction machinery, no mock of the
  query builder. **Do NOT use a schema-altering fault (e.g. `CREATE TRIGGER`/`dropColumn` mid-test)**
  to force a query to fail — DDL causes an implicit `COMMIT` in MySQL/InnoDB, which silently commits
  the `RefreshDatabase`/`FasterRefreshDatabase`-managed test transaction and leaks fixture rows past
  the test. Registering `DB::listen` is test-scoped (Laravel boots a fresh `Application` per test
  method), so it never needs manual teardown. Used for `PurgeExpiredPayloads`'s AC12 atomicity test
  (#5): failing the `dispatched_payloads` `UPDATE` rolls back the already-executed `webhook_events`
  `UPDATE` in the same transaction.
- **Proving the ABSENCE of a per-row policy call (no N+1) during resource serialization**:
  same `partialMock(ProxyPolicy::class)` but `shouldReceive('update')->never()` /
  `->delete()->never()` over a multi-row index render. Unmocked `viewAny` runs real and
  authorizes the page; the per-record abilities must receive zero calls regardless of row
  count. Cleaner than `Gate::spy()`, which would also intercept the required `viewAny`.
  (Used for ADR-009 Amendment B: affordance display derives client-side from
  `ProxyResource.is_creator` + page-level `ProxyPermissions`, not a per-row `$user->can()`.)
- **Reproducing a select-then-act race (a hold reappearing between selection and a
  compare-and-set `UPDATE`) without real concurrency:** `DB::listen()`, guarded by a
  captured `bool` so it fires only once, matching the SELECT's SQL substring (e.g.
  `select \`id\` from \`webhook_events\``); inside the callback, `DB::table(...)->insert(...)`
  the row that "reappears" (e.g. a `pending` `fifo_dispatches` row) before the code under
  test's next statement (the erase `UPDATE`, which re-asserts the hold in its own `WHERE`)
  runs. Proves the CAS affects zero rows and the target is skipped — no mock, no production
  code change. Used for `PurgeExpiredPayloads`'s reappeared-hold race (#5, T15).
- **Testing a pipeline step in isolation with a test-only step ahead of it:** build an
  anonymous class `implements PipelineStep` (mutate `$ctx->payload` or similar in `handle()`,
  call `$next($ctx)`), then `app(Illuminate\Pipeline\Pipeline::class)->send($ctx)->through([$testStep,
  RealStep::make()])->thenReturn();` — a test-local pipeline composition, not the wired
  `PipelineFactory`. Lets an acceptance test exercise a divergence/mutation case the real
  pipeline doesn't yet produce (e.g. `CaptureDispatchedStep`'s diverged-payload branch, #5 T18)
  without a second production step existing yet.
- **Driving a FIFO line across a mid-line state change** (e.g. the claimed event's parent
  becoming cleaned before the advancer processes it): mutate the target row directly with
  `$model->forceFill([...])->saveQuietly()` (bypasses `#[Fillable]` + suppresses model events;
  precedent: `TeamScopingTest`), then call `AdvanceProxyFifoQueue::run($proxyId)` under
  `Queue::fake()` and assert step-by-step (one `::run()` call settles/claims one row — the
  self-dispatch is captured, not recursed; see the existing note above on testing a
  self-dispatching queue action).
