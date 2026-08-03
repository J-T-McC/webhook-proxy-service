# Question: PRD-02 creator capture & ownership-check composition

- **Status:** Resolved (2026-08-03) — decided in
  `docs/architecture/adr-009-proxy-permission-mechanism.md` (*Amendment A*;
  ADR still Proposed, awaiting Project Owner approval). Non-blocking for PRD
  approval.
- **Raised by:** Product Manager (routed from Q-02-01's Owner answer)
- **Owner (must answer):** Principal Engineer *(technical design mechanism)*
- **Raised:** 2026-08-03
- **Gates:** Technical design for
  `docs/product/prd-02-role-based-collaboration.md` AC5/AC6/AC7/AC8. Does **not**
  gate Project Owner approval of the PRD's requirements — the PRD specifies only
  the observable behavior (AC5/AC6/AC7), not the mechanism.
- **Source:** Project Owner answer to Q-02-01
  (`docs/questions/prd-02-role-permission-mapping.md`, 2026-08-03), which layered
  an ownership-scoped update/delete rule and a creator-capture requirement under
  the full-CRUD bundle and routed the mechanism to the Principal Engineer.

## Context
Q-02-01 resolved the bundle mapping (all three roles hold the four proxy CRUD
permissions) and added an **ownership** dimension: a Member may update/delete
only proxies they created; Admin/Owner may update/delete any team proxy;
create/read are never ownership-scoped (AC5/AC6). Enforcing this requires the
system to **capture the creator** of a proxy at creation (AC7) and to **compose**
that ownership check with the existing permission-bundle check in `ProxyPolicy`.

The Owner proposed a technical shape to validate (not rubber-stamp): a reusable
`HasCreator` trait that boots a `creating` observer to set `created_by`, defines a
`creator` relation, and a policy that combines the permission check with an
ownership check. The tension to resolve: the policy must know a role is
"ownership-limited" vs "unrestricted" **without** hard-coding a role literal,
which would violate PRD-02's permission-based (never role-based) principle.

## Question
1. How is the creator captured on proxy creation (AC7), safely across contexts
   with no authenticated actor (console, queue, seeders, token-authed ingest)?
2. How does `ProxyPolicy` compose the permission-bundle check with the ownership
   check for update/delete (AC5/AC6) without a role literal in the policy body?
3. What `created_by` schema/FK/backfill approach fits `proxies` (and future
   models generally)?
4. What does the ownership rule imply for AC8's conditional UI?

## Impact if unresolved
None on PRD approval. Blocks only the technical plan / implementation of #2's
ownership scoping and creator capture, which must record a concrete mechanism.

## Answer
- **Answered By:** Principal Engineer, 2026-08-03
- **Decision:** ADR-009 *Amendment A*
  (`docs/architecture/adr-009-proxy-permission-mechanism.md`) — **Proposed,
  awaiting Project Owner approval.** Validated the Owner-proposed shape and
  adopted it with the refinements below; stays wholly within the native enum
  mechanism (no new library, no stack change).

1. **Creator capture — `App\Concerns\HasCreator` trait** (structural twin of the
   existing `BelongsToCurrentTeam`): a `creating` boot hook sets `created_by` to
   the authenticated user's id **only when not already set and `Auth::check()` is
   true**, and a `creator(): BelongsTo` relation to `User`. In no-actor contexts
   it leaves `created_by` null and never throws — so the unscoped ingest flow
   (which creates `DeliveryAttempt`s, not proxies) is untouched.

2. **Ownership as an orthogonal permission axis, not a role check.** Add two
   `proxy:`-namespaced bypass cases to `TeamPermission` — `UpdateAnyProxy`
   (`proxy:update-any`), `DeleteAnyProxy` (`proxy:delete-any`) — granted in the
   Admin bundle and auto-granted to Owner via `TeamPermission::cases()`, and
   **omitted** from Member. `ProxyPolicy::update/delete` pass when the actor holds
   the base CRUD permission **and** (`created_by === user->id` **or** holds the
   matching `-any` bypass). "Ownership-limited" = *the bundle lacks the `-any`
   permission*; the policy never names a role. `view`/`create` stay single-axis.

3. **Migration:** `created_by` nullable FK to `users` with `nullOnDelete`
   (`$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()`).
   Nullable and **no backfill** — AC7 is forward-looking; historical rows keep
   `created_by = null` (Admin/Owner-manageable only, a safe fail-closed default).
   `nullOnDelete` because the team, not the individual, owns the proxy — a deleted
   creator must not cascade-delete the record. Same shape generalizes to future
   `HasCreator` models.

4. **AC8 becomes record-scoped for update/delete.** Team-level `create`/`view`
   booleans stay on a page-level `ProxyPermissions` DTO; per-record
   `can: { update, delete }` (computed by the policy against each proxy) move onto
   `ProxyResource`, so Index/Show render edit/delete affordances per record. The
   server policy remains the single source of truth.

Full weighing, code sketches, and alternatives (a `TeamRole` method vs. a
policy-side role literal vs. a single combined bypass permission vs. an audit
table) are in ADR-009 *Amendment A* (A2–A7).

**Gates still open:** ADR-009 needs Project Owner approval, and the #2 technical
plan must await PRD-02 approval. This closes Q-02-03.
