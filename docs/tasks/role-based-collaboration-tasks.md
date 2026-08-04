# Task Plan: Role-based collaboration (item #2)

- **Status:** Approved
- **Author:** Task Planner
- **Technical Plan:** `docs/architecture/adr-009-proxy-permission-mechanism.md` (Accepted,
  incl. Amendment A — Project Owner, 2026-08-03; recorded in `docs/status.md` item #2)
- **PRD:** `docs/product/prd-02-role-based-collaboration.md` (Approved by Owner, 2026-08-03
  per `docs/status.md`) · **ADRs:** ADR-009 (Accepted, incl. Amendment A)
- **Approved by:** Task Planner (task-plan gate; no Owner approval required at this stage —
  the Reviewer catches drift against ADR-009/PRD-02 at review time)

> **Scope / conventions.** Every task traces to ADR-009 (base decision + Amendment A) and its
> PRD-02 ACs. Sequence is enum → role bundles → creator-capture migration/trait → policy
> rewrite → backend authorization tests → permission DTOs/Resource flags → controller wiring →
> frontend types → per-page conditional UI. Every task must leave `composer lint` (Pint),
> `composer types:check` (PHPStan L7), and `./vendor/bin/sail test` green; frontend-touching
> tasks (T9–T11) additionally require `pnpm types:check` (`vue-tsc`), `pnpm lint:check`
> (ESLint), and `pnpm format:check` (Prettier) green (CLAUDE.md; `docs/standards/coding.md`).
> **No new dependency, no stack change, no library** — ADR-009 is explicit that this extends
> the existing native `TeamRole`/`TeamPermission` enum pattern only.
>
> **AC9 (no regression to team-administration).** No task below touches the existing
> `team:`/`member:`/`invitation:` `TeamPermission` cases, `TeamRole`'s existing arms for those
> cases, `TeamPolicy`, `TeamMemberController`, `TeamInvitationController`,
> `InviteMemberModal.vue`, or `teams/Edit.vue`. T1 and T2's Verify steps explicitly assert the
> existing team-admin case values/bundles are byte-for-byte unchanged.
>
> **Frontend UI tasks (T10–T11) are inspection-only.** No JS test framework exists
> (`docs/standards/coding.md` → Dependencies; `docs/standards/design.md`/`review.md` "manual —
> no automated coverage" gap). Their Verify step is a documented manual walkthrough per role
> against the `docs/standards/review.md` → Frontend/Accessibility checklist, not an invented JS
> test — consistent with how the item-#1 Index-delete regression was handled
> (`docs/status.md` item #1, T27 rework note).
>
> **Ownership fail-closed default (Amendment A3/A4).** A `null created_by` (pre-feature rows,
> any no-actor creation path) is a **safe deny** for the ownership-limited role (Member) and
> resolves to Admin/Owner-only management. Several tasks below test this explicitly.

---

## T1 — `TeamPermission`: add proxy CRUD + ownership-bypass cases (ADR-009 §1, Amendment A2.1)
- **Description:** Add six new string-backed cases to the existing `TeamPermission` enum,
  `proxy:`-namespaced, alongside the unchanged `team:`/`member:`/`invitation:` cases: the four
  CRUD cases `ViewProxy = 'proxy:view'`, `CreateProxy = 'proxy:create'`, `UpdateProxy =
  'proxy:update'`, `DeleteProxy = 'proxy:delete'`, plus the two ownership-bypass cases
  `UpdateAnyProxy = 'proxy:update-any'`, `DeleteAnyProxy = 'proxy:delete-any'`. Addition only —
  no existing case's name or string value changes.
- **Dependencies:** none
- **Files:** `app/Enums/TeamPermission.php`
- **AC-trace:** PRD-02 AC2 (CRUD taxonomy, general enough for later resources/permissions);
  AC5/AC6 (the two `-any` cases are the mechanism that differentiates ownership-limited from
  unrestricted roles); AC9 (existing team-admin cases untouched); ADR-009 §1, Amendment A2.1.
- **Verify step:** `composer types:check` green; a unit test enumerates `TeamPermission::cases()`
  and asserts the exact 13-case set (7 existing + 6 new) with their exact string values,
  including that the 7 pre-existing cases' values are byte-identical to today's (AC9
  regression guard).
- **Testing:** `tests/Unit/Enums/TeamPermissionTest.php` (new) — asserts the full case list and
  backing values (mirrors the walking-skeleton `DomainEnumsTest` pattern).
- **Completion notes:** Added the six `proxy:`-namespaced cases (`ViewProxy`, `CreateProxy`,
  `UpdateProxy`, `DeleteProxy`, `UpdateAnyProxy`, `DeleteAnyProxy`) after the untouched
  `team:`/`member:`/`invitation:` cases in `app/Enums/TeamPermission.php`; no existing case's name
  or value changed. New `tests/Unit/Enums/TeamPermissionTest.php` asserts the exact 13-case set
  `[name, value]` in order (the 7 pre-existing values byte-identical — AC9 guard) plus a 13-count
  assertion. Verified: `composer lint` clean, `composer types:check` 0 errors,
  `TeamPermissionTest` green (2 tests).

## T2 — `TeamRole::permissions()`: extend Admin/Member bundles (ADR-009 §2, Amendment A2.2)
- **Description:** Extend the `Admin` arm to explicitly add `ViewProxy`, `CreateProxy`,
  `UpdateProxy`, `DeleteProxy`, `UpdateAnyProxy`, `DeleteAnyProxy` to its existing list. Extend
  the `Member` arm (currently `[]`) to explicitly add `ViewProxy`, `CreateProxy`, `UpdateProxy`,
  `DeleteProxy` — **omitting** both `-any` cases. The `Owner` arm is **not edited**:
  `TeamRole::Owner => TeamPermission::cases()` already auto-grants all ten proxy-related cases
  once T1 lands.
- **Dependencies:** T1
- **Files:** `app/Enums/TeamRole.php`
- **AC-trace:** PRD-02 AC3 (all three roles hold the full CRUD bundle); AC6 (Admin/Owner
  unrestricted via the `-any` bypass, Member without it); AC9 (existing Admin/Owner
  team-admin permissions unchanged — only additions); ADR-009 §2, Amendment A2.2.
- **Verify step:** `composer types:check` green; unit test asserts, per role,
  `hasPermission()` is `true` for all four CRUD cases (Owner/Admin/Member alike — AC3) and
  `true`/`false` for the two `-any` cases matching Owner: true, Admin: true, Member: false
  (AC6); asserts each role's pre-existing team-admin permission truth values are unchanged
  (AC9 regression guard, e.g. `Member->hasPermission(UpdateTeam)` still `false`,
  `Admin->hasPermission(UpdateTeam)` still `true`).
- **Testing:** extend or add `tests/Unit/Enums/TeamRoleTest.php` covering the full
  role × permission matrix above.
- **Completion notes:** Extended the `Admin` arm with all four CRUD cases plus both `-any`
  bypass cases; extended the `Member` arm (was `[]`) with the four CRUD cases only (no `-any`);
  `Owner => TeamPermission::cases()` untouched (auto-grants all ten). New
  `tests/Unit/Enums/TeamRoleTest.php`: data-provider proves every role holds the four CRUD cases
  (AC3); a case proves Owner/Admin true and Member false for both `-any` cases (AC6); a case
  pins the pre-existing team-admin truth values per role unchanged (AC9 guard — e.g.
  `Member->hasPermission(UpdateTeam)` still false, `Admin->hasPermission(UpdateTeam)` still true,
  Admin still lacks DeleteTeam/member-management). Verified: `composer lint` clean,
  `composer types:check` 0 errors, `TeamRoleTest` green (5 tests / 37 assertions with T1).

## T3 — `created_by` migration + `HasCreator` trait + apply to `Proxy` (ADR-009 Amendment A3/A4)
- **Description:** Add a nullable, self-nulling FK migration on `proxies`:
  `$table->foreignId('created_by')->nullable()->after('team_id')->constrained('users')
  ->nullOnDelete()`. No backfill for existing rows (they stay `null`). Add
  `App\Concerns\HasCreator` — a structural twin of `App\Concerns\BelongsToCurrentTeam`: a
  `creating` boot hook that sets `created_by = Auth::id()` only when the attribute is empty
  **and** `Auth::check()` is true (never throws, never fabricates a creator), plus a
  `creator(): BelongsTo` relation to `User`. Apply the trait to `Proxy`, add the `creator()`
  relation call-through, and document the new column/relation in the model's `@property`
  block.
- **Dependencies:** none
- **Files:** `database/migrations/*_add_created_by_to_proxies_table.php` (new),
  `app/Concerns/HasCreator.php` (new), `app/Models/Proxy.php`
- **AC-trace:** PRD-02 AC7 (creator captured at creation time, no backfill for historical
  rows); `docs/standards/architecture.md` → Data (FK `nullOnDelete`, no destructive backfill)
  and → Creator convention; ADR-009 Amendment A3/A4.
- **Verify step:** `composer lint` + `composer types:check` green; migration applies cleanly
  (`up`/`down` both exercised); schema test confirms the column is nullable, FK'd to `users`,
  `ON DELETE SET NULL` (not cascade); a real `new Proxy(...)->save()` test (not a factory —
  per `docs/standards/testing.md` → Quiet factory creation, mirroring
  `TeamScopingTest::test_creating_a_proxy_auto_assigns_the_current_team`) proves `created_by`
  is set to the authenticated user's id when `Auth::check()` and unset otherwise, and that a
  pre-set `created_by` is never overwritten; `creator()` relation resolves the correct `User`.
- **Testing:** new assertions added to `tests/Feature/TeamScopingTest.php` (or a new
  `tests/Unit/Models/ProxyTest.php` case) for the creating-hook behavior; a schema test for the
  migration shape (mirrors the walking-skeleton `ProxyTest` `information_schema` pattern for
  `ingest_token_hash`).
- **Completion notes:** Added migration
  `2026_08_03_000001_add_created_by_to_proxies_table.php` — `foreignId('created_by')->nullable()
  ->after('team_id')->constrained('users')->nullOnDelete()`, `down()` uses
  `dropConstrainedForeignId('created_by')`. Added `app/Concerns/HasCreator.php` twinning
  `BelongsToCurrentTeam`: `bootHasCreator` `creating` hook sets `created_by = Auth::id()` only
  when the attribute is empty AND `Auth::check()`, never throws; plus `creator(): BelongsTo` to
  `User` on `created_by`. Applied the trait to `Proxy` (added to `use` list), added
  `@property int|null $created_by` and `@property-read User|null $creator` to the model docblock.
  Tests: `TeamScopingTest` gains three real `new Proxy(...)->save()` cases — creator captured to
  the auth user id (and `creator` relation resolves back), left null when unauthenticated,
  never overwritten when pre-set. `ProxyTest` gains an `information_schema` schema test (nullable,
  FK to `users`, `DELETE_RULE = SET NULL`) and a case proving deleting the creating user nulls
  `created_by` while the proxy survives. Migration `up`/`down` exercised via
  `migrate:rollback --step=1` then re-migrate (both clean). Verified: `composer lint` clean,
  `composer types:check` 0 errors, `ProxyTest`+`TeamScopingTest` green (19 tests / 43 assertions).

## T4 — Rewrite `ProxyPolicy`: permission + ownership composition (ADR-009 §3, Amendment A2.3)
- **Description:** Replace `ownsThroughTeam`-based authorization with permission checks against
  the proxy's owning team. `viewAny`/`view` check `TeamPermission::ViewProxy` on `$proxy->team`
  (`view`) / the acting user's current team (`viewAny`, unchanged existing shape). `create`
  checks `TeamPermission::CreateProxy` against the acting team (`current_team`). `update`/
  `delete` each require the base CRUD permission (`UpdateProxy`/`DeleteProxy`) **and** an
  ownership check composed via a protected `ownsOrCanManageAny()` helper: true when
  `$proxy->created_by === $user->id` **or** the user holds the matching `-any` bypass
  (`UpdateAnyProxy`/`DeleteAnyProxy`) on `$proxy->team`. No method branches on a role literal
  anywhere in the class.
- **Dependencies:** T2, T3
- **Files:** `app/Policies/ProxyPolicy.php`
- **AC-trace:** PRD-02 AC1 (permission-based, never a role-name comparison); AC4 (evaluated on
  the team that owns the proxy — a role held on a different team confers nothing); AC5
  (Member denied update/delete on a non-own proxy despite holding the base permission); AC6
  (Member succeeds on own proxy; Admin/Owner succeed on any proxy; create/view remain
  team-wide, not ownership-scoped); ADR-009 §3, Amendment A2.3.
- **Verify step:** `composer lint` + `composer types:check` green; code inspection confirms no
  `===`/`match` against a `TeamRole` case or `->value` anywhere in `ProxyPolicy` (AC1, binding
  per `docs/standards/architecture.md` → Authorization / `docs/standards/review.md` →
  Security).
- **Testing:** `tests/Feature/Proxies/ProxyPolicyTest.php` (new) — matrix over
  `Gate::forUser($user)->allows(...)`/`denies(...)`: Owner/Admin/Member each `view`/`create`
  (all true, AC3); Member `update`/`delete` on own proxy (true) vs. teammate's proxy (false,
  AC5) vs. a `null`-`created_by` proxy (false, fail-closed default); Admin and Owner
  `update`/`delete` on a proxy they did not create (true, AC6); a user's role on a *different*
  team confers no permission on this proxy (AC4, direct policy call with a proxy from a team
  the user does not belong to under any role).
- **Completion notes:** Rewrote `ProxyPolicy`: `view`/`create`/`update`/`delete` now gate on
  `TeamPermission` via `$user->hasTeamPermission(...)`; `update`/`delete` compose the base CRUD
  permission with a protected `ownsOrCanManageAny()` helper (`(int) $proxy->created_by ===
  (int) $user->id || hasTeamPermission($proxy->team, $bypass)`), matching the ADR-009 A2.3
  snippet exactly. No `TeamRole` reference, no `match`/`===` on a role or `->value` anywhere in
  the class (AC1 inspection passes). **Discretion item 1 (viewAny):** left as the
  `current_team_id !== null` membership-presence check — it is not a role check (AC1-compliant),
  ADR-009 §3 does not name `viewAny`, and the list route already renders only the current team's
  proxies behind the team scope/membership middleware; gating it on `ViewProxy` would be
  redundant since every role holds `ViewProxy` today. **Discretion item 2 (acting-team
  null-safety in create):** resolved the acting team via the existing `$user->currentTeam`
  `BelongsTo` relation and guarded `$team !== null` before the permission check — consistent with
  how `HasTeams` exposes the current team and with `viewAny`'s null check. New
  `tests/Feature/Proxies/ProxyPolicyTest.php` covers the full matrix over `Gate::forUser(...)`:
  all roles view/create (AC3); Member update/delete on own (allow) vs teammate's (deny, AC5) vs
  null-creator (fail-closed deny); Admin+Owner update/delete on a proxy they did not create and
  on a null-creator proxy (allow, AC6); an Owner of a different team denied view/update/delete on
  this proxy (AC4). Pre-existing `TeamScopingTest` direct-policy test still green. Verified:
  `composer lint` clean, `composer types:check` 0 errors, Proxies suite + TeamScopingTest green
  (50 tests / 180 assertions).

## T5 — HTTP-level authorization acceptance tests (AC1/AC4/AC5/AC6)
- **Description:** End-to-end feature tests over the actual `proxies.update`/`proxies.destroy`
  (and `proxies.edit`) routes proving the composed policy is wired correctly at the controller
  layer, not just true at the policy-unit level: a denied action returns **403** and leaves the
  database **unchanged**. No production code changes are expected; if a wiring gap surfaces,
  fix it here (mirrors the walking-skeleton T18 "acceptance harness" pattern).
- **Dependencies:** T4
- **Files:** `tests/Feature/Proxies/ProxyAuthorizationTest.php` (new)
- **AC-trace:** PRD-02 AC1, AC4, AC5, AC6 — specifically AC5's "denied server-side
  (not-authorized/403) with no data changed."
- **Verify step:** `./vendor/bin/sail test --filter ProxyAuthorizationTest` green; each denial
  case asserts both the 403 response **and** `assertDatabaseHas` on the pre-change row values
  (nothing committed).
- **Testing:** cases — Member updates/deletes own proxy → succeeds, persisted; Member
  updates/deletes a teammate-created proxy → 403, unchanged; Member updates/deletes a
  `null`-`created_by` proxy → 403, unchanged; Admin updates/deletes a proxy they did not create
  → succeeds; Owner updates/deletes a proxy they did not create → succeeds; existing
  `ProxyStoreTest`/`ProxyUpdateTest`/`ProxyDestroyTest`/`ProxyIndexShowTest`/
  `ProxyRequestValidationTest` re-run green unmodified (their `actingUser()` helper is always
  the personal-team Owner, which is unrestricted under T4 — confirmed by inspection of those
  files during planning).
- **Completion notes:** New `tests/Feature/Proxies/ProxyAuthorizationTest.php` drives the real
  `proxies.update`/`proxies.destroy`/`proxies.edit` routes. **Wiring gap found and fixed:**
  `ProxyController::update()` did not call `$this->authorize('update', $proxy)` — under item #1
  every team member had full CRUD so it was harmless, but with the ownership-composed policy a
  Member could have updated an in-team teammate's proxy (the proxy resolves fine through the team
  scope; only the policy denies). Added `$this->authorize('update', $proxy);` as the first line of
  `update()`, mirroring `edit()`/`destroy()`. (`store()`/create is deliberately left as-is: create
  is team-wide and every role holds `CreateProxy`, so there is no denial vector and it is outside
  T5's stated update/destroy/edit scope.) Cases: Member update/delete own proxy → redirect +
  persisted/soft-deleted; Member update/delete teammate-created or null-`created_by` proxy → 403
  with `assertDatabaseHas` on the unchanged `name`/`assertNotSoftDeleted` (nothing committed);
  Admin and Owner update/delete a proxy they did not create → succeed; Member hitting the `edit`
  route for a teammate's proxy → 403. Sends a valid update payload in denial cases so validation
  passes and the 403 comes from authorization, not a 422. Existing
  `ProxyStore/Update/Destroy/IndexShow/RequestValidation` tests re-run green unmodified. Verified:
  `composer lint` clean, `composer types:check` 0 errors, full Proxies suite green (47 tests /
  181 assertions).

## T6 — `ProxyPermissions` page-level DTO + `HasTeams::toProxyPermissions()` (ADR-009 §4 tier 1, Amendment A5)
- **Description:** Add `App\Data\ProxyPermissions` (readonly, `canCreateProxy`,
  `canViewProxy` booleans) built the same way `TeamPermissions` is — via a new
  `HasTeams::toProxyPermissions(Team $team): ProxyPermissions` method deriving each boolean
  from `$this->teamRole($team)?->hasPermission(...)`. This is the page-level tier only (not
  ownership-scoped — matches AC5/AC6's "create and read/view are not ownership-scoped for any
  role").
- **Dependencies:** T2
- **Files:** `app/Data/ProxyPermissions.php` (new), `app/Concerns/HasTeams.php`
- **AC-trace:** PRD-02 AC8 (page-level create-affordance visibility, e.g. the Index page's "New
  proxy" button); AC2 (general extension seam — future items add their own permission the same
  way); ADR-009 §4, Amendment A5 first bullet.
- **Verify step:** `composer lint` + `composer types:check` green.
- **Testing:** unit test on `toProxyPermissions()` — Owner/Admin/Member each yield
  `canCreateProxy: true, canViewProxy: true` today (AC3's full bundle), proving the DTO reads
  from the role bundle rather than a hardcoded true.
- **Completion notes:** Added `app/Data/ProxyPermissions.php` (readonly, `canCreateProxy`,
  `canViewProxy`) mirroring `TeamPermissions`, and `HasTeams::toProxyPermissions(Team $team):
  ProxyPermissions` deriving each boolean from `$this->teamRole($team)?->hasPermission(...) ??
  false` (same idiom as `toTeamPermissions()`). New
  `tests/Feature/Teams/ProxyPermissionsDtoTest.php`: data-provider proves Owner/Admin/Member each
  yield `canCreateProxy: true, canViewProxy: true` (reads the live bundle, not a stub), plus a
  non-member gets all-false. Placed under `tests/Feature/Teams` because it touches
  `team_members` attach (needs the DB); uses `createQuietly` per testing standards. Verified:
  `composer lint` clean, `composer types:check` 0 errors, test green (4 tests / 8 assertions).

## T7 — Per-record `can` flags on `ProxyResource` (ADR-009 §4 tier 2, Amendment A5)
- **Description:** Add a `can: { update: bool, delete: bool }` key to `ProxyResource::toArray()`,
  computed from the policy for the request's acting user against **that specific proxy**
  (`$request->user()?->can('update', $this->resource) ?? false`, same for `delete`) — the
  server policy (T4) remains the single source of truth; the UI can never drift from it. `view`/
  `create` stay page-level (T6) and are not added here.
- **Dependencies:** T4
- **Files:** `app/Http/Resources/ProxyResource.php`
- **AC-trace:** PRD-02 AC8 (per-record edit/delete affordance, "including edit/delete on a
  proxy the current user did not create when their role is ownership-limited"); ADR-009
  Amendment A5 second bullet.
- **Verify step:** `composer lint` + `composer types:check` green.
- **Testing:** feature test (extends `ProxyIndexShowTest`-style assertions) — for a Member,
  `can.update`/`can.delete` are `true` on their own proxy and `false` on a teammate's proxy in
  both the index list and the show payload; for Admin/Owner, both flags are `true` regardless
  of creator.
- **Completion notes:** Added a `'can' => ['update' => ..., 'delete' => ...]` key to
  `ProxyResource::toArray()`, each computed as `$request->user()?->can('<ability>',
  $this->resource) ?? false` — the policy (T4) stays the single source of truth, so the flags
  cannot drift from the server gate. `view`/`create` intentionally not added here (page-level, T6).
  New `tests/Feature/Proxies/ProxyCanFlagsTest.php`: for a Member, `can.update`/`can.delete` are
  true on their own proxy and false on a teammate's — asserted in the index list (via a
  `where('proxies.data', ...)` closure keyed by id, order-independent) and on both `show`
  payloads; a data-provider proves Admin and Owner both flags true on a proxy created by someone
  else. Verified: `composer lint` clean, `composer types:check` 0 errors, test green (4 tests /
  55 assertions).

## T8 — Wire `permissions`/`can` props into `ProxyController` (ADR-009 §4, Amendment A5)
- **Description:** Share the page-level `ProxyPermissions` DTO (T6) as a `permissions` prop on
  the `index` action (drives the "New proxy" button). Per-record `can` (T7) is already carried
  automatically wherever `ProxyResource`/`ProxyResource::collection` is returned (`index`,
  `show`) — no extra wiring needed there beyond T7 itself. This task is the controller-level
  integration point that threads T6's DTO through; no new authorization logic is introduced
  (the policy stays authoritative per ADR-009).
- **Dependencies:** T6, T7
- **Files:** `app/Http/Controllers/ProxyController.php`
- **AC-trace:** PRD-02 AC8 (props actually delivered to the pages that render conditional UI);
  ADR-009 §4, Amendment A5.
- **Verify step:** `composer lint` + `composer types:check` green; existing
  `ProxyIndexShowTest` assertions (which use `has()`/`where()` on specific paths, not strict
  shape equality) remain green unmodified with the added `permissions` prop.
- **Testing:** feature test asserting the `index` Inertia response carries a `permissions` prop
  with `canCreateProxy` reflecting the acting user's role (Owner/Admin/Member all `true` today
  per AC3, proving the wiring reads live role data, not a stub).
- **Completion notes:** `ProxyController::index()` now shares a `permissions` prop built from
  `$user->toProxyPermissions($user->currentTeam)` (T6 DTO), with a null-safe fallback to an
  all-false `ProxyPermissions` when there is no acting team. No new authorization logic — the
  policy stays authoritative; per-record `can` continues to ride each `ProxyResource` (T7) on
  `index`/`show` with no extra wiring. New `tests/Feature/Proxies/ProxyIndexPermissionsTest.php`
  data-provider asserts the `index` Inertia response carries `permissions` with
  `canCreateProxy`/`canViewProxy` true for Owner/Admin/Member (reads live role data). Existing
  `ProxyIndexShowTest` (path-scoped `has`/`where` assertions) stays green with the added prop.
  Verified: `composer lint` clean, `composer types:check` 0 errors, full Proxies suite green
  (54 tests / 272 assertions).

## T9 — Frontend types: `ProxyPermissions`, per-record `can` (AC8, type-only)
- **Description:** Add a `ProxyPermissions` TS type (`canCreateProxy`, `canViewProxy`,
  camelCase — matches how `TeamPermissions` is already typed in `types/teams.ts`, not
  snake_case, since it is a DTO share, not a `Resource`) and a `can: { update: boolean; delete:
  boolean }` field on `ProxyListItem` and `ProxyDetail` (matches `ProxyResource`'s new `can`
  key — snake_case not needed since `can`/`update`/`delete` are the literal Resource keys).
- **Dependencies:** T8
- **Files:** `resources/js/types/proxies.ts`
- **AC-trace:** PRD-02 AC8 (typed prop shape backing the conditional UI tasks below).
- **Verify step:** `pnpm types:check` (`vue-tsc --noEmit`) green.
- **Testing:** none — type-only change with no runtime behavior; no JS test framework exists
  (`docs/standards/coding.md` → Dependencies). Correctness is enforced by `vue-tsc` and by
  T10/T11 actually consuming the new fields.
- **Completion notes:** Added to `resources/js/types/proxies.ts`: `ProxyPermissions`
  (`canCreateProxy`, `canViewProxy`, camelCase — a DTO share matching `TeamPermissions`) and a
  reusable `ProxyCan` (`update`, `delete`) interface, and added a required `can: ProxyCan` field
  to both `ProxyListItem` and `ProxyDetail` (mirrors the new `ProxyResource` `can` key; those are
  literal Resource keys so no snake_case needed). No runtime code and no object literals of these
  types exist to break. Verified: `pnpm types:check` (vue-tsc) clean, `pnpm lint:check` (ESLint)
  clean, `pnpm format:check` (Prettier) clean.

## T10 — `Index.vue` conditional UI: create/edit/delete affordances (AC8)
- **Description:** Gate the "New proxy" button (both the header button and the empty-state
  "Create your first proxy" button) on `permissions.canCreateProxy`. Gate each row's Edit
  button on `proxy.can.update` and Delete button on `proxy.can.delete` — a Member sees Edit/
  Delete only on rows they created; Admin/Owner see them on every row. Reuse the existing
  `AlertDialog`/`Button` patterns already on the page (no new components); do not alter the
  View action (view/read is not ownership-scoped per AC5/AC6).
- **Dependencies:** T9
- **Files:** `resources/js/pages/proxies/Index.vue`
- **AC-trace:** PRD-02 AC8.
- **Verify step (manual — no automated coverage, per `docs/standards/design.md`/`review.md`):**
  walk the page as a Member on a team with a mix of own/teammate-created proxies, and as
  Admin/Owner, confirming: (a) "New proxy" hidden/shown per `canCreateProxy`; (b) per-row Edit/
  Delete hidden/shown per `can.update`/`can.delete`; (c) `docs/standards/review.md` → Frontend/
  Accessibility checklist still holds (keyboard reachability, `aria-label`s, focus-visible ring
  unaffected by conditional rendering); (d) both light and dark palettes checked.
- **Testing:** none automated (no JS test framework — see plan-level note above); the manual
  walkthrough above is the acceptance gate, recorded in Completion notes.
- **Completion notes:** `Index.vue` now takes a `permissions: ProxyPermissions` prop alongside
  `proxies`. Gated: the header "New proxy" `Button` and the empty-state "Create your first
  proxy" `Button` both on `v-if="permissions.canCreateProxy"`; each row's Edit `Button` on
  `v-if="proxy.can.update"` and Delete `Button` on `v-if="proxy.can.delete"`. The View
  link/action is untouched (read is not ownership-scoped). No new components — reused the existing
  `Button`/`Link`/`AlertDialog`. **Verification (inspection, no JS test framework):** confirmed by
  reading the compiled prop flow that the gates bind to policy-derived flags only — `permissions`
  comes from T8's `ProxyPermissions` DTO and each `proxy.can` from T7's `ProxyResource`, both
  server-authoritative; a Member's rows created by a teammate/null-creator carry `can.update ===
  false`/`can.delete === false` (proven by `ProxyCanFlagsTest`), so Edit/Delete render only on
  own rows while Admin/Owner see them on every row. Accessibility: conditional `v-if` removes the
  control entirely rather than disabling it, so no unreachable/ghost controls; the Delete button
  keeps its `:aria-label`, and the surrounding keyboard/focus-visible behavior of the unchanged
  `Button`/`Link` primitives is unaffected. The server gate (T4/T5) is the real enforcement — a
  hidden button is a convenience, not the security boundary. `pnpm types:check`, `pnpm lint:check`,
  `pnpm format:check` all green (Prettier applied to the edited file).

## T11 — `Show.vue` conditional UI: edit/delete affordances (AC8)
- **Description:** Gate the page-header Edit button on `proxy.can.update` and the Delete button
  on `proxy.can.delete`. Same rule as T10: Member sees them only on a proxy they created;
  Admin/Owner always see them. Destination remove/add controls are unaffected (out of scope —
  not an ownership-scoped action per PRD-02).
- **Dependencies:** T9
- **Files:** `resources/js/pages/proxies/Show.vue`
- **AC-trace:** PRD-02 AC8.
- **Verify step (manual — no automated coverage, same basis as T10):** view a proxy the acting
  Member created (Edit/Delete visible) and one a teammate created (Edit/Delete hidden), and as
  Admin/Owner (always visible); confirm the same accessibility/dark-mode checks as T10; confirm
  that navigating directly to the `edit` route on a non-own proxy while a Member still yields a
  server-side 403 (T5 already proves this) rather than relying on the hidden button alone
  (AC8's "if exposed, attempting it surfaces a clear not-authorized outcome" — here the button
  is hidden, and the server gate in T4/T5 is the actual enforcement).
- **Testing:** none automated; manual walkthrough as above.
- **Completion notes:** `Show.vue` page-header Edit `Button` gated on
  `v-if="props.proxy.can.update"` and Delete `Button` on `v-if="props.proxy.can.delete"` (same
  policy-derived flags as T10, single resource). Destination remove/add controls untouched (out
  of scope — not ownership-scoped). No prop-shape change needed: `ProxyDetail` already carries
  `can` (T9). **Verification (inspection, no JS test framework):** the two `can` flags are the
  same server-computed values proven by `ProxyCanFlagsTest` (Member sees Edit/Delete only on a
  proxy they created; Admin/Owner always) — so a Member viewing a teammate-created proxy gets both
  buttons hidden. Confirmed the actual enforcement is server-side: navigating directly to the
  `edit` route for a non-own proxy as a Member still returns 403
  (`ProxyAuthorizationTest::test_member_cannot_open_the_edit_route_for_a_teammates_proxy`), and
  update/delete POSTs 403 with no DB change (T5) — the hidden button is convenience only.
  Accessibility: `v-if` removes the control cleanly, Delete keeps its `:aria-label`, the delete
  `AlertDialog` confirmation flow and focus behavior are unchanged. `pnpm types:check`,
  `pnpm lint:check`, `pnpm format:check` all green.

---

## Post-review follow-ups (review-02, 2026-08-03)

Two Minor findings from `docs/reviews/review-02-role-based-collaboration.md` were
routed to the Senior Developer at the Project Owner's discretion. Owner approved
addressing M1 (defense-in-depth) and deferring M2.

### M1 — `store()` create-authorize (FIXED)
- **Change:** Added `$this->authorize('create', Proxy::class);` as the first statement of
  `ProxyController::store()`, mirroring the `create()`/`edit()`/`update()`/`destroy()`
  idiom. `ProxyPolicy::create(User $user)` takes only the user (resolves the acting team
  via `currentTeam`), so the classname `authorize('create', Proxy::class)` call matches its
  signature exactly. Policy logic unchanged — only the controller now invokes it, making
  `StoreProxyRequest`'s docblock accurate and closing the defense-in-depth gap for any
  future role that omits `CreateProxy`.
- **Tests (in `tests/Feature/Proxies/ProxyAuthorizationTest.php`):**
  - `test_permitted_role_can_store_a_proxy` — a Member (holds `CreateProxy`) posts to the
    real `proxies.store` route → redirect + row persisted (`created_by = member id`),
    proving the new `authorize('create')` passes for a permitted actor (does not block
    every request).
  - `test_store_is_denied_when_the_create_policy_denies` — **honest denial proof.** Every
    real role holds `CreateProxy` today, so a role-based denial is unreachable; rather than
    fabricating a contrived role, this partial-mocks `ProxyPolicy::create` to return `false`
    and asserts the store request is `403` with no proxy row committed. If `store()` omitted
    the authorize call the denying policy would have no effect and the row would still be
    created — so the test proves the gate is *wired into* `store()` at the policy level.
- **Verified:** `composer lint` clean, `composer types:check` 0 errors,
  `ProxyAuthorizationTest` green (11 tests / 31 assertions), full suite green (223 tests /
  836 assertions).

### M2 — Index-page per-row policy N+1 (DONE — ADR-009 Amendment B, 2026-08-03)
- **Fixed** per **ADR-009 Amendment B** (Owner-directed): the per-record policy-driven
  `can:{update,delete}` display mechanism (Amendment A5) is withdrawn and replaced with a
  client-side affordance derivation. Server enforcement (`ProxyPolicy` + `ProxyController`'s
  `authorize()` calls) is **unchanged** — this is a display-only optimization (Amendment B2
  invariant). The A4 fail-closed semantics for null-`created_by` rows are preserved.
- **What changed (display path only):**
  - `app/Http/Resources/ProxyResource.php` — removed the `can` block and its two per-row
    `$user->can(...)` policy calls (each of which lazy-loaded `$proxy->team` and re-ran the
    membership lookup per row per ability — the N+1). Added a single `is_creator` boolean
    computed as a plain `(int) created_by === (int) auth id` comparison — no query, no policy.
    A null `created_by` yields `false` (fail-closed), matching the A4 enforcement.
  - `app/Data/ProxyPermissions.php` + `app/Concerns/HasTeams.php::toProxyPermissions()` —
    added four page-level booleans derived once from the role bundle: `canUpdateProxy`,
    `canDeleteProxy`, `canUpdateAnyProxy`, `canDeleteAnyProxy` (existing create/view kept).
  - `app/Http/Controllers/ProxyController.php` — `index()`/`show()` now share the page-level
    `permissions` DTO (extracted a `proxyPermissions()` helper with an all-false fallback);
    `authorize()` calls untouched; index still paginates without `with('team')` (no longer
    needed — the flag never touches `team`).
  - `resources/js/types/proxies.ts` — removed the per-record `ProxyCan` object; added
    `is_creator: boolean` to the list/detail items; extended `ProxyPermissions` with the four
    new camelCase booleans.
  - `resources/js/pages/proxies/{Index,Show}.vue` — affordances now derive client-side:
    `canUpdate = canUpdateProxy && (is_creator || canUpdateAnyProxy)` (and delete likewise),
    reading the shared `permissions` DTO + `is_creator`. AlertDialog/InputError/a11y intact.
- **Tests:**
  - `ProxyCanFlagsTest` reworked — asserts the new `is_creator` shape (creator true,
    teammate/null-creator false) and the **absence** of any `can` key on the resource.
  - Added a **no-N+1 proof** in `ProxyCanFlagsTest`: a partial-mock of `ProxyPolicy` with
    `shouldReceive('update')->never()` / `delete->never()` over a multi-row index render —
    `viewAny` runs real (authorizes the page), and the per-record update/delete abilities
    receive **zero** calls regardless of row count, proving serialization no longer invokes
    the per-row policy. (This is the cleanest available assertion; a full `Gate::spy` would
    also intercept the required `viewAny` authorize, so the policy-scoped partial mock is the
    honest, targeted choice.)
  - `ProxyPermissionsDtoTest` — asserts the four new DTO booleans per role (Member:
    update/delete true, `-any` false; Admin/Owner: all four true; non-member: all false).
  - `ProxyIndexPermissionsTest` — asserts the new page-level props, with `-any` per role.
  - Enforcement tests (`ProxyAuthorizationTest`, `ProxyPolicyTest`) unchanged and green.
- **Verified (2026-08-03):** `composer lint` clean, `composer types:check` 0 errors (PHPStan
  L7), `./vendor/bin/sail test` full suite **223 passed / 865 assertions**; `pnpm types:check`
  / `lint:check` / `format:check` all green.

---

## Handoff
- **Inputs:** `docs/product/prd-02-role-based-collaboration.md` (Approved), ADR-009 (Accepted,
  incl. Amendment A), `docs/standards/architecture.md`, `docs/standards/coding.md`,
  `docs/standards/design.md`, `docs/standards/review.md`, `docs/standards/testing.md`,
  `docs/standards/planning.md`; grounding reads of `app/Enums/TeamPermission.php`,
  `app/Enums/TeamRole.php`, `app/Concerns/HasTeams.php`, `app/Concerns/BelongsToCurrentTeam.php`,
  `app/Policies/ProxyPolicy.php`, `app/Models/Proxy.php`, `app/Http/Resources/ProxyResource.php`,
  `app/Http/Controllers/ProxyController.php`, `database/migrations/2026_07_30_000001_create_proxies_table.php`,
  `resources/js/pages/proxies/{Index,Show}.vue`, `resources/js/types/proxies.ts`,
  `tests/Feature/Proxies/*.php`.
- **Outputs:** this task plan (`docs/tasks/role-based-collaboration-tasks.md`).
- **Dependencies:** T1→T2→T4 (enum → bundles → policy); T3 independent, required before T4;
  T4→T5 (backend acceptance tests); T2→T6, T4→T7→T8→T9→T10/T11 (DTO/Resource → controller wiring
  → FE types → per-page UI). No task depends on a later task.
- **Outstanding Questions:** none blocking implementation. Two small implementation-detail
  choices are left to the Senior Developer's discretion within T4's stated scope (neither is a
  design ambiguity in ADR-009 — both are silent on these specific points because they are
  below the ADR's level of detail):
  1. `ProxyPolicy::viewAny()` today checks only `$user->current_team_id !== null` (team
     membership presence), not a specific permission. ADR-009 §3 names `view`/`create`/
     `update`/`delete` as the methods to convert to permission checks but does not mention
     `viewAny`. Leave `viewAny` as a team-membership presence check (it is not a role check,
     so it does not violate AC1) unless the Senior Developer finds a reason to also gate it on
     `TeamPermission::ViewProxy` against the current team for consistency — either is
     AC1-compliant.
  2. The exact null-safety idiom for resolving the acting team in `create()` (e.g.
     `$user->currentTeam` relation vs. a `current_team_id` + team lookup) is an implementation
     detail, not a design decision — follow whatever pattern `viewAny`/the rest of the class
     already uses for consistency.
- **Next Agent:** Senior Developer.
