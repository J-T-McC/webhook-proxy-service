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
| 11 | Analytics / stats | **Implementation** | **Senior Developer** | **IN PROGRESS.** Depends on #4 (Done). **V7 RESOLVED/closed** (Tier 3; export ruled **out**, not deferred). **V8 renewed as a deferral, still open against #4 and #11** — no numeric target, no verdict layer, but four definitions fixed. **Q-11-03 RESOLVED** (PE, 2026-08-26, all ten items). **Q-11-04 RESOLVED** (PE, 2026-08-26) — `plan-11` now at **Revision A**. **No open questions and no outstanding approvals on #11.** **See § Item #11 — live detail** | PRD-11 Approved (Owner, 2026-08-26, 37 ACs) + **Amendment A** (PM, 2026-08-26); design-11 **fully Approved** (PM, 2026-08-26) — all six corrections landed, C1 cleared on section-scoped re-check; **plan-11 fully approved** — PE-self-certified 2026-08-26 **and both Owner flags ruled (Project Owner, 2026-08-26)**: charting dependency approved as recommended (`chart.js` ^4 **plus** `@j-t-mcc/vue3-chartjs`, local-wrapper alternative explicitly not taken), and the four-index change set approved exactly as enumerated. **No ADR** — each candidate walked against the bar and the two gates are themselves the decision record; **`docs/tasks/analytics-tasks.md` self-certified (Task Planner, 2026-08-26)** — 29 tasks T1–T29 across M1–M7, no approval gate required |
| 12 | Change detection | Backlog | — (Product Manager on start) | Not started. Dependency on #8 is **real but narrower than the label**: #12 needs only the **expected incoming structure** slice (plus its establish-from-event-or-sample flow), not the mapping editor. That slice is separable and could ship with #9; blocked while #8 is deferred unless it is extracted | — |
| 13 | Notifications (in-app & email) | Backlog | — (Product Manager on start) | Not started; depends on #12 (usable earlier for failure alerts once #6 exists). **Inherits no threshold — a cost of the V8 deferral** | — |
| 14 | Test payloads | Backlog | — (Product Manager on start) | Not started; depends on #1 (more useful after #8) | — |

## Item #11 — live detail

**Artifacts:** `docs/product/prd-11-analytics.md` (Approved, Owner, 2026-08-26, 37 ACs,
plus `## Amendment A`); `docs/design/design-11-analytics.md` (**fully Approved**,
PM, 2026-08-26); `docs/plans/plan-11-analytics.md` (**fully approved**, PE-self-certified
plus both Owner flags ruled, 2026-08-26);
`docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md` (**RESOLVED**);
`docs/tasks/analytics-tasks.md` (**self-certified**, Task Planner, 2026-08-26).

- **Next gate: Implementation (Senior Developer) — unblocked, all of M1–M7.** Requirements,
  UX Design, Technical Design and Task Planning are all closed, and **no open question or
  outstanding approval remains on #11**. Task lists carry no approval gate.
- **Implementation progress: M1 and M2 complete — T1 through T11 landed and committed**
  (Senior Developer, 2026-08-26). `composer lint`, `composer types:check` (PHPStan level 7,
  zero errors, no suppressions) and the full suite at 810/810 all verified green afterwards.
  Five deviations were
  flagged rather than decided, all recorded in `docs/tasks/analytics-tasks.md`'s completion
  notes and none of them blocking. Two are worth carrying here because a later reader could
  otherwise mistake them for defects. First, **T1's `down()` is not four bare `dropIndex`
  calls**: InnoDB silently reclaims `deliveries.team_id`/`proxy_id`'s automatic
  foreign-key-support index once the composite index covers it, so dropping the composite
  outright fails with error 1553 — the rollback restores an equivalent single-column index
  first, guarded by `Schema::hasIndex()` so it is safe to run twice. **The forward schema is
  untouched: still exactly the four indexes the Owner approved.** Second, the task doc's
  miniature "canonical 100%/67%" fixture (one delivery, three attempts, one succeeded) is
  internally inconsistent — 67% is that fixture's *failure* share, while `UnitFigure::$rate`
  is a success rate throughout, which reads ≈33%. Implemented against the correct value.
  **This does not disturb design-11's canonical pair**, which is a different and larger
  fixture (42 of 42 delivered = 100% delivery success, 28 of 42 = 67% attempt success) and
  still stands as correction C5 landed it.
- **M3 complete — T12 through T17 landed and committed** (Senior Developer, 2026-08-26).
  The Dashboard (Screen 1) is built: `resources/js/data/analyticsLabels.ts` as the single
  source of every unit-bearing label, `DashboardController`'s `statistics`/`proxies` props,
  the two-tier headline and bridge sentence, the sortable Proxies table, the Retry & replay
  tiles and Latency card, and the Trend accessible table. Verified green afterwards:
  `composer lint`, `composer types:check` (0 errors), `pnpm lint:check`, `pnpm types:check`,
  `pnpm format:check`, and the full suite at **817/817** (up from 810; T13 added seven
  tests). **Next task is T18** (M4, Proxy Show). Three things worth carrying forward.
  First, **every manual verification in M3 was run against a production build with
  `public/hot` removed** — the marker was present at session start, was removed before the
  first build, and stayed removed, so review-07 Finding 8 did not bite. Second, **T15 reads
  `Q-11-03(9)`'s deleted-proxy degradation as plural**: a deleted proxy's row keeps its
  figures and gains a muted **Deleted** badge but loses *both* the name link and the
  Terminal-failures drill-through link, because `withTrashed()` on either path would surface
  the shipped **Replay** affordance against a deleted parent. Third, **the last
  `PlaceholderPattern` usage came out in T16's commit rather than T17's**, even though T17
  is the task that names the removal — T16's card placement displaced it and the dead import
  had to go for `pnpm lint:check` to stay green. Both tasks' completion notes record this so
  a later reader does not go looking in the wrong commit.
- **M4 complete — T18 through T20 landed and committed** (Senior Developer, 2026-08-26),
  preceded by one Owner-reported fix. Proxy Show (Screens 2 and 3) is built:
  `ProxyController::show()`'s `window`/`statistics`/`destinations` props, the Analytics card,
  and the extended Destinations table. Verified green afterwards: `composer lint`,
  `composer types:check` (0 errors), `pnpm lint:check`, `pnpm types:check`,
  `pnpm format:check`, and the full suite at **825/825** (up from 817). **Next task is T21**
  (M5, Events list and drill-through). Four things worth carrying forward.
  First, **the Owner-reported fix**: the Dashboard Proxies table's action-column header
  rendered visible "View" text; it is now `sr-only`, the column and its action unchanged.
  The reading rests on `design-11` line 271, which writes that table as
  `Proxy | Delivery success | Attempt success | Terminal failures (deliveries) | (View)` —
  four bare labels and one parenthesised entry, which is an unlabelled action column rather
  than a fifth labelled header. `resources/js/pages/proxies/Index.vue`'s **visible** `Actions`
  header is a genuine counter-precedent and was deliberately left alone. Recorded as a rework
  note on T15 rather than in `docs/fixes/`, because it corrects in-flight implementation
  output rather than shipped or reviewed code.
  Second, **T20's Destinations table keeps a visible `Actions` header**, unlike Screen 1 —
  design-11 writes that one unparenthesised. The two screens differ on purpose.
  Third, **T19 included the trend/series table even though T19's own task text omits it.**
  Design-11's mockup, Flow C and T28's own back-reference all require it on this screen, and
  no other M4 task could have built it. This is a task-text omission, not a design change.
  Fourth, **T19 kept the window selector visible in the zero-traffic collapsed state.**
  Design-11's text says the entire card collapses to one message, but also calls the selector
  page-level; removing it would strand a member on an empty window with no way to check
  another. **This one is a genuine reading of design intent rather than a settled ruling —
  it is the one M4 decision the Owner or the Designer may want to overturn.**
- **M5 landed but T23 is incomplete — T21, T22 and T24 are done; T23 is partial and
  blocked on `Q-11-04`** (Senior Developer, 2026-08-26). The Events list filter resolver
  (`window`/`destination`/`outcome`, with `withQueryString()` on pagination), the
  deleted-parent drill-through tests for both halves of `Q-11-03(9)`, and the filter chips /
  explanatory copy / empty-filtered state all landed. Verified green afterwards: `composer
  lint`, `composer types:check` (0 errors), `pnpm lint:check`, `pnpm types:check`,
  `pnpm format:check`, and the full suite at **836/836** (up from 825).
  **What is blocked, and what is not.** Three of T23's four drill-through entry points are
  wired and verified — the Dashboard Proxies table's Terminal failures cell, Proxy Show's
  Retry & replay Terminal failure tile, and the Destinations table's `View events` action.
  The fourth, **the Trend "View as table" row's per-day, per-unit link, is not built**, and
  T23 must not be marked complete until it is. `docs/questions/prd-11-q-11-04-trend-day-drill-through.md`
  is **open, directed to the Principal Engineer** as the plan's owner: design-11 requires
  that link to carry a window "narrowed to that single day", while plan-11 defines the
  resolver as accepting exactly three query parameters with no day-granular mechanism, and
  `AnalyticsWindow::tryFrom()` falls back **silently** to the 30-day default (Technical
  ruling 8), so passing a date through `window` would produce the wrong window rather than
  narrowing — precisely the silently-wrong-answer failure Technical ruling 3 forbids. Both
  documents are fully approved, so this is a shape disagreement between them rather than an
  ambiguity, and resolving it means changing one of them. **The same entry point recurs at
  T27/T28** once the chart itself exists, so the ruling needs to cover the chart's own click
  target and not only the accessible table's row.
  Separately, **T21 reconciled `AC28`'s byte-identical-when-unfiltered requirement against
  § Architecture E's more literal "window always narrows `received_at`" prose in favour of
  AC28**, matching design-11's own "arrived directly" state: a bare request stays identical
  to the pre-#11 surface, and narrowing begins only once `destination` or `outcome` actually
  resolves. Recorded in T21's completion notes.
- **`Q-11-04` RESOLVED and `plan-11` re-certified at Revision A** (Principal Engineer,
  2026-08-26), on plan authority — **no Owner gate was sought or needed** (no dependency, no
  stack change, no data-model change, no security surface, nothing irreversible; the walk is
  written into the plan item by item so it can be checked rather than trusted), **and no ADR**.
  The ruling adds a **fourth optional query parameter, `date`** (ISO `Y-m-d`, deliberately the
  same string `SeriesPoint.date` already carries). A resolved `date` **replaces** the window's
  range bound with the half-open interval `[that day 00:00, next day 00:00)` — written as
  `>= start` and `< end`, never an inclusive `whereBetween`, so no instant at a day boundary
  belongs to two days or to neither. That is the same partition Technical ruling 9's
  `DATE(updated_at)` bucket produces, which is what makes a day cell's figure and that day's
  drill-through describe the same records. Absent or malformed means **no day-narrowing and
  never a 422**, per ruling 8 as now amended. `window` still resolves and is still emitted —
  it is the period a member returns to — it simply does not bound the query while a `date` is
  resolved. **The day is not a fourth chip**: Screen 4 fixes the chip row at three and Flow E
  calls the day *the window* narrowed, so it renders as the existing Window chip's value and
  that chip's `×` drops `window` and `date` together. **No design change, so nothing returned
  to the Designer.** Recorded as `## Revision A` plus new **Technical ruling 10** in
  `plan-11-analytics.md`, with `### Re-certification at Revision A` appended below the original
  certification. **T27/T28 need nothing from this**: the canvas carries **no click target by
  design** (Flow C step 3, Implementation Note 14's `aria-hidden`, and T27's own criteria
  forbidding `tabindex` and click handlers), so drill-through lives on the accessible table.
  Scoped further: the entry point is the **Proxy Show** trend table only — the C1 re-check
  states the Dashboard's team-grained trend is not a drill-through entry point at all, so its
  rows get no links **despite T23's own task text naming `Dashboard.vue`**.
- **T23 complete** (Senior Developer, 2026-08-26) — the day-narrowed Trend drill-through landed
  against Revision A's `date` parameter, and all four of T23's entry points are now wired.
  Suite at **844/844**; `composer lint`, `composer types:check`, and the three `pnpm` checks
  all green.
- **M6 STOPPED AT T25 — awaiting a Project Owner ruling. The charting dependency was not
  committed, and this is the task's own designed exit rather than a failure.** T25's check 2
  is the decisive one, and it fails: **`@j-t-mcc/vue3-chartjs` 2.1.0 defeats `chart.js`
  tree-shaking.** Its bundled source imports `chart.js`'s `registerables` export and, on every
  mount, runs `Chart.register(...registerables)` unconditionally — the same effect
  `chart.js/auto` has, reached by a different path. **The literal string `chart.js/auto` does
  not appear in the package**, so a text search for it would have passed; the finding rests on
  reading the wrapper's own bundled code, verified against the published 2.1.0 tarball fetched
  from the registry rather than only against whatever resolved locally, and independently
  re-checked by the orchestrator before this entry was written. `registerables` is an eagerly
  constructed array naming every controller, element, scale and plugin the library ships, so
  importing it pulls all of them into the module graph no matter what the consuming app
  registers. **Measured, not only read:** two minimal esbuild bundles registering the same
  seven line-chart pieces came out at **218.6 kB raw / 77.6 kB gzip with the wrapper** against
  **159.4 kB / 57.0 kB without it** — roughly **59 kB raw, 20.6 kB gzip** of pure tax — and all
  seven unused controllers (`BarController`, `BubbleController`, `DoughnutController`,
  `PieController`, `PolarAreaController`, `RadarController`, `ScatterController`) appear in the
  wrapper bundle and in none of the other. The packages were installed only to run checks 1 and
  2 against the real published code rather than an assumption, then reverted; `package.json`
  and `pnpm-lock.yaml` are unchanged and neither package is in `node_modules`. Checks 3 and 4
  were not run, being conditioned on check 2 passing. **The Owner's standing ruling was that
  the wrapper is used because it is the Owner's own package — but that approval was explicitly
  conditional on these checks, and this is the condition it named.** The plan's own recorded
  alternative is to adopt `chart.js` alone behind roughly forty lines of local `TrendChart.vue`
  doing what the wrapper's component does — hold a `<canvas>` ref, construct in `onMounted`,
  `update()` on prop change, `destroy()` on unmount — which needs no plan change beyond dropping
  one package name. **Selecting between that, accepting the tax, and fixing the wrapper upstream
  is the Owner's call.** T26, T27 and T28 are unstarted and blocked behind it; T29 is unstarted.
- **Task breakdown: 29 tasks, T1–T29, no task depends on a later one.** M1 T1 (four-index
  migration) · M2 T2–T11 (`AnalyticsWindow`, DTOs, `DeliveryStatistics`) · M3 T12–T17
  (Dashboard) · M4 T18–T20 (Proxy Show) · M5 T21–T24 (Events list and drill-through) ·
  M6 T25–T28 (charting dependency and `TrendChart.vue`) · M7 T29 (whole-surface
  verification pass). The Task Planner found **no conflict or gap** requiring escalation.
  Constraints that must not be lost in implementation are carried as acceptance criteria on
  named tasks, not as preamble: the four charting verification checks run **before** the
  packages are committed and the task **reports back rather than committing** if the wrapper
  pulls `chart.js/auto` (T25); `onMounted`-only chart construction (T27); colour resolution
  reuses the PR #12 `readTokens()`/`withAlpha()` round-trip rather than pattern-matching
  token text (T26); the `updated_at` anchor invariant is pinned by a dedicated test (T10);
  explicit `team_id` on every analytics query with cross-team isolation coverage (T9, T13,
  T18); the migration is exactly the four approved indexes and nothing else (T1); and every
  UI task carries a manual-verification step run against `pnpm run build` with `public/hot`
  removed first, review-07 Finding 8 being the standing trap (T29 closes the sweep).
- **Both Owner gates RULED (Project Owner, 2026-08-26).** **(a) Charting dependency —
  approved as recommended:** `chart.js` (^4) **plus** `@j-t-mcc/vue3-chartjs`, both
  packages; the local-wrapper alternative was **explicitly not taken**, the wrapper being
  the Owner's own package. Conditions survive the ruling: the four § Dependencies checks
  still run on the adopting task, and the decisive one is that **if the wrapper pulls
  `chart.js/auto`, tree-shaking is lost** and the task reports back rather than committing.
  Construction stays **`onMounted`-only** — Inertia SSR is configured `enabled => true` but
  has **no entrypoint and no bundle**, so nothing renders server-side today and a future
  entry must not be able to break the page. Note **Dependabot covers only `github-actions`**,
  so this package gets no automated vulnerability scanning. **(b) Four indexes — approved
  exactly as enumerated**, additive only, rollback four `dropIndex`. The approval rests on
  the **column order** (grain equality, then `status`, then `updated_at` ranging last),
  which is what bounds each query by window traffic rather than table size — and therefore
  what makes AC18's indefinite retention cost storage rather than query latency.
- **`Q-11-03` RESOLVED (PE, 2026-08-26), all ten items.** Both design contingencies
  collapsed to the good branch: a **true nearest-rank percentile is feasible live**, so
  AC20 is met outright and design-11's labelled substitute is **not** triggered; and with
  **no rollup**, the conditional "as of" caption **renders nothing** and AC11 is satisfied
  vacuously. Item **(10) holds** — the Events list filters by outcome at both grains with
  no new index or table, so nothing returned to the Designer. Item **(9)** splits: a
  deleted **destination** keeps a live link, while a deleted **proxy** takes the
  pre-approved degradation, because `withTrashed()` would surface the shipped **Replay**
  affordance against it. **No AC lacks a supporting column.** One caveat carried into
  implementation rather than buried: the window anchor is **`updated_at`**, resting on a
  terminal-rows-are-never-rewritten invariant **pinned by test, not by schema**;
  `created_at` was rejected because it makes past buckets mutable, and a new `resolved_at`
  column was rejected as new capture, which AC29 and D-11-3 forbid. Also found:
  **`ApplyTeamScope` does not scope `Delivery` or `WebhookEvent`** (only `Proxy`,
  `Destination`, `DeliveryAttempt`), so every analytics query states `team_id` explicitly.
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
