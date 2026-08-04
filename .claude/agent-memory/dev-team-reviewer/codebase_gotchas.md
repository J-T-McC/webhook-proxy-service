---
name: codebase-gotchas
description: Non-obvious authorization/serialization traps to check when reviewing proxy work
metadata:
  type: reference
---

- **`ProxyController::store()` has no `$this->authorize('create', …)` call** — it relies on
  `EnsureTeamMembership` middleware + the fact that every TeamRole holds `CreateProxy`. The
  `create()` form action DOES authorize. No denial vector today, but it deviates from the
  "controller calls authorize" standard and from `StoreProxyRequest`'s own docblock. Pre-existing
  from item #1. Re-check if a future role is added that omits `CreateProxy`.
- **`ProxyResource` `can:{update,delete}` flags call the policy per row** (`$user->can(...)` →
  `hasTeamPermission($proxy->team, …)`). The index query does not `with('team')` and `teamRole`
  is not memoized, so the index page is an N+1 (bounded by page size 15). Watch for this pattern
  spreading to other resources.
- **`createQuietly()` suppresses model `creating` hooks** — so `HasCreator`/`BelongsToCurrentTeam`
  auto-assignment does NOT fire in factory-built records; tests set `created_by`/`team_id`
  explicitly. The auto-assign hooks are covered separately by real `new Model()->save()` tests.
- **Authorization idiom:** every proxy/team decision is a Policy gating on `TeamPermission` via
  `$user->hasTeamPermission($team, …)`; a role literal (`role === Member`) in a policy/controller
  is a standards violation (permission-based, never role-based). Ownership is a second axis modeled
  as `-any` bundle permissions, not a role check.
