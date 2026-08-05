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
- **Proving the ABSENCE of a per-row policy call (no N+1) during resource serialization**:
  same `partialMock(ProxyPolicy::class)` but `shouldReceive('update')->never()` /
  `->delete()->never()` over a multi-row index render. Unmocked `viewAny` runs real and
  authorizes the page; the per-record abilities must receive zero calls regardless of row
  count. Cleaner than `Gate::spy()`, which would also intercept the required `viewAny`.
  (Used for ADR-009 Amendment B: affordance display derives client-side from
  `ProxyResource.is_creator` + page-level `ProxyPermissions`, not a per-row `$user->can()`.)
