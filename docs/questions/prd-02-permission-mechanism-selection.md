# Question: PRD-02 team-scoped permission mechanism selection

- **Status:** Resolved (2026-08-03) — decided in
  `docs/architecture/adr-009-proxy-permission-mechanism.md` (Proposed; awaiting
  Project Owner approval). Non-blocking for PRD approval.
- **Raised by:** Product Manager
- **Owner (must answer):** Principal Engineer *(technical)*
- **Raised:** 2026-08-03
- **Gates:** Technical design for `docs/product/prd-02-role-based-collaboration.md`.
  Does **not** gate Project Owner approval of the PRD's requirements — the PRD
  states a requirement ("must support team-scoped permissions"), not a solution.
- **Source:** Owner direction 2026-08-03.

## Context
The PRD requires proxy authorization to be **permission-based** (never a direct
role check) and **team-scoped** (a user's permissions are evaluated within the
context of the team that owns the proxy). Today's `TeamRole`/`TeamPermission`
pair is a hand-rolled, in-app version of this pattern for team-administration
actions only; it has no notion of scoping permissions to a *different* resource
(proxies) or of a pluggable permission store.

The Owner named three candidate approaches as context only, explicitly without
choosing between them:

1. **Laratrust** — supports team-scoped roles/permissions.
2. **Spatie laravel-permission** — supports team-scoped roles/permissions.
3. **Jetstream-native permissions** (already present in this project) — role +
   permission-set registration, e.g.:
   `Jetstream::role(Role::ADMIN->value, Role::ADMIN->label(), Role::getAdminPermissions())->description(Role::ADMIN->description());`
   — permissions would need to be defined; nothing new to install.

## Question
Which mechanism should implement team-scoped permission storage and checks for
proxy actions: Laratrust, Spatie laravel-permission, Jetstream-native
permissions (extending the existing `TeamRole`/`TeamPermission` pattern), or
another approach? This is a feasibility/architecture call — the Product Manager
is not making it and the three names above are Owner-supplied context, not a
Product Manager preference.

## Impact if unresolved
None on PRD approval — the requirement ("permission-based, team-scoped, general
across proxy actions") is answerable and testable regardless of mechanism. This
blocks only the start of the Principal Engineer's technical design/ADR for
feature #2, which should record the chosen mechanism.

## Answer
- **Answered By:** Principal Engineer, 2026-08-03
- **Decision:** ADR-009
  (`docs/architecture/adr-009-proxy-permission-mechanism.md`) — **Proposed,
  awaiting Project Owner approval.**

**Extend the existing native `TeamRole`/`TeamPermission` enum pattern; add no
library** (not Laratrust, not Spatie laravel-permission).

Rationale in brief (full weighing in ADR-009):
- **Team-scoping is already solved and shared.** `HasTeams::teamRole(Team)`
  reads the user's role from the `team_members` pivot for the specific team, and
  `hasTeamPermission(Team, TeamPermission)` gates on it. Passing `$proxy->team`
  satisfies "evaluate on the team that owns the proxy" (AC4) for free.
  Laratrust/Spatie would stand up a **second, DB-backed source of truth for team
  roles** that must be kept consistent with the pivot — added risk, no new
  capability.
- **No DB-backed permission store is needed.** The role set is fixed
  (Owner/Admin/Member) and custom per-member bundles are out of scope, so
  permissions are **static, code-defined bundles per role** — exactly what
  `TeamRole::permissions()` already is. A queryable permission table solves a
  problem this feature does not have.
- **Consistency + zero cost.** Team-administration already authorizes through
  this pattern and `ProxyPolicy` already sits on the same policy seam. Extending
  it is additive (new enum cases + extended bundles + a permission-based
  `ProxyPolicy`), needs no install/migration/config, and does not touch shipped
  team-admin authorization (AC8).
- **General enough for later items (AC2).** A future proxy permission (#8 mapping
  edit, #6 replay, #5/#7 storage/mode, #13 notification opt-out) is one new
  `TeamPermission` case + its inclusion in the relevant role bundles + one policy
  method — same model, no re-architecture.

**Design-level how (not implemented here):** add `proxy:`-namespaced cases to
`TeamPermission` (view/create/update/delete); extend `TeamRole::permissions()`
per the Q-02-01 mapping (`docs/questions/prd-02-role-permission-mapping.md`);
rewrite `ProxyPolicy` to call `$user->hasTeamPermission($proxy->team, …)`; expose
the acting user's proxy permissions to the frontend for AC7 via a
`ProxyPermissions` DTO mirroring `HasTeams::toTeamPermissions()`.

**Gates still open:** this ADR needs Project Owner approval, and the #2 technical
plan must await PRD-02 approval (blocked on Q-02-01, a PM/Owner decision). The
mechanism decision is independent of both and can be recorded now.
