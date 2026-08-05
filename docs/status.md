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
| ADR-010 raw-payload capture (durable pre-dispatch) | Accepted | 2026-08-04 |
| ADR-011 per-proxy FIFO dispatch mechanism & `processing_mode` attribute | Accepted | 2026-08-04 |
| ADR-012 payload retention & garbage collection (erase-in-place) | Accepted | Project Owner, 2026-08-05 |
| ADR-013 dispatched-output store (divergence-gated nullable body) | Accepted | Project Owner, 2026-08-05 |
| ADR-014 captured-entity erasure & header encryption (partially supersedes ADR-010) | Accepted | Project Owner, 2026-08-05 |

## Feature status

| # | Feature | Phase | Current Agent | Blockers | Approvals |
|---|---|---|---|---|---|
| 1 | Walking skeleton: ingest → fan-out delivery | Done | — | None | PRD/Design/Plan/Tasks Approved (2026-07-30); Review *Approve with follow-ups* (2026-07-31); PR #1 merged (`5aba84b`). Post-merge Index-delete bug **fixed** (`89cfd71`, merged `19e73c7`, 2026-07-31); Owner skipped re-review and merged. Frontend regression-test harness deferred to backlog (Option B) |
| 2 | Role-based collaboration | Done | — | None | PRD-02 Approved by Owner 2026-08-03; ADR-009 Accepted by Owner 2026-08-03 (incl. Amendments A and B); Task Plan `docs/tasks/role-based-collaboration-tasks.md` Task-Planner-certified; Reviewer review-02 Approve-with-follow-ups (2026-08-03); Merged to main PR #3 2026-08-03; M1 fixed, M2 fixed via ADR-009 Amendment B (client-side affordance derivation); Owner-accepted |
| 3 | Decoupled upstream response | Done | — | None | PRD-03 Approved (Owner 2026-08-03); Task plan `docs/tasks/decoupled-upstream-response-tasks.md` Task-Planner-certified (T1–T12); Reviewer review-03 *Approve with follow-ups* (2026-08-04)—M-1 (`response_body` `max:` char-vs-byte) + `ProxyResource` N+1 both Owner-accepted, non-blocking; R2 override—capture mode-independent (Owner 2026-08-04); ADR-010 Accepted (Owner 2026-08-04); proxies columns `response_status`/`response_body` approved (Owner 2026-08-04); security acknowledgements—headers plaintext until #10 + APP_PREVIOUS_KEYS guard binding (Owner 2026-08-04); **PR #4 review-driven additions (Owner 2026-08-04): `response_status` restricted to select {200,202,204} + 204⇒empty body (PRD-03 AC4/AC12), read-only Response card on Show page (design-03-show, PM-approved), status defs centralized to `data/` const + `DataOption` standard (coding.md); Owner waived delta re-review**; **PR #4 merged to main (`3221a1d`), branch deleted, 2026-08-04** |
| 4 | Queued processing (FIFO & Async) | Done | — | None. V3/V8 Owner-deferred | PRD-04/design-04/plan-04/ADR-011/tasks-04 approved; implementation T1–T25 done; **review-04 Approve with follow-ups (2026-08-04)**; M-1 fixed & verified (overlap-lock TTL==lease); all checks green (354 tests, lint/types/frontend-triad, CI `tests` SUCCESS); **PR #5 squash-merged to main (`bd4bf4d`), branch deleted, 2026-08-05** |
| 5 | Payload storage & retention | Review — rework | Senior Developer (M-1) | **review-05 *Request changes*** (2026-08-05): M-1 config-sanity lower bound missing — `RETENTION_DAYS` blank/non-numeric ⇒ cutoff `now()` ⇒ mass erasure; `RETENTION_PURGE_BATCH=0` ⇒ non-terminating loop. 2 Minors are upstream (PE: partial-fan-out Async hold gap; PM: `failed_jobs` plaintext `DeliveryUnit` vs AC6 reach) — non-blocking, Owner decision pending | **PRD-05 Approved (Owner 2026-08-05), amended by Amendment A (Owner ruling 2026-08-05)** — retention erases payload content **in place**, record retained with explicit cleaned state; V4/V5/V6 settled in PRD; **D1 (retained-record growth) accepted out of scope**; Q-05-01 RESOLVED Option B — no payload-inspection UI, no Designer gate; Q-05-02 RESOLVED; Q-05-03 amended (answers superseded by Amendment A); Q-05-04 RESOLVED — AC21/AC22 feasible as stated; **ADR-012 / ADR-013 / ADR-014 all Accepted (Owner 2026-08-05)** — covers irreversible payload erasure, `dispatched_payloads` table, `webhook_events` schema change, at-rest encryption extended to headers, and partial supersession of ADR-010 |
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
- **History:** Task list Approved (2026-07-30); T1–T30 implemented; Review
  `docs/reviews/review-01-walking-skeleton.md` returned *Approve with follow-ups*
  (2026-07-31); PR #1 **merged** to `main` (`5aba84b`).
- **Open defect (2026-07-31, from Project Owner):** On the proxies **Index** table
  (`resources/js/pages/proxies/Index.vue`), clicking a row's Delete → confirm modal
  → Confirm does **not** delete the proxy. The Show/detail-page delete button works,
  and the backend is verified green (`ProxyDestroyTest`, route helper
  `resources/js/routes/proxies/index.ts` `destroy()`, `SetTeamUrlDefaults` +
  `EnsureTeamMembership`). Root cause is isolated to Index-table frontend wiring —
  suspected `AlertDialogAction` (reka-ui) auto-close interfering with
  `confirmDelete()` at Index.vue:53-73 / :231-237; the working pattern is on
  `resources/js/pages/proxies/Show.vue`.
- **Routing:** Defect in merged, implemented code → rework flows back to the
  **Senior Developer** (per workflow rework rule). Scope is contained within
  approved AC4; no PRD/design/plan/tasks change required, so no upstream gate to
  reopen. Inputs: this bug report, the four approved item-#1 artifacts, and the
  working `Show.vue` delete reference. Deliverable: minimal fix on the Index table
  wiring **plus a regression test** proving row-delete removes the proxy, then hand
  to the **Reviewer**; final release re-approval stays with the Project Owner.
- **Delete-bug fix status (2026-07-31):** Fix applied in
  `resources/js/pages/proxies/Index.vue` (decouple dialog `open` boolean from target
  data; see T27 rework note in `docs/tasks/walking-skeleton-tasks.md`); verified green
  (`pnpm lint:check`/`types:check`/`format:check`, `composer lint`/`types:check`,
  `./vendor/bin/sail test --filter ProxyDestroyTest`). The **automated frontend
  regression test is deferred** per Project Owner decision **Option B**
  (`docs/questions/prd-01-index-delete-regression-test-harness.md`, RESOLVED
  2026-07-31): ship the fix now with a **documented manual-verification step** (Index
  Delete → confirm → row removed + toast), and defer the frontend test harness to the
  backlog. **Merged to `main`** (`89cfd71`, merge `19e73c7`, pushed 2026-07-31); Owner skipped re-review. Item #1 **Done**.

## Backlog follow-ups (deferred, not gating any current item)

- **Frontend test harness (Vitest + `@vue/test-utils` + DOM env + `test:js` script).**
  Deferred per Owner Option B (2026-07-31) and already captured as deferred/backlog task
  **T31** in `docs/tasks/walking-skeleton-tasks.md`. First automated test to write once it
  lands: the **Index-table row-delete regression** (row Delete → confirm → `router.delete`
  fires / proxy removed), which no PHP/sail test can exercise. Until then the fix is guarded
  by the documented manual-verification step (T27 rework note). Does **not** run under
  `./vendor/bin/sail test` (PHPUnit); CI wiring to be updated when scheduled.

- **Real-concurrency integration test for the FIFO single-advancer window (review-04 follow-up).**
  Current PHPUnit (single connection) proves the committed-claim short-circuit but can't interleave
  two live claim transactions; production ordering leans on `WithoutOverlapping` serialization. Write a
  true-concurrency integration test if/when an integration harness lands. Non-blocking; M-1 fix holds the
  liveness guarantee.
- **Optional T18 mode-switch test consolidation (review-04 follow-up).** T18 exercises mode-switch via the
  model; endpoint paths are covered by T19/T20. No action needed unless consolidating later.

## Open questions register (roadmap-level, deferred to their gating item)

V2 (#10), V3 (#4), V4 (#5), V5 (#5), V6 (#5), V7 (#11), V8 (#4/#11), M1 (#8),
M2 (#8). Each is settled at the named item's PRD/plan, not before. R1, R2, R3,
R4, R5 and V1 are resolved (see roadmap "Resolved Decisions" and ADR-006).
