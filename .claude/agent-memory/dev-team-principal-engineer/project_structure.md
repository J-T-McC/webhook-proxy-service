---
name: project-structure
description: Where ADRs live and the native team authorization seam this project uses
metadata:
  type: reference
---

**ADRs live in `docs/architecture/`**, named `adr-NNN-<slug>.md` (not `docs/adr/` — task prompts sometimes say that; the real convention is `docs/architecture/`). Sequential; ADR-001..009 exist as of 2026-08-03. Template: dev-team plugin `templates/adr.md` (sections: Question, Decision, Alternatives, Reasoning, Impact). Status starts **Proposed**, becomes **Accepted** on Project Owner approval.

**Native team authorization seam** (hand-rolled Jetstream-style, no auth library):
- `App\Enums\TeamRole` (Owner/Admin/Member) — `permissions(): array<TeamPermission>` static bundle per role; `Owner => TeamPermission::cases()`. Also `hasPermission()`, `level()`/`isAtLeast()`, `assignable()`.
- `App\Enums\TeamPermission` — string-backed, namespaced (`team:`, `member:`, `invitation:`). Team-admin only until #2 adds `proxy:` cases.
- `App\Concerns\HasTeams` (on User) — `teamRole(Team)` reads role from `team_members` pivot (`Membership.role` cast); `hasTeamPermission(Team, TeamPermission)` is the team-scoped gate; `toTeamPermissions(Team)` builds the `App\Data\TeamPermissions` boolean DTO shared to the frontend for conditional UI.
- Policies consume `$user->hasTeamPermission($team, TeamPermission::X)` (see `TeamPolicy`). `ProxyPolicy` was membership-only through #1; #2 (ADR-009) makes it permission-based on the same seam.
