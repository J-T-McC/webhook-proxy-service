# Task Plan: Analytics / stats — item #11

- **Status:** Approved (Task-Planner-certified)
- **Author:** Task Planner
- **Technical Plan:** `docs/plans/plan-11-analytics.md` (**fully approved** — Principal Engineer
  self-certified, and both Owner-approval flags ruled by the Project Owner, 2026-08-26: flag 1,
  the charting dependency, approved as recommended — `chart.js` ^4 plus `@j-t-mcc/vue3-chartjs`,
  both packages, local-wrapper alternative not taken; flag 2, the four-index change set, approved
  exactly as enumerated)
- **PRD:** `docs/product/prd-11-analytics.md` (Approved, Project Owner, 2026-08-26; 37 acceptance
  criteria, D-11-1..7 ratified) + **Amendment A** (Product Manager, 2026-08-26 — (i) a
  zero-denominator rate is `null`/"No deliveries yet", never `0%`; (ii) AC16's daily series and
  AC20's percentile are obliged at team and proxy grain only, not destination)
- **Design:** `docs/design/design-11-analytics.md` (**fully approved**, Product Manager,
  2026-08-26 — all six corrections C1–C6 landed, C1 cleared on the section-scoped re-check of
  Flow E and Screen 4; the approval record governs over the spec body where they differ)
- **Question:** `docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md` (**RESOLVED**,
  Principal Engineer, 2026-08-26, all ten items)
- **Approved by:** Task Planner (task-plan gate; no further Owner approval required at this
  stage — the Reviewer catches drift against the plan/PRD-11/design-11 at review time)

> **Scope / conventions.** Every task traces to plan-11 and PRD-11's ACs (AC1–AC37, Amendment A)
> or a named plan technical ruling. Sequencing follows the plan's own milestones verbatim
> (**M1–M7**), each mapped to a contiguous task range below: **M1 analytics indexes migration**
> (T1) → **M2 `AnalyticsWindow`, the DTOs, and `DeliveryStatistics` with its unit tests** (T2–T11)
> → **M3 Dashboard, Screen 1** (T12–T17) → **M4 Proxy Show, Screens 2 and 3** (T18–T20) → **M5
> Events list and drill-through, Screen 4 / Flows B–E** (T21–T24) → **M6 charting dependency and
> `TrendChart.vue`** (T25–T28) → **M7 whole-surface verification pass** (T29). **Both of plan-11's
> Owner-approval flags are ruled**, so — unlike the plan's own sequencing note, written before the
> ruling — no milestone here is gate-blocked; T1 and T25 carry their respective flag's approved
> shape forward as an Acceptance Criterion, not as an open gate. No task depends on a later task.
>
> Every task must leave `composer lint` (Pint) and `composer types:check` (PHPStan L7) green, and
> `./vendor/bin/sail test` green with its own tests included (`CLAUDE.md`,
> `docs/standards/planning.md`). Frontend tasks (T12, T14–T17, T19–T20, T23–T24, T26–T28)
> additionally require `pnpm lint:check` and `pnpm types:check` green.
>
> **There is no frontend test harness in this project** (backlog **T31** on
> `docs/tasks/walking-skeleton-tasks.md`; plan-11 R4). Every task that changes rendered UI states an
> explicit **manual verification** section with concrete steps and expected outcomes, filled into
> the Senior Developer's completion notes. **`pnpm run build` must precede every such check, with
> `public/hot` removed first** — review-07 Finding 8 is the standing trap: with `public/hot` present
> and a Vite dev server running, a "verified against a fresh build" claim is served from the dev
> server instead. This is not optional per task; it is the only way a claim of manual verification
> means anything in this project.
>
> **One new pnpm dependency pair, conditional.** `chart.js` (^4) and `@j-t-mcc/vue3-chartjs` are
> approved (Owner, both flags ruled), but **T25 commits them only after its four verification
> checks pass** — in particular check 2, the decisive one: if the wrapper pulls `chart.js/auto`,
> tree-shaking is lost and T25 reports back rather than committing (plan § Dependencies). No
> Composer package, no stack-row change (`docs/stack/stack.md` untouched either way).
>
> **Binding constraints carried through the tasks below, named once and traced to where each
> lands, per this plan's Owner rulings and Implementation Notes — none is stylistic:**
> 1. **All of M1–M7 are unblocked** (both Owner flags ruled 2026-08-26) — reflected in T1's and
>    T25's Acceptance Criteria rather than in an open gate.
> 2. **T25 runs all four § Dependencies verification checks *before* committing the charting
>    packages**, and reports back rather than committing if check 2 (tree-shaking / `chart.js/auto`)
>    fails.
> 3. **Chart construction is `onMounted`-only** (T27), so a future Inertia SSR entrypoint cannot
>    break the page (`config('inertia.ssr.enabled')` is `true` today with no entrypoint and no
>    bundle).
> 4. **Colour theming reuses the in-repo PR #12 fix** — `resources/js/components/welcome/
>    canvasKit.ts`'s `readTokens()` and `withAlpha()`'s `fillStyle` round-trip — rather than
>    pattern-matching token text (T26).
> 5. **The `updated_at` window-anchor invariant is pinned by a dedicated test (T10), not by
>    schema** — a terminal `deliveries`/`delivery_attempts` row's `updated_at` never moves again.
> 6. **`ApplyTeamScope` does not scope `Delivery` or `WebhookEvent`** (only `Proxy`, `Destination`,
>    `DeliveryAttempt`) — every analytics query states `team_id` (or a policy-gated `proxy_id`)
>    explicitly; T9 carries the cross-team-isolation test proving it (AC23, plan Technical ruling 7).
> 7. **No new capture, no new table, no new column, no new route, no export.** T1's migration is
>    exactly the four approved indexes; nothing else in this list adds to the schema, adds an
>    endpoint, or ships a download.
> 8. **No frontend test harness exists** (backlog T31) — every design-11 flow needing browser
>    verification is an explicit manual-verification step in the task it lands in, and `pnpm run
>    build` (with `public/hot` removed) precedes every one of them.
>
> **Load-bearing invariants carried through every task below (binding; plan §§ Technical rulings,
> Implementation Notes):**
> - No lock, no transaction, on any analytics read path (no `lockForUpdate()`, no `sharedLock()`,
>   no `DB::transaction()`).
> - No analytics query selects `webhook_events.body` or `headers`, and no aggregate hydrates a
>   `WebhookEvent` model.
> - No aggregate joins or eager-loads `proxies` or `destinations`; `withTrashed()` appears in
>   exactly two places (the two label lookups) and nowhere else.
> - Every query carries its own `team_id` (or a policy-gated `proxy_id`); never
>   `withoutGlobalScope(TeamScope::class)`.
> - `duration_ms` is guarded by the same `whereNotNull('duration_ms')` for the average, the count
>   and the percentile.
> - Never a blind `save()` on `deliveries` or `delivery_attempts` (already a plan-06 invariant; now
>   also what keeps history from moving).
> - No per-row query anywhere, including in a Vue loop or a resource `map` — breakdown rows come
>   from grouped queries only (R7).
> - A rate with a zero denominator is `null`, never `0`, in the DTO; counts are always integers and
>   always rendered, including `0`.
> - The daily series is densified server-side — every day in the window is a point.
> - No control, class, colour or icon anywhere is conditioned on a figure's value (AC22(b)).
> - Every success/failure/retry figure carries its unit in its own label, sourced from
>   `resources/js/data/analyticsLabels.ts`.
>
> **Scope discipline (plan §§ Overview / Explicitly out of scope) — do NOT build in this
> feature:** any export, download, CSV, scheduled report, BI integration, live refresh or polling
> (AC37); any alert, threshold, notification or emitted analytics event (#13, AC31); any
> per-event-type, per-map or payload-derived figure (AC32); any statistics-retention window, cap,
> prune or rollup (AC18); any new capture on the ingest or delivery path, including a
> `resolved_at`-style column (AC29); any change to retention, GC, holds, retry policy, replay,
> processing mode, the mode attribute, or the masked payload viewer and its reveal (AC27/AC28); any
> second events surface, event-detail view or per-received-event statistic (AC21/AC28); a
> per-destination daily series or per-destination percentile (permitted later, not built now,
> Amendment A(ii)); a worst-first default sort, a verdict colour, a badge, a reference line, or any
> evaluative wording (AC22(b), flagged design calls 5 and 6); any new permission, role or policy
> method (AC24).

---

## M1 — Analytics indexes migration

## T1 — Four-index migration (Owner-approval flag 2, approved exactly as enumerated) (plan § Data Model)
- **Description:** New migration
  `database/migrations/2026_08_26_000001_add_analytics_indexes_to_delivery_tables.php`, additive
  only, portable to MySQL 8.0 and SQLite. Adds exactly four composite indexes, column order
  grain-equality → `status` → `updated_at` range, matching Laravel's default index names:
  `delivery_attempts (team_id, status, updated_at)` →
  `delivery_attempts_team_id_status_updated_at_index`; `delivery_attempts (proxy_id, status,
  updated_at)` → `delivery_attempts_proxy_id_status_updated_at_index`; `deliveries (team_id,
  status, updated_at)` → `deliveries_team_id_status_updated_at_index`; `deliveries (proxy_id,
  status, updated_at)` → `deliveries_proxy_id_status_updated_at_index`. No table, column, enum
  value, FK, default, or existing index is added to, altered, or removed — in particular
  `delivery_attempts (proxy_id, status)` is kept even though it is now a strict prefix of the new
  proxy-grain index (a later reclaim is a separate, later decision with its own gate). `down()` is
  exactly four `dropIndex` calls.
- **Dependencies:** none
- **Files:** `database/migrations/2026_08_26_000001_add_analytics_indexes_to_delivery_tables.php`
  (new)
- **Acceptance Criteria:**
  - Migration applies cleanly (`up()`/`down()` both exercised); all four named indexes exist with
    the exact column order above, post-migration.
  - Every pre-existing index on `deliveries` and `delivery_attempts` — `UNIQUE(dispatch_uuid,
    destination_id)`, `(webhook_event_id, status)`, `(status, next_attempt_at)` on `deliveries`;
    `delivery_attempts (proxy_id, status)`, `ingest_id`, `(team_id, created_at)`,
    `UNIQUE(delivery_id, attempt_number)` on `delivery_attempts` — is still present, unchanged,
    after the migration runs.
  - `down()` removes exactly the four new indexes and nothing else; every pre-existing index
    still present post-rollback.
  - No new table, column, enum value, FK, or default appears anywhere in the diff (this
    migration's Files entry is the only schema change in the whole task list — Owner flag 2's
    "additive only" ruling, verified by inspection of the diff, not merely asserted).
- **Testing:** `tests/Unit/Migrations/AnalyticsIndexesTest.php` (new) — the four-index-presence
  assertion (exact column order, via `information_schema` on MySQL / `PRAGMA index_info` on
  SQLite, mirroring the existing index-presence pattern in `DeliveryAttemptTest`/`FifoDispatchTest`
  from plan-06's tasks), the full pre-existing-index-survival list, and the rollback round-trip
  (`artisan migrate:rollback --step=1` + `artisan migrate`, both directions clean).
- **Completion notes:** Implemented as `database/migrations/2026_08_26_000001_add_analytics_indexes_to_delivery_tables.php`
  — the four composite indexes exactly as enumerated, column order grain → `status` →
  `updated_at`, Laravel's default index names. Test suite runs against MySQL only (Sail;
  `DB_CONNECTION=mysql` in `.env`, `phpunit.xml` overrides only `DB_DATABASE`), so
  `tests/Unit/Migrations/AnalyticsIndexesTest.php` mirrors `DeliveryAttemptTest`/`FifoDispatchTest`'s
  `information_schema`-only pattern; no SQLite branch exists elsewhere in this suite to mirror.

  **Flagged deviation, root-caused and fixed rather than escalated (no data-model or public-interface
  change involved — confined to `down()`'s internal bookkeeping):** `down()` is not simply four
  `dropIndex` calls as the task description states. Verified empirically against this project's MySQL
  8.4: before this migration, `deliveries.team_id` and `deliveries.proxy_id` carried no explicit index
  of their own — only the single-column index InnoDB auto-creates to support a `constrained()` foreign
  key when no other index covers it. Adding this migration's composite indexes (leading on the same
  columns) makes those automatic single-column indexes redundant, and InnoDB silently drops them as
  part of the same `ALTER TABLE` (confirmed via `SHOW CREATE TABLE`: no separate
  `deliveries_team_id_foreign` / `deliveries_proxy_id_foreign` key survives `up()`, only the new
  composite ones). Consequently a literal `dropIndex` of the composite index in `down()` fails with
  MySQL error 1553, "needed in a foreign key constraint" — nothing else in the table would service the
  FK. Reproduced in isolation against a throwaway table before touching the real migration. Fix:
  `down()` restores an equivalent single-column index on `deliveries.team_id` and `deliveries.proxy_id`
  first, then drops the two composite indexes — this is what makes the rollback path actually work
  rather than merely read as if it does. `delivery_attempts` needed no such restoration: its
  pre-existing `(team_id, created_at)` and `(proxy_id, status)` indexes already cover both foreign
  keys independently (confirmed both survive `up()` unchanged). This does not touch the forward (`up()`)
  schema — still exactly the four approved indexes and nothing else — and does not persist beyond a
  rollback; it is the only way `down()` is reversible on this engine, and is recorded here per the
  "record deliberate simplifications" convention since it means "every pre-existing index still present
  post-rollback" is met by an index that is functionally, not byte-for-byte, identical to the one MySQL
  had auto-created (same column, different generated name).

  Also discovered during testing: DDL inside a `RefreshDatabase`-wrapped test causes MySQL to
  implicitly commit, so the migration's `up()`/`down()`/`up()` round-trip in the rollback test executes
  outside the per-test transaction sandbox and mutates the real `testing` database directly (confirmed
  by inspecting `SHOW CREATE TABLE` against the `testing` connection after a broken intermediate run).
  This is expected and self-healing as long as the test passes (it ends by reapplying `migrate`,
  restoring full schema) — noted here so a future migration-rollback test author isn't surprised by it.

  Verified: `composer lint`, `composer types:check` (PHPStan level 7), and `./vendor/bin/sail test --parallel`
  all green (762/762) after this task.

---

## M2 — `AnalyticsWindow`, the `App\Data\Analytics\*` DTOs, and `DeliveryStatistics`

## T2 — `App\Enums\AnalyticsWindow` (AC17; plan § Services & Actions, Technical ruling 8)
- **Description:** New string-backed enum with cases `TwentyFourHours = '24h'`, `SevenDays =
  '7d'`, `ThirtyDays = '30d'`; methods `label(): string`, `days(): int` (1/7/30), `interval():
  CarbonInterval`, and `default(): self` returning `ThirtyDays`. Not persisted anywhere — exists so
  an unrecognised `window` query parameter is impossible to propagate rather than merely validated
  against (ruling 8).
- **Dependencies:** none
- **Files:** `app/Enums/AnalyticsWindow.php` (new)
- **Acceptance Criteria:** exactly the three documented cases and no others; `days()` returns
  1/7/30 respectively; `default()` returns `ThirtyDays`; `AnalyticsWindow::tryFrom('garbage')` is
  `null` (the controller-side fallback, exercised in T13/T18, is `tryFrom($value) ??
  AnalyticsWindow::default()`).
- **Testing:** `tests/Unit/Enums/AnalyticsWindowTest.php` (new) — exact case-set assertion,
  `days()`/`label()`/`interval()` per case, `default()`, `tryFrom()` on a garbage string.
- **Completion notes:** Implemented as `app/Enums/AnalyticsWindow.php`, mirroring `TeamRole`'s
  `label()` pattern. Exactly the three cases (`24h`/`7d`/`30d`); `days()` returns 1/7/30;
  `interval()` returns a `CarbonInterval` of matching length; `default()` returns `ThirtyDays`.
  `tests/Unit/Enums/AnalyticsWindowTest.php` covers the case-set, `days()`/`label()`/`interval()`
  per case, `default()`, and `tryFrom('garbage') === null`. Verified: `composer lint`,
  `composer types:check`, `./vendor/bin/sail test --filter AnalyticsWindowTest` (6/6 passed).

## T3 — `App\Data\Analytics\*` DTOs (plan § API "Prop shapes")
- **Description:** Eight readonly DTOs, mirroring `App\Data\ProxyPermissions`'s style
  (constructor-promoted, no logic): `UnitFigure` (`succeeded: int, failed: int, total: int, rate:
  ?float`); `RetryReplayFigures` (`eventualSuccess: int, terminalFailure: int, retryVolume: int,
  live: int, replay: int`); `LatencyFigure` (`averageMs: ?int, p95Ms: ?int, sampleCount: int`);
  `SeriesPoint` (`date: string` ISO `Y-m-d`, plus delivery/attempt `succeeded`/`failed`/`rate`);
  `StatisticsPanel` (`window: AnalyticsWindow, delivery: UnitFigure, attempt: UnitFigure,
  bridgeFailedAttempts: int, retryReplay: RetryReplayFigures, latency: LatencyFigure, series:
  list<SeriesPoint>, hasTraffic: bool`); `ProxyBreakdownRow` (`id: int, name: string, isDeleted:
  bool, delivery: UnitFigure, attempt: UnitFigure, terminalFailures: int, canDrillThrough: bool`);
  `DestinationBreakdownRow` (`id: int, url: string, httpMethod: string, isDeleted: bool, delivery:
  UnitFigure, attempt: UnitFigure, latencyAverageMs: ?int`); `EventListFilters` (`window:
  AnalyticsWindow`, `destination: ?array{id: int, url: string, httpMethod: string, isDeleted:
  bool}`, `outcome: ?array{unit: string, label: string}`). `list<...>` PHPDoc annotations on every
  collection property so PHPStan level 7 is satisfied without suppressions.
- **Dependencies:** T2
- **Files:** `app/Data/Analytics/UnitFigure.php`, `app/Data/Analytics/RetryReplayFigures.php`,
  `app/Data/Analytics/LatencyFigure.php`, `app/Data/Analytics/SeriesPoint.php`,
  `app/Data/Analytics/StatisticsPanel.php`, `app/Data/Analytics/ProxyBreakdownRow.php`,
  `app/Data/Analytics/DestinationBreakdownRow.php`, `app/Data/Analytics/EventListFilters.php`
  (all new)
- **Acceptance Criteria:** each class is `readonly`, constructor-promoted, exactly the documented
  properties and types (`rate`, `averageMs`, `p95Ms` all nullable; every count property a plain
  `int`, never nullable); `composer types:check` (PHPStan level 7) passes with no suppression on
  any `list<...>` property.
- **Testing:** non-behavioral — plain data holders with no logic to assert beyond construction and
  typing, which PHPStan level 7 already checks statically. No dedicated test file; each DTO is
  exercised indirectly by every `DeliveryStatistics` test from T4 onward.
- **Completion notes:** _pending_

## T4 — `DeliveryStatistics`: two-unit success/failure figures, team · proxy · destination grain (AC7, AC13, AC14, Amendment A(i); plan § Architecture A/B, Technical ruling 6)
- **Description:** New `App\Services\DeliveryStatistics` service (stateless, no HTTP knowledge).
  This task builds its private core: one `GROUP BY status` aggregate over `deliveries` and one over
  `delivery_attempts`, each filtered to `status IN ('succeeded','failed')` and windowed on
  `updated_at`, at team grain (`team_id = ?`), proxy grain (`proxy_id = ?`), and destination grain
  (`proxy_id = ? AND destination_id = ?`) — never joined to each other, never joined to `proxies`
  or `destinations`. Produces `UnitFigure` pairs (delivery + attempt) per grain. `rate` is `null`
  when `total === 0` (never `0`); counts are always integers, always present, including `0`.
  Pre-#6 `delivery_attempts` rows (`delivery_id IS NULL`) are structurally excluded from the
  delivery-level query (which never reads `delivery_attempts`) and structurally included in the
  attempt-level one — no exclusion clause to forget (F4, `Q-11-03(4)`).
- **Dependencies:** T3
- **Files:** `app/Services/DeliveryStatistics.php` (new)
- **Acceptance Criteria:**
  - **The canonical 100%/67% fixture:** one delivery, three attempts (two failed, one succeeded).
    Delivery-level figure reads 100% (1 of 1); attempt-level figure reads 67% (2 of 3); at team and
    proxy grain.
  - `pending`/`retrying` deliveries are absent from delivery-level counts and not counted as
    failures; `dispatched` attempts are absent from attempt-level counts.
  - A `delivery_attempts` row with `delivery_id = NULL` appears in the attempt-level denominator
    and in no delivery-level figure.
  - A window with zero deliveries/attempts yields `rate === null` at both units; a window with
    traffic but zero of one status still yields a numeric `rate` (never `null` merely because one
    side is zero).
  - No query in this task joins `deliveries` to `delivery_attempts`, or either to `proxies`/
    `destinations`.
- **Testing:** `tests/Unit/Services/DeliveryStatisticsUnitFiguresTest.php` (new) — the canonical
  fixture at team/proxy/destination grain, the exclusion cases, the pre-#6 case, the zero-total
  `rate === null` case, a mixed-status non-zero case.
- **Completion notes:** _pending_

## T5 — `DeliveryStatistics`: retry/replay figures and the bridge count (AC9, AC19; plan § Architecture A/B)
- **Description:** Adds, at team and proxy grain: **eventual success** — count of `deliveries`
  with `status = 'succeeded'` and `EXISTS (delivery_attempts WHERE delivery_id = deliveries.id AND
  attempt_number >= 2)`; **terminal failure** — count of `deliveries.status = 'failed'`; **retry
  volume** — `SUM` of attempts with `attempt_number > 1` from the attempt-level pass (T4); **live
  vs replay** — `deliveries` split by `kind`. Also the **bridge-sentence count**: failed attempts
  belonging to the window's succeeded deliveries (`delivery_attempts` joined to the window's
  succeeded `deliveries`, the one deliberate two-table join in the service, per plan § Architecture
  A). Assembles `RetryReplayFigures`. All five values are plain counts — always integers, always
  rendered, `0` in an empty window, never replaced by "no data".
- **Dependencies:** T4
- **Files:** `app/Services/DeliveryStatistics.php`
- **Acceptance Criteria:** each of the four AC19 figures asserted independently against a fixture
  built for it; a replay fixture proves live-vs-replay never inflates or deflates the live count;
  the bridge count matches the canonical 100%/67% fixture's expectation (2 failed attempts behind
  the 1 succeeded delivery); an empty window yields all five figures as `0`, not `null` and not
  omitted.
- **Testing:** extend `tests/Unit/Services/DeliveryStatisticsUnitFiguresTest.php` (or a new
  `DeliveryStatisticsRetryReplayTest.php`) — one case per AC19 sub-criterion, the replay-fixture
  case, the bridge-count case, the empty-window all-zero case.
- **Completion notes:** _pending_

## T6 — `DeliveryStatistics`: latency — average and exact 95th percentile (AC12, AC20, Amendment A(ii); plan Technical rulings 4 and 5)
- **Description:** Adds `LatencyFigure` computation over `delivery_attempts.duration_ms`, guarded
  by `whereNotNull('duration_ms')` for the count, the average, and the percentile alike (never
  three different populations). Average and `sampleCount` at team, proxy, and destination grain.
  95th percentile at **team and proxy grain only** (Amendment A(ii)) via nearest-rank: `n` = the
  already-computed resolved-attempt count at that grain, ordinal `CEIL(0.95 × n)`, read with
  `ORDER BY duration_ms ASC LIMIT 1 OFFSET CEIL(0.95 × n) − 1`; the second query does not run when
  `n = 0`. `p95Ms` is `null` at destination grain by construction (no query issued there), and
  `null` at any grain when `sampleCount === 0`; `averageMs` likewise `null` when `sampleCount ===
  0`, never `0`.
- **Dependencies:** T4
- **Files:** `app/Services/DeliveryStatistics.php`
- **Acceptance Criteria:**
  - A fixture with known `duration_ms` values asserts the nearest-rank result exactly, including
    boundary cases `n = 1`, `n = 2`, `n = 20`.
  - Average and percentile are computed over the same population (same `whereNotNull` guard) —
    asserted by a fixture with a mix of `NULL` and non-`NULL` `duration_ms`.
  - A window with no resolved attempts yields `averageMs === null`, `p95Ms === null`, `sampleCount
    === 0`.
  - `p95Ms` is present at team and proxy grain, `null` (no query issued) at destination grain.
- **Testing:** `tests/Unit/Services/DeliveryStatisticsLatencyTest.php` (new) — the exact-percentile
  fixture at each boundary `n`, the shared-population assertion, the `n = 0` all-null case, the
  destination-grain `p95Ms === null` case.
- **Completion notes:** _pending_

## T7 — `DeliveryStatistics`: daily series, densified (AC16; plan § Windowing/Architecture C)
- **Description:** Two `GROUP BY DATE(updated_at), status` queries (delivery and attempt) at team
  and proxy grain only (Amendment A(ii)), producing raw per-day counts; a PHP pass then
  **densifies** the series to exactly one `SeriesPoint` per calendar day in the window — a day with
  no traffic is a real point with zero counts and `rate === null`, never a gap. `DATE(updated_at)`
  is computed in the application timezone (ruling 9); no per-user timezone is read or invented.
- **Dependencies:** T4
- **Files:** `app/Services/DeliveryStatistics.php`
- **Acceptance Criteria:** a window containing a day with zero traffic still returns a point for
  that day (zero counts, `rate === null`); the series length equals the window's day count exactly
  (24h → 1 point is out of scope here — series applies to 7d/30d; verify both); a sparse `GROUP BY`
  result never produces a shorter series than the window implies.
- **Testing:** `tests/Unit/Services/DeliveryStatisticsSeriesTest.php` (new) — the densification
  case (a fixture with traffic on some days only), the series-length assertion for 7d and 30d, an
  all-empty-window series (every point zero/`null`).
- **Completion notes:** _pending_

## T8 — `DeliveryStatistics`: `proxyBreakdown()` / `destinationBreakdown()`, deleted-parent labelling (AC6, AC15; plan § Architecture A, Technical ruling / Q-11-03(2); R7)
- **Description:** The two whole-set breakdown methods, per plan § Services & Actions:
  `proxyBreakdown(int $teamId, AnalyticsWindow $window): list<ProxyBreakdownRow>` and
  `destinationBreakdown(Proxy $proxy, AnalyticsWindow $window): list<DestinationBreakdownRow>`.
  Each runs two grouped aggregates (`GROUP BY proxy_id, status` / `GROUP BY destination_id,
  status`) plus **one** label-resolution query with `withTrashed()` over exactly the id set the
  aggregate returned — the second of the feature's exactly-two `withTrashed()` call sites (T4's
  breakdown-adjacent label lookup being the other, if not already folded here; state which). A
  `deleted_at` row renders the **Deleted** label. `canDrillThrough` on `ProxyBreakdownRow` is
  `false` iff the proxy is soft-deleted, `true` otherwise — a fact about the route
  (`Q-11-03(9)`), not a permission. `destinationBreakdown`'s row set is the **union** of the
  proxy's live destinations and every `destination_id` with activity in the window. **No method
  computing a single row exists** — no `forDestination(...)` to call in a loop (R7).
- **Dependencies:** T4, T6
- **Files:** `app/Services/DeliveryStatistics.php`
- **Acceptance Criteria:**
  - **The AC6 test:** compute every figure; soft-delete a proxy and a destination that both have
    activity in the window; recompute; numbers identical, both rows present, both flagged
    `isDeleted === true`.
  - `proxyBreakdown` for N proxies runs a fixed, small number of queries regardless of N (assert
    the exact count via `DB::listen`/`assertQueryCount` — no growth with proxy count).
    `destinationBreakdown` likewise fixed regardless of destination count.
  - A deleted proxy's row has `canDrillThrough === false`; a live proxy's has `true`.
  - `destinationBreakdown`'s row set includes a live destination with zero traffic (present, zero
    figures) and a deleted destination with traffic (present, `isDeleted === true`), and excludes
    neither.
  - No query in either method eager-loads or joins `proxies` or `destinations` directly — grouping
    is by the `proxy_id`/`destination_id` columns on the fact rows.
- **Testing:** `tests/Unit/Services/DeliveryStatisticsBreakdownTest.php` (new) — the AC6 case, the
  query-count assertion for both methods at N = 1/3/10 proxies or destinations, the
  `canDrillThrough` true/false case, the destination union case (live-no-traffic +
  deleted-with-traffic).
- **Completion notes:** _pending_

## T9 — `DeliveryStatistics`: `forTeam()` / `forProxy()` window assembly, explicit `team_id` scoping, mode independence (AC23, AC25, AC26; plan Technical ruling 7)
- **Description:** The two public panel methods — `forTeam(int $teamId, AnalyticsWindow $window):
  StatisticsPanel` and `forProxy(Proxy $proxy, AnalyticsWindow $window): StatisticsPanel` —
  assembling T4–T7's pieces into one `StatisticsPanel` per grain, with `hasTraffic` set from the
  delivery+attempt totals. Every query issued by every method built in T4–T8 is audited here to
  confirm it states `team_id` (or a policy-gated `proxy_id`) explicitly and **never** relies on
  `ApplyTeamScope` (which does not scope `Delivery` or `WebhookEvent`) and never calls
  `withoutGlobalScope(TeamScope::class)`.
- **Dependencies:** T4, T5, T6, T7, T8
- **Files:** `app/Services/DeliveryStatistics.php`
- **Acceptance Criteria:**
  - **Cross-team isolation, asserted per surface method, not once:** a second team's identical
    traffic contributes nothing to `forTeam`, `forProxy`, `proxyBreakdown`, or
    `destinationBreakdown`'s figures.
  - A team-less scenario (no current team) is not exercised by the service directly (it requires a
    resolved `teamId`/`Proxy`) — instead assert the controller-level guard exists (cross-referenced
    in T13/T18); note this explicitly in completion notes rather than asserting it here.
  - **A Simple proxy's retry figures are counted, not gated out** (AC25) — a Simple-mode fixture's
    `RetryReplayFigures` are non-zero exactly as an Enhanced one's would be.
  - **FIFO and Async proxies produce figures through the same path** (AC26) — no prop, branch, or
    class in `DeliveryStatistics` reads `processing_mode` or `mode` at all (asserted by absence:
    grep the class for either column name in a test or via static reflection, and by a
    FIFO-vs-Async fixture producing figures through identical code paths).
- **Testing:** `tests/Unit/Services/DeliveryStatisticsScopingTest.php` (new) — the cross-team
  isolation case per method (four assertions), the Simple-mode retry-figures-counted case, the
  FIFO/Async identical-path case (and the `mode`/`processing_mode`-absence check).
- **Completion notes:** _pending_

## T10 — Anchor invariant: a terminal row's `updated_at` never moves again (R2; binding constraint 5)
- **Description:** A dedicated, required test — not folded into another task — pinning the
  invariant the entire windowing/anchor design rests on (plan Technical ruling 1, § Risks R2): once
  a `deliveries` or `delivery_attempts` row reaches a terminal/resolved status, no code path may
  write to it again, so its `updated_at` (the anchor) is frozen. No production code changes here;
  this task only proves the invariant holds today by construction.
- **Dependencies:** T4
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - A terminally-`succeeded`/`failed` `Delivery`'s `updated_at` is unchanged after a re-driven
    settle attempt through the existing delivery path (e.g. re-invoking the settle logic that
    would have transitioned it, proving the CAS keyed on a non-terminal prior status is a no-op).
  - A resolved `DeliveryAttempt`'s `updated_at` is unchanged after the redelivery path
    (`DeliverToDestination::resume()`) is invoked against it.
  - Both a terminal `Delivery` row and a resolved `DeliveryAttempt` row are unchanged after a
    `PurgeExpiredPayloads` run over the events they belong to (ADR-012 Decision 5: GC reads, never
    writes, either table).
- **Testing:** `tests/Feature/Analytics/AnchorInvariantTest.php` (new) — the three cases above,
  each asserting `updated_at` byte-identical (not merely "not much different") before and after.
- **Completion notes:** _pending_

## T11 — Separation and lifecycle: AC1, AC2, AC3, AC4, AC5 (plan § Test strategy "Separation and lifecycle")
- **Description:** The integration-level tests proving #11's central premise — statistics survive
  and are unaffected by payload expiry, and #11 writes nothing. No production code changes; this
  task is pure test coverage exercising the finished T2–T9 service end to end.
- **Dependencies:** T9
- **Files:** none production; test-only
- **Acceptance Criteria:**
  - **AC2 — the single most important test in the feature.** Compute every figure at every grain
    for a fixture with received events; run `PurgeExpiredPayloads` over those events; recompute;
    assert every figure **numerically identical**.
  - **AC3** — a proxy whose events are all cleaned produces the same figures as one whose events
    are retained, and still resolves to its destinations in the breakdown.
  - **AC5** — snapshot row counts and `updated_at` values across `deliveries`, `delivery_attempts`,
    `webhook_events`, `dispatched_payloads`, and `fifo_dispatches`; call every public
    `DeliveryStatistics` method; assert nothing changed.
  - **AC1/AC4** — no query built by `DeliveryStatistics` selects `webhook_events.body` or
    `headers`, and no aggregate hydrates a `WebhookEvent` model (assert via `DB::listen` column
    inspection or reflection over the built SQL, not merely by code review).
- **Testing:** `tests/Feature/Analytics/AnalyticsSeparationTest.php` (new) — one test method per
  bullet above.
- **Completion notes:** _pending_

---

## M3 — Dashboard (Screen 1)

## T12 — `resources/js/data/analyticsLabels.ts`: single source of every figure label and unit (R6, correction C4; plan Implementation Note 13)
- **Description:** New data-const module, the pattern's design-11-endorsed home (§ Components
  "Data-const recommendation"), consumed by both the Dashboard (T14–T17) and Proxy Show (T19–T20)
  so wording cannot drift between the two homes. Exports the exact unit-bearing label strings
  correction C4 requires: `"Delivery success"`, `"Attempt success — destination health"`,
  `"Terminal failures (deliveries)"`, `"Eventual success (deliveries)"`, `"Terminal failure
  (deliveries)"`, `"Retry volume (attempts)"`, `"Live vs replay (deliveries)"`, plus the "Average"/
  "95th percentile" latency labels and the "Excludes time spent waiting in the queue." caption, as
  a typed object or set of named constants — no free-standing string literal for any of these
  labels may appear in a `.vue` file from T14 onward.
- **Dependencies:** none
- **Files:** `resources/js/data/analyticsLabels.ts` (new)
- **Acceptance Criteria:** every C4-required label listed above is exported as a named constant;
  `pnpm types:check` passes; nothing in this file is a component (pure data).
- **Testing:** non-behavioral — a data-const module with no logic; no frontend test harness exists
  (backlog T31). Correctness is verified by every consuming task's manual-verification step reading
  the rendered label text against this file's values.
- **Completion notes:** _pending_

## T13 — `DashboardController`: analytics props (AC7, AC8, AC17, AC23; plan §§ API, Validation, R7)
- **Description:** `DashboardController::__invoke` gains, alongside the existing
  `pendingInvitations` prop: `window` resolution via `AnalyticsWindow::tryFrom($request->query
  ('window')) ?? AnalyticsWindow::default()` (ruling 8 — never a 422); `statistics` =
  `DeliveryStatistics::forTeam($team->id, $window)`; `proxies` = `DeliveryStatistics::
  proxyBreakdown($team->id, $window)`. Team-level figures aggregate over the current team, gated by
  the existing `EnsureTeamMembership`/`ApplyTeamScope` middleware already on this route — no new
  gate, no new permission (AC24).
- **Dependencies:** T9 (statistics), T8 (breakdown)
- **Files:** `app/Http/Controllers/DashboardController.php`
- **Acceptance Criteria:**
  - Absent/malformed `?window=` resolves to the 30-day default and a 200, not a 422.
  - A member of another team sees none of this team's records in either prop.
  - **Query-count assertion (R7):** the number of queries issued does not grow with the number of
    proxies on the team (assert at N = 1 and N = 10 proxies, same query count).
  - A team with no proxies at all renders with `proxies` empty and issues no per-proxy aggregate
    query (the "no proxies at all" state's backing data).
  - The three existing Dashboard-related tests (`tests/Feature/DashboardTest.php`) stay green
    unmodified — `pendingInvitations` behaviour is untouched.
- **Testing:** extend `tests/Feature/DashboardTest.php` or add `tests/Feature/Analytics/
  DashboardControllerTest.php` (new) — the window-fallback case, the cross-team isolation case, the
  query-count assertion, the no-proxies case.
- **Completion notes:** _pending_

## T14 — `Dashboard.vue`: two-tier headline card + bridge sentence + window selector (AC7, AC13, AC14, AC17, C1/AC22 flagged call 1; plan § Read surfaces D)
- **Description:** Removes the first `PlaceholderPattern` block. Adds the page-level `WindowSelector`
  (3-button group, `aria-current="true"` on the active one, full-page `router.get` navigation per
  design-11 § Interactions) and the "Deliveries" card: `dl`/`dt`/`dd` two-tier headline — large
  delivery-success figure with its "X of Y delivered · last {window}" caption, smaller attempt-
  success figure beneath it with its own caption, and the bridge sentence (descriptive only, never
  arithmetic per AC14(d)) — using `analyticsLabels.ts` (T12) for every label. Zero-deliveries state:
  both rates read "No deliveries yet" (Amendment A(i)), counts still read `0 of 0 delivered ·
  last {window}`, bridge sentence omitted.
- **Dependencies:** T12, T13
- **Files:** `resources/js/pages/Dashboard.vue`
- **Acceptance Criteria:** headline renders both units always, never a toggle/tab/dropdown between
  them (AC14(c)); the attempt-level figure is never collapsible or behind a "show more"; bridge
  sentence renders only when there is something to bridge; zero-traffic state matches the rule
  above exactly; window selector switches window via full navigation, carrying `?window=` in the
  URL.
- **Testing:** no frontend test harness (backlog T31). **Manual verification required** —
  documented in completion notes: `pnpm run build` (with `public/hot` removed), then visually
  confirm the two-tier headline, the bridge sentence, the zero-traffic "No deliveries yet" state
  (seed a team with a proxy and no deliveries), and window-selector navigation across `24h`/`7d`/
  `30d`, in both light and dark theme.
- **Completion notes:** _pending_

## T15 — `Dashboard.vue`: Proxies breakdown table (AC6, AC15, correction C2/C4/C5; plan flagged design call 5)
- **Description:** "Proxies" card: `Table` listing `props.proxies` (T13's `ProxyBreakdownRow[]`) —
  Proxy | Delivery success | Attempt success | Terminal failures (deliveries) | View, one row per
  readable proxy. Card subtitle states "Last {window}" (C2). Default sort alphabetical by name
  (never worst-first, flagged call 5); client-side sortable by column click, `aria-sort` on each
  sortable `TableHead`, keyboard-operable (`Enter`/`Space`), ascending/descending toggle, resets to
  default on reload. A deleted proxy's row renders with a muted **Deleted** badge, historical
  figures intact, no management link — `canDrillThrough === false` disables the row's failure-cell
  link (wired fully in T23) but the row itself still renders and sorts.
- **Dependencies:** T12, T13
- **Files:** `resources/js/pages/Dashboard.vue`
- **Acceptance Criteria:** one row per proxy in `props.proxies`, including deleted ones, labelled
  **Deleted**; default order alphabetical, not performance-ranked; column-click sorting works both
  directions with correct `aria-sort`; zero-traffic row shows "No deliveries yet" on the two rate
  columns and `0` on Terminal failures (C3 — counts always render as `0`); every label carries its
  unit (C4).
- **Testing:** manual verification (no harness) — `pnpm run build`, confirm sort toggling, the
  deleted-proxy row, and the zero-traffic row treatment, both themes.
- **Completion notes:** _pending_

## T16 — `Dashboard.vue`: Retry & replay tiles + Latency block (AC12, AC19, AC20, correction C3/C4; plan Technical ruling 5)
- **Description:** "Retry & replay" card: four stat tiles — Eventual success (deliveries), Terminal
  failure (deliveries), Retry volume (attempts), Live vs replay (deliveries: "42 live · 3 replay",
  two labelled numbers, never colour-only) — from `props.statistics.retryReplay`, always rendered,
  reading `0` in an empty window (never hidden, never replaced by a message — C3). "Latency" card:
  average and 95th-percentile `dt`/`dd` pairs from `props.statistics.latency`, each independently
  "No data" when `sampleCount === 0` (AC12, AC20), plus the fixed caption "Excludes time spent
  waiting in the queue." Both cards carry a "Last {window}" subtitle (C2).
- **Dependencies:** T12, T13
- **Files:** `resources/js/pages/Dashboard.vue`
- **Acceptance Criteria:** all four tiles render in every state, including all-zero; latency reads
  "No data" independently per field, never `0 ms`; no tile, colour, or icon is conditioned on the
  figure's value (AC22(b)); every tile label carries its unit per `analyticsLabels.ts`.
- **Testing:** manual verification (no harness) — `pnpm run build`, confirm the all-zero Retry &
  replay state and the "No data" latency state, both themes.
- **Completion notes:** _pending_

## T17 — `Dashboard.vue`: Trend accessible table, window selector wiring, empty states, `PlaceholderPattern` removal (AC12, AC16, § Accessibility; plan M3 note "table before chart")
- **Description:** "Trend" card, built **without** a chart (T27/M6 adds the canvas beside this):
  the `Collapsible`/`CollapsibleTrigger`/`CollapsibleContent` "View as table" data table, rendered
  **visible by default** at this stage (since it is the only representation until M6 lands),
  showing `props.statistics.series` — one row per day, both units. Completes the zero-proxies-at-
  all state (single centered `Card`, no window selector, no headline/table/chart shells) and the
  zero-deliveries-in-window trend treatment ("No data for this period" — rates, not a zeroed
  chart). Removes the remaining `PlaceholderPattern` usages and the now-unused import.
- **Dependencies:** T13, T15, T16
- **Files:** `resources/js/pages/Dashboard.vue`
- **Acceptance Criteria:** no `PlaceholderPattern` import or usage remains anywhere in
  `Dashboard.vue`; the series table renders one row per day across the window, densified (a
  no-traffic day is a real row with `0`/`0`/"No deliveries yet"); the no-proxies-at-all state shows
  a single card with no window selector and no other card shells; `pnpm lint:check` and `pnpm
  types:check` pass.
- **Testing:** manual verification (no harness) — `pnpm run build`, confirm the densified series
  table (seed a window with a gap day), the no-proxies-at-all state, and the zero-deliveries trend
  message, both themes.
- **Completion notes:** _pending_

---

## M4 — Proxy Show (Screens 2 and 3)

## T18 — `ProxyController::show`: analytics props (AC7, AC15, AC17, AC23; plan §§ API, Validation, R7; flagged design call 3's carried-window)
- **Description:** `ProxyController::show` gains `window` resolution identical to T13's
  (`AnalyticsWindow::tryFrom($request->query('window')) ?? AnalyticsWindow::default()`, carried
  from a Dashboard drill-through link per design-11 § Interactions), `statistics` =
  `DeliveryStatistics::forProxy($proxy, $window)`, and `destinations` =
  `DeliveryStatistics::destinationBreakdown($proxy, $window)`. Authorization unchanged
  (`$this->authorize('view', $proxy)`, existing `ProxyPolicy::view`) — no new gate.
- **Dependencies:** T9, T8
- **Files:** `app/Http/Controllers/ProxyController.php`
- **Acceptance Criteria:**
  - Absent/malformed `?window=` resolves to the 30-day default, 200 not 422.
  - A member without `TeamPermission::ViewProxy` is denied by the existing policy (no new
    permission exists — assert `TeamPermission::cases()` unchanged by this task's diff).
  - **Query-count assertion (R7):** number of queries does not grow with the number of
    destinations on the proxy (N = 1 vs. N = 10, same count).
  - Existing `proxies/Show` tests (permissions payload, existing `proxy` prop shape) stay green
    unmodified beyond the two new props.
- **Testing:** extend the existing Proxy Show controller test file (or add
  `tests/Feature/Analytics/ProxyShowControllerTest.php`, new) — the window-fallback case, the
  permission-denial case, the query-count assertion.
- **Completion notes:** _pending_

## T19 — `proxies/Show.vue`: Analytics card (AC7, AC12, AC13, AC19, AC20; correction C2/C3/C4/C5; flagged design call 3)
- **Description:** New "Analytics" card inserted **immediately after the header block, before
  Ingest URL** (design-11 Screen 2; the reordering flagged design call the Product Manager
  accepted). Identical shape to Dashboard's headline + bridge sentence (T14), Retry & replay tiles
  (T16), and Latency block (T16) — scoped to this proxy, sourced from `props.statistics`, using
  `analyticsLabels.ts` (T12) for every label so wording matches the Dashboard byte-for-byte.
  Zero-traffic-for-this-proxy state: the entire card collapses to one message ("No deliveries to
  this proxy in the last {window}. Figures appear once it receives and delivers a webhook.") — no
  chart shell, no zeroed tiles, no latency block.
- **Dependencies:** T12, T18
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:** card sits between the header and the existing "Ingest URL" card, ahead
  of it; every label matches `analyticsLabels.ts` exactly (no drift from the Dashboard's wording);
  zero-traffic collapse renders the single message and nothing else from this card; window selector
  present and functional for this page.
- **Testing:** manual verification (no harness) — `pnpm run build`, confirm card position, the
  collapsed zero-traffic state (seed a proxy with no deliveries), label parity against the
  Dashboard, both themes.
- **Completion notes:** _pending_

## T20 — `proxies/Show.vue`: Destinations table extended (AC6, AC15; plan Implementation Note 11)
- **Description:** The existing `Destinations` card (currently a plain `ul`) becomes a `Table`:
  Destination | Delivery success | Attempt success | Latency (avg) | Actions, one row per
  destination in `props.destinations` (T18's `DestinationBreakdownRow[]` — **not**
  `ProxyResource.destinations`, per plan Implementation Note 11: that relation is live-only and
  shared with `index()`/`edit()`). A live destination's Actions cell shows **View events** (links
  into the Events list, filtered to this destination and the current window, wired fully in T23); a
  deleted destination shows a muted **Deleted** label plus the same still-functional **View
  events** link, no edit/manage action ever. Order unchanged (creation order, not re-sorted —
  flagged design call 5's reasoning applied here too). Zero-traffic-for-a-destination row reads
  "No deliveries yet" / "—" on its Delivery/Attempt columns, not `0%`; row is not hidden.
- **Dependencies:** T12, T18
- **Files:** `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:** table is driven from `props.destinations`, never from
  `props.proxy.destinations`; a deleted destination with historical traffic appears as a row,
  labelled **Deleted**, with its figures intact; a live destination with no traffic appears with
  "No deliveries yet" / "—", not hidden and not `0%`; no edit/manage action appears on any row;
  column headers carry the unit (AC14(a) via table-header association, no per-cell relabelling
  needed).
- **Testing:** manual verification (no harness) — `pnpm run build`, confirm the deleted-destination
  row, the zero-traffic-destination row, and that the table reads from the analytics prop (seed a
  destination not in `ProxyResource.destinations`' live set but with window activity), both themes.
- **Completion notes:** _pending_

---

## M5 — Events list and drill-through (Screen 4 / Flows B–E)

## T21 — `ProxyEventController::index`: filter resolver, outcome subqueries, `withQueryString` (AC10, AC21; plan §§ Architecture E, Technical rulings 3 and 8, Validation)
- **Description:** A private filter resolver turning up to three query parameters into (a) query
  predicates and (b) `EventListFilters` chip descriptors, from one place, so the chips and the
  query can never disagree. **`window`** — `AnalyticsWindow::tryFrom(...) ?? default()`, always
  resolves. **`destination`** — `Destination::withTrashed()->where('proxy_id',
  $proxy->id)->find($id)`; unresolved ⇒ no filter, no chip. **`outcome`** — matched against
  `delivery_failed`/`attempt_failed`; anything else ⇒ no filter, no chip. Query narrowing: **no
  outcome** — window applies to `webhook_events.received_at`, destination (if present) to the
  existing proxy↔destination relationship. **Outcome active** — window moves inside the subquery
  onto the figure's own `updated_at` predicate (ruling 3): delivery-grain `webhook_events.id IN
  (SELECT webhook_event_id FROM deliveries WHERE proxy_id = ? AND status = 'failed' AND updated_at
  BETWEEN ?)` reading the new `deliveries (proxy_id, status, updated_at)` index; attempt-grain
  `webhook_events.ingest_id IN (SELECT ingest_id FROM delivery_attempts WHERE proxy_id = ? AND
  status = 'failed' AND updated_at BETWEEN ?)` reading `delivery_attempts (proxy_id, status,
  updated_at)`. The paginator carries `->withQueryString()` so page 2 does not silently drop an
  active filter (Implementation Note 10). Unresolved `destination`/`outcome` values drop the filter
  and render no chip, still 200 (ruling 8). Unfiltered requests render byte-identical to today
  (AC28).
- **Dependencies:** T1 (indexes), T2 (`AnalyticsWindow`)
- **Files:** `app/Http/Controllers/ProxyEventController.php`
- **Acceptance Criteria:**
  - Delivery-grain outcome filter returns exactly the events containing at least one delivery
    matching the predicate; attempt-grain returns exactly the events containing at least one
    matching attempt, **including** an event whose overall delivery succeeded on a later attempt,
    and **including** a pre-#6 attempt row (`delivery_id = NULL`).
  - With an outcome filter active, a delivery terminalized today from an event received outside the
    window **is** returned (window travels on `updated_at`, not `received_at`).
  - Filters survive pagination (`withQueryString` asserted via the rendered `links`).
  - An unknown `destination` id or `outcome` token drops the filter, renders no matching chip
    descriptor, returns 200 (never 422).
  - The Events list without any filter parameter renders byte-identical props to today's shipped
    surface (AC28) — no column, badge, action, or pagination change.
- **Testing:** `tests/Feature/Analytics/ProxyEventDrillThroughTest.php` (new) — one case per
  bullet above, plus the two subquery shapes independently (delivery-grain, attempt-grain).
- **Completion notes:** _pending_

## T22 — Deleted-parent drill-through: both halves (AC6; plan § Architecture E, `Q-11-03(9)`)
- **Description:** No new production code beyond what T8 (`canDrillThrough`) and T21 (destination
  resolution via `withTrashed()`) already built — this task is the acceptance coverage proving the
  split ruling holds end to end: a **deleted destination**'s drill-through stays live because the
  destination travels as a query filter on a live proxy's route and soft delete preserves the id; a
  **deleted proxy** takes the pre-approved degradation — its Dashboard row keeps its figures and
  **Deleted** label but its links are muted (`canDrillThrough === false`), and the events route for
  a trashed proxy still 404s (making the proxy route resolve a trashed model would surface the
  shipped Replay affordance against a deleted proxy).
- **Dependencies:** T8, T21
- **Files:** none production; test-only
- **Acceptance Criteria:** a deleted destination's `View events` link resolves and filters
  correctly through `Destination::withTrashed()` + the `proxy_id` predicate; a deleted proxy's
  breakdown row (from T8) carries `canDrillThrough === false`; the events route
  (`proxies.events.index`) for a soft-deleted proxy id still returns 404 — the degradation is real,
  not cosmetic.
- **Testing:** `tests/Feature/Analytics/DeletedParentDrillThroughTest.php` (new) — the three
  bullets above as three test methods.
- **Completion notes:** _pending_

## T23 — Wire drill-through links: Dashboard and Show entry points (AC10, AC21; design-11 Flow E entry-point table)
- **Description:** Wires the failure-shaped and total-shaped entry points design-11's Flow E table
  fixes, each carrying exactly the filters named: Dashboard Proxies table's **Terminal failures
  (deliveries)** cell → proxy · window · outcome=delivery_failed (T15); Dashboard Proxies table's
  Delivery/Attempt success cells → **not links** (already correct from T15, confirmed here); Proxy
  Show Retry & replay's **Terminal failure** tile → proxy · window · outcome=delivery_failed (T16
  on the Show page, T19); the other three Retry & replay tiles → **not links**; Trend chart's "View
  as table" row, per day per unit → proxy · window narrowed to that single day ·
  outcome=delivery_failed or attempt_failed at the clicked cell's unit (T17/T27's accessible
  table); Destinations table's **View events** action → proxy · destination · window, **no**
  outcome filter (T20).
- **Dependencies:** T15, T16, T17, T19, T20, T21
- **Files:** `resources/js/pages/Dashboard.vue`, `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:** every failure-shaped entry point link's query string matches the table
  above exactly; every non-failure-shaped figure (rate cells, the three non-terminal tiles) has no
  link; the Destinations row's `View events` link carries no `outcome` parameter; a deleted proxy's
  Terminal failures cell is not a link (`canDrillThrough === false`, T22).
- **Testing:** manual verification (no harness) — `pnpm run build`, click through each entry point
  and confirm the landed Events list's active filter chips match the table above, both themes.
- **Completion notes:** _pending_

## T24 — `proxies/events/Index.vue`: filter chips, explanatory copy, empty-filtered state (AC10, AC21; correction/C1 landed, § Accessibility)
- **Description:** Above the existing (unchanged) table: up to three removable `FilterChips` —
  window, destination, and the new **outcome** chip ("Outcome: Terminal failure (deliveries)" /
  "Outcome: Terminal failure (attempts)"), same `Badge` + labelled remove-button composition as the
  existing two, each removal a re-navigation (not client-side filtering) dropping that query
  parameter. When an Outcome chip is active, render the explanatory line: "Showing events with at
  least one matching delivery — one event can hold more than one, so this list's row count won't
  match the figure's count exactly" (adjusted per attempt-grain wording, C1(b)). Empty-filtered
  state: existing "No events yet" card, copy adjusted to "No events match these filters" when at
  least one chip is active, plus a **Clear filters** link; chips remain visible so the member can
  clear them. Arrived-unfiltered: no chip row renders, visually identical to today.
- **Dependencies:** T21
- **Files:** `resources/js/pages/proxies/events/Index.vue`
- **Acceptance Criteria:** an outcome chip renders with the correct label for its unit, removable
  via a keyboard-operable, `aria-label`-carrying control (never a bare icon-only `×`); the
  explanatory line renders only while an Outcome chip is active; the empty-filtered state shows
  adjusted copy plus **Clear filters**, chips still visible; no filter present renders identically
  to the pre-#11 shipped surface.
- **Testing:** manual verification (no harness) — `pnpm run build`, arrive via each T23 entry
  point, confirm chip rendering/removal/labels, the explanatory copy, and the empty-filtered state
  (filter to a combination with zero matches), both themes.
- **Completion notes:** _pending_

---

## M6 — Charting dependency and `TrendChart.vue`

## T25 — Adopt the charting dependency: four verification checks BEFORE committing the packages (Owner-approval flag 1, approved as recommended; plan § Dependencies; binding constraint 2)
- **Description:** Runs the four checks plan-11 § Dependencies names, **in order, before running
  `pnpm add`** — this task reports back rather than committing if check 2 fails, per the Owner
  ruling's own condition. **(1) Resolution** — `pnpm add chart.js @j-t-mcc/vue3-chartjs` resolves
  `chart.js` at `^4` and satisfies the wrapper's Vue 3 peer against 3.5.40, no peer warning, no
  `--force`. **(2) Registration and tree-shaking — decisive.** Confirm the wrapper does **not**
  import `chart.js/auto` internally (inspect its source/`package.json` `exports`); register only
  `LineController`, `LineElement`, `PointElement`, `LinearScale`, `CategoryScale`, `Tooltip`,
  `Legend` via `Chart.register(...)`. **If the wrapper pulls `chart.js/auto`, stop here — do not
  commit the packages — and report back per the plan's Option 2 (`chart.js` alone, a local ~40-line
  wrapper), which is a reasonable ruling requiring no plan change beyond a package name.** **(3)
  Bundle impact, measured not estimated** — record the gzip delta of `pnpm build` before and after
  in this task's completion note. **(4) Theming in both themes** — series colours resolved per
  T26's rule, verified against a production build with `public/hot` removed, in light and dark,
  non-text contrast checked per `design-11` § Accessibility. Only after all four pass (or check 2's
  fallback is exercised) is `package.json` committed.
- **Dependencies:** none
- **Files:** `package.json`, `pnpm-lock.yaml`
- **Acceptance Criteria:**
  - All four checks are run and their outcomes recorded in the completion note, in order, before
    `package.json` is committed.
  - If check 2 passes: `chart.js` ^4 and `@j-t-mcc/vue3-chartjs` are both present in
    `dependencies`, no `--force` was used, and the gzip delta from check 3 is recorded.
  - If check 2 fails: **no package is committed**, the completion note states the finding plainly,
    and the task hands off to the Reviewer/Owner for the Option 2 ruling rather than proceeding
    silently on either path.
  - `pnpm lint:check`, `pnpm types:check`, and `pnpm run build` all still pass after the dependency
    change.
- **Testing:** non-behavioral — a dependency-adoption task. No new test file; check 2's finding is
  itself the verification artifact (recorded in the completion note), and check 4 is a manual
  visual verification against a production build (no frontend test harness, backlog T31).
- **Completion notes:** _pending_

## T26 — `resources/js/lib/chartTokens.ts`: colour resolution reusing the PR #12 fix (R8; binding constraint 4)
- **Description:** New module resolving a series colour by reading the token verbatim
  (`getComputedStyle(document.documentElement).getPropertyValue('--chart-1')` /
  `'--chart-2'`) and then **normalising it through the browser** — assigning it to a 2D canvas
  context's `fillStyle` and reading the value back — rather than pattern-matching the token text.
  This is the exact technique already proven in `resources/js/components/welcome/canvasKit.ts`
  (`readTokens()` plus the normaliser behind `withAlpha()`), extracted or duplicated here per the
  plan's stated preference for either (a shared `lib/` normaliser is preferable if convenient, a
  local copy is acceptable). Exposes a function re-resolving both series colours, called on init
  and again whenever the theme changes (`useAppearance`), so a chart does not cache a stale palette
  across a light/dark toggle.
- **Dependencies:** none
- **Files:** `resources/js/lib/chartTokens.ts` (new); optionally
  `resources/js/components/welcome/canvasKit.ts` (only if the normaliser is extracted to be
  shared — no behaviour change to the welcome illustrations either way)
- **Acceptance Criteria:** colour resolution never pattern-matches `hsl(...)` or any token-text
  format — resolution is via the canvas `fillStyle` round-trip only; resolving `--chart-1`/
  `--chart-2` against a **production build** (minified CSS) returns the same usable colour as
  against the dev server; a theme toggle after chart mount produces a re-resolved, correct colour
  on the next render (no stale palette).
- **Testing:** non-behavioral in isolation — no frontend test harness (backlog T31). Verified via
  T27/T28's manual verification step, which specifically checks a production build in both themes
  and a live theme toggle.
- **Completion notes:** _pending_

## T27 — `TrendChart.vue`: two-series line chart, `onMounted`-only construction, accessible canvas (AC16, § Accessibility; binding constraint 3; plan Implementation Notes 14–15)
- **Description:** New component wrapping `@j-t-mcc/vue3-chartjs` (or a local wrapper, if T25
  landed Option 2). Two-series line chart — delivery success solid, attempt success dashed (line
  style, not colour alone, distinguishes them) — using `chartTokens.ts` (T26) for series colour,
  fed `props.series` (the same `SeriesPoint[]` T17's accessible table already renders). **Creates
  its `Chart` instance in `onMounted` only and destroys it on `onUnmounted`** — nothing chart-
  related runs at module scope or during render (binding constraint 3), so the component stays
  renderable if an Inertia SSR entrypoint is ever added. The canvas is `aria-hidden="true"` with a
  short `aria-label` summary on the surrounding figure (e.g. "Daily delivery and attempt success
  rate, last 30 days — see table below for exact values"); it carries no click target — the
  accessible table beside it is the authoritative representation and the only interactive surface
  (design-11 Flow C step 3). Re-resolves colours via T26 on theme change; `update()`s on prop
  change rather than tearing down and rebuilding.
- **Dependencies:** T25, T26
- **Files:** `resources/js/components/TrendChart.vue` (new)
- **Acceptance Criteria:** `Chart` construction happens exclusively inside `onMounted`; the
  instance is destroyed in `onUnmounted`; the canvas has `aria-hidden="true"`, no `tabindex`, no
  click handler; the two lines are visually distinguished by both colour and dash style; colours
  are correct in both themes against a production build (T26); switching theme after mount updates
  the rendered colours without a full remount; `pnpm lint:check`/`pnpm types:check` pass.
- **Testing:** no frontend test harness (backlog T31). **Manual verification required**: `pnpm run
  build` with `public/hot` removed, mount the chart on the Dashboard/Show trend cards, confirm
  onMounted-only construction (no console error on a cold load), unmount-cleanup (navigate away and
  back with no duplicate canvas/leak), both series' colour and dash distinguishable in light and
  dark, `aria-hidden` present via devtools inspection.
- **Completion notes:** _pending_

## T28 — Wire `TrendChart.vue` into Dashboard and Show, beside the existing accessible table (AC16, § Accessibility)
- **Description:** Both trend cards (Dashboard's "Trend" card, T17; Proxy Show's Analytics card
  trend block, T19) render `TrendChart.vue` **above** the already-shipped "View as table"
  `Collapsible`, both fed the same `props.statistics.series` — the chart is the supplement, the
  table remains the authoritative representation per § Accessibility, and it stays present and
  functional exactly as T17/T19 left it. No control on the chart canvas narrows or filters
  anything; the per-day "View as table" row remains the only click target into Flow E (already
  wired in T23).
- **Dependencies:** T27, T17, T19, T23
- **Files:** `resources/js/pages/Dashboard.vue`, `resources/js/pages/proxies/Show.vue`
- **Acceptance Criteria:** both trend cards render the chart and the table together, chart never
  replacing or hiding the table; the table's per-day drill-through links (T23) are unaffected by
  the chart's presence; zero-traffic/no-data states from T17/T19 still render correctly with the
  chart present (no chart shell when there is nothing to plot — the "No data for this period"
  message stands in for both).
- **Testing:** manual verification (no harness) — `pnpm run build`, confirm both cards render chart
  + table together on Dashboard and Show, the zero-traffic states still suppress the chart shell,
  both themes.
- **Completion notes:** _pending_

---

## M7 — Whole-surface verification pass

## T29 — Production-build verification pass and final compliance sweep (R4; binding constraint 8; plan M7)
- **Description:** The closing task, blocked by M6. Two parts. **(1) Automated:** full
  `composer lint`, `composer types:check`, `pnpm lint:check`, `pnpm types:check`, and
  `./vendor/bin/sail test` (whole suite) green; a targeted re-check that the Implementation Notes
  hold across the finished diff — no `lockForUpdate()`/`sharedLock()`/`DB::transaction()` on any
  analytics path, no query selecting `webhook_events.body`/`headers`, `withTrashed()` appears in
  exactly the documented call sites and nowhere else, every analytics query states `team_id` or a
  policy-gated `proxy_id`, no blind `save()` on `deliveries`/`delivery_attempts` anywhere in the
  diff, no per-row query in any breakdown path; and a final scope-boundary check against plan §
  Explicitly out of scope (no export affordance, no alert/threshold, no per-event-type figure, no
  new capture, no change to retention/GC/retry/replay/mode/masked-viewer, no second events surface,
  no worst-first sort or verdict colour anywhere in the diff). **(2) Manual, against a real
  production build** (`pnpm run build`, `public/hot` removed first — the review-07 Finding 8
  trap): Dashboard, Proxy Show, and the Events list, in **both themes**, across **all three
  windows** (24h/7d/30d), through **every empty state** named in design-11 (no proxies at all, zero
  deliveries in window, zero traffic for a proxy, zero traffic for a destination, empty-filtered
  Events list), with non-text contrast on the trend-chart lines checked per `design-11` §
  Accessibility.
- **Dependencies:** T11, T17, T20, T22, T24, T28
- **Files:** none production; verification only
- **Acceptance Criteria:**
  - All five automated checks pass with the full suite included.
  - Every Implementation Note and scope-boundary item above is confirmed by inspection, and any
    violation found is fixed before this task is marked complete, not merely noted.
  - Every named surface × theme × window × empty-state combination is visually confirmed against a
    genuine production build (not a dev-server-served page) and recorded in the completion note.
- **Testing:** the full automated suite (part 1) plus the manual matrix (part 2), both recorded in
  the completion note — this task's own record is the evidence that a "verified against a fresh
  build" claim in this feature is not, per review-07 Finding 8, actually served from a dev server.
- **Completion notes:** _pending_

## Handoff
- **Inputs:** `docs/plans/plan-11-analytics.md` (fully approved, both Owner flags ruled);
  `docs/product/prd-11-analytics.md` (Approved, 37 ACs) + Amendment A; `docs/design/
  design-11-analytics.md` (fully approved, C1–C6 landed, C1 cleared);
  `docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md` (RESOLVED, all ten items);
  `docs/standards/planning.md`; `docs/tasks/retry-replay-tasks.md` and
  `docs/tasks/enhanced-mode-toggle-tasks.md` (house format precedent); the code on
  `feat/item-11-analytics` — `DashboardController`, `ProxyController`, `ProxyEventController`,
  `ProxyResource`, `ApplyTeamScope`, `TeamScope`, `routes/web.php`, `Dashboard.vue`,
  `proxies/Show.vue`, `proxies/events/Index.vue`, `resources/css/app.css`,
  `resources/js/components/welcome/canvasKit.ts`, `resources/js/composables/useAppearance.ts`,
  `vite.config.ts`, `config/inertia.php`, `package.json`.
- **Outputs:** this task plan.
- **Dependencies:** one new pnpm dependency pair (`chart.js`, `@j-t-mcc/vue3-chartjs`), conditional
  on T25's checks — see T25. No Composer package, no stack-row change.
- **Outstanding Questions:** none. Both Owner-approval flags on plan-11 are ruled; `Q-11-03` is
  RESOLVED in full; design-11 is fully approved with no correction outstanding. If T25's check 2
  fails (the wrapper pulls `chart.js/auto`), that is not a new question — the plan's own Option 2
  fallback is pre-approved and requires no further Owner sign-off, only a package-name change
  recorded in T25's completion note.
- **Next Agent:** Senior Developer, starting at T1.

