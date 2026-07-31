# Project Status

Maintained by the **Orchestrator**. One row per feature. Update on every phase
transition, approval, or blocker change. This is a living document — no approval
gate is required to keep it current.

Phases: `Requirements → UX Design (UI only) → Technical Design → Task Planning → Implementation → Review → Done`

Source of truth: `docs/product/roadmap.md` (Approved by Project Owner, 2026-07-30;
14-item backlog). Nothing here invents or reorders roadmap items.

## Foundational work (cross-cutting, not a roadmap line)

| Artifact | State | Approval |
|---|---|---|
| `docs/plans/foundational-architecture-plan.md` | Accepted | Project Owner, 2026-07-30 |
| ADR-001 ingest→delivery pipeline spine | Accepted | 2026-07-30 |
| ADR-002 simple/enhanced mode attribute | Accepted | 2026-07-30 |
| ADR-003 delivery-attempt records & events | Accepted | 2026-07-30 |
| ADR-004 upstream-response decoupling | Accepted | 2026-07-30 |
| ADR-005 queue-dispatch abstraction | Accepted | 2026-07-30 |
| ADR-006 ingest-URL generation & security (resolves R5) | Accepted | 2026-07-30 |
| ADR-007 Laravel Actions adoption | Accepted | 2026-07-30 |
| ADR-008 inbound header-forwarding policy | Accepted | 2026-07-30 |

## Feature status

| # | Feature | Phase | Current Agent | Blockers | Approvals |
|---|---|---|---|---|---|
| 1 | Walking skeleton: ingest → fan-out delivery | Task Planning | Project Owner (task-list approval gate) | Task list is **Draft**, pending Project Owner approval before Senior Developer may implement | PRD Approved (2026-07-30); Design Approved (2026-07-30); Technical Plan Accepted (2026-07-30); Task list **not yet approved** |
| 2 | Role-based collaboration | Backlog | — (Product Manager on start) | Not started; depends on #1 | — |
| 3 | Decoupled upstream response | Backlog | — (Product Manager on start) | Not started; depends on #1 | — |
| 4 | Queued processing (FIFO & Async) | Backlog | — (Product Manager on start) | Not started; depends on #1. Open: V3, V8 | — |
| 5 | Payload storage & retention | Backlog | — (Product Manager on start) | Not started; depends on #1, benefits from #4. Open: V4, V5, V6 | — |
| 6 | Retry & replay | Backlog | — (Product Manager on start) | Not started; depends on #4, #5 | — |
| 7 | Enhanced-mode toggle | Backlog | — (Product Manager on start) | Not started; depends on #5, #6 | — |
| 8 | Payload mapping / reshaping | Backlog | — (Product Manager on start) | Not started; depends on #7. Open: M1, M2 (settle at #8 PRD) | — |
| 9 | Multi-format ingestion | Backlog | — (Product Manager on start) | Not started; depends on #8 | — |
| 10 | Sensitive data handling | Backlog | — (Product Manager on start) | Not started; depends on #5. Open: V2 | — |
| 11 | Analytics / stats | Backlog | — (Product Manager on start) | Not started; depends on #4. Open: V7, V8 | — |
| 12 | Change detection | Backlog | — (Product Manager on start) | Not started; depends on #8 | — |
| 13 | Notifications (in-app & email) | Backlog | — (Product Manager on start) | Not started; depends on #12 (usable earlier for failure alerts once #6 exists) | — |
| 14 | Test payloads | Backlog | — (Product Manager on start) | Not started; depends on #1 (more useful after #8) | — |

## Item #1 — routing detail

- **Artifacts:** PRD `docs/product/prd-01-walking-skeleton.md`; Design
  `docs/design/design-01-walking-skeleton.md`; Plan
  `docs/plans/plan-01-walking-skeleton.md`; Tasks
  `docs/tasks/walking-skeleton-tasks.md`.
- **Gating questions:** all resolved —
  `docs/questions/prd-01-walking-skeleton-r5-ingest-url.md` (Resolved, formalised
  in ADR-006), `docs/questions/prd-01-design-manage-scope.md` (Resolved),
  `docs/questions/prd-01-attempt-records-vs-storage.md` (Resolved). No open
  questions block implementation.
- **Next action:** Project Owner records approval of the task list (the ✋ Owner
  approves tasks gate). On approval, the Orchestrator moves item #1 to
  **Implementation** and routes to the **Senior Developer**; inputs are the four
  approved artifacts above.
- **Note for the Senior Developer (from the task plan):** the team-scope binding
  (`current_team_id` / `HasTeams` / `EnsureTeamMembership`) must be confirmed with
  the team lead before task T7 — a flagged gap, not an open question.

## Open questions register (roadmap-level, deferred to their gating item)

V2 (#10), V3 (#4), V4 (#5), V5 (#5), V6 (#5), V7 (#11), V8 (#4/#11), M1 (#8),
M2 (#8). Each is settled at the named item's PRD/plan, not before. R1, R2, R3,
R4, R5 and V1 are resolved (see roadmap "Resolved Decisions" and ADR-006).
