---
name: project-structure
description: Where ADRs live and the native team authorization seam this project uses
metadata:
  type: reference
---

**ADRs live in `docs/architecture/`**, named `adr-NNN-<slug>.md` (not `docs/adr/` — task prompts sometimes say that; the real convention is `docs/architecture/`). Sequential; ADR-001..011 exist as of 2026-08-04. Template: dev-team plugin `templates/adr.md` (sections: Question, Decision, Alternatives, Reasoning, Impact). Status starts **Proposed**, becomes **Accepted** on Project Owner approval.

**Ingest→delivery dispatch seam (ADR-001/005/007) — two run-sync-or-queue `lorisleiva/laravel-actions`:** `App\Actions\ProcessIngestedWebhook` (pipeline-level: runs the whole native `Illuminate\Pipeline\Pipeline` over one in-memory `PipelineContext`) and `App\Actions\DeliverToDestination` (per-destination). `::run` = sync inline; `::dispatch` = queued. Steps (e.g. `DeliverStep`) are `AsObject`, never queued individually. Gotcha: **`webhook_events` is raw-only immutable by construction (ADR-010)** — it cannot hold dispatch/processing/claim state, so any per-event ordering/claim state (e.g. #4 FIFO) needs a **sidecar table**, and queued jobs should dispatch **by reference** (`ingest_id`, rebuild `PipelineContext` from the `webhook_events` row on the worker) rather than serialize the `Proxy` model or the raw body (up to the ADR-006 body cap). `delivery_attempts` is payload-free (ADR-003). Data-model changes (new columns/tables) are a **CLAUDE.md Owner-approval gate** — never PE-self-certified.

**Native team authorization seam** (hand-rolled Jetstream-style, no auth library):
- `App\Enums\TeamRole` (Owner/Admin/Member) — `permissions(): array<TeamPermission>` static bundle per role; `Owner => TeamPermission::cases()`. Also `hasPermission()`, `level()`/`isAtLeast()`, `assignable()`.
- `App\Enums\TeamPermission` — string-backed, namespaced (`team:`, `member:`, `invitation:`). Team-admin only until #2 adds `proxy:` cases.
- `App\Concerns\HasTeams` (on User) — `teamRole(Team)` reads role from `team_members` pivot (`Membership.role` cast); `hasTeamPermission(Team, TeamPermission)` is the team-scoped gate; `toTeamPermissions(Team)` builds the `App\Data\TeamPermissions` boolean DTO shared to the frontend for conditional UI.
- Policies consume `$user->hasTeamPermission($team, TeamPermission::X)` (see `TeamPolicy`). `ProxyPolicy` was membership-only through #1; #2 (ADR-009) makes it permission-based on the same seam.
