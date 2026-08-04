# Review: Role-based collaboration — permission-gated proxy authorization (item #2)

- **Reviewer / date:** Reviewer — 2026-08-03
- **Scope:** Branch `feat/item-02-role-based-collaboration` (uncommitted working
  tree). Changed source: `TeamPermission`, `TeamRole`, `ProxyPolicy`, `HasCreator`
  (new), `ProxyPermissions` DTO (new), `HasTeams`, `ProxyResource`, `Proxy`,
  `ProxyController`, `created_by` migration (new), `resources/js/types/proxies.ts`,
  `proxies/Index.vue`, `proxies/Show.vue`, and their tests.
- **Inputs verified:** PRD-02 (Approved, Owner 2026-08-03), ADR-009 (Accepted incl.
  Amendment A, Owner 2026-08-03), task plan `role-based-collaboration-tasks.md`
  (Approved, completion notes present on T1–T11), `docs/standards/{review,coding,
  architecture,design,testing}.md`, CLAUDE.md commands.
- **Gates run (actual, not claimed):**
  - `composer lint` (Pint) → **passed**, clean.
  - `composer types:check` (PHPStan L7) → **passed, 0 errors**.
  - `./vendor/bin/sail test` → **221 passed / 832 assertions**, 0 failures.
  - `pnpm types:check` (`vue-tsc --noEmit`) → **clean**.
  - `pnpm lint:check` (ESLint) → **clean**.
  - `pnpm format:check` (Prettier over `resources/`) → **clean**.

## Summary

The implementation is complete and faithful to PRD-02 and ADR-009 (base decision +
Amendment A). Proxy authorization is now permission-based and team-scoped, the
ownership axis is modeled as bundle-carried `-any` permissions (no role literal in
the policy), creator capture is a boot-hook trait twinning `BelongsToCurrentTeam`
with a nullable self-nulling FK, and the conditional UI is driven entirely by
server-computed policy flags. Every acceptance criterion is implemented and backed
by a test with meaningful assertions. All six toolchain gates are green.

The scrutiny items in the review brief check out:

- **`ProxyController::update()` production fix is present** (line 135,
  `$this->authorize('update', $proxy)` as the first statement), and
  `ProxyAuthorizationTest` proves a denied update returns 403 with the row
  unchanged (`assertDatabaseHas` on the original name) and a denied delete leaves
  the row not-soft-deleted.
- **AC9 is a true regression-free additive change.** `git diff` on both enums shows
  the 7 existing `team:`/`member:`/`invitation:` cases and their string values are
  byte-identical, and the Admin arm's existing team-admin entries (`UpdateTeam`,
  `CreateInvitation`, `CancelInvitation`) are untouched — only proxy cases were
  appended; the Owner arm (`TeamPermission::cases()`) was not edited.
- **Ownership denial prevents the data change, not just returns 403 after
  mutating.** The policy composes `hasTeamPermission(UpdateProxy) && (ownedRecord ||
  hasTeamPermission(UpdateAnyProxy))`; the gate runs before any mutation in the
  controller, and the DB-unchanged assertions confirm nothing is committed. A
  Member is blocked from a teammate's in-team proxy even though the team scope
  resolves the record (`test_member_updating_a_teammates_proxy_is_forbidden_and_unchanged`).
- **`created_by` in unauthenticated contexts is safe.** `HasCreator::bootHasCreator`
  sets `created_by` only when the attribute is empty **and** `Auth::check()`; it
  never throws and never fabricates a creator, leaving null in console/queue/ingest.
  A null `created_by` fails id-equality, so ownership-limited roles are denied
  (fail-closed) while Admin/Owner still pass via the `-any` bypass.
- **No secret/token/raw-payload logging** was introduced in any changed source
  (grep of the five changed PHP source files finds no `Log::`/`logger()`/`info()`).

Two Minor follow-ups are recorded below; neither blocks approval. **Recommendation:
Approve with follow-ups.** The final decision rests with the Project Owner.

## PRD-02 acceptance-criteria coverage

| AC | Implemented | Test evidence | Verdict |
|---|---|---|---|
| AC1 permission-based, never a role-name comparison | `ProxyPolicy` gates every decision on `hasTeamPermission(...)`; grep confirms no `TeamRole`/`->value`/`match`-on-role in the class (only docblock prose) | `ProxyPolicyTest`, `ProxyAuthorizationTest` | Pass |
| AC2 ≥4 CRUD proxy permissions, extensible taxonomy | Six `proxy:`-namespaced cases added to `TeamPermission`; DTO/bundle seam reused so a later item adds one case + one bundle arm + one policy method | `TeamPermissionTest` (13-case guard), `TeamRoleTest` | Pass |
| AC3 all three roles hold the full CRUD bundle | Member arm extended `[] → [View,Create,Update,Delete]`; Admin adds all four; Owner via `cases()` | `TeamRoleTest::test_every_role_holds_the_full_proxy_crud_bundle` (data provider over all 3 roles) | Pass |
| AC4 evaluated on the team that owns the proxy | Policy passes `$proxy->team` to `hasTeamPermission`; a role on a different team confers nothing | `ProxyPolicyTest::test_a_role_on_a_different_team_confers_no_permission_on_this_proxy` | Pass |
| AC5 ownership-scoped update/delete; Member denied on non-own, 403, no data change | `ownsOrCanManageAny()` composition; controller authorize before mutation | `ProxyAuthorizationTest` (403 + `assertDatabaseHas`/`assertNotSoftDeleted` on teammate + null-creator) | Pass |
| AC6 permitted mirror: Member on own, Admin/Owner on any; create/read team-wide | Own-record short-circuit + `-any` bypass for Admin/Owner; view/create single-axis | `ProxyPolicyTest`, `ProxyAuthorizationTest` (admin+owner on non-own succeed) | Pass |
| AC7 creator captured at creation, no historical backfill | `HasCreator` `creating` hook (`Auth::id()` when empty && `Auth::check()`); nullable FK, no backfill | `TeamScopingTest` (real `new Proxy()->save()` cases), `ProxyTest` schema test | Pass |
| AC8 per-record conditional UI, server-authoritative | Page-level `ProxyPermissions` (create/view) + per-record `can:{update,delete}` on `ProxyResource` from the policy; `Index.vue`/`Show.vue` `v-if` on those flags | `ProxyCanFlagsTest`, `ProxyIndexPermissionsTest`; Vue inspected directly | Pass |
| AC9 team-administration authorization unchanged | Additive enum + bundle changes only; existing cases/values/arms byte-identical | `git diff` inspection, `TeamRoleTest::test_existing_team_administration_permissions_are_unchanged`, `TeamPermissionTest` | Pass |

## ADR-009 conformance

| ADR-009 element | Verified in | Verdict |
|---|---|---|
| Additive `TeamPermission` proxy CRUD + two `-any` cases | `app/Enums/TeamPermission.php` | Match |
| Bundles: Owner via `cases()`, Admin explicit (both `-any`), Member no `-any` | `app/Enums/TeamRole.php` | Match |
| `HasCreator`: `created_by` set only when unset && `Auth::check()`; `creator()` relation | `app/Concerns/HasCreator.php` | Match |
| Nullable `created_by` FK, `nullOnDelete`, no backfill | migration `2026_08_03_000001_...` | Match |
| `ProxyPolicy` composes `hasTeamPermission` with ownership via `-any` bypass; no role literal | `app/Policies/ProxyPolicy.php` | Match (A2.3 snippet reproduced verbatim) |
| `ProxyPermissions` DTO page-level (create/view); per-record `can:{update,delete}` on `ProxyResource` | `app/Data/ProxyPermissions.php`, `HasTeams::toProxyPermissions`, `ProxyResource::toArray` | Match |

Discretion items in the task plan (viewAny left as membership-presence check;
`$user->currentTeam` null-safe resolution in `create`) are both AC1-compliant (no
role check) and consistent with the rest of the class — accepted as designed.

## Standards checklist (`docs/standards/review.md`)

- **Security** — Every proxy decision lives in `ProxyPolicy` consuming
  `TeamPermission` via `hasTeamPermission`; no policy/controller branches on a role
  literal. `created_by` id-equality is composed *with* (not instead of) the
  permission check; null creator is a safe deny (ADR-009 item verified). No
  never-log-list value written to logs in any changed source. **Pass** (one Minor on
  `store()` authorize, below).
- **Data / Migrations** — No lower layer references `Http/`; authorization is only
  in the Policy. `created_by` is `nullable()->constrained('users')->nullOnDelete()`
  via `HasCreator` — matches the Creator convention and surviving-actor FK intent.
  Forward-only, no destructive backfill, pre-feature rows stay null. Migration date
  (`2026_08_03`) orders after the `proxies` table creation. **Pass.**
- **Backend code** — Controller stays thin (authorize → Form Request → transaction →
  Inertia/redirect). Inertia props serialize through `ProxyResource` (`$wrap = null`).
  DTO is a typed `readonly` class. No new Composer/pnpm dependency (ADR-009 mandates
  none). PHPStan L7 clean. **Pass.**
- **Frontend / Accessibility** (manual, no JS test framework) — `Index.vue` and
  `Show.vue` inspected directly: create/edit/delete affordances gated with `v-if` on
  server-derived flags (`permissions.canCreateProxy`, `proxy.can.update/delete`);
  `v-if` removes controls entirely (no disabled/ghost unreachable controls); Delete
  buttons keep `:aria-label`; the destructive `AlertDialog` retains Cancel-before-
  destructive-Confirm, `:disabled` during request, and the item-#1 regression pattern
  (`open` ref separate from target, target reset in `onFinish`) is intact and
  untouched; server-side `Resource` keys stay `snake_case`, the DTO share is
  `camelCase`. **Pass.**
- **Testing** — Suite green locally (221/832). Factory records use `createQuietly()`;
  no test declares `RefreshDatabase`; the `HasCreator` auto-assign hook is covered by
  a real `new Proxy(...)->save()` test (not a factory) in `TeamScopingTest`;
  cross-team isolation sets distinct `team_id`s explicitly. **Pass.**
- **Toolchain / CI** — `composer lint`, `composer types:check`, `pnpm types:check`,
  `pnpm lint:check`, `pnpm format:check` all clean. **Pass.**
- **Documentation / Process** — PRD-02/ADR-009 approvals recorded in the artifacts
  and `docs/status.md`; task plan carries Status/Author/Approval/Handoff and truthful
  completion notes verified against the code. **Pass.**

## Findings

### Minor

- **M1 — `ProxyController::store()` does not call `$this->authorize('create', …)`**
  (`app/Http/Controllers/ProxyController.php:62`). The `create()` form action
  authorizes (line 54) and `StoreProxyRequest`'s docblock asserts "Authorization
  lives on the controller endpoint (`ProxyPolicy::create` via `$this->authorize`)",
  but `store()` performs no policy check — it relies solely on `EnsureTeamMembership`
  middleware plus the fact that all three roles hold `CreateProxy`. **No denial
  vector exists today** (every team role holds `CreateProxy`; non-members are blocked
  by middleware before reaching the action), so no PRD-02 AC is violated — this is a
  pre-existing item-#1 condition, not introduced by #2. Criterion: `review.md` →
  Security ("the controller calls `$this->authorize(...)` against a Policy"). Adding
  `$this->authorize('create', Proxy::class);` as the first line of `store()` would
  align it with `create()` and make the request's own docblock accurate as
  defense-in-depth for any future role that omits `CreateProxy`. Follow-up, does not
  block.

- **M2 — Per-row policy evaluation on the index page is an N+1**
  (`app/Http/Resources/ProxyResource.php:43-46`, `app/Http/Controllers/ProxyController.php:31-34`).
  The index query paginates without `with('team')`, and each row's `can.update`/
  `can.delete` calls `$user->can(...)` → `hasTeamPermission($proxy->team, …)`, which
  lazy-loads `$proxy->team` and re-runs the membership lookup (`teamRole` is not
  memoized) per row and per ability. At the capped page size of 15 this is tolerable
  and correctness is unaffected, but it multiplies queries per page load. Criterion:
  `coding.md` performance hygiene (no explicit `review.md` bullet). Follow-up
  (e.g. eager-load `team`, or memoize `teamRole` per team on the user); does not
  block.

### Blockers

None.

### Majors

None.

## Recommendation

**Approve with follow-ups.** Every PRD-02 acceptance criterion (AC1–AC9) and every
ADR-009 element (base + Amendment A) is implemented, tested, and green across all six
toolchain gates. The two Minor findings (M1 defense-in-depth `store()` authorize; M2
index N+1) are non-blocking follow-ups that route to the Senior Developer at the
Project Owner's discretion. The release decision remains with the Project Owner.

## Handoff

- **Inputs:** PRD-02, ADR-009 (incl. Amendment A), task plan, the six standards docs,
  the changed source and tests listed under Scope.
- **Outputs:** this review.
- **Routing:** No Blocker/Major → Project Owner for the release decision. Minor
  findings M1/M2 → Senior Developer if the Owner elects to address them (neither
  gates approval).
- **Next Agent:** Project Owner.
</content>
</invoke>
