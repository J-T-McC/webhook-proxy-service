# Project Status

Maintained by the **Orchestrator**. One row per feature. Update on every phase
transition, approval, or blocker change. This is a living document — no approval
gate is required to keep it current.

Phases: `Requirements → UX Design (UI only) → Technical Design → Task Planning → Implementation → Review → Done`

Source of truth: `docs/product/roadmap.md` (Approved by Project Owner, 2026-07-30;
14-item backlog). Nothing here invents or reorders roadmap items.

**This file carries only what routing needs**: phase, owner, blockers, approvals,
and a pointer to the artifact that holds the detail. The artifacts are the record —
a ruling's reasoning lives in the PRD, design, plan, ADR, review or question doc
that made it, never here. Narrative history of items already **Done** is archived in
`docs/status-history.md`, which no agent needs to read to route work.

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
| ADR-015 delivery retry mechanism (`deliveries` state, policy columns, delayed-job + sweeper, terminal state) | Accepted | Project Owner, 2026-08-12 |
| ADR-016 FIFO composition under retry & replay (partially supersedes ADR-011 P1–P3) | Accepted | Project Owner, 2026-08-12 |
| ADR-017 replay dispatch & payload read surface (fetch-on-reveal) | Accepted | Project Owner, 2026-08-12 |
| ADR-018 one mode selector, two evaluation points (partially supersedes ADR-015 Decision 3) | Accepted | Project Owner, 2026-08-25 |
| ADR-019 payload mapping — composition-time step & resolution-time configuration | **Proposed** (not Accepted; parked with #8) | — |

## Feature status

Artifact naming is regular: `docs/product/prd-NN-*.md`, `docs/design/design-NN-*.md`,
`docs/plans/plan-NN-*.md`, `docs/tasks/*-tasks.md`, `docs/reviews/review-NN-*.md`,
`docs/questions/`. Only irregular or currently-live paths are named below.

| # | Feature | Phase | Current Agent | Blockers | Approvals & artifacts |
|---|---|---|---|---|---|
| 1 | Walking skeleton: ingest → fan-out delivery | Done | — | None | All four artifacts Approved (2026-07-30); review-01 *Approve with follow-ups*; PR #1 merged (`5aba84b`). Post-merge Index-delete defect fixed and merged (`19e73c7`, 2026-07-31), Owner skipped re-review. Frontend regression harness deferred → **backlog T31** |
| 2 | Role-based collaboration | Done | — | None | PRD-02 + ADR-009 (incl. Amendments A/B) Approved (Owner, 2026-08-03); review-02 *Approve with follow-ups*; PR #3 merged 2026-08-03 |
| 3 | Decoupled upstream response | Done | — | None | PRD-03 Approved (Owner, 2026-08-03); ADR-010 Accepted; review-03 *Approve with follow-ups*, both Minors Owner-accepted; PR #4 merged (`3221a1d`, 2026-08-04). Security acknowledgement: **headers stay plaintext until #10** |
| 4 | Queued processing (FIFO & Async) | Done | — | None. **V3 and V8 remain Owner-deferred against this item** | PRD-04 / design-04 / plan-04 / ADR-011 / tasks-04 Approved; review-04 *Approve with follow-ups*, M-1 fixed; PR #5 merged (`bd4bf4d`, 2026-08-05) |
| 5 | Payload storage & retention | Done | — | None. Both carried-forward Minors closed 2026-08-25 | PRD-05 Approved + **Amendment A** (erase-in-place) + **Amendment B** ratified 2026-08-25; ADR-012/013/014 Accepted; review-05 *Approve with follow-ups*, M-1 fixed; PR #6 merged (`ed421f1`, 2026-08-05). **Exposure carried to #10 as deferred concern D2, which gates #10's PRD** |
| 6 | Retry & replay | Done | — | None blocking | PRD-06 Approved (Owner, 2026-08-12); design-06 PM-approved; plan-06 PE-certified; ADR-015/016/017 + 4 data-model changes Owner-approved; tasks-06 T1–T46; review-06 *Approve with follow-ups* — 3 Majors fixed and re-verified; PR #8 merged (`e1c2894`, 2026-08-25). **10 follow-ups carried forward** — see `docs/reviews/review-06-retry-replay.md`. AC19/AC21/AC23/AC24 rest on inspection, not an automated gate |
| 7 | Enhanced-mode toggle | Done | — | None | PRD-07 Approved (Owner, 2026-08-21) + Amendments A/B (PM, 2026-08-25); design-07 PM-approved; plan-07 PE-certified + **Revision A**; ADR-018 Accepted; tasks-07 T1–T13 + M7; review-07 **Approve** after re-review (2026-08-26) — one Major (persisted retry policy destroyed by an abandoned in-session downgrade) fixed on Owner ruling *keep preservation, fix the re-seed*; PR #14 merged (`13f0da7`, 2026-08-26). **Follow-ups: review-07 Finding 8 (`public/hot` + a live Vite dev server invalidate "verified against a fresh build" claims) and Nits 5–7** |
| 8 | Payload mapping / reshaping | **Deferred (Owner, 2026-08-26)** | — | **Deferred: not needed for MVP.** Artifacts complete and **parked, not withdrawn**; zero implementation exists (`PipelineFactory` carries only its reserved `#8` comment), so deferral unwinds nothing in code | PRD-08 Approved (Owner, 34 ACs); design-08 PM-approved; plan-08 self-certified **except its two Owner gates, deliberately NOT approved** — a four-table data model must not be approved against a codebase that will have moved by build time; they are re-presented on resumption. ADR-019 **Proposed**. **On resumption see § Item #8 — carried forward** |
| 9 | Multi-format ingestion | Backlog | — (Product Manager on start) | Not started. **#9 does NOT require #8 (Owner correction, 2026-08-26)** — the roadmap's constraint is *consistency*, one canonical JSON representation, not a functional prerequisite. **Two obligations transfer to whoever goes first: define the canonical JSON representation well enough for #8/#12 to inherit without inventing a second, and rule explicitly on what destinations receive (expected: unchanged — reshaping is #8's)** | — |
| 10 | Sensitive data handling | Backlog | — (Product Manager on start) | Not started; depends on #5. Open: **V2**. **#5's deferred concern D2 gates this PRD**; #3 left headers plaintext until this item | — |
| 11 | Analytics / stats | **UX Design** | **Designer** | **IN PROGRESS.** Depends on #4 (Done). **V7 RESOLVED/closed** (Tier 3; export ruled **out**, not deferred). **V8 renewed as a deferral, still open against #4 and #11** — no numeric target, no verdict layer, but four definitions fixed. **Q-11-03 OPEN → Principal Engineer**, non-blocking. **See § Item #11 — live detail** | PRD-11 Approved (Owner, 2026-08-26, 37 ACs) + **Amendment A** (PM, 2026-08-26); design-11 **Approved with six required corrections** (PM, 2026-08-26) |
| 12 | Change detection | Backlog | — (Product Manager on start) | Not started. Dependency on #8 is **real but narrower than the label**: #12 needs only the **expected incoming structure** slice (plus its establish-from-event-or-sample flow), not the mapping editor. That slice is separable and could ship with #9; blocked while #8 is deferred unless it is extracted | — |
| 13 | Notifications (in-app & email) | Backlog | — (Product Manager on start) | Not started; depends on #12 (usable earlier for failure alerts once #6 exists). **Inherits no threshold — a cost of the V8 deferral** | — |
| 14 | Test payloads | Backlog | — (Product Manager on start) | Not started; depends on #1 (more useful after #8) | — |

## Item #11 — live detail

**Artifacts:** `docs/product/prd-11-analytics.md` (Approved, Owner, 2026-08-26, 37 ACs,
plus `## Amendment A`); `docs/design/design-11-analytics.md` (Approved with corrections,
PM, 2026-08-26); `docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md` (OPEN).

- **Next gate:** the six corrections **C1–C6** land with the Designer. **C2–C6 land under
  the existing approval and need no re-approval. C1 alone returns to the Product Manager
  for a section-scoped re-check of Flow E and Screen 4** — not a re-approval of the spec,
  and it does not block the Principal Engineer handoff. Then Technical Design.
- **Open to the Principal Engineer — `Q-11-03`, non-blocking, carries findings F1–F4.**
  Two of the design's accepted calls are **contingencies whose trigger is the PE's at
  `Q-11-03(6)`**, not the PM's: the labelled latency-tail substitute (a bare average still
  fails AC20) and the conditional "as of" caption (a rollup makes it mandatory and
  concrete; a live query may omit it). Item **(9)** asks whether a drill-through can
  resolve a **soft-deleted** parent at the routing/authorization layer — its requirement
  half is already ruled and its fallback pre-approved, so it blocks nothing.
- **Two Owner gates fall due at plan time, neither approved yet:**
  **(a) the charting library** — the Owner *suggested* `@j-t-mcc/vue3-chartjs`
  (https://github.com/J-T-McC/vue3-chartjs), which adds **two** npm packages
  (`chart.js` + the wrapper) to a project with no charting library today. A suggestion is
  not the gate clearing itself: the PE must confirm fit and record it formally rather than
  let it arrive in a diff. Verify Chart.js 4 tree-shaking/registration under Vite 8 and
  bundle impact; behaviour under Inertia SSR if enabled; dark-mode theming against the
  app's CSS custom properties — noting that `getComputedStyle` returns custom properties
  **verbatim** and a production minifier can rewrite `hsl()` tokens to hex (PR #12).
  **(b) the index/aggregation store**, whose shape the V7 ruling fixed.
- **Standing constraints on the feature, from the Owner's rulings.** Success and failure
  are reported as **both units, labelled distinctly, never merged into one figure and never
  behind a unit toggle** — the same healthy traffic reads 67% failure per-attempt and 100%
  success per-delivery, which is the confusion the labelling exists to prevent. **Statistics
  are retained indefinitely at #11** (two permanently growing tables — PRD-05 D1's class of
  concern, compounded by **F1**: ADR-003 promised attempt records a lifecycle that was never
  built, so "long-lived" means forever by omission); the technical half stays with the PE at
  `Q-11-03(1)`. **Per-event-type analytics is excluded** — **F3**, no long-lived event-type
  attribute exists outside the payload body. **Tier 3 needs no new capture**; every figure
  traces to an existing column. Nothing #11 counts is erased by GC — but that holds *only*
  because of PRD-05 **Amendment A**; under ADR-012's original hard-delete an events-received
  count would have decayed silently every night. **AC2 pins it.**

## Item #8 — carried forward (must not be lost while deferred)

- **ADR-019's finding:** `MapStep` must terminalize a failed dispatch's deliveries before
  short-circuiting, or FIFO parks at `awaiting_retry` with no lease, hold H2 has no age
  escape, and payloads become immortal — a **PRD-05 AC6 breach**.
- **Re-validate on resumption** against whatever shipped meanwhile, in particular **#10
  (sensitive data)**, which the PE named as an explicit input: `proxy_maps.output` and
  `proxy_map_conditions.value` hold member-typed plaintext literals.
- Roadmap **M1/M2** and **Q-08-03** are RESOLVED; the deferral does not reopen them.

## Backlog follow-ups (deferred, not gating any current item)

- **Frontend test harness (Vitest + `@vue/test-utils` + DOM env + `test:js` script).**
  Deferred per Owner Option B (2026-07-31); captured as backlog task **T31** in
  `docs/tasks/walking-skeleton-tasks.md`. First test to write once it lands: the
  **Index-table row-delete regression**, which no PHP/sail test can exercise. Does **not**
  run under `./vendor/bin/sail test` (PHPUnit); CI wiring to be updated when scheduled.
  Until it lands, every design flow verified by hand is guarded only by a documented
  manual-verification step — and review-07 Finding 8 is the standing trap: with `public/hot`
  present and a Vite dev server running, a "verified against a fresh build" claim was served
  from the dev server.
- **Real-concurrency integration test for the FIFO single-advancer window** (review-04).
  PHPUnit on a single connection proves the committed-claim short-circuit but cannot
  interleave two live claim transactions; production ordering leans on `WithoutOverlapping`
  serialization. Non-blocking; the M-1 fix holds the liveness guarantee.
- **Optional T18 mode-switch test consolidation** (review-04). Endpoint paths are covered by
  T19/T20; no action unless consolidating later.

## Open questions register (roadmap-level, deferred to their gating item)

V2 (#10), V3 (#4), V7 — **RESOLVED/closed 2026-08-26**, V8 (#4 and #11 — **deferred a
fourth time, still open**, and the first deferral with a visible product cost: #13 inherits
no threshold), M1/M2 (#8 — **resolved**), V4/V5/V6 (#5 — settled in PRD-05). Each remaining
question is settled at the named item's PRD or plan, not before. R1–R5 and V1 are resolved
(see roadmap "Resolved Decisions" and ADR-006).
