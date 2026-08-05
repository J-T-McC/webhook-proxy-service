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
- **Proving the ABSENCE of a per-row policy call (no N+1) during resource serialization**:
  same `partialMock(ProxyPolicy::class)` but `shouldReceive('update')->never()` /
  `->delete()->never()` over a multi-row index render. Unmocked `viewAny` runs real and
  authorizes the page; the per-record abilities must receive zero calls regardless of row
  count. Cleaner than `Gate::spy()`, which would also intercept the required `viewAny`.
  (Used for ADR-009 Amendment B: affordance display derives client-side from
  `ProxyResource.is_creator` + page-level `ProxyPermissions`, not a per-row `$user->can()`.)
