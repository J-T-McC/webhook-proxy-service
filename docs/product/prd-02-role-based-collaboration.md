# PRD: Role-based collaboration (permission-gated proxy authorization)

- **Status:** Draft
- **Author:** Product Manager
- **Date:** 2026-08-03
- **Approved by / date:** *(pending)*
- **Backlog item:** Roadmap #2 (`docs/product/roadmap.md`)
- **Reframing note (2026-08-03):** The roadmap's original #2 description reads
  "invite users to a team, assign view / add / modify roles, and remove their
  access." The Project Owner has since confirmed that team-membership mechanics
  — invite by email with a role, accept/decline, change a member's role, remove
  a member — are **already delivered by the starter kit boilerplate**
  (`TeamRole`, `TeamPolicy`, `TeamPermission`, `TeamMemberController`,
  `TeamInvitationController`, `InviteMemberModal.vue`, `teams/Edit.vue`) and are
  **not** re-specified or rebuilt by this PRD. The genuine, unmet gap the Owner
  identified is that those roles today only gate **team-administration**
  actions (update/delete team, add/update/remove member, create/cancel
  invitation) — the product's actual resource, **proxies**, is authorized purely
  by team membership (`app/Policies/ProxyPolicy.php`: any team member has full
  view/create/update/delete on every proxy, regardless of role). This PRD is
  **trimmed** to close that specific gap: govern proxy actions by team-scoped
  **permissions**, with the existing roles acting as permission bundles — not a
  rebuild of team collaboration.

## Feature
Proxy actions (view, create, update, delete) are authorized by team-scoped
**permissions** — never by a direct role check — with each of the existing team
roles (Owner, Admin, Member) holding a defined bundle of those permissions, so
that a member's role determines what they can do to the proxies owned by that
team.

## Problem
`ProxyPolicy` currently authorizes every proxy action purely by team
membership:

```php
protected function ownsThroughTeam(User $user, Proxy $proxy): bool
{
    return $user->teams()->whereKey($proxy->team_id)->exists();
}
```

Every `view`, `create`, `update`, and `delete` check reduces to "is this user on
the team." A "Member" invited today gets exactly the same proxy CRUD access as
the team's Owner. The `TeamRole`/`TeamPermission` pair that already exists only
governs *team-administration* actions (`team:update`, `team:delete`,
`member:add`, `member:update`, `member:remove`, `invitation:create`,
`invitation:cancel`) — there is no permission in that enum, or anywhere else,
that governs a proxy action. Role-based collaboration on the product's actual
resource does not exist yet; this PRD builds it.

## Goals
- Replace proxy authorization's team-membership-only check with a team-scoped,
  **permission-based** check for every proxy action.
- Never gate a proxy action directly on a role name (e.g. `role === 'admin'`);
  every check tests for a **permission**, and a role is a named bundle of
  permissions — the same shape `TeamRole::permissions(): array<TeamPermission>`
  already uses for team-administration.
- Model permissions generally as **CRUD (create/read/update/delete) per
  resource**, applied first to the proxy resource (view = read, create,
  update, delete) but defined generally enough that another resource/model can
  register its own CRUD permission set under the same shape — per the
  roadmap's existing build-ahead note for future items #5–#8/#13.
- Evaluate a user's permissions **within the context of the team that owns the
  proxy** being acted on, not any global or cross-team notion of role.
- For update and delete specifically, further constrain a permitted action to
  an **ownership** rule where the Owner has directed it (see AC3/AC5/AC6):
  holding the permission is necessary but not always sufficient — a
  ownership-limited role (today, Member) may act only on records it created.
- Define the permission taxonomy against proxy **actions** broadly enough that
  later roadmap items — mapping edits (#8), replay (#6), storage/mode
  configuration (#5, #7), notification opt-outs (#13) — can register new
  proxy-scoped permissions under the same model without redesigning
  authorization (per the roadmap's existing #2 build-ahead note).

## Users
- **Team Owner / Admin** — already assigns roles to members (existing,
  out-of-scope capability); this feature makes that role assignment
  consequential for proxy access, not just team administration.
- **Team Member** — is now subject to permission-gated proxy actions instead of
  unconditional full access to every proxy on the team.

## User Stories
- As a team Owner or Admin, I want each member's ability to view, create,
  modify, or delete proxies to be governed by their role's permissions, so I can
  share visibility into a proxy without granting everyone the ability to change
  or delete it.
- As a team Member whose role's permission bundle does not include a given
  proxy action, I want the system to prevent me from performing that action, so
  the access boundaries my team lead configured are actually enforced, not just
  cosmetic.
- As the Principal Engineer building a later feature (mapping edits, replay,
  storage/mode config, notification opt-outs), I want the permission model
  already expressed against proxy actions in general, so I can add a new
  permission for my feature under the same model instead of re-architecting
  authorization.
- As a team Member, I want to update or delete only the proxies I created, so
  that I cannot accidentally change or remove a teammate's work even though my
  role permits the update/delete action in general.
- As a team Owner or Admin, I want to update or delete any proxy on my team
  regardless of who created it, so I retain full oversight of the team's
  proxies.

## Acceptance Criteria
1. Proxy authorization (`ProxyPolicy`'s `view`/`create`/`update`/`delete`, or
   its replacement) checks the acting user's **permissions** for the team that
   owns the proxy — no proxy action is authorized by comparing the user's role
   name directly to an expected role.
2. A permission taxonomy defines at least four proxy-scoped permissions,
   modeled as **CRUD (create/read/update/delete)** against "proxy actions" —
   view = read — generally enough (not hard-wired to exactly these four) that a
   later item can add a new proxy-scoped permission (e.g. an "edit mapping" or
   "replay" permission) under the same model, or a later resource/model can
   define its own CRUD permission set under the same model, without reshaping
   the authorization approach.
3. **Permission-bundle mapping (resolves
   `docs/questions/prd-02-role-permission-mapping.md`, Q-02-01):** each of the
   three existing team roles — Owner, Admin, and Member — is assigned the
   **full** bundle of all four proxy permissions (create, read, update,
   delete). No role is denied any proxy permission at the bundle level; the
   roles are differentiated instead by the ownership rule in AC5/AC6.
4. A user's proxy permissions are evaluated using their role on the **team that
   owns the proxy being acted on** — a role the user holds on a different team
   confers no permission on this proxy.
5. **Ownership-scoped update/delete:** holding the update or delete permission
   is necessary but, for a **Member**, not sufficient — a Member may update or
   delete only a proxy they created. A Member attempting to update or delete a
   proxy created by a different team member is denied server-side
   (not-authorized / 403) with no data changed, even though the Member's role
   holds the update/delete permission per AC3. Create and read/view are **not**
   ownership-scoped for any role — any role holding the create or read
   permission acts on all of the team's proxies for those two actions,
   regardless of who created them.
6. **Ownership-scoped update/delete — permitted cases:**
   - A Member succeeds when updating or deleting a proxy **they created**.
   - An Admin or Owner succeeds when updating or deleting **any** proxy on
     their team, regardless of who created it — Admin and Owner are not
     ownership-limited.
   - Every role succeeds on create and read/view actions team-wide (subject
     only to AC3's bundle, which is full for all three roles) — holders of
     the create/read permissions see no regression from today's behavior.
7. **Creator capture:** the creator of a proxy is recorded at creation time
   (e.g. a reference to the creating user) so that the ownership check in
   AC5/AC6 has a record to evaluate against. Every proxy created after this
   feature ships has a recorded creator. (The mechanism that captures the
   creator and composes the ownership check with the permission check is a
   Principal Engineer technical design item — see Open Questions and Handoff.)
8. Proxy-related UI controls the current user is not permitted to use
   (create/edit/delete affordances, including edit/delete on a proxy the
   current user did not create when their role is ownership-limited) are not
   exposed as usable actions, or, if exposed, attempting them surfaces a clear
   not-authorized outcome — consistent with how the product already handles
   unauthorized actions elsewhere. New UI is in scope only to the extent the
   existing boilerplate has no equivalent pattern to reuse.
9. No existing team-administration capability — invite, accept/decline invite,
   change a member's role, remove a member (`TeamMemberController`,
   `TeamInvitationController`, `TeamRole`, `TeamPolicy`, `TeamPermission`,
   `InviteMemberModal.vue`, `teams/Edit.vue`) — is altered by this feature; it
   is reused exactly as it exists today.

## Out of Scope
- Inviting members, accepting/declining invitations, removing members, and the
  basic role-assignment UI — already delivered by the starter kit boilerplate
  (see AC9's file list). Not re-specified or rebuilt here.
- Introducing new roles or letting a team define a custom, hand-picked
  permission bundle per member. The assignable role set stays exactly
  Owner/Admin/Member as it exists today; only *what proxy permissions those
  roles carry* is new. The ownership-scoped update/delete rule (AC5/AC6) is
  not a custom per-member bundle either — it applies uniformly based on the
  existing role (Member vs. Admin/Owner) and a record's recorded creator
  (AC7); teams cannot configure or override it.
- Wiring permission checks into the specific actions introduced by later
  roadmap items (mapping edits #8, replay #6, storage/mode configuration #5/#7,
  notification opt-outs #13). This PRD requires only that the permission model
  be general enough to extend to them; each of those items wires its own
  action's permission when it is built, per the roadmap's existing build-ahead
  note for #2.
- Selecting the permission mechanism/library — Laratrust, Spatie
  laravel-permission, or Jetstream-native role/permission registration (all
  three, per the Owner, support team-scoped roles/permissions) — is a Principal
  Engineer technical decision, not decided here. See Open Questions.
- Any change to the existing team-administration permission set
  (`TeamPermission`: `team:update`, `team:delete`, `member:add`,
  `member:update`, `member:remove`, `invitation:create`, `invitation:cancel`) or
  to the Owner/Admin/Member role hierarchy itself.

## Open Questions
- **Q-02-01** (Product — **Resolved**, 2026-08-03, Project Owner):
  `docs/questions/prd-02-role-permission-mapping.md` — resolved the
  permission-bundle mapping (all three roles hold full CRUD) and surfaced the
  ownership-scoped update/delete rule and creator-capture requirement, now
  reflected in AC3, AC5, AC6, and AC7 above. No longer blocks Owner approval of
  this PRD, subject to the Owner approving these revised ACs.
- **Q-02-02** (Technical — for the Principal Engineer; does **not** block Owner
  approval of this PRD's requirements, only the start of technical design):
  `docs/questions/prd-02-permission-mechanism-selection.md` — which mechanism
  implements team-scoped permission storage/checks (Laratrust, Spatie
  laravel-permission, Jetstream-native, or other)? Framed here as a
  requirement ("must support team-scoped permissions"), not a solution.
  Resolved by the Principal Engineer (ADR-009, Proposed).
- **Q-02-03** (Technical — for the Principal Engineer; does **not** block
  Owner approval of this PRD's requirements): how is the creator captured on
  record creation (AC7) and how is the ownership check composed with the
  permission check in the authorization layer (AC5/AC6)? This is a design
  mechanism, not a requirement — the PM specifies only the observable
  behavior in AC5/AC6/AC7. Routed to the Principal Engineer to fold into
  ADR-009 (`docs/architecture/adr-009-proxy-permission-mechanism.md`), which
  currently covers only the permission-bundle mechanism and does not yet
  address ownership scoping or creator capture.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#2 and its build-ahead note; Resolved
  Decision R1), `docs/product/vision.md` (Target Users),
  `docs/product/prd-01-walking-skeleton.md` (`ProxyPolicy` built as the
  extension seam for this item), `app/Policies/ProxyPolicy.php`,
  `app/Enums/TeamRole.php`, `app/Enums/TeamPermission.php`, Project Owner
  direction (2026-08-03) trimming this feature to a permission-based scope,
  Project Owner answer to Q-02-01 (2026-08-03, full-CRUD bundle +
  ownership-scoped update/delete + creator-capture requirement).
- **Outputs:** this PRD.
- **Dependencies:** Roadmap #1 (Done) — proxies and `ProxyPolicy` already exist
  as the point this feature extends. Starter-kit team-membership mechanics
  (already delivered; reused as-is, not a dependency to build).
- **Outstanding Questions:** Q-02-01 (Project Owner — **Resolved**, 2026-08-03;
  PRD ACs updated accordingly, now awaiting Owner approval of the revised
  ACs); Q-02-02 (Principal Engineer — Resolved, ADR-009 Proposed); Q-02-03
  (Principal Engineer, non-blocking — creator-capture and ownership-check
  mechanism, to be folded into ADR-009 during technical design).
- **Next Agent:** Principal Engineer. No new screens or user flows are
  introduced — the only UI-facing change (AC8) is conditional visibility of
  action controls (now including ownership-based visibility for Member
  update/delete affordances) that already exist on `proxies/Index.vue` and
  `proxies/Show.vue`, which the codebase already has patterns for (confirm
  dialogs, per-action buttons). If the Principal Engineer's technical design
  surfaces a genuine new UI need beyond that, route back to the Product Manager
  to hand off to the Designer.
