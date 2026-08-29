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
  then call the real action under `Queue::fake()` and assert step-by-step. **The trap this guards
  against fails silently, not loudly:** `$model->update(['non_fillable_col' => $v])` on a column
  deliberately left out of `#[Fillable]` (e.g. a system-managed column like `paused_at`) neither
  throws nor errors by default — it just drops that key and updates nothing, so the assertion that
  follows fails with a confusing "value unchanged" result that looks like the code under test is
  broken rather than the test fixture. `forceFill(...)->save()` (or `->saveQuietly()` to also skip
  events) is the fix in test setup; production code sets such columns through a dedicated
  action/controller, never mass assignment.

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
- **`Model::factory()->createManyQuietly($count)` does NOT accept an attributes array as a second
  argument** — the real signature is `createManyQuietly(int|iterable|null $records = null)`, same
  shape as `createMany()`. Passing `createManyQuietly(2, ['status' => X])` silently ignores the
  second argument and creates records with plain factory defaults (a status-dependent count then
  reads 0, not "2 records in status X"). Chain `->state([...])->createManyQuietly($count)` instead.
- **Asserting "this class's code never references X" (a column name, a model class) via a plain
  `str_contains($source, 'X')`/`assertStringNotContainsString` false-positives the moment the
  class's own doc-block documents that very invariant in prose** (e.g. "this class never reads
  `processing_mode`" — the string `processing_mode` is right there in the comment). Strip comments
  first with the tokenizer before the substring check: `foreach (token_get_all($source) as $token)
  { if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code
  .= is_array($token) ? $token[1] : $token; }`, then assert against `$code`, not `$source`.
- **A `withTrashed()`/soft-delete query on a model using `BelongsToCurrentTeam`'s `TeamScope` still
  carries the scope's own implicit `current_team_id` filter** (only `SoftDeletingScope` is removed
  by `withTrashed()`) — harmless in a real authenticated request (the passed team id always matches
  session current-team by construction) and a non-issue in a unit test with no `actingAs()` (no
  authenticated user ⇒ `TeamScope` no-ops entirely, so the test's own explicit `where('team_id',
  ...)` is the only thing providing isolation — actually the more rigorous test design for proving
  a service's own explicit scoping works, independent of session state).
- **Larastan cannot see a cast-triggered exception through a plain Eloquent property access** — a
  `try { return $model->value; } catch (DecryptException) {...}` around an `encrypted`-cast
  attribute is flagged `catch.neverThrown` (dead catch), because PHPStan has no model of what
  `Model::getAttribute()` does internally for that cast. Fix at the root: call
  `Crypt::decryptString((string) $model->getRawOriginal('column'))` directly instead of reading the
  cast attribute — functionally identical to what the cast does, but now a real, statically-visible
  call whose vendor docblock carries `@throws DecryptException`, so the catch is genuine rather than
  suppressed.
- **Proving "the raw request body is read exactly once" cannot be done through the full HTTP test
  client when any route middleware itself legitimately calls `$request->getContent()`** (e.g. a
  body-size-limit middleware falling back to `strlen($request->getContent())` when
  `Content-Length` is absent) — that read is real, pre-existing, and unrelated to the guarantee
  under test, and would false-fail a naive whole-pipeline counter. Isolate to the class that owns
  the guarantee: resolve the controller from the container and call `__invoke()` directly against a
  small `Illuminate\Http\Request` subclass that increments a counter inside an overridden
  `getContent()`, bypassing route/middleware entirely.
- **This app's global `ConvertEmptyStringsToNull` middleware (Laravel framework default, active
  here) converts a submitted `""` to `null` before validation runs, for every field, everywhere** —
  a write-only "leave unchanged" contract keyed on `nullable` + `min:N` (e.g. a secret field like
  `destinations.*.credential_secret`) rejects a too-short *string* but silently treats an empty
  string exactly like an absent key, both taking the "unchanged" branch with no 422. Don't assume a
  task's own prose ("an absent field, not an empty string, is what 'leave unchanged' reads as")
  implies the two are validated differently — write the test against actual behaviour first, then
  adjust the assumption in completion notes if the framework already collapses the distinction.
- **`$this->postJson($uri, $data, $headers)`'s raw body is exactly `json_encode($data, 0)`**
  (`Illuminate\Foundation\Testing\Concerns\MakesHttpRequests::json()`, confirmed by reading the
  vendor source, not assumed) — for an HTTP-level test that must sign/HMAC the exact bytes a
  controller will read via `$request->getContent()`, reconstruct the same `json_encode($data)` call
  independently rather than trying to capture the request's own serialization; the two are
  guaranteed byte-identical by construction, not merely likely to match.
- **`Http::fake([...])` calls accumulate — they never replace.** `Illuminate\Http\Client\Factory::fake()`
  given an array merges each stub onto the existing `stubCallbacks` collection, and request resolution
  takes the FIRST matching stub (`->filter()->first()`). A second `Http::fake(['*' => Http::response(...,
  500)])` later in the same test never overrides an earlier `Http::fake(['*' => Http::response(..., 200)])`
  registered against the same pattern — the old stub keeps winning silently (only `Http::recorded()`'s
  history resets on each `fake()` call, not the stub rules). For "fails N times then succeeds," prefer
  `Http::fakeSequence()->pushStatus(500)->pushStatus(500)->whenEmpty(Http::response('ok', 200))` over two
  sequential `Http::fake([...])` calls. To change response behaviour mid-test on a condition rather than a
  fixed sequence, use one `Http::fake()` closure for the whole method branched on a mutable flag captured
  `use (&$flag)` — a plain closure, never an arrow `fn`, since `fn` captures enclosing variables BY VALUE
  at definition time and silently freezes the flag forever. Found by dumping actual `Http::recorded()`
  status codes rather than assuming a later fake took effect.
- **A job dispatched inside `ProcessIngestedWebhook::run()` via `::dispatch()->onQueue()->afterCommit()`
  needs `ProcessIngestedWebhook::run($ingestId)` (the direct action call, not `::dispatch()`) to create
  its `Delivery`/`DeliverToDestination`-job side effects while `Queue::fake()` is active** — faking the
  queue BEFORE dispatching `ProcessIngestedWebhook` itself (e.g. via the ingest HTTP endpoint, which
  dispatches it through the queue) prevents it from running at all, so nothing downstream ever gets
  created. Established precedent: `AsyncDispatchAcceptanceTest::test_each_destination_gets_a_separate_job_on_the_webhooks_queue`.
- **Proving a resource never leaks a `$hidden`/never-queried relation even under a "someone eager-loads
  it by mistake" scenario:** don't fight route-model-binding to force the mistake through a real HTTP
  round trip — `$model->load(['relation'])` the model directly, then serialize it straight through the
  same resource class(es) production uses (`new SomeResource($eagerLoaded))->resolve()`), and assert
  both that the JSON has no key for the relation at all (the "never serialized" guard) AND that
  `$eagerLoaded->relation->first()->toArray()` itself has no key for the hidden column (the model's own
  `$hidden` guard), independently. Cheaper and less fragile than overriding `Route::bind()` for the
  parameter name to inject an eager-loaded instance into the real controller path, and proves the same
  two guards. Established for item #10 T48 (`SecretAbsenceSweepTest`).
