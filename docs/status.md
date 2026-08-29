# Project Status

Maintained by the **Orchestrator**. One row per feature. Update on every phase
transition, approval, or blocker change. This is a living document — no approval
gate is required to keep it current.

Phases: `Requirements → UX Design (UI only) → Technical Design → Task Planning → Implementation → Review → Done`

Source of truth: `docs/product/roadmap.md` (Approved by Project Owner, 2026-07-30;
**15-item backlog** since item #15 was added by the Owner on 2026-08-27). Nothing
here invents or reorders roadmap items.

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
| ADR-020 FIFO advancer job duration & claim-lease safety (partially supersedes ADR-011 Decisions 2 and 3; amends ADR-016 Decision 1) | Accepted | Project Owner, 2026-08-26 |
| ADR-021 secret handling & rotation (`proxy_secrets` rows for rotating secrets, columns for the credential, recoverable encryption throughout) | Accepted | Project Owner, 2026-08-27 |
| ADR-022 inbound verification at the ingest boundary (the seam, and the closed two-case scheme registry) | Accepted | Project Owner, 2026-08-27 |
| ADR-023 outbound request contract (amends ADR-008 P1 and P2) | **Accepted by ratification** — PRD-10's approval | Project Owner, 2026-08-27 |
| ADR-024 field obfuscation & revealed-payload envelope (partially supersedes ADR-017 — adopts an alternative it rejected by name) | Accepted | Project Owner, 2026-08-27 |
| ADR-025 outbound header policy, signature pass-through & signing header names (supersedes positions in ADR-008 and ADR-023) | Accepted | Project Owner, 2026-08-28 |
| ADR-026 inbound-verification removal & minimal outbound header strip (supersedes ADR-022 in full; positions in ADR-008, ADR-023, ADR-025) | Accepted | Project Owner, 2026-08-28 |

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
| 10 | Sensitive data handling | **Review complete — Approve** | — (Project Owner decides, then the pull request) | None. Review gate run in full and **re-reviewed after rework: Approve** (`docs/reviews/review-10-sensitive-data-handling.md`, 2026-08-28) — 0 Blockers, 0 Majors, 0 Minors, 4 Nits carried forward, none routed. The gate's one Major (an edited credential header name was silently discarded unless the secret was also replaced) and its four Minors are all closed: `55db968` fixed findings 4, 5 and 8, the Designer resolved finding 6 with no code work, and the Product Manager closed finding 9 by running both open design-10 amendment gates. Suite 1019/1019, 4818 assertions; lint and types clean. **Awaiting the Owner's decision on the review, then the pull request** — nothing is pushed or merged | PRD-10 Approved with Amendments A, B and C (Project Owner, 2026-08-27; Amendment C by the Product Manager, 2026-08-28); `docs/plans/plan-10-sensitive-data-handling.md` fully approved, all four Owner gates; `docs/design/design-10-sensitive-data-handling.md` Approved as amended — **four closed gates, none open** (two 2026-08-27, plus the inbound-verification withdrawal and the Screen 6 `DialogDescription` correction, both gated 2026-08-28; the Screen 4b placement ruling is Designer self-certified); ADR-021, ADR-022, ADR-024 Accepted, ADR-023 Accepted by ratification, ADR-025 and ADR-026 Accepted (2026-08-28); tasks at `docs/tasks/sensitive-data-handling/index.md`, split per milestone, every task carrying completion notes; review-10 *Approve*; Q-10-02, Q-10-03, Q-10-04 and Q-10-05 all RESOLVED |
| 11 | Analytics / stats | Merged — Review gate not run | — | Merged to `main` (PR #17, `d9fed9c`, 2026-08-26). Depends on #4 (Done). **The independent Review gate was NOT run** — the Owner merged on T29's self-verification, so no `docs/reviews/review-11-*.md` exists. #6 and #7 each surfaced Majors at that gate, so this is a recorded gap, not a completed phase. V7 RESOLVED/closed; **V8 renewed as a deferral, still open against #4 and #11**. See § Item #11 — live detail | PRD-11 Approved (Project Owner, 2026-08-26, 37 ACs) plus Amendments A and B; design-11 fully Approved and re-approved for the Amendment B delta (Product Manager, 2026-08-26); plan-11 fully approved — PE self-certified plus both Owner flags ruled — now at Revision B; `docs/tasks/analytics-tasks.md` self-certified, T1–T32 complete. No ADR: the two gates are themselves the decision record |
| 12 | Change detection | Backlog | — (Product Manager on start) | Not started. Dependency on #8 is **real but narrower than the label**: #12 needs only the **expected incoming structure** slice (plus its establish-from-event-or-sample flow), not the mapping editor. That slice is separable and could ship with #9; blocked while #8 is deferred unless it is extracted | — |
| 13 | Notifications (in-app & email) | Backlog | — (Product Manager on start) | Not started; depends on #12 (usable earlier for failure alerts once #6 exists). **Inherits no threshold — a cost of the V8 deferral** | — |
| 14 | Test payloads | Backlog | — (Product Manager on start) | Not started; depends on #1 (more useful after #8) | — |
| 15 | Pause and resume dispatch | **Requirements** | Product Manager → **Project Owner (approval)** | New roadmap line added 2026-08-27 per Owner ruling that it is not #10 scope. PRD-15 drafted, 22 ACs, **awaiting Owner approval**. Depends on #4 (Done) and #6 (Done); interacts with #5's retention window. **The Owner specifically is required because PRD-15 narrows two already-approved documents** — PRD-05 AC8 and PRD-06 AC18. **Q-15-01 is open to the Principal Engineer.** **PRD-15 carries a `## UX Direction` section, so the Designer is a required phase before Technical Design.** Rulings, scope calls and the reasoning behind them are in PRD-15 and Q-15-01, not here | `docs/product/prd-15-pause-and-resume-dispatch.md` (Draft, 22 ACs); `docs/questions/prd-15-q-15-01-pause-dispatch-scheduler-interactions.md` (open) |

## Item #11 — live detail

**Phase:** Merged to `main` (PR #17, `d9fed9c`, 2026-08-26). The independent Review gate was
not run.

**Artifacts:** `docs/product/prd-11-analytics.md` (Approved, Project Owner, 2026-08-26, 37 ACs,
plus Amendments A and B); `docs/design/design-11-analytics.md` (fully Approved, Product Manager,
2026-08-26, and re-approved the same day for the Amendment B delta with no corrections);
`docs/plans/plan-11-analytics.md` (fully approved — PE self-certified plus both Owner flags
ruled, 2026-08-26; now at Revision B, Technical rulings 1–13);
`docs/tasks/analytics-tasks.md` (self-certified, Task Planner; T1–T32 all complete, T31
deliberately skipped, with every task's completion notes);
`docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md` and
`prd-11-q-11-04-trend-day-drill-through.md` (both RESOLVED, Principal Engineer, 2026-08-26).

The per-milestone build record — deviations, interpretive calls, manual-verification evidence
and suite counts — lives in `analytics-tasks.md`'s completion notes. The rulings behind it live
in `plan-11` (Revisions A and B), PRD-11 Amendment B, and the two question documents. None of
that is repeated here; read the owning artifact.

- **Recorded gap: the Review gate was never run.** The Owner squash-merged PR #17 on T29's own
  self-verification, so no `docs/reviews/review-11-*.md` exists. Items #6 and #7 each surfaced
  Majors at that gate. This is a gap rather than a completed phase, and it is the only
  outstanding item on #11.
- **Owner ruling on T25's check-2 finding (Project Owner, 2026-08-26): adopt
  `@j-t-mcc/vue3-chartjs` anyway.** The wrapper defeats `chart.js` tree-shaking; the measured
  cost, the local-wrapper fallback and the fix-upstream-first option were all put to the Owner
  and neither alternative was taken. Recorded in `plan-11-analytics.md` § *Owner ruling on T25's
  check-2 finding* and in T25's completion notes.
- **Carried here because no other artifact records it: the wrapper's cost measured after T27 and
  T28 wired the chart in is +206.6 kB raw / +71.0 kB gzip** (901.97 → 1108.56 kB raw,
  278.28 → 349.23 kB gzip). This does not contradict the ruling. The ~59 kB raw / 20.6 kB gzip
  the Owner accepted was the wrapper's own auto-registration tax — the avoidable part, which is
  what the ruling was about. The rest is `chart.js` itself and would have been paid under the
  declined local-wrapper option too. See also the backlog follow-up on the wrapper's upstream
  defects.
- **Standing constraints from the Owner's rulings, because they bind future work on this
  surface.** Success and failure are reported as **both units, labelled distinctly**, never
  merged into one figure and never behind a unit toggle — the same healthy traffic reads 67%
  failure per-attempt and 100% success per-delivery. Statistics are **retained indefinitely**
  (two permanently growing tables; the technical half stays with the Principal Engineer at
  `Q-11-03(1)`). **Per-event-type analytics is excluded** — no long-lived event-type attribute
  exists outside the payload body. Nothing #11 counts is erased by garbage collection, but only
  because of PRD-05 **Amendment A**; under ADR-012's original hard-delete an events-received
  count would have decayed silently every night. AC2 pins it.

## Item #8 — carried forward (must not be lost while deferred)

- **ADR-019's finding:** `MapStep` must terminalize a failed dispatch's deliveries before
  short-circuiting, or FIFO parks at `awaiting_retry` with no lease, hold H2 has no age
  escape, and payloads become immortal — a **PRD-05 AC6 breach**.
- **Re-validate on resumption** against whatever shipped meanwhile, in particular **#10
  (sensitive data)**, which the PE named as an explicit input: `proxy_maps.output` and
  `proxy_map_conditions.value` hold member-typed plaintext literals.
- Roadmap **M1/M2** and **Q-08-03** are RESOLVED; the deferral does not reopen them.

## Operations work in flight (not a roadmap line)

Owner-directed operational work, deliberately outside the dev-team pipeline. Each entry is
here because it has no roadmap row; where an artifact owns the detail, it is named rather
than summarised.

**Branch `feat/horizon`, PR #18, merged 2026-08-27 (`bd0e67d`); branch deleted.** Laravel
Horizon `^5.48` at `/horizon`, plus `ADR-020` (Accepted, Project Owner, 2026-08-26), which
this work uncovered and which is load-bearing for FIFO. The design, the by-reference delivery
job, the two recorded hazards and the concurrency-evidence limits all live in
`docs/architecture/adr-020-fifo-advancer-job-duration-and-claim-lease-safety.md`,
`docs/reviews/review-adr-020-fifo-and-horizon.md` (**Approve**, one Nit, fixed in `8e22649`)
and `docs/fixes/fifo-advancer-duration-and-settle-race.md`. Implementation is `70d667a` and
`bbc7762`; suite 880/880, `composer lint` and `composer types:check` clean.

Two operational facts have no other home:

- **Horizon access is HTTP Basic against `HORIZON_USERNAME`/`HORIZON_PASSWORD`, not a user
  allow-list**, because this project has no superadmin role — operational access is deployment
  configuration, not a property of an application account. Both the route middleware and
  Horizon's own `viewHorizon` gate consult the same check, so removing the middleware from
  `config/horizon.php` cannot open the dashboard. Unset credentials fail closed.
  `.env.example` moves to `QUEUE_CONNECTION=redis`, since Horizon supervises Redis queues only.
- **Two supervisors replace the single published one** — `supervisor-webhooks` on the
  `webhooks` queue and `supervisor-default` on `default`. Concurrency is safe on both because
  FIFO ordering is held by `AdvanceProxyFifoQueue`'s atomic `FOR UPDATE` claim, not by there
  being a single worker. `tries` stays 1 on both: `DeliverToDestination` declares `$tries = 1`
  because retry is ADR-015's application-level policy, and a queue-level retry would re-send a
  webhook outside it.

**Dependabot PR #19, merged 2026-08-27 (`ea35499`).** `pnpm/action-setup` 6.0.9 → 6.0.10 in the
`github-actions` group. Routine, no review gate.

**Branch `chore/mailgun-mailer`, PR #20, merged 2026-08-27 (`cb0fdf4`).** Mailgun becomes a
selectable mail transport: `symfony/mailgun-mailer` and `symfony/http-client` added (the
project had `symfony/mailer` but no HTTP client), a `mailgun` entry in `config/mail.php`, and a
credentials block in `config/services.php` reading `MAILGUN_DOMAIN`, `MAILGUN_SECRET` and
`MAILGUN_ENDPOINT`. Nothing switches by default — `MAIL_MAILER` stays `log` in `.env.example`
and Mailgun is selected by deployment configuration only. **The production environment was
already configured by the Owner before the packages landed, so the variable names above are
assumed to match what production sets;** they are Laravel's conventional names. Verified by
resolving `MailgunHttpTransport` from the container under `MAIL_MAILER=mailgun` rather than by
sending mail. Suite 880/880, `composer lint` and `composer types:check` clean.

## Backlog follow-ups (deferred, not gating any current item)

Open:

- **PRD-10's Status block still says Amendment B is awaiting the Project Owner, while
  § Amendment B records that the Owner approved it on 2026-08-27.** The document contradicts
  itself in its own header, which is the half a later reader trusts first. Owned by the Product
  Manager. It blocks nothing — every downstream document already treats Amendment B as approved.
- **`.env.example` makes a fresh `composer setup` fail, and no one owns the fix yet.** It ships
  `DB_CONNECTION=sqlite` with every MySQL variable commented out, so anyone following it
  literally hits the MySQL-only migration set at `artisan migrate`. `compose.yaml` also
  interpolates `${DB_DATABASE}`/`${DB_USERNAME}`/`${DB_PASSWORD}`, which are empty under that
  file. It is a code change rather than a doc change, and `.env.example` is on the autopilot's
  forbidden-paths list, so it needs a Project Owner decision.
- **Async fan-out has been exposed to queue-driver message-size limits since #4.** Recorded by
  ADR-020 Decision 8 as pre-existing. `INGEST_MAX_BODY_BYTES` defaults to 50 MiB while SQS caps
  a message at 256 KiB; Redis and the `database` driver both tolerate it, which is why it stays
  invisible until a driver migration turns it into a hard failure. ADR-020's by-reference
  delivery job removes the exposure. **Choosing the 50 MiB cap itself is the Product Manager's
  or the Owner's, not the Principal Engineer's** — it is flagged in `config/ingest.php` as a
  placeholder to revisit before MVP.
- **`@j-t-mcc/vue3-chartjs` 2.1.0 has two upstream defects that #11 works around, and no other
  artifact records them.** Its exposed `update()` replays a `props` snapshot frozen once in
  `setup()`, so prop-driven colour and data changes silently no-op — `TrendChart.vue` therefore
  writes to the exposed `chartJSState.chart` directly, without which a live theme toggle leaves
  the chart painted in the old theme's colours. It also ships a broken `exports` map with no
  `types` condition, unreachable under `moduleResolution: "bundler"`, so
  `resources/js/types/vue3-chartjs.d.ts` carries a local ambient shim. **Both are bugs in the
  Owner's own package**, worth fixing upstream rather than carrying the workarounds
  indefinitely.
- **Frontend test harness (Vitest + `@vue/test-utils` + DOM env + `test:js` script).** Deferred
  per Owner Option B (2026-07-31); captured as backlog task **T31** in
  `docs/tasks/walking-skeleton-tasks.md`. First test to write once it lands: the Index-table
  row-delete regression, which no PHP/sail test can exercise. Does not run under
  `./vendor/bin/sail test`; CI wiring to be updated when scheduled. Until it lands, every design
  flow verified by hand is guarded only by a documented manual-verification step — and
  **review-07 Finding 8 is the standing trap**: with `public/hot` present and a Vite dev server
  running, a "verified against a fresh build" claim was served from the dev server.
- **Real-concurrency integration test for the FIFO single-advancer window** (review-04). PHPUnit
  on a single connection proves the committed-claim short-circuit but cannot interleave two live
  claim transactions; production ordering leans on `WithoutOverlapping` serialization.
  Non-blocking.
- **Optional T18 mode-switch test consolidation** (review-04). Endpoint paths are covered by
  T19/T20; no action unless consolidating later.

Closed, kept only as a pointer:

- ~~`league/commonmark` 2.8.3 denial-of-service advisories.~~ **CLOSED 2026-08-27** —
  `docs/fixes/commonmark-dos-advisories.md`. Upgraded to 2.10.0 within the existing `^2.8.1`
  constraint; `composer audit` clean.
- ~~The production asset build depends on a third-party font CDN at build time.~~
  **CLOSED 2026-08-27 — PR #21 (`5d9f75e`).** Fonts vendored at `resources/fonts/`.
- ~~`docs/stack/stack.md` records "Local/default: SQLite".~~ **CLOSED 2026-08-27 — PR #22
  (`6bc26a6`).** `stack.md` now records MySQL only and names the MySQL-only DDL that forces it.
  **The standing constraint is stack.md's to state: the full migration set cannot run against
  SQLite, so `./vendor/bin/sail test` is in practice the only way this suite runs.**
- ~~`design-11`'s Components row describes the charting dependency as ungated.~~
  **CLOSED 2026-08-27 — PR #22 (`6bc26a6`).**

## Open questions register (roadmap-level, deferred to their gating item)

V2 (#10), V3 (#4), V7 — **RESOLVED/closed 2026-08-26**, V8 (#4 and #11 — **deferred a
fourth time, still open**, and the first deferral with a visible product cost: #13 inherits
no threshold), M1/M2 (#8 — **resolved**), V4/V5/V6 (#5 — settled in PRD-05). Each remaining
question is settled at the named item's PRD or plan, not before. R1–R5 and V1 are resolved
(see roadmap "Resolved Decisions" and ADR-006).
