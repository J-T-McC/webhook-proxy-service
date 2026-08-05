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
- **`max:` on a string rule counts multibyte CHARACTERS, not bytes** — so a validation rule
  built from a `*_max_bytes` config (e.g. `response_body` → `max:config('ingest.response_body_max_bytes')`)
  lets a UTF-8 value exceed the intended byte cap by up to ~4×. Flag as a Minor whenever a byte-named
  cap feeds a string `max:` rule; a byte-exact check needs a custom rule.
- **`WithoutOverlapping` job middleware defaults to no TTL** (`expiresAfter = 0`) — on an ungraceful
  worker crash (SIGKILL/OOM) the Redis lock leaks forever. In the FIFO advancer (`AdvanceProxyFifoQueue`)
  this permanently stalls a proxy's line: the sweeper reaps the DB claim but its re-dispatched advancer
  is gated by the same leaked lock. Whenever `WithoutOverlapping` guards a self-dispatching/scheduled
  job, require an explicit `->expireAfter(...)` (align to the claim lease). Raised as Major in review-04.
- **Authorization idiom:** every proxy/team decision is a Policy gating on `TeamPermission` via
  `$user->hasTeamPermission($team, …)`; a role literal (`role === Member`) in a policy/controller
  is a standards violation (permission-based, never role-based). Ownership is a second axis modeled
  as `-any` bundle permissions, not a role check.
