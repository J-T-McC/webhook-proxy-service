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
- **`QUEUE_CONNECTION=sync` (phpunit.xml) means ANY un-faked dispatch — including a
  `->delay(...)`'d one — runs `handle()` synchronously in-process the moment `dispatch()` is
  called** (`SyncQueue::later()` just calls `push()`, ignoring the delay; the delay is a
  no-op under `sync`). Before adding a new delayed dispatch inside an existing action (e.g. a
  retry-scheduling call inside `DeliverToDestination::send()`), audit every pre-existing test
  that exercises the triggering branch WITHOUT `Queue::fake()` — they will now really execute
  the dispatched job's `handle()`. If the dispatched class doesn't fully exist yet (a forward
  reference to a not-yet-implemented sibling task), give it a genuinely empty no-op `handle()`
  rather than a "not implemented" throw, so those pre-existing tests stay green until the real
  task lands.
- **Filling in a real `handle()` body behind a previously-no-op `AsJob`/`AsAction` stub that a
  prior task already wired a real (un-faked) `::dispatch()->delay()->onQueue()` call to** (e.g.
  `RetryDelivery`, stubbed empty by T13, filled in by T14): audit every pre-existing test that
  exercises the triggering branch WITHOUT `Queue::fake()` — under `QUEUE_CONNECTION=sync` they
  now execute the real body, and if that body can itself re-trigger the same dispatch (a retry
  cascade), counts inflate to the full cascade (e.g. the system-default attempt limit) instead of
  stopping at one. Fix each on its own terms, don't blanket-add `Queue::fake()`: if the
  assertion's real subject is unrelated to the cascade AND something upstream of the cascade
  trigger still runs for real without the queue (e.g. `DeliverToDestination::run()` called
  directly, not `::dispatch()`), `Queue::fake()` cleanly suppresses just the new cascade; but if
  the test's whole stated purpose IS the un-faked sync-drain behaviour (e.g. "no Queue::fake — the
  dispatched work drains inline" acceptance tests), faking would zero out the test's own subject —
  update the count assertions to the real cascade total instead, with an inline comment naming the
  task and the config default that produced the number. Used for T14 (`RetryDelivery`), which
  cascaded through `config('retry.default_attempt_limit')` (5) in six pre-existing tests.
- **`AsJob`-only actions (no `AsAction`) have no `::run()`/`::make()` static helper** — `AsJob`
  (`vendor/lorisleiva/laravel-actions/src/Concerns/AsJob.php`) provides
  `dispatch`/`dispatchSync`/`assertPushed`/etc. but not `AsObject`'s `run`/`make` (those come only
  via `AsAction`, which composes `AsObject + AsJob + ...`). To invoke the job body directly in a
  test (bypassing the queue entirely), container-resolve and call `handle()` yourself:
  `app(RetryDelivery::class)->handle($id, $n)` — same container-resolution parity as `::run()`
  would give, just without the `AsObject` convenience wrapper. Used for T14's `RetryDeliveryTest`.
- **Inspecting a `->delay()` value set on a Lorisleiva-Actions job under `Queue::fake()`:**
  the `assertPushed` callback's 3rd arg is the `Lorisleiva\Actions\Decorators\JobDecorator`
  instance; `PendingDispatch::delay()` sets `$job->delay` on it directly (public property,
  `Illuminate\Bus\Queueable` trait) — read `$job->delay` (a `CarbonInterval`/`DateInterval`) to
  assert the scheduled delay, e.g. `fn ($action, $params, JobDecorator $job, $queue) => (int)
  $job->delay->totalSeconds === $expected`.
- **`DB::transactionLevel()` is 1, not 0, for the entire body of every test in this suite** —
  `FasterRefreshDatabase` (wraps `RefreshDatabase`) opens one outer transaction per test via
  `beginDatabaseTransaction()` before the test body runs, and that transaction stays open (never
  committed, only rolled back in teardown) the whole time. A test asserting "no transaction is
  held at point X" must capture the ambient level first (`$ambient = DB::transactionLevel();`
  before the action under test runs) and assert equality against that captured value, never a
  literal `0` — a hardcoded-`0` assertion silently never proves what it claims (see next bullet
  for why it can go undetected for a long time).
- **A failed PHPUnit assertion INSIDE application code the test doesn't control (e.g. inside an
  `Http::fake()` closure that runs deep inside the action under test) is just a thrown
  `PHPUnit\Framework\ExpectationFailedException` to that code** — if the action has its own
  `catch (Throwable $e)` around the call site (e.g. `DeliverToDestination::send()`'s HTTP-call
  try/catch, there to catch real transport errors), it silently swallows the assertion failure
  and treats it as an ordinary application-level failure, and the test can go on to pass on
  unrelated downstream assertions. The assertion still counts toward PHPUnit's assertion total
  (visible as a suspiciously-low count, e.g. 2 asserts executed out of 3 written) but the test
  goes green. Symptom to watch for: a test with N `assertSame` calls reports fewer than N
  assertions in the JSON summary while still "passing" — that's the tell this happened. Found via
  the (now-fixed) hardcoded-`0` transaction-level bug above: it had silently asserted `1 === 0`
  and failed for an unknown span of time before a real downstream behavioural change (T16 of
  feature #6 making a FIFO row's fate depend on the delivery's real outcome, not settling
  unconditionally) turned the swallowed failure into a visible one.
- **Simulating a real queue worker executing faked, queued Lorisleiva-Actions jobs in place**
  (needed when a test fakes the queue to assert dispatch, but a LATER assertion in the same test
  depends on that job's side effects having actually run — e.g. a FIFO row that only settles once
  its async-dispatched delivery completes): `Queue::pushed(\Lorisleiva\Actions\ActionManager::
  $jobDecorator, function (\Lorisleiva\Actions\Decorators\JobDecorator $job) { if
  ($job->decorates(TargetAction::class)) { TargetAction::run(...$job->getParameters()); } return
  true; })` — runs every currently-pushed job of that action synchronously. Idempotent to
  re-invoke if the target action's own idempotency guard covers redelivery (e.g.
  `DeliverToDestination`'s existing-attempt resume-or-skip), so it's safe to call after each of
  several `Queue::fake()`'d dispatch points in a multi-step test rather than tracking which jobs
  are "new". Used to fix `ProcessingModeSwitchAcceptanceTest` after feature #6 T16 made a
  pre-switch FIFO row's settlement depend on its now-async-dispatched delivery actually running.
- **Testing a `JsonResource`'s `whenLoaded()`/conditional-key omission directly (no HTTP round
  trip):** call `->resolve(request())`, NOT `->toArray(request())`. `toArray()` returns the raw
  array with `Illuminate\Http\Resources\MissingValue` objects still in place for omitted keys
  (`assertArrayNotHasKey` then fails - the key exists, just holding a `MissingValue`); `resolve()`
  runs the same `filter()` pass Inertia/`toResponse()` does in production, so omitted keys are
  genuinely absent. Used for #6 T25's `WebhookEventResource`/`DeliveryResource` unit tests.
- **`abort(410)` (or any `abort($code)` for a lifecycle/non-error status) renders the app's full
  HTML error-page body in the test env** - if an endpoint's contract requires a genuinely empty
  body on that status (e.g. ADR-017's "cleaned => 410 Gone, no body content"), use `return
  response('', $code);` instead of `abort($code)`. Caught by a first failing test run asserting
  against the response content. Used for #6 T28's `ProxyEventPayloadController`.
