# Question: PRD-02 role -> proxy-permission mapping

- **Status:** Resolved
- **Raised by:** Product Manager
- **Owner (must answer):** Project Owner *(business/product decision)*
- **Raised:** 2026-08-03
- **Gates:** `docs/product/prd-02-role-based-collaboration.md` AC3, AC5, AC6 —
  final Owner approval of the PRD.
- **Source:** Owner direction 2026-08-03 trimming feature #2 to a
  permission-based proxy-authorization model; `docs/product/roadmap.md` item #2.

## Context
The trimmed feature #2 requires that proxy actions (view / create / update /
delete) be gated by **permissions**, not role literals, with the existing team
roles (Owner, Admin, Member) each holding a bundle of permissions — mirroring how
`TeamRole::permissions()` already bundles `TeamPermission` cases for
team-administration actions today.

The Owner specified that authorization must be permission-based, team-scoped,
and general enough to later cover mapping edits, replay, storage/mode config,
and notification opt-outs. The Owner did **not** state which of the three
existing roles should hold which of the four proxy permissions. This is a
product/business decision, not something the Product Manager can infer — the
Product Manager does not invent requirements the Owner has not stated.

## Question
For each of the three existing roles, which proxy permissions (view, create,
update, delete) should the role's bundle include?

For reference, three candidate shapes (not a recommendation — the PM has no
basis to prefer one):

- **Option A:** Owner and Admin hold all four (view/create/update/delete);
  Member holds view + create only (can add/see proxies but not change or remove
  ones they don't own).
- **Option B:** Owner and Admin hold all four; Member holds view only.
- **Option C:** All three roles hold all four (parity with today's behavior),
  with the permission model in place structurally so a future role or a role's
  bundle can be narrowed later without an authorization rewrite.

The Owner may also specify a different mapping entirely.

## Impact if unresolved
AC3, AC5, and AC6 of the PRD are not concretely testable until the mapping is
known — a Reviewer cannot verify "a Member without X permission is denied"
without knowing whether Member holds X. This blocks final PRD approval and,
downstream, the Principal Engineer's technical design (the mechanism can be
chosen without this answer, but the seed data / registration for each role's
bundle cannot).

## Answer
- **Answered By:** Project Owner
- **Answered:** 2026-08-03

The permission model generalizes beyond proxies: permissions are **CRUD
(create/read/update/delete) per resource/model**, applied first to proxies but
defined generally — consistent with the roadmap's existing build-ahead note for
future items #5–#8/#13.

**Permission-bundle mapping (resolves this question):** for now, **all three
roles — Owner, Admin, and Member — hold all four CRUD permissions.** The
bundle grid is full across the board; every role can create, read, update, and
delete. This is deliberately permissive at the bundle level. None of Options
A/B/C as originally framed — the Owner selected full parity (closest to Option
C) but layered a new ownership constraint underneath it (see below), which none
of the three candidate options anticipated.

**New requirement surfaced by this answer — ownership-scoped update/delete:**
holding the update or delete permission is necessary but, for a **Member**, is
further constrained to records that Member created:
- **Member:** may update and delete **only** items they created (own records).
- **Admin and Owner:** may update and delete **any** team member's items
  (unrestricted within the team).
- **Create and read (view) are not ownership-scoped** — any role holding those
  permissions acts on all team items for create/read, regardless of who
  created what.

This means authorization = permission-bundle check **and**, for update/delete,
an ownership check when the actor's role is ownership-limited (Member today).

**Supporting requirement:** to enforce ownership scoping, the system must
**capture the creator of records** for models that need it (e.g. a
`created_by` reference to the user who created the record).

**Scope note:** the Owner also proposed a technical mechanism (a reusable
trait/observer to auto-set the creator on record creation, composed with the
permission check in the policy). That is an implementation detail and is
**not** specified here — routed to the Principal Engineer to own and validate,
folded into ADR-009 (see PRD-02 Handoff).

The PRD's AC3, AC5, and AC6 (and related ACs affected by the ownership rule)
are updated in `docs/product/prd-02-role-based-collaboration.md` to reflect the
full-CRUD bundle and the new ownership-scoped update/delete rule. This closes
Q-02-01. PRD-02 remains in Draft pending final Owner approval of the revised
ACs.
