# ADR-009: Team-scoped proxy permissions via the existing native TeamRole/TeamPermission enum pattern

- **Status:** Accepted (Project Owner, 2026-08-03; includes Amendment A)
- **Author:** Principal Engineer
- **Date:** 2026-08-03 (amended 2026-08-03 — see *Amendment A*: ownership scoping & creator capture)
- **Feature:** #2 role-based collaboration (`docs/product/prd-02-role-based-collaboration.md`); build-ahead seam for #5, #6, #7, #8, #13
- **Resolves:** `docs/questions/prd-02-permission-mechanism-selection.md` (Q-02-02);
  `docs/questions/prd-02-creator-capture-ownership-composition.md` (Q-02-03, via *Amendment A*)

## Question
Which mechanism should implement **team-scoped permission storage and checks**
for proxy actions (view/create/update/delete, and later mapping-edit #8, replay
#6, storage/mode config #5/#7, notification opt-out #13): **Laratrust**,
**Spatie laravel-permission**, or **extending the project's existing native
Jetstream-style `TeamRole`/`TeamPermission` enum pattern**? The three names are
Owner-supplied context, not a mandate. The choice must satisfy PRD-02's
requirement that proxy authorization be permission-based (never a direct role
check), team-scoped to the team that owns the proxy, and general enough that a
later item registers a new proxy-scoped permission without reshaping
authorization (AC1–AC6). This is not a greenfield decision — the enums, the
team-scoping, and the policy-consumption surface already exist and are in use.

## Decision
**Extend the existing native `TeamRole`/`TeamPermission` enum pattern. Add no
new library.**

Concretely, at a design level (not implemented here):

1. **Define proxy permissions as new cases on the existing `TeamPermission`
   enum**, string-backed under a `proxy:` namespace mirroring the current
   `team:` / `member:` / `invitation:` convention — e.g. `ViewProxy =
   'proxy:view'`, `CreateProxy = 'proxy:create'`, `UpdateProxy = 'proxy:update'`,
   `DeleteProxy = 'proxy:delete'`. These sit alongside the existing
   team-administration cases; the existing cases and their string values are
   unchanged (satisfies PRD-02 AC8 / Out-of-Scope).
2. **Extend `TeamRole::permissions()`** so each role's static bundle includes its
   proxy permissions, per the mapping resolved in Q-02-01
   (`docs/questions/prd-02-role-permission-mapping.md`). Because
   `TeamRole::Owner => TeamPermission::cases()`, the Owner automatically holds
   every proxy permission — desirable and requires no edit to the Owner arm.
   Admin and Member bundles are extended explicitly according to the Q-02-01
   mapping.
3. **Rewrite `ProxyPolicy` to check permissions, not membership.** Each of
   `view`/`update`/`delete` resolves the proxy's owning team and calls
   `$user->hasTeamPermission($proxy->team, TeamPermission::…)`; `create` checks
   the create permission against the acting team (`current_team`). This reuses
   the identical seam `TeamPolicy` already uses (`hasTeamPermission` →
   `teamRole($team)?->hasPermission(...)`), which reads the user's role from the
   `team_members` pivot — i.e. team-scoping is inherited, not rebuilt.
4. **Expose the acting user's proxy permissions to the frontend (AC7)** by
   extending the existing DTO seam — a `ProxyPermissions` readonly DTO built the
   same way `HasTeams::toTeamPermissions()` builds `TeamPermissions` (booleans
   derived from the role's bundle), shared to Inertia so Vue conditionally
   renders create/edit/delete affordances on `proxies/Index.vue` /
   `proxies/Show.vue`. Server-side policy remains the authoritative gate (AC5).

This is a permission-store decision only. The **role-to-proxy-permission
mapping** (Q-02-01) and the **PRD approval** remain separate gates — see
Approval gate below.

## Alternatives
- **Spatie laravel-permission (team-scoped)** — mature, DB-backed roles/permissions
  with a teams feature. Rejected: introduces a **parallel, DB-backed source of
  truth for team roles** competing with the already-authoritative `team_members`
  pivot + `Membership.role` cast; requires migrations, config, a
  `setPermissionsTeamId()` team-context call on every request, and either
  migrating or dual-writing existing role data — for a need that is **static
  bundles per fixed role**, not dynamic per-user grants (PRD Out-of-Scope
  forbids custom per-member bundles). Heavy machinery for a requirement the
  enum already models.
- **Laratrust (team-scoped)** — same class of tool, same objections: extra
  tables/migrations/config, a second role store, and a data migration of the
  shipped `team_members` roles. No capability PRD-02 needs that the enum lacks.
- **A separate `ProxyPermission` enum (native, but distinct from `TeamPermission`)**
  — keeps proxy perms out of the team-admin enum, but forces `hasTeamPermission`
  / `TeamRole::permissions()` / `hasPermission()` to be generalized or
  duplicated to carry two permission types, fragmenting the single
  role→permission-bundle mechanism. Rejected in favour of one enum, one bundle
  mechanism, one policy seam. (Namespacing via the `proxy:` string prefix already
  gives clean separation without a second type.)
- **Keep membership-only authorization** — the status quo `ProxyPolicy` where any
  team member has full CRUD. Rejected: it *is* the gap PRD-02 exists to close
  (AC1).

## Reasoning
- **Team-scoping is already solved and shared.** `HasTeams::teamRole(Team)`
  resolves the user's role from the `team_members` pivot for the *specific team*,
  and `hasTeamPermission(Team, TeamPermission)` gates on it. PRD-02 AC4
  ("evaluate permissions on the team that owns the proxy") falls out for free by
  passing `$proxy->team`. A library would re-implement team-scoping in a second
  store that must then be kept consistent with the pivot — added risk for zero
  new capability.
- **No DB-backed permission store is actually needed.** PRD-02 fixes the role set
  at Owner/Admin/Member and explicitly excludes custom per-member bundles
  (Out-of-Scope). Permissions are therefore **static, code-defined bundles keyed
  by role** — exactly what `TeamRole::permissions()` already is. A dynamic,
  queryable permission table solves a problem this feature does not have.
- **Consistency and a proven seam.** Team-administration already authorizes
  through this pattern, and `ProxyPolicy` already lives on the same policy
  surface `TeamPolicy` uses. Extending it means one authorization idiom across
  the app, no new mental model, and the existing `ProxyDestroyTest` /
  `EnsureTeamMembership` / `SetTeamUrlDefaults` machinery keeps working.
- **Zero install/migration cost, lowest reversibility risk.** Nothing to install,
  no migration, no config, no seeding, no touching shipped team-admin
  authorization (AC8). The change is additive: new enum cases + extended bundle
  arms + a permission-based `ProxyPolicy`.
- **Generality for #5–#8, #13 (AC2).** Adding a future proxy-scoped permission is
  a new `TeamPermission` case (e.g. `ReplayProxy = 'proxy:replay'`,
  `EditMapping = 'proxy:mapping.update'`) plus its inclusion in the relevant role
  bundles and one policy method — the *same* model, no re-architecture. This is
  precisely the roadmap #2 build-ahead intent.
- **Stack discipline.** Adds no dependency, so no `docs/stack/stack.md` change and
  no new-dependency ADR gate (unlike ADR-007). Laratrust/Spatie would each be a
  new first-party dependency requiring separate Owner approval on top of this.

## Impact
- **Easier:** proxy authorization becomes permission-based with one small,
  additive change set; future items (#5–#8, #13) register their permission the
  same way; a single authorization idiom app-wide; AC7 conditional UI reuses the
  existing `TeamPermissions`-style DTO/Inertia-share seam.
- **Harder / constrained (honest):**
  - Permission bundles are **compile-time**, per fixed role. If the product later
    needs **dynamic, per-user or per-team custom permission bundles**, the enum
    model is outgrown and a DB-backed store (Spatie/Laratrust) becomes the right
    call — a *future* ADR superseding this one. PRD-02 explicitly excludes that,
    so it is a deliberate deferral, not an oversight.
  - `TeamRole::Owner => TeamPermission::cases()` means every added case is
    auto-granted to Owner; correct for proxy CRUD, but any future permission that
    should *not* belong to Owner would require changing the Owner arm from
    `cases()` to an explicit list. Acceptable and visible.
  - Adds proxy cases into the same enum as team-admin permissions; the `proxy:`
    string namespace keeps them legible, and the existing team-admin cases/values
    are untouched (AC8 preserved).
- **Approval gate — this ADR is a *proposal* and does not authorize
  implementation.** Two independent gates remain:
  1. **PRD-02 is still Draft** (approval pending; blocked on Q-02-01, the
     role→permission mapping, which is a Product Manager / Project Owner
     decision). This ADR selects the *mechanism* only — answerable independent of
     the mapping and of final PRD wording — but the technical **plan** for #2 must
     not be built against the PRD until the Owner approves it.
  2. **This ADR itself requires Project Owner approval** before any code is
     written, consistent with the project's ADR convention. Do not extend the
     enums or rewrite `ProxyPolicy` until both this ADR is Accepted and PRD-02 is
     approved.

---

## Amendment A (2026-08-03): ownership scoping & creator capture

The Owner's answer to Q-02-01 layered an **ownership constraint** under the
full-CRUD bundle: holding `proxy:update` / `proxy:delete` is necessary but, for a
Member, sufficient only on proxies that Member created; Admin/Owner may act on any
team proxy (PRD-02 AC5/AC6/AC7). This amendment folds Q-02-03
(`docs/questions/prd-02-creator-capture-ownership-composition.md`) into ADR-009,
extending — not replacing — the decision above. It stays wholly within the native
enum mechanism: no new library, no stack change.

### A1. Question (added)
Two mechanisms are needed on top of the permission-bundle store: (a) how the
creator of a proxy is captured at creation time (AC7); (b) how the policy composes
the permission-bundle check with an ownership check for update/delete (AC5/AC6),
*without* hard-coding a role literal (`role === Member`) in the policy — which
would violate the "permission-based, never role-based" principle this ADR is
built on.

### A2. Decision — ownership is a second permission axis, not a role check

**Model "may act on records I did not create" as its own permission**, granted in
the same static `TeamRole::permissions()` bundles as everything else. Ownership is
an axis orthogonal to the CRUD bundle: the CRUD permission answers *"may this role
update/delete a proxy at all"* (all three roles: yes, per AC3); the new
**ownership-bypass** permission answers *"on whose records — team-wide or own-only."*

1. **Add two ownership-bypass cases to `TeamPermission`**, `proxy:`-namespaced
   alongside the four CRUD cases:
   - `UpdateAnyProxy = 'proxy:update-any'`
   - `DeleteAnyProxy = 'proxy:delete-any'`
   These are **additional** to — not part of — AC3's "four proxy permissions," so
   AC3 (all three roles hold the full CRUD bundle) is unaffected; the bypass cases
   are precisely the differentiator AC3 says distinguishes the roles.

2. **Extend `TeamRole::permissions()`** so Admin's bundle explicitly includes both
   `-any` cases; Member's bundle explicitly **omits** them (Member still holds the
   four CRUD cases). Owner is unchanged — `Owner => TeamPermission::cases()`
   auto-grants both `-any` cases, which is correct.

3. **`ProxyPolicy` composes the two axes** with no role literal. `update`/`delete`
   pass when the actor holds the base CRUD permission **and** either created the
   record **or** holds the matching `-any` bypass:

   ```php
   public function update(User $user, Proxy $proxy): bool
   {
       return $user->hasTeamPermission($proxy->team, TeamPermission::UpdateProxy)
           && $this->ownsOrCanManageAny($user, $proxy, TeamPermission::UpdateAnyProxy);
   }

   public function delete(User $user, Proxy $proxy): bool
   {
       return $user->hasTeamPermission($proxy->team, TeamPermission::DeleteProxy)
           && $this->ownsOrCanManageAny($user, $proxy, TeamPermission::DeleteAnyProxy);
   }

   protected function ownsOrCanManageAny(User $user, Proxy $proxy, TeamPermission $bypass): bool
   {
       return (int) $proxy->created_by === (int) $user->id
           || $user->hasTeamPermission($proxy->team, $bypass);
   }
   ```

   `view`/`create` remain single-axis (base permission only) — never
   ownership-scoped, per AC5/AC6. "Ownership-limited" is defined entirely as
   *"the role's bundle lacks the `-any` bypass permission"* — the policy never
   names a role. A future own-only role is expressed by omitting the bypass from
   its bundle; nothing in the policy changes.

### A3. Decision — creator capture via a `HasCreator` trait

Adopt the Owner-proposed trait, which is a direct structural twin of the existing
`App\Concerns\BelongsToCurrentTeam` (proven `static::creating` + `Auth` guard idiom
in this codebase):

- **`App\Concerns\HasCreator`** — applied to models carrying a `created_by` FK
  (Proxy first; reusable for future models). Boots a `creating` hook that sets
  `created_by` to the authenticated user's id, and defines a `creator(): BelongsTo`
  relation to `User`.
- **Unauthenticated-context safety (console, queue, seeders, token-authed ingest).**
  The hook mirrors `BelongsToCurrentTeam` exactly: it sets `created_by` **only when
  a value is not already present and `Auth::check()` is true**; otherwise it leaves
  the column null. It never throws and never fabricates a creator. The ingest fan-out
  path does not create proxies (it writes `DeliveryAttempt` rows), so the unscoped
  ingest flow is untouched; the guard is the safety net for any future
  no-actor creation path.

  ```php
  public static function bootHasCreator(): void
  {
      static::creating(function (Model $model): void {
          if (empty($model->getAttribute('created_by')) && Auth::check()) {
              $model->setAttribute('created_by', Auth::id());
          }
      });
  }
  ```

- **A null `created_by` is a safe deny for ownership.** Because the ownership check
  is id-equality, a null creator matches no user, so an ownership-limited role
  (Member) is denied update/delete on a null-creator proxy while Admin/Owner still
  succeed via the bypass. This is the correct, fail-closed default for records
  created without an actor and for pre-feature rows (see A4).

### A4. Decision — `created_by` migration for `proxies`

Add a nullable, self-nulling FK:

```php
$table->foreignId('created_by')->nullable()->after('team_id')
      ->constrained('users')->nullOnDelete();
```

- **Nullable** — required for (a) existing pre-feature proxies (no known creator)
  and (b) any legitimate no-actor creation. We do **not** backfill a guessed
  creator: AC7 requires only that proxies created *after* this ships have a
  recorded creator, and inventing a creator for historical rows would be a
  fabricated fact. Pre-feature proxies therefore have `created_by = null` and are
  Admin/Owner-manageable only (Members cannot update/delete them) — a safe,
  intentional consequence, called out here so it is not a surprise.
- **`nullOnDelete`, not cascade** — the *team* owns the proxy, not the individual
  creator (PRD-02 framing). If the creator's `User` is deleted, the proxy must
  survive; its `created_by` becomes null and it falls back to Admin/Owner-only
  management. Cascade-deleting the team's proxy when a creator account is removed
  would be data loss.
- **Removing a creator from the team (membership removal, not user deletion) does
  not touch `created_by`** — the FK references the `User`, not the membership. But
  the ownership check is composed with `hasTeamPermission($proxy->team, …)`, which
  returns false for a non-member (no role on that team). So a removed member is
  denied regardless of `created_by`; if later re-added, they regain own-only
  access to records they created. This is acceptable and consistent with
  "ownership + current team permission."
- **Generality** — the column name (`created_by`), nullability, and `nullOnDelete`
  are the standard shape any future `HasCreator` model adopts; the FK carries its
  own index (as `team_id` does), sufficient for the id-equality ownership check.

### A5. Decision — AC8 conditional UI becomes record-scoped for update/delete

The `ProxyPermissions` DTO proposed in the base decision (item 4) captured
**team-level** booleans. That is correct for `create` and `view` (not
ownership-scoped) but **insufficient for update/delete**, whose answer now depends
on the specific record's `created_by`. Split the exposure into two tiers:

- **Team-level (once per page):** `canCreateProxy`, `canViewProxy` — still fine as a
  page-level `ProxyPermissions` share mirroring `HasTeams::toTeamPermissions()`.
- **Per-record (per proxy):** `can: { update, delete }` computed for the acting user
  against *that* proxy and emitted on **`ProxyResource`** (which already serializes
  each proxy for Index/Show/Edit). The flags are produced by the policy itself
  (`$user->can('update', $proxy)` / `Gate::forUser($user)->allows(...)`), so the
  server policy stays the single source of truth and the UI cannot drift from it.
  Index renders per-row edit/delete affordances from each row's `can`; Show/Edit
  from the single resource's `can`.

This keeps AC8 honest: a Member sees edit/delete only on proxies they created,
Admin/Owner on all — driven by the same policy composition (A2) that enforces the
server gate, never by a client-side role check.

### A6. Alternatives considered (ownership axis)
- **`isOwnershipLimited()` / `bypassesOwnership()` method on `TeamRole`** matching on
  role literals — encapsulates the role check but still encodes role→behavior
  semantics and pulls the "which roles are unrestricted" decision out of the bundle
  and into a bespoke method; less aligned with the pure permission-bundle model and
  less extensible than a bundle-carried permission. Rejected.
- **Hard-code `Admin`/`Owner` (or `role !== Member`) in `ProxyPolicy`** — directly
  violates PRD-02 AC1 / the Goal ("never gate a proxy action on a role name").
  Rejected outright.
- **A single combined bypass permission** (e.g. `proxy:manage-any` covering both
  update and delete) — one fewer case, but conflates two distinct verbs and reads
  ambiguously against the CRUD taxonomy. Two `-any` cases mirror the `update`/`delete`
  verbs precisely and let a future role bypass one without the other. Chosen the two.
- **A separate `created_by` pivot / audit table** instead of a column — unnecessary
  indirection for a single-valued creator; a nullable FK column is the minimal,
  indexable representation. Rejected.

### A7. Impact (delta from base decision)
- **Consistent with the base mechanism decision** — no library, no stack change, no
  DB-backed permission store. The ownership axis is two more `TeamPermission` cases +
  two bundle-arm edits + a policy composition; creator capture is a boot-hook trait
  twinning `BelongsToCurrentTeam` + one nullable FK migration. Fully additive.
- **`Owner => TeamPermission::cases()` auto-grants the `-any` cases** — correct here;
  the standing caveat (any future permission that should *not* belong to Owner forces
  the Owner arm off `cases()`) still applies and now covers the bypass cases too.
- **Fail-closed on unknown creators** — null `created_by` denies ownership-limited
  roles; pre-feature and no-actor rows are Admin/Owner-managed only. Deliberate.
- **AC8 update/delete visibility is per-record**, carried on `ProxyResource`, not the
  page-level DTO — the one shape change downstream (Task Planner / Senior Developer)
  must account for; create/view stay page-level.
- **Still one gate, one idiom** — future ownership-scoped resources reuse `HasCreator`
  + the `-any`-permission pattern verbatim.

Amendment A is included in the Project Owner's 2026-08-03 approval; the ADR (base
decision + Amendment A) is **Accepted**. PRD-02 is likewise Owner-approved.
