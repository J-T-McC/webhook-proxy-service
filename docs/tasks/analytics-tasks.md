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

  **Addendum (found during T6, fixed in the same migration file):** `./vendor/bin/sail test
  --parallel` uses one persisted MySQL database per worker (`testing_test_1..N`), each re-migrated
  only when the migration files' checksum changes (`FasterRefreshDatabase`), not on every suite
  run. Because this migration's `down()` restores `deliveries.team_id`/`deliveries.proxy_id`'s
  single-column index (see above), running the full parallel suite a second time against the same
  worker databases hit `1061 Duplicate key name 'deliveries_team_id_index'` — the first run's
  rollback test had already added it, and nothing ever removes it again, so the second run's
  rollback tried to add it a second time. Fixed by guarding both restorations with
  `Schema::hasIndex('deliveries', ['team_id'])` / `['proxy_id']` before adding, so `down()` is safe
  to run more than once against the same database. Verified by resetting all fourteen parallel
  worker databases and running `./vendor/bin/sail test --parallel` twice in direct succession
  (787/787 both times) plus one serial `./vendor/bin/sail test` run (787/787).

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
- **Completion notes:** Implemented all eight DTOs under `app/Data/Analytics/`, `readonly`,
  constructor-promoted, no logic, mirroring `App\Data\ProxyPermissions`'s style. Two shape
  decisions the task's prose left open, resolved for consistency with the rest of the set:
  `SeriesPoint` embeds `delivery: UnitFigure` and `attempt: UnitFigure` (rather than six flat
  prefixed fields) — reuses the one figure type everywhere a succeeded/failed/rate/total triple
  appears, so a day's figure and a window's figure are the same shape; `EventListFilters`'
  `destination`/`outcome` are typed inline shape arrays (`array{...}|null`) per the task's own
  PHPDoc, with the shape spelled out on the constructor's doc-block for PHPStan level 7. `rate`,
  `averageMs`, `p95Ms`, `latencyAverageMs` are all nullable; every count property a plain `int`.
  `composer types:check` (PHPStan level 7) passes with no suppression anywhere, including the
  `list<SeriesPoint>` property on `StatisticsPanel`. Verified: `composer lint`, `composer
  types:check`. No dedicated test file, per the task's own testing note — exercised indirectly
  from T4 onward.

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
- **Completion notes:** Implemented `app/Services/DeliveryStatistics.php` with its private core:
  `unitFigure()`/`statusCounts()` build one `GROUP BY status` query per table (`deliveries` /
  `delivery_attempts`) via `DB::table()`, filtered to `status IN ('succeeded','failed')` and
  windowed on `updated_at` (never `created_at`). Exposed as three public grain-scoped methods —
  `unitFiguresForTeam()`, `unitFiguresForProxy()`, `unitFiguresForDestination()` — each returning
  `array{delivery: UnitFigure, attempt: UnitFigure}`. These are public (not `private`, as the
  plan's "private helpers do the grouped aggregates" line reads) for a specific reason worth
  recording: destination grain has no route through the plan's four enumerated public methods
  (`forTeam`/`forProxy`/`proxyBreakdown`/`destinationBreakdown`) until T8's bulk-grouped
  `destinationBreakdown()` lands, and T4's own Acceptance Criteria require destination-grain
  coverage now, with the suite green at the end of this task. Reading "private" here as
  "encapsulated inside this class, never built by another class" (the sentence immediately after
  it — "No other class may build an analytics query") rather than as a literal PHP `private`
  keyword mandate satisfies both the plan's intent and T4's own testability requirement; T9's
  `forTeam()`/`forProxy()` will call these same two team/proxy methods rather than duplicating the
  query. `proxyBreakdown()`/`destinationBreakdown()` (T8) will NOT reuse the destination method in
  a loop — they run their own bulk `GROUP BY proxy_id`/`GROUP BY destination_id` queries per R7 (no
  per-row query anywhere).

  **Flagged and corrected, not guessed:** the task's own "canonical 100%/67% fixture" text is
  internally inconsistent. The stated composition — one delivery, three attempts, two failed and
  one succeeded — is unambiguous and matches the PRD's own illustrative example verbatim ("a
  delivery that succeeded on attempt three... is two failures and one success", PRD-11 § MVP
  criterion 9). `UnitFigure::$rate` is a success rate throughout the plan and this fixture's own
  delivery-level reading ("100% (1 of 1)" only parses as succeeded/total). Applying that same
  formula to the stated attempt composition gives 1 of 3 succeeded ≈ 33%, not the "67% (2 of 3)"
  the task text states — 67% is the *failure* share of the same three attempts, not `rate`. Since
  the fixture composition and the rate formula are both unambiguous and consistent with every other
  reference in plan-11 and PRD-11, this reads as a transcription slip in the task doc's illustrative
  percentage rather than a genuine requirements conflict, so it did not warrant pausing the task for
  a question doc; implemented and tested against the correct value (≈33% attempt-level success),
  documented inline in the test. Flagging here per the "record deliberate simplifications" and
  "flag rather than decide" conventions, in case the Task Planner intended a different fixture
  composition than the one literally written.

  Verified: `composer lint`, `composer types:check` (PHPStan level 7, no suppressions), and
  `./vendor/bin/sail test --parallel` all green (775/775, up from 762 after T1-T3).

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
- **Completion notes:** New `tests/Unit/Services/DeliveryStatisticsRetryReplayTest.php`, per the
  task's own alternative. Refactored T4's private query helpers into `deliveryAggregates()`
  (`GROUP BY status, kind`) and `attemptAggregates()` (`GROUP BY status`, carrying `SUM(CASE WHEN
  attempt_number > 1 THEN 1 ELSE 0 END)` in the same pass) so `terminalFailure`, `live`/`replay`,
  and `retryVolume` all come from the same two aggregate queries T4 already issues — no extra
  round-trip, per the plan's § Architecture B table. `eventualSuccessCount()` is the
  `EXISTS(delivery_attempts WHERE delivery_id = deliveries.id AND attempt_number >= 2)` count;
  `bridgeFailedAttemptsCount()` is the one deliberate two-table `join` in the service, filtered to
  `delivery_attempts.status = 'failed'` against `deliveries.status = 'succeeded'`, windowed on the
  deliveries side. Exposed as `retryReplayForTeam()`/`retryReplayForProxy()` (team/proxy grain
  only, matching plan § Architecture B — no destination-grain retry/replay figures exist).
  `tests/Unit/Services/DeliveryStatisticsRetryReplayTest.php` covers each AC19 figure
  independently, the live/replay split with a replay fixture proving the live count isn't
  inflated or deflated, the bridge count against the T4 canonical fixture's exact composition (2
  failed attempts behind 1 succeeded delivery), and the all-zero empty-window case. `composer
  lint`, `composer types:check` (PHPStan level 7, no suppressions), and `./vendor/bin/sail test
  --parallel` all green (781/781).

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
- **Completion notes:** Extended `attemptAggregates()` (T4/T5's existing `GROUP BY status` query)
  with `SUM(duration_ms)`/`COUNT(duration_ms)` — SQL aggregate functions skip `NULL` rows by
  ordinary semantics, so this is the same population an explicit `whereNotNull('duration_ms')`
  filter would select, without a second clause to keep in sync with the percentile query's
  explicit one. `latencyFigure()` sums `duration_sum`/`duration_count` across both status groups
  for the overall average (`round()`, cast to `?int`), then calls `percentileDurationMs()` — the
  nearest-rank `ORDER BY duration_ms ASC LIMIT 1 OFFSET CEIL(0.95 × n) − 1` read — only when
  `includePercentile` is true and `sampleCount > 0`. Exposed as `latencyForTeam()`/
  `latencyForProxy()` (percentile included) and `latencyForDestination()` (percentile always
  `null`, per Amendment A(ii); no percentile query issued there). `tests/Unit/Services/
  DeliveryStatisticsLatencyTest.php` covers the three boundary fixtures (`n = 1, 2, 20`), the
  shared-population assertion (a dispatched attempt with no `duration_ms` excluded from both
  average and percentile), the `n = 0` all-null/zero case, and `p95Ms` present at team/proxy grain
  vs. `null` at destination grain (with `averageMs` still populated there). `composer lint`,
  `composer types:check` (PHPStan level 7), and `./vendor/bin/sail test --parallel` all green
  (787/787).

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
- **Completion notes:** `seriesForTeam()`/`seriesForProxy()` run `dailyAggregates()` once per table
  (`GROUP BY DATE(updated_at), status`), then `daysInWindow()` densifies in PHP — every calendar
  day gets a `SeriesPoint` via `dailyUnitFigure()`, whether or not the raw `GROUP BY` result has a
  row for that day. `DATE(updated_at)` is computed by MySQL's session timezone, which this
  deployment reports as `SYSTEM`/`UTC` (checked directly via `SELECT @@session.time_zone,
  @@system_time_zone`), matching `config('app.timezone')` — Technical ruling 9 holds without a
  connection-level `time_zone` override, which stayed out of scope for this task.

  One design decision the task's prose left open: the series window is **calendar-day aligned**
  (`seriesWindowStart()`: today's start-of-day minus `days() - 1`), not the precise rolling
  `windowStart()` every other figure in this class uses (`now() - interval`). This is what makes
  "the series length equals the window's day count exactly" (T7's own AC) hold trivially for both
  7d and 30d — a precise rolling cutoff spans `days() + 1` distinct calendar dates whenever the
  cutoff time doesn't land exactly at midnight, which would either produce a partial boundary day
  or an off-by-one day count. The headline figures (T4-T6) intentionally keep the precise
  "last N hours" reading; only the trend series needs calendar buckets to have a fixed length.
  `tests/Unit/Services/DeliveryStatisticsSeriesTest.php` covers the densification case (one day
  of traffic in a 7-day window, asserting both the traffic day and a definitely-empty day), the
  7d/30d length assertions, a sparse-`GROUP BY` case (traffic on exactly one of 30 days), and the
  all-empty-window all-zero case. `composer lint`, `composer types:check` (PHPStan level 7, no
  suppressions — `array_values()` wrapping was needed twice to make PHPStan recognize the `list<>`
  return type from a `Collection::map()->all()`), and `./vendor/bin/sail test --parallel` all
  green (791/791).

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
- **Completion notes:** `proxyBreakdown()`'s row set is every proxy the team has, live and
  soft-deleted (`Proxy::withTrashed()->where('team_id', ...)`), not just proxies with traffic in
  the window — this is a deliberate reading of the task's "over exactly the id set the aggregate
  returned" line, which taken literally would drop a zero-traffic live proxy's row entirely.
  T13/T15 both require a zero-traffic proxy to still render ("0 of 0 delivered", "No deliveries
  yet"), which is only possible if the row set is the team's full proxy roster, not the narrower
  aggregate-only set; the narrower "exactly the aggregate's ids" framing does hold for
  `destinationBreakdown()`, whose task text explicitly states the **union** shape it needs (live
  destinations ∪ active-but-deleted ones), which is what's implemented. One label-lookup query
  (`Proxy::withTrashed()`, one of the feature's exactly two `withTrashed()` call sites) plus one
  grouped aggregate per table (`groupedAggregates()`, new: `GROUP BY $groupColumn, status`,
  optionally carrying `duration_sum`/`duration_count` — reused by `destinationBreakdown()` for
  `latencyAverageMs`) gives `proxyBreakdown()` a fixed 3-query cost regardless of proxy count
  (verified directly via `DB::listen`: `select id,name,deleted_at from proxies...`,
  `GROUP BY proxy_id,status` on `deliveries`, same on `delivery_attempts`, nothing else).
  `destinationBreakdown()` costs 3 queries when every id with activity is already live, or 4 when
  at least one trashed destination has activity (`Destination::withTrashed()->whereIn(...)`, the
  feature's other `withTrashed()` site) — both O(1) in destination count, never O(N).
  `tests/Unit/Services/DeliveryStatisticsBreakdownTest.php` covers the AC6 case (soft-delete both
  a proxy and a destination with activity, figures identical, `isDeleted === true`), the
  query-count assertion for both methods at N = 1 vs. N = 10, `canDrillThrough` true/false, and
  the destination union case. `composer lint`, `composer types:check` (PHPStan level 7, no
  suppressions), and `./vendor/bin/sail test --parallel` all green (796/796).

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
- **Completion notes:** `forTeam()`/`forProxy()` compose `StatisticsPanel` by calling the exact
  same public per-grain methods (`unitFiguresFor*()`, `retryReplayFor*()`, `latencyFor*()`,
  `seriesFor*()`) a direct caller could call — no duplicate query-building, and a figure can never
  disagree between the panel and a standalone call to the same method. `hasTraffic` is
  `delivery.total > 0 || attempt.total > 0`. Did not add a query-consolidation pass (e.g. sharing
  one `attemptAggregates()` call across `unitFigures`/`retryReplay`/`latency`): the resulting
  query count for `forTeam()`/`forProxy()` (roughly 8-10 queries, depending on whether the
  percentile query runs) still lands within the plan's own "roughly nine to twelve" Dashboard
  estimate once `proxyBreakdown()`'s 3 queries are added, no test asserts an exact total, and the
  binding constraint that matters (R7, no per-row/per-proxy query) is satisfied regardless — this
  is recorded as a known, deliberate simplification with a clear ceiling (a future rollup, § Risks
  R1, would revisit this class's query shape entirely anyway).

  The `team_id`/mode-independence audit itself is written directly into `forTeam()`'s doc-block
  (AC23, AC25, AC26) rather than as a separate document, since the actual auditable fact is "every
  `$constraints` array this class builds is `['team_id' => ...]` or `['proxy_id' => ...]`, applied
  via a plain `where()` loop with no branch" — a claim best checked against the code itself.
  `tests/Unit/Services/DeliveryStatisticsScopingTest.php` covers cross-team isolation for
  `forTeam()`, `forProxy()`, `proxyBreakdown()`, and `destinationBreakdown()` (four separate test
  methods, each with a second team's identical-shaped traffic contributing nothing), a Simple-mode
  fixture whose retry figures come back non-zero, a FIFO-vs-Async fixture producing identical
  figures through the same path, and a `mode`/`processing_mode`-absence check that tokenizes
  `DeliveryStatistics.php` and strips comments/doc-blocks before the substring check (a plain grep
  would have false-positived on this very invariant being *documented* in the class's own
  doc-block). The team-less controller-level guard (AC23's other half) is cross-referenced to
  T13/T18, not asserted here, per the task's own testing note — the service takes a resolved
  `teamId`/`Proxy`, so there is nothing to construct a team-less case against at this layer.
  `composer lint`, `composer types:check` (PHPStan level 7, no suppressions), and
  `./vendor/bin/sail test --parallel` all green (803/803).

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
- **Completion notes:** Test-only, as scoped — no production file touched. All three cases drive
  real production entry points rather than reproducing their query shape:

  - **Delivery CAS no-op** reuses the exact scenario `DeliverToDestinationTest::
    test_a_racing_duplicate_terminal_settle_fires_no_duplicate_event_and_schedules_nothing`
    already established (raw-`UPDATE` a delivery straight to `Failed`, simulating a settler that
    already won the terminal CAS, then drive a fresh, later attempt — `attempt_number = 5` — of
    the *same* delivery through `DeliverToDestination::run()`). That attempt has no existing row,
    so `resume()`'s early-return never fires; it reaches `settleDelivery()`/`transition()` for
    real, and the CAS (`WHERE status IN ('pending','retrying')`) affects zero rows against the
    already-`Failed` delivery — proving the no-op at the SQL level `transition()` actually runs,
    not merely that `resume()` short-circuits first.
  - **Attempt redelivery no-op** reuses `DeliverToDestinationTest::
    test_redelivery_after_success_is_a_no_op`'s exact pattern (`DeliverToDestination::run($unit)`
    twice with the identical unit) — the second call resolves via `resume()`'s early return
    (`status !== Dispatched`) — with a raw `updated_at` capture added before/after.
  - **GC read-only** mirrors `PurgeExpiredPayloadsTest::expiredEventFor()`'s fixture shape (an
    event 31 days old, past the 30-day default retention window) plus a terminal `Delivery` and a
    resolved `DeliveryAttempt` linked to it via `webhook_event_id`/`ingest_id`. Asserts the event
    really was cleaned (`payload_cleaned_at` set) — proving this is a genuine GC run over live,
    collectable rows, not a run that skipped everything — while both fact rows' `updated_at`
    values stay byte-identical (H5's "terminal deliveries hold nothing" plus ADR-012 Decision 5's
    "GC reads, never writes, either table").

  All three assertions compare a raw `DB::table(...)->value('updated_at')` string captured before
  and after (`assertSame`), not a Carbon comparison with implicit tolerance — genuinely
  byte-identical, per the task's own wording. `composer lint`, `composer types:check` (PHPStan
  level 7), and `./vendor/bin/sail test --parallel` all green (806/806).

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
- **Completion notes:** Test-only, as scoped — no production file touched.

  - **AC2** (`test_ac2_every_figure_is_numerically_identical_before_and_after_purge_expired_payloads`)
    computes every public `DeliveryStatistics` method's output (`unitFiguresFor*()`,
    `latencyFor*()`, `seriesForTeam()`, `retryReplayForTeam()`, `proxyBreakdown()`,
    `destinationBreakdown()`, `forTeam()`) against a fixture with a 31-day-old received event and
    a terminal delivery/two attempts, runs `PurgeExpiredPayloads`, confirms the event really was
    erased (`payload_cleaned_at` set — a real cleanup, not a skip), recomputes every figure, and
    asserts the whole result set `assertEquals` identical.
  - **AC3** builds two proxies with identically-shaped traffic, ages and purges only one of them,
    and asserts `forProxy()`'s `delivery`/`attempt` `UnitFigure`s match exactly between the cleaned
    and retained proxy, plus that the cleaned proxy's destination still resolves in
    `destinationBreakdown()`.
  - **AC5** snapshots `(id, updated_at)` for every row in `deliveries`, `delivery_attempts`,
    `webhook_events`, `dispatched_payloads`, and `fifo_dispatches` (all five populated — a
    `DispatchedPayload` and a `FifoDispatch` row were added to the fixture specifically so this
    assertion has something to prove for those two tables, not just an unpopulated no-op), calls
    every public method (including several beyond `computeEveryFigure()`'s set — a second
    `forProxy()` call and three more grain/window combinations), and asserts the full snapshot
    unchanged.
  - **AC1/AC4** captures every SQL statement `computeEveryFigure()` issues via `DB::listen` and
    asserts none references `webhook_events` (table name) or the literal `` `body` ``/`` `headers`
    ``` column identifiers, plus a source-level check (via `ReflectionClass` + `token_get_all()`
    stripping comments/doc-blocks, same technique T9 used to avoid a comment-text false positive)
    that `DeliveryStatistics`'s executable code never references the `WebhookEvent` class at all —
    stronger than "no query happens to touch it today," since the class cannot touch it without a
    code change this test would then fail on.

  `composer lint`, `composer types:check` (PHPStan level 7), and `./vendor/bin/sail test
  --parallel` (810/810) and a serial `./vendor/bin/sail test` (810/810) both green.

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
- **Completion notes:** Implemented `resources/js/data/analyticsLabels.ts`, mirroring
  `data/proxyDeliveryStates.ts`'s data-const-plus-small-pure-functions style. Exports every
  C4-required label verbatim (`DELIVERY_SUCCESS_LABEL`, `ATTEMPT_SUCCESS_LABEL`,
  `TERMINAL_FAILURES_COLUMN_LABEL`, `EVENTUAL_SUCCESS_LABEL`, `TERMINAL_FAILURE_LABEL`,
  `RETRY_VOLUME_LABEL`, `LIVE_VS_REPLAY_LABEL`, `LATENCY_AVERAGE_LABEL`, `LATENCY_P95_LABEL`,
  `LATENCY_CAPTION`).

  Two additions beyond the literal 7-label list, both within the task's "typed object or set of
  named constants" latitude and named here rather than left implicit: (1)
  `DELIVERY_SUCCESS_COLUMN_LABEL`/`ATTEMPT_SUCCESS_COLUMN_LABEL` — the Proxies table's (T15) and
  the future Destinations table's (T20) column headers, one word shorter than the headline's
  `ATTEMPT_SUCCESS_LABEL` because a column header pairs with its neighbour rather than repeating
  the headline's "— destination health" qualifier; without a shared constant these two tables
  (T15 now, T20 later) would each hand-write "Attempt success" independently, which is exactly the
  drift R6 exists to prevent. (2) A `windowLabel()`/`lastWindowSubtitle()` pair mirroring
  `AnalyticsWindow::label()` (`'24h'` → `'24 hours'`, etc.) for design correction C2's "Last
  {window}" subtitles, which the task's own prose names as appearing on four cards/tables — another
  multi-surface wording that belongs in the single-source file rather than four separate template
  literals. Also added, since every consumer needs them and a second copy anywhere would be the
  same drift risk: `formatRate()` (a `rate: number|null` → `"NN%"` or `RATE_NO_DATA_LABEL`,
  Amendment A(i)), `formatLatencyMs()` (→ `"NNN ms"` / `"N.N s"` / `LATENCY_NO_DATA_LABEL`),
  `deliveryCaption()`/`attemptCaption()` (the "N of M delivered/attempts succeeded · last {window}"
  captions), `bridgeSentence()` (AC14(d)'s descriptive, never-arithmetic gap sentence, returning
  `null` when there is nothing to bridge), and `liveVsReplayText()` (the "N live · M replay"
  two-labelled-numbers text, § Accessibility's non-colour-only requirement). `AnalyticsWindowValue`
  is imported from `resources/js/types/analytics.ts` (new, added ahead of its own task since T13's
  props and this file's functions both need it — see T13's completion notes).

  `pnpm types:check` passes. Nothing in this file is a component; `nothing in this file is a
  component (pure data)` per the task's own AC is read, per `data/proxyDeliveryStates.ts`'s
  established precedent in this codebase, as "no `.vue` component" rather than "no functions" —
  small pure formatting/lookup functions alongside the constants they format is the existing
  pattern this file follows, not a departure from it.

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
- **Completion notes:** Implemented as described — `DashboardController` gains a constructor-injected
  `App\Services\DeliveryStatistics` (mirroring `IngestController`'s constructor-injection style),
  `window` resolved via `AnalyticsWindow::tryFrom((string) $request->query('window')) ??
  AnalyticsWindow::default()`, and two new Inertia props: `statistics` = `forTeam($team->id,
  $window)`, `proxies` = `proxyBreakdown($team->id, $window)`. No separate top-level `window` prop
  — plan-11 § API's "Prop shapes" lists only `statistics`/`proxies` as the Dashboard's new props,
  and `StatisticsPanel::$window` already carries the resolved value; the window selector (T14)
  reads `props.statistics.window` rather than a duplicate prop that could disagree with it.

  Added a controller-level guard (`$team = $user->currentTeam; abort_if($team === null, 404);`)
  before either service call — this is the "controller-level guard" T9's completion notes
  cross-reference: `DeliveryStatistics::forTeam()`/`proxyBreakdown()` both take a plain
  non-nullable `int $teamId`, so the controller is the one place that must resolve a concrete team
  id before calling either, never the service. In practice this branch is unreachable through the
  shipped route (`EnsureTeamMembership` aborts 403 before this action runs unless the user belongs
  to the `{current_team}` the URL names, which sets `currentTeam` via `switchTeam()`), but it keeps
  `$team->id` PHPStan-narrowed from `Team|null` to `Team` without a suppression, and it is the
  concrete artifact of the guard T9 promised existed here.

  Added `resources/js/types/analytics.ts` (new, not itself named in this task's Files list) —
  the TypeScript mirror of the `App\Data\Analytics\*` DTOs plan-11 § API documents, needed for
  `pnpm types:check` to type-check `analyticsLabels.ts`'s `AnalyticsWindowValue` import (T12) and
  every Dashboard prop from T14 onward. Reading "Files" lists across this plan's tasks as the
  production surface each task **must** touch rather than an exhaustive enumeration (T4's
  completion notes record the same reading for `DeliveryStatistics`'s method visibility) — a typed
  props contract is required for `pnpm types:check` to mean anything on the very next task, T14,
  and duplicating the eight interfaces once per consuming task would itself be the kind of drift
  R6 exists to prevent.

  `tests/Feature/Analytics/DashboardControllerTest.php` (new) covers: absent `window` → `30d`
  default with 200; malformed `window` (`'garbage'`) → same default, 200 not 422; a valid `window`
  (`'7d'`) honoured; cross-team isolation (`proxies`/`statistics` for one team carry none of a
  second team's identical-shaped traffic); the query-count assertion at N=1 vs. N=10 proxies (same
  count); a team with zero proxies renders `proxies` empty at the **same** fixed query cost as N=1
  — not a "zero queries" branch, since `DeliveryStatistics::proxyBreakdown()` (T8) is already O(1)
  in proxy count including N=0, and asserting "no group-by query at all" would have required
  reaching into and changing T8's already-tested, out-of-scope-for-this-task service; and a sanity
  check that the existing `pendingInvitations` prop is untouched. `tests/Feature/DashboardTest.php`
  itself needed no edits and stays green unmodified.

  Verified: `composer lint`, `composer types:check` (PHPStan level 7, no suppressions),
  `pnpm lint:check`, `pnpm types:check`, `pnpm format:check`, and `./vendor/bin/sail test --filter
  "DashboardControllerTest|DashboardTest"` (13/13) all green; full `./vendor/bin/sail test
  --parallel` 817/817 (up from 810 after T1–T11).

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
- **Completion notes:** Implemented the page-level `WindowSelector` (three `Button`-wrapped `Link`s,
  `aria-current="true"` on the active window, full-page Inertia navigation carrying `?window=`) and
  the "Deliveries" card (two-tier `dl`/`dt`/`dd` headline + bridge sentence) directly in
  `Dashboard.vue`, per this task's own Files list (no separate `WindowSelector.vue` — T14 doesn't
  name one, and design-11 only names the widget conceptually; reused if T19 needs the identical
  markup, out of scope here). Removed exactly the first of the four existing `PlaceholderPattern`
  blocks (the first grid cell), leaving the other three in place for T15–T17 to remove in turn.
  Every label/caption/rate/bridge-sentence string comes from `analyticsLabels.ts` (T12) — no
  zero-state branching was needed in the template beyond what `formatRate()`/`bridgeSentence()`
  already encode (`rate === null` → "No deliveries yet"; `bridgeFailedAttempts === 0` → sentence
  omitted via `v-if`), which is exactly the point of centralising that logic in T12 rather than
  duplicating a zero-check per card across T14–T20.

  **Manual verification** (recipe in agent memory `manual_verification_recipe.md`): removed
  `public/hot` (present at session start — the review-07 Finding 8 standing trap), ran `pnpm run
  build`, seeded two throwaway teams via `sail tinker` — one with the canonical fixture (one
  delivery, three attempts: two failed, one succeeded) on a real proxy/destination, one with a
  proxy and zero deliveries — logged in via a real Playwright login flow (`User::factory()`'s
  default password `password`) against `http://localhost` (Sail).

  - **Has-traffic team:** headline read exactly `100%` / "1 of 1 delivered · last 30 days" and
    `33%` / "1 of 3 attempts succeeded · last 30 days" — the canonical fixture's correct
    delivery/attempt split (T4's completion notes already flagged the task doc's own worked
    example as internally inconsistent; this run reconfirms `UnitFigure::$rate` reads 33%, not
    67%, for 1-of-3 succeeded). Bridge sentence read "2 attempts failed before these deliveries
    succeeded — see Retry & replay below." Clicking `7d` navigated to
    `.../dashboard?window=7d` and `aria-current="true"` moved to the clicked button. Verified
    visually in both light and dark theme (screenshots inspected) — headline, captions, bridge
    sentence and window buttons all render correctly and legibly in both.
  - **Zero-traffic team (proxy exists, no deliveries):** headline read `No deliveries yet` for the
    rate and "0 of 0 delivered · last 30 days" for the count caption (Amendment A(i) — a rate, not
    a count, reads "no data"); no bridge-sentence paragraph rendered at all (0 elements matched),
    confirming the `v-if="bridgeText"` omission.

  Cleaned up both throwaway teams/users (`forceDelete()`, children before parents) via a second
  `sail tinker` call afterward.

  Verified: `pnpm lint:check`, `pnpm types:check`, `pnpm format:check` all green. Backend suite
  unaffected by this frontend-only task; not re-run here (T13's own commit already left it green,
  see below for T15–T17's shared re-verification).

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
- **Completion notes:** Implemented the "Proxies" card directly in `Dashboard.vue` — client-side
  sortable `Table` over `props.proxies` (T13's `ProxyBreakdownRow[]`), default alphabetical by
  `name` ascending (flagged design call 5 — never worst-first). Each sortable `TableHead` wraps a
  `<button type="button">` (native Enter/Space handling, no bespoke keyboard code needed) carrying
  `aria-sort` on the `TableHead` itself and a `▲`/`▼` indicator beside the active column's label;
  clicking the same header again flips direction, clicking a different header resets to ascending.
  Rate cells use the new `compactRateText()` helper (added to `analyticsLabels.ts`, T12's file — a
  `"96% (42/42)"`/`RATE_NO_DATA_LABEL` formatter) rather than plain `formatRate()`, matching design
  correction C4/Screen 3's compact convention so a later T20 Destinations table reads identically
  rather than inventing its own cell format — same drift-prevention reasoning T12 already
  documented for the column-header labels.

  **Both the proxy-name link and the Terminal-failures link are gated on `canDrillThrough`**, not
  independently — per plan-11 § API's own reading of `Q-11-03(9)` ("a deleted proxy's row keeps its
  figures but not its links", plural), not merely the "no link to manage it" language that,
  read in isolation, could be misread as muting only an edit affordance. A deleted row therefore
  renders its name and Terminal-failures count as plain text and its View cell as a muted em-dash,
  while every figure stays intact. The Terminal-failures link and the View link both carry `window`
  only today (`proxyEventRoutes.index`/`proxyRoutes.show` with `?window=`); T23 adds
  `&outcome=delivery_failed` to the former once T21's filter resolver exists to read it — this task
  builds the link Flow B step 5 names, T23 "wires" it the rest of the way, exactly as this task's
  own description phrases it.

  Replaced the two remaining grid-cell `PlaceholderPattern` blocks (T14 removed the first of four)
  with this card in one edit rather than one at a time — the 3-column small-tile grid the
  placeholders lived in doesn't fit a full-width table, so the grid wrapper itself had to go, not
  just its contents. One placeholder (the large `min-h-[100vh]` block) remains for T16/T17.

  **Manual verification:** `public/hot` removed, `pnpm run build`, seeded a team with four proxies
  via `sail tinker` — "Zulu Proxy" (3 failed deliveries), "Alpha Proxy" (1 succeeded), "Mike Proxy"
  (zero traffic), "Delta Proxy" (1 succeeded, then soft-deleted) — deliberately named so alphabetical
  order and any accidental id/insertion-order sort would disagree, proving the sort genuinely reads
  `name`. Logged in via Playwright:
  - Default order: Alpha, Delta, Mike, Zulu — alphabetical, confirming default sort and that the
    deleted proxy still appears in its natural alphabetical position.
  - Clicking the "Proxy" header once reversed to Zulu, Mike, Delta, Alpha (`aria-sort="descending"`
    on that header); clicking again returned to ascending order — the toggle-on-repeat-click
    behaviour.
  - Clicking "Terminal failures (deliveries)" sorted ascending by that count (Alpha/Delta/Mike all
    `0`, Zulu `3` last), independent confirmation the column-click sort reads the clicked column,
    not just re-running the previous one.
  - Delta's row (deleted) rendered the muted **Deleted** badge beside its name, its figures intact
    (`100% (1/1)` both units), no `<a>` element anywhere in that row (`0` links), and its View cell
    read `—`.
  - Mike's row (zero traffic) read `No deliveries yet` on both rate columns and `0` on Terminal
    failures — the count/rate split from correction C3, already free from `compactRateText()`
    without a template-level branch.
  - Screenshots inspected in both light and dark theme — table renders legibly, badge and muted
    dash visually distinct without relying on colour alone.

  Cleaned up the throwaway team/proxies/deliveries/attempts afterward (`forceDelete()`, including
  `Proxy::withTrashed()` for the soft-deleted row).

  Verified: `pnpm lint:check`, `pnpm types:check`, `pnpm format:check` all green.

  **Rework (Owner-reported, fixed before T18):** the action column's `TableHead` originally rendered
  a visible "View" header, which design-11 line 271 writes as `(View)` — parenthesised, unlike the
  four bare column labels beside it — reading as an unlabelled action column, not a fifth labelled
  header. Fixed by wrapping the text in a `sr-only` span (`<span class="sr-only">View</span>`)
  inside the existing `TableHead`, so the column keeps its cell and alignment but nothing renders
  visibly in the `thead`. Deliberately not harmonised with `proxies/Index.vue`'s visible `Actions`
  header, which is a genuine counter-precedent the Owner was shown and chose not to apply here.
  Verified via Playwright against a production build (`public/hot` removed, `pnpm run build`):
  the header cell's rendered text is empty, the `sr-only` span's computed style is
  `position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%)` (visually
  clipped, present for assistive tech), and the column still lines up with its body cells (the
  "View" link renders directly beneath the now-empty header). `pnpm lint:check`, `pnpm types:check`,
  `pnpm format:check` all green.

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
- **Completion notes:** Implemented both cards directly in `Dashboard.vue`. "Retry & replay": a
  `dl` grid of four tiles (`grid-cols-2 sm:grid-cols-4`) sourced from
  `props.statistics.retryReplay` — plain integers rendered as-is (never gated on `rate` or any
  other value, satisfying "always rendered, `0` in an empty window" directly, with no template
  branch needed since these four fields are already plain counts in the DTO), and
  `liveVsReplayText()` (T12) for the two-labelled-numbers live/replay tile. "Latency": a
  `dt`/`dd` pair per figure using `formatLatencyMs()` (T12 — `"NNN ms"` below 1 s, `"N.N s"` at or
  above, `LATENCY_NO_DATA_LABEL` when `sampleCount === 0`), plus the fixed caption. Both cards
  carry the "Last {window}" subtitle via `lastWindowSubtitle()` (C2). No control, class, or colour
  anywhere in either card is conditioned on a figure's value (AC22(b)) — every tile and both
  latency rows use identical, unconditional markup regardless of what the number is.

  This task's own card placement (replacing the last remaining `min-h-[100vh]` `PlaceholderPattern`
  block) removed the template's only remaining usage of `PlaceholderPattern`, so its now-dead
  import had to come out in this commit too — `pnpm lint:check`'s `no-unused-vars` rule fails
  otherwise, and every task's own gate requires lint green. T17's own line "removes the remaining
  `PlaceholderPattern` usages and the now-unused import" is therefore already satisfied by this
  task; T17's own AC ("no `PlaceholderPattern` import or usage remains anywhere") holds as a
  confirmation, not a new removal, when that task runs.

  **Manual verification:** `public/hot` removed, `pnpm run build`, seeded a team via `sail tinker`
  with three deliveries designed to exercise all four retry/replay figures and both latency
  values distinctly — an eventually-succeeded delivery (1 failed + 1 succeeded attempt, kind
  `original`), a terminally-failed delivery (1 failed attempt, kind `original`), and a
  successful-on-first-try replay delivery (kind `replay`) — plus a second, all-empty team/proxy
  for the zero-window state. Logged in via Playwright and read the rendered page:
  - Traffic team: Eventual success `1`, Terminal failure `1`, Retry volume `1` (the one
    `attempt_number > 1` row), Live vs replay `"2 live · 1 replay"` (two `original` deliveries,
    one `replay`) — all four figures independently correct, not just non-zero. Latency: Average
    `525 ms` (the exact mean of the fixture's four `duration_ms` values 100/1500/200/300) and 95th
    percentile `1.5 s` — confirming `formatLatencyMs()`'s ms/s threshold switches correctly at
    1000, not merely that some plausible-looking number renders. Caption present.
  - All-zero team: all four Retry & replay tiles read `0`/`"0 live · 0 replay"` (never hidden,
    never a message in their place) and both Latency rows read `No data` independently, matching
    design-11's zero-window Screen 1 state exactly.
  - Screenshots inspected in both light and dark theme — legible, no colour-only distinction
    relied upon anywhere in either card.

  Cleaned up both throwaway teams afterward.

  Verified: `pnpm lint:check`, `pnpm types:check`, `pnpm format:check` all green.

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
- **Completion notes:** Implemented the "Trend" card between "Proxies" and "Retry & replay" (design
  ordering) directly in `Dashboard.vue`: `props.statistics.hasTraffic` gates between the
  `TREND_NO_DATA_LABEL` message (new in `analyticsLabels.ts`, T12's file — "No data for this
  period.", the plotted-series no-data treatment "in place of the chart," read here as "in place
  of the only representation that exists yet" since T27/M6 hasn't landed the canvas) and a
  `Collapsible` wrapping a `Table` over `props.statistics.series` — Date | Delivery success |
  Attempt success, each rate cell using `compactRateText()` (T15) for the same "96% (42/42)"
  convention already established on the Proxies table. **Rendered `default-open` rather than
  collapsed**, exactly as this task's own description requires ("visible by default at this
  stage, since it is the only representation until M6 lands") — a code comment marks this for
  T27/T28 to flip back to collapsed-by-default once the chart lands beside it, so that binding
  design-11 § Interactions requirement isn't silently lost when this task's own justification for
  the exception (no chart yet) stops being true.

  **No-proxies-at-all state:** the entire template now branches on `props.proxies.length === 0` at
  the top level — `true` renders a single centered `Card` ("No proxies yet" + helper text + a
  **Create a proxy** link to `proxyRoutes.create`), matching the existing empty-state idiom
  (`events/Index.vue`'s "No events yet"); `false` renders everything T14–T17 built (window
  selector, all five cards). No window selector, no card shell of any kind renders in the `true`
  branch, per this task's own AC.

  Confirmed (rather than newly performed, per this task's own AC wording) that no
  `PlaceholderPattern` import or usage remains anywhere in `Dashboard.vue` — T16's completion notes
  already record removing the last usage and the now-dead import as an unavoidable consequence of
  that task's own card placement (`pnpm lint:check`'s `no-unused-vars` rule would have failed T16's
  own gate otherwise). This task's AC on that point is satisfied by inspection, not by a new edit.

  **Manual verification:** `public/hot` removed, `pnpm run build`, seeded three throwaway
  teams/proxies via `sail tinker`:
  - **Densified series with a deliberate gap.** One team/proxy with a delivery today and a second
    delivery whose `updated_at` was backdated to exactly 10 days ago (`Aug 16, 2026`, given the
    session's current date of `Aug 26, 2026`), leaving a real, unambiguous gap day directly after
    it (`Aug 17`) with zero raw `GROUP BY` rows. The rendered table (read directly, without
    clicking "View as table" — it was already open) had **exactly 30 rows**, `Jul 28` through
    `Aug 26` inclusive; the `Aug 16` row read `0% (0/1)` (correct — a failed attempt/delivery, not
    absent); the `Aug 17` gap-day row read "No deliveries yet" on both columns rather than being
    missing from the table entirely — proving genuine server-side densification, not merely a
    coincidentally-complete raw aggregate; `Aug 26` (today) read `100% (1/1)`. Confirmed identically
    in both light and dark theme via full-page screenshots.
  - **No proxies at all.** A second team with zero proxies rendered exactly the single "No proxies
    yet" card — no `<h1>Dashboard</h1>`, no window-selector `<nav>` (both asserted at `0` via
    Playwright locator counts, not merely "not visually noticed"), confirmed in both themes.
  - **Zero-deliveries-in-window trend message.** A third team with one proxy and zero deliveries
    rendered "No data for this period." in the Trend card, with no "View as table" trigger present
    at all (`0` matches) — while every other card on the same page still rendered its own
    appropriate zero-state (headline "No deliveries yet", Proxies table's zero-traffic row, all-zero
    Retry & replay tiles, "No data" Latency) — confirming the mixed per-card empty-state
    presentation design-11 specifies for a single zero-traffic team, not one uniform "empty page"
    treatment.

  Cleaned up all three throwaway teams afterward.

  Verified: `pnpm lint:check`, `pnpm types:check`, `pnpm format:check` all green. Re-ran the full
  backend gate at the close of this milestone since T14–T17 were frontend-only and hadn't been
  re-checked since T13's commit: `composer lint`, `composer types:check` (PHPStan level 7, zero
  errors), and `./vendor/bin/sail test --parallel` all green (817/817, unchanged from T13 — no
  backend file touched by T14–T17).

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
- **Completion notes:** Implemented as described — `ProxyController` gains a constructor-injected
  `App\Services\DeliveryStatistics` (mirroring `DashboardController`'s T13 constructor-injection
  style), and `show()` gains `window` resolution identical to T13's
  (`AnalyticsWindow::tryFrom((string) $request->query('window')) ?? AnalyticsWindow::default()`)
  and two new Inertia props: `statistics` = `forProxy($proxy, $window)`, `destinations` =
  `destinationBreakdown($proxy, $window)`. Authorization untouched — `$this->authorize('view',
  $proxy)` still runs before either service call, no new gate, no new permission.

  `tests/Feature/Analytics/ProxyShowControllerTest.php` (new) covers: absent `window` → `30d`
  default with 200; malformed `window` (`'garbage'`) → same default, 200 not 422; a valid `window`
  (`'7d'`) honoured; `statistics`/`destinations` scoped to the requested proxy (a second proxy on
  the same team with its own traffic contributes nothing); the permission-denial case via
  `partialMock(ProxyPolicy::class, ...)` forcing `view` to `false` and asserting 403 — the
  established technique (`testing_patterns.md`/`ProxyAuthorizationTest::
  test_store_is_denied_when_the_create_policy_denies`) for proving `authorize()` is genuinely wired
  when every real role already holds the permission being tested, so a role-based denial is
  unreachable; a direct assertion that `TeamPermission::cases()` is the exact, unchanged
  fourteen-case list (AC24 — no new permission introduced by this task's diff, checked
  independently of the policy-mock test above); the query-count assertion at N=1 vs. N=10
  destinations on the proxy (same count, `DeliveryStatistics::destinationBreakdown()`, T8, is
  already O(1) in destination count); and a sanity check that the existing `proxy`/`permissions`
  props are untouched. `tests/Feature/Proxies/ProxyIndexShowTest.php` itself needed no edits and
  stays green unmodified.

  Verified: `composer lint`, `composer types:check` (PHPStan level 7, no suppressions),
  `./vendor/bin/sail test --filter "ProxyShowControllerTest|ProxyIndexShowTest"` (18/18) all
  green; full `./vendor/bin/sail test --parallel` 825/825 (up from 817 after T17).

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
- **Completion notes:** Implemented the "Analytics" card as one `Card` directly in
  `resources/js/pages/proxies/Show.vue`, inserted immediately after the header block and before
  the existing "Ingest URL" card (flagged design call 3's accepted reordering). Every label comes
  from `analyticsLabels.ts` (T12) — the same constants and formatting functions T14/T16 already
  use on the Dashboard, so wording matches byte-for-byte by construction, not by duplication.
  Structure follows design-11 Screen 2's mockup literally: `h2 "Analytics"` plus the window
  selector as the card's first row (mirrors the mockup's ASCII layout, which nests `[24h] [7d]
  [30d]` inside the "Card 'Analytics'" block, unlike Dashboard where the selector sits above every
  card since Dashboard has several windowed cards); the two-tier headline + bridge sentence (T14's
  shape); a densified trend table (see flagged deviation below); `h3 "Retry & replay"` + "Last
  {window}" subtitle + four tiles (T16's shape); `h3 "Latency"` + subtitle + the two `dt`/`dd`
  pairs + caption (T16's shape).

  **Two deliberate reads, flagged rather than silently decided:**

  1. **The trend/series accessible table is included in this task, even though T19's own
     Description/Acceptance-Criteria/Testing text never names it** (it lists only "headline +
     bridge sentence (T14)," "Retry & replay tiles (T16)," and "Latency block (T16)" as the shape
     to match — omitting T17's Trend card entirely). Three independent sources say it belongs here:
     design-11 Screen 2's own mockup shows `[dual-line trend chart] + "View as table"` inside the
     Analytics card; Flow C step 3 describes it as part of the same flow ("Sees the daily trend
     chart... Each row of the chart's 'View as table' fallback is a link into Flow E"); and T28's
     own description assumes it already exists — "Both trend cards (Dashboard's 'Trend' card, T17;
     **Proxy Show's Analytics card trend block, T19**)... render `TrendChart.vue` **above** the
     already-shipped 'View as table' Collapsible." No task in M4 (T18–T20) other than T19 could
     plausibly have built it, and `statistics.series` (T7/T18) is already on the page's props with
     nothing else to consume it. Reading T19's own bullet list as an incomplete shape-reference
     (three examples of "match the Dashboard," not an exhaustive one) rather than a deliberate
     scope cut is the only reading consistent with an already-approved design and a later task's
     explicit back-reference — implemented rather than escalated, since design-11 and T28 already
     settle what belongs here; nothing was invented. Built identically to T17's Dashboard table:
     `Date | Delivery success | Attempt success`, `compactRateText()` cells, rendered
     `default-open` (no chart yet, T27/T28), with the same code comment marking it for
     collapsed-by-default once the chart lands. No per-day drill-through links yet — T23's own
     Files list already names `proxies/Show.vue` for wiring those, matching T17's Dashboard table
     at the same stage.
  2. **The window selector stays visible in the zero-traffic-for-this-proxy collapsed state**,
     rather than disappearing along with the figures. Design-11's literal text ("the entire
     Analytics card collapses to one message... no chart shell, no zeroed tiles, no latency block")
     could be read as removing everything including the selector, but the same paragraph also
     calls the selector "(page-level for this page)" — a control for the whole page's context, not
     one of the collapsing card's own figure blocks — and a member has no other way to check
     whether a different window has traffic if it vanishes. This mirrors Dashboard's own "no
     proxies at all" vs. "zero deliveries in window" distinction (design-11 lines 292–319): the
     selector is removed only when there is nothing to window over at all (Dashboard's "no proxies"
     state), never merely because the current window is empty. Implemented by rendering the
     selector unconditionally at the top of the card and gating only the figure content
     (`v-if="!props.statistics.hasTraffic"` vs. the rest) below it.

  `canDrillThrough`-style gating was not needed for the Retry & replay card's Terminal failure tile
  link (the only failure-shaped tile, Flow C step 4): this page only renders for a live proxy in
  the first place — the route's implicit model binding 404s on a soft-deleted one (T22) — so
  drill-through is always available from here, unlike the Dashboard's Proxies-table row which must
  handle both live and deleted proxies in the same table.

  **Manual verification** (recipe in agent memory `manual_verification_recipe.md`): removed
  `public/hot` (absent already, confirmed), ran `pnpm run build`, seeded a throwaway team via
  `sail tinker` with two proxies — "Traffic Proxy" (one delivery succeeded on attempt 2: one failed
  attempt at 200 ms, one succeeded at 300 ms) and "Zero Proxy" (a destination, zero deliveries).
  Logged in via Playwright:

  - **Traffic proxy, card position:** page heading order read `Traffic Proxy` (h1), `Analytics`,
    `Ingest URL`, `Response`, `Destinations`, `Retry policy` — Analytics leads, ahead of Ingest URL,
    exactly as design-11 requires.
  - **Traffic proxy, headline:** `100%` / "1 of 1 delivered · last 30 days" and `50%` / "1 of 2
    attempts succeeded · last 30 days" — the seeded delivery's correct split; bridge sentence read
    "1 attempt failed before these deliveries succeeded — see Retry & replay below."
  - **Traffic proxy, trend table:** exactly 30 rows (`Jul 28` through `Aug 26` inclusive, the
    session's current date), every day but the last reading "No deliveries yet" on both columns,
    `Aug 26` reading `100% (1/1)` / `50% (1/2)` — genuine server-side densification, not a
    coincidentally-complete raw aggregate.
  - **Traffic proxy, Retry & replay tiles:** Eventual success `1`, Terminal failure `0`, Retry
    volume `1`, Live vs replay `"1 live · 0 replay"` — all four independently correct against the
    fixture.
  - **Traffic proxy, Latency:** Average `250 ms` (exact mean of 200/300), 95th percentile
    `300 ms` (nearest-rank at `n = 2`, ordinal `2` — the larger of the two values) — confirming the
    percentile reads correctly at this boundary `n`, not just a plausible-looking number.
  - **Window selector:** navigating to `?window=7d` moved `aria-current="true"` to the `7d` button
    and the Retry & replay/Latency subtitles updated to "Last 7 days".
  - **Zero-traffic proxy:** the Analytics card rendered exactly `Analytics` + the three window
    buttons (still present and clickable) + the single message "No deliveries to this proxy in the
    last 30 days. Figures appear once it receives and delivers a webhook." — zero `dl` elements in
    the card (asserted via a `0`-count Playwright locator check, not merely "not visually
    noticed"), confirming no chart shell, no tiles, no latency block rendered alongside it.
  - Screenshots inspected in both light and dark theme for both proxies — legible, correct card
    ordering and content, no colour-only distinction relied upon anywhere.

  Cleaned up the throwaway team/proxies/destinations/deliveries/attempts/user afterward
  (`forceDelete()`, children before parents).

  Verified: `pnpm lint:check`, `pnpm types:check`, `pnpm format:check` all green (one file needed a
  Prettier re-format after the manual edit, applied and re-verified). Backend suite unaffected by
  this frontend-only task; `./vendor/bin/sail test --filter "ProxyShowControllerTest|ProxyIndexShowTest"`
  (18/18) re-run as a sanity check, unchanged and green.

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
- **Completion notes:** Implemented the "Destinations" card as a `Table` directly in
  `resources/js/pages/proxies/Show.vue`, replacing the plain `ul`: `Destination | Delivery success |
  Attempt success | Latency (avg) | Actions`, one row per `props.destinations` (T18's
  `DestinationBreakdownRow[]`) — never `props.proxy.destinations` (that relation stays live-only,
  shared with `index()`/`edit()`, plan Implementation Note 11). Column headers carry the unit
  (`DELIVERY_SUCCESS_COLUMN_LABEL`/`ATTEMPT_SUCCESS_COLUMN_LABEL` from T12/T15, plus a new
  `LATENCY_AVERAGE_COLUMN_LABEL` added to `analyticsLabels.ts` for this table's "Latency (avg)"
  header) so no per-cell relabelling is needed (AC14(a)). Rate cells reuse `compactRateText()`
  (T15) and the latency cell reuses `formatLatencyMs()` (T12) — both already produce the correct
  zero-traffic text ("No deliveries yet" / "No data") without a template-level branch, matching
  T15's own completion notes anticipating this table would reuse the same compact convention rather
  than inventing its own. No column-click sorting exists on this table (unlike the Dashboard's
  Proxies table, T15) — row order is exactly `props.destinations`' order (live destinations first,
  then any trashed-but-active ones, per T8's service-level union), matching flagged design call 5's
  reasoning applied to this table too, per the task's own text.

  The **Actions** column header is rendered as plain visible text ("Actions"), not `sr-only` —
  deliberately different from the just-fixed Dashboard Proxies-table header (this session's Work
  item 1). Design-11 line 369 writes this table's header row as `Destination | Delivery success |
  Attempt success | Latency (avg) | Actions` with no parentheses around "Actions", unlike Screen
  1's `(View)`, which is the same textual signal the Owner's ruling on the Dashboard fix turned on —
  a bare, unparenthesised label reads as a genuine fifth column header, matching `proxies/
  Index.vue`'s existing visible "Actions" precedent rather than the parenthesised, sr-only-only
  "(View)" case.

  **Actions cell composition follows design-11's Row-content description literally**, which places
  the muted **Deleted** label in the Actions cell alongside the still-functional **View events**
  link — not beside the destination name/badge, unlike the Dashboard Proxies table's deleted-row
  treatment (T15). `viewEventsHref()` carries `proxy` (route param) · `destination` (id, query) ·
  `window` (query) — **no** `outcome` parameter (Flow D step 3: this row's figures are rates over
  all of that destination's traffic, not a failure count, so the action is total-shaped) — and is
  identical for a live or deleted destination, since soft delete preserves the id and
  `destinationBreakdown()` (T8) already resolves both. No `canDrillThrough`-style gate exists on
  this link at all (design-11: "its View events link stays live... a soft delete preserves the
  id") — unlike the Dashboard Proxies table's per-row gate, which exists because that table must
  also disable the *proxy* name/edit link for a deleted parent; nothing analogous applies to a
  destination row here. `ProxyEventController` does not yet read `?destination=`/`?window=`
  (T21) — this link is built now and wired the rest of the way later, the same "builds the link,
  T23/T21 finish the wiring" pattern already established by T15/T19's own Terminal-failures links.

  **Observed, not a defect:** the five-column table (`Destination` cell includes a method `Badge` +
  a URL, four narrower cells beside it) exceeds the Show page's `max-w-3xl` container width on a
  typical viewport, so the table's existing `overflow-x-auto` wrapper (`components/ui/table/
  Table.vue`, unmodified, already used by every other table in this feature) engages a horizontal
  scrollbar within the card rather than clipping content — confirmed via `scrollWidth` (`959px`) >
  `clientWidth` (`686px`) on the table container, not a hard visual cut with no way to reach the
  rest. This is the existing `Table` primitive's own standing behaviour app-wide, not something
  this task's own Acceptance Criteria ask to change, so it was left as-is rather than redesigning
  the container or dropping a column.

  **Manual verification** (recipe in agent memory `manual_verification_recipe.md`): removed
  `public/hot` (absent already, confirmed), ran `pnpm run build`, seeded a throwaway team via
  `sail tinker` with one proxy and three destinations — a live destination with traffic (one
  succeeded delivery/attempt, 150 ms), a live destination with zero traffic, and a destination with
  traffic (one succeeded delivery/attempt, 400 ms) that was then soft-deleted — deliberately not
  present in `props.proxy.destinations`' live set once deleted, so its row can only come from the
  analytics prop. Logged in via Playwright:

  - **Headers:** `["Destination", "Delivery success", "Attempt success", "Latency (avg)",
    "Actions"]` — exact column order.
  - **Live traffic-destination row:** `100% (1/1)` / `100% (1/1)` / `150 ms`, `View events` link
    with no `Deleted` badge.
  - **Live zero-traffic-destination row:** `No deliveries yet` / `No deliveries yet` / `No data` —
    not `0%`, not hidden (present as its own row).
  - **Deleted destination row (traffic):** rendered as its own row — present at all only because it
    came from `props.destinations`, not `props.proxy.destinations` — reading `100% (1/1)` /
    `100% (1/1)` / `400 ms`, a muted **Deleted** `Badge` plus a still-present, correctly-hrefed
    **View events** link in the same Actions cell.
  - **Link hrefs:** every row's `View events` link read
    `/{team}/proxies/{proxy}/events?window=30d&destination={id}` — `window` and `destination`
    present, `outcome` absent from every row including the deleted one, confirming Flow D step 3's
    "no outcome filter" rule holds uniformly.
  - Screenshots inspected in both light and dark theme — legible, badge and muted-link text
    visually distinct without relying on colour alone; the horizontal-scroll behaviour noted above
    confirmed present in both.

  Cleaned up the throwaway team/proxy/destinations/deliveries/attempts/user afterward
  (`forceDelete()`, children before parents, including a stray destination row left over from an
  earlier seed attempt that hit an invalid `HttpMethod` enum value before erroring — `Destination`
  only accepts `POST`/`PUT`, not `GET`).

  Verified: `composer lint`, `composer types:check` unaffected (frontend-only task; not re-run
  here). `pnpm lint:check`, `pnpm types:check`, `pnpm format:check` all green (one file needed a
  Prettier re-format after the manual edit, applied and re-verified).
  `./vendor/bin/sail test --filter "ProxyShowControllerTest|ProxyIndexShowTest"` (18/18) re-run as
  a sanity check, unchanged and green; full `./vendor/bin/sail test --parallel` 825/825 (unchanged
  from T18 — no backend file touched by T19/T20).

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
- **Completion notes:** Implemented the private filter resolver directly in `ProxyEventController`
  as described — `resolveFilters()` turns `window`/`destination`/`outcome` into (a) a nullable
  predicate closure and (b) the `EventListFilters` chip descriptors from one place, so the query and
  the chips can never disagree. `resolveDestination()` is
  `Destination::withTrashed()->where('proxy_id', $proxy->id)->find($id)` exactly as plan-11 §
  Validation specifies (`Q-11-03(9)`'s destination half); `resolveOutcomeUnit()` matches
  `delivery_failed`/`attempt_failed` against the two known tokens, anything else dropping the
  filter. `applyFilters()` implements both of Architecture E's exact shapes: no outcome active —
  `window` narrows `webhook_events.received_at`, `destination` (if present) narrows via
  `whereHas('deliveries', ...)` on `destination_id`; outcome active — the window moves inside a
  `whereIn` subquery over the failing records at the outcome's own unit (`deliveries.updated_at`
  delivery-grain, `delivery_attempts.updated_at` via `ingest_id` attempt-grain), narrowed by
  `destination_id` inside the subquery too when both filters are present — matching the same
  predicate the source figure used (AC10). The attempt-grain subquery matches on `ingest_id`, never
  `delivery_id`, so a pre-#6 row (`delivery_id = NULL`) is included by construction, and a replayed
  attempt (dispatched under the event's existing `ingest_id`, `ProxyEventReplayController`) matches
  too. `->withQueryString()` is added to the paginator so an active filter survives a page-2
  navigation.

  **One interpretive call, flagged rather than silently decided — reconciling this task's own AC28
  bullet with plan-11 § Architecture E's more general prose.** Architecture E states "the window
  applies to `webhook_events.received_at` when no Outcome chip is active," and `window` "always
  resolves" (ruling 8) — read completely literally, that would mean every request, including one
  with no query string at all, gets a default-30-day `received_at` filter applied. But this task's
  own Acceptance Criteria state, verbatim: "The Events list without any filter parameter renders
  byte-identical props to today's shipped surface (AC28) — no column, badge, action, or pagination
  change." Today's shipped surface has no time-window concept at all — every event ever received
  shows, paginated newest-first — so applying an implicit 30-day cutoff on a bare, no-parameter
  request would silently drop any event older than 30 days, which is not "byte-identical" under any
  reading and would be a real regression on a team with older history. `design-11` Screen 4's own
  state list resolves this the same way independently: "Arrived directly (no filter): no chip row
  renders — visually identical to today," distinct from "Arrived via drill-through, filters applied"
  — and every one of Flow E's five named entry points carries `destination` and/or `outcome`
  alongside `window`; none carries `window` alone. Implemented accordingly: filtering (both the
  `received_at`/`whereHas` narrowing and the outcome subquery) activates iff `destination` or
  `outcome` resolved to a real value; a bare request, or one where `destination`/`outcome` both
  failed to resolve (unknown id, unknown token), runs the pre-#11 query unmodified. `window` itself
  is still always resolved into `EventListFilters::$window` (ruling 8 holds for the DTO), it simply
  does not by itself flip the query into "filtered" mode — matching the design's own "arrived
  directly" state and this task's own literal AC28 bullet exactly, and never diverging from any
  named Flow E entry point since none of them is `window`-only. Recorded here per the "flag rather
  than decide" convention in case the Principal Engineer intended literal, unconditional
  `received_at` narrowing instead.

  `tests/Feature/Analytics/ProxyEventDrillThroughTest.php` (new, 8 tests) covers: the delivery-grain
  subquery in isolation; the attempt-grain subquery including both the eventual-success case (a
  failed attempt behind an overall-succeeded delivery) and the pre-#6 `delivery_id = NULL` case,
  both matching, with a succeeded-only control event excluded; the window-travels-on-`updated_at`
  case (an event received 40 days ago whose delivery terminalized today still matches the
  outcome-filtered default 30-day window); the destination-only filter in isolation; the unknown-id
  and unknown-token drop-the-filter cases (200, no chip, un-narrowed result); the true
  no-parameter-at-all case (an event received 90 days ago still renders, proving no implicit
  windowing occurred); and the `withQueryString()` pagination case (20 matching events, page 1 of 2,
  every rendered pagination link carries `outcome=delivery_failed`).

  Verified: `composer lint`, `composer types:check` (PHPStan level 7, no suppressions),
  `./vendor/bin/sail test --filter "ProxyEvent"` (55/55, includes this task's 8 plus every
  pre-existing `ProxyEvents`/`Replay` test unmodified and green); full `./vendor/bin/sail test
  --parallel` 833/833 (up from 825 after T20).

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
- **Completion notes:** No production file touched, as scoped — T8 and T21 already built
  everything this task verifies. `tests/Feature/Analytics/DeletedParentDrillThroughTest.php` (new,
  3 tests) drives all three bullets end to end through real routes:

  - **Deleted destination:** soft-deletes a destination with historical delivery traffic, hits
    `proxies.events.index` with `?destination={id}`, and asserts the list resolves and filters
    correctly (only the destination's own event returned, a second destination's event excluded)
    and the chip descriptor reads `filters.destination.isDeleted === true` — proving T21's
    `Destination::withTrashed()` resolution keeps the drill-through live exactly as `Q-11-03(9)`
    rules.
  - **Deleted proxy:** soft-deletes a proxy with historical traffic alongside a second, live proxy,
    hits the real `dashboard` route, and asserts the deleted proxy's breakdown row still carries its
    figures (`delivery.total === 1`) and its `isDeleted`/`Deleted` label, but `canDrillThrough ===
    false`, while the live proxy's row reads `canDrillThrough === true` — end-to-end confirmation of
    T8's `proxyBreakdown()` through the actual controller/prop path, not just the service-level unit
    test T8 already has.
  - **Route degradation is real, not cosmetic:** soft-deletes a proxy with no traffic and asserts
    `proxies.events.index` for that proxy id returns a genuine 404 — Laravel's default implicit
    route-model binding already excludes trashed models (no code change was needed for this to hold,
    confirming `Q-11-03(9)`'s proxy-half ruling that making the route resolve a trashed proxy would
    be the larger, out-of-scope change, not something this milestone does).

  Verified: `composer lint`, `composer types:check` (PHPStan level 7, no suppressions),
  `./vendor/bin/sail test --filter DeletedParentDrillThroughTest` (3/3); full `./vendor/bin/sail
  test --parallel` 836/836 (up from 833 after T21).

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
- **Completion notes:** **Complete — all five entry points design-11's Flow E table names are
  wired.** The fifth (the Trend chart's per-day, per-unit row) was blocked on `Q-11-04` at first
  pass; the question resolved (Principal Engineer, plan-11 Revision A) and that entry point is now
  wired too — see the dedicated section below. Wired four of the five entry points on the first
  pass:

  - **Dashboard Proxies table's Terminal failures (deliveries) cell** (`proxyFailuresHref()`,
    `Dashboard.vue`) — added `outcome=delivery_failed` alongside the `window` T15 already carried.
  - **Proxy Show Retry & replay's Terminal failure tile** (`terminalFailureHref()`, `proxies/
    Show.vue`) — same change, T16/T19's existing `window`-only href.
  - **The three non-failure-shaped Retry & replay tiles and the Dashboard's Delivery/Attempt success
    cells** — confirmed, not re-implemented, already correct as plain text with no `Link` wrapper
    (T15/T16/T19's own work); no diff needed.
  - **Destinations table's View events action** — confirmed, not re-implemented, `viewEventsHref()`
    (T20) already carries proxy · destination · window with no `outcome` parameter, exactly as Flow
    D step 3 and this task's own AC require.

  **Was blocked, escalated rather than guessed at, and is now resolved: the Trend chart's "View as
  table" row, per day per unit.** `docs/questions/prd-11-q-11-04-trend-day-drill-through.md` (new,
  directed to the Principal Engineer) — design-11's Flow C step 3 and Flow E table both require this
  entry point to carry "window narrowed to that single day," but plan-11 §§ Architecture E, API,
  Services & Actions and Validation all defined the Events list's filter resolver (T21, already
  implemented) as taking exactly three query parameters — `window` (one of `AnalyticsWindow`'s three
  fixed values), `destination`, `outcome` — with no mechanism for narrowing to a single calendar
  day. `AnalyticsWindow::tryFrom()` silently falls back to the 30-day default on any value it
  doesn't recognise (ruling 8), so routing a literal date through the existing `window` parameter
  would not narrow anything — it would silently resolve to the wrong window, exactly the "silently
  wrong answer" ruling 3 says this feature must not produce. Inventing a fourth query parameter here
  would have extended T21's already-implemented, task-approved public interface beyond what any
  approved artifact specifies, not a purely local implementation detail left open by the task —
  squarely the "plan conflicts with reality... pause the affected task" escalation rule, directed to
  the Principal Engineer as plan-11's owner.

  **Manual verification** (recipe in agent memory `manual_verification_recipe.md`): `public/hot`
  absent already (confirmed), ran `pnpm run build`, seeded a throwaway team via `sail tinker` with
  one proxy, one destination, and one failed delivery. Logged in via Playwright:

  - Read the Dashboard's Terminal-failures cell href directly:
    `/boyer-group/proxies/51/events?window=30d&outcome=delivery_failed`. Clicked it; landed on
    `http://localhost/boyer-group/proxies/51/events?outcome=delivery_failed&window=30d` — the
    Events list for the correct proxy, both query parameters present.
  - Read the Proxy Show page's Retry & replay Terminal-failure tile href directly: identical
    `?window=30d&outcome=delivery_failed`; clicked it, landed on the same URL shape.
  - The Events list itself does not yet render an Outcome chip or explanatory copy for either
    landing (T24, next) — confirmed absent as expected at this stage, not a defect in this task.

  Cleaned up the throwaway team/proxy/destination/event/delivery/user afterward (`forceDelete()`,
  children before parents).

  Verified: `pnpm lint:check`, `pnpm types:check`, `pnpm format:check` all green. Backend suite
  unaffected by this frontend-only task; not re-run here.

  ### Resolution — `Q-11-04`, plan-11 Revision A: the fifth entry point wired

  `Q-11-04` resolved (Principal Engineer, plan-11 Revision A, Technical ruling 10): a fourth,
  optional `date` query parameter (ISO `Y-m-d`, the same string `SeriesPoint.date` already carries).
  A resolved `date` **replaces** the window's range bound with the half-open interval `[that day
  00:00, next day 00:00)` in the application timezone. This is not purely a T23 change — the ruling
  widens T21's resolver (its own task, already Done) and T24's chip rendering (its own task, already
  Done) as well as T23's own hrefs, so the touched files go beyond this task's originally-listed
  `Dashboard.vue`/`Show.vue`, per Technical ruling 10's own scope statement. Touched, beyond this
  task's own two files:

  - **`app/Http/Controllers/ProxyEventController.php`** (T21's file) — `resolveDate()` (new private
    method) parses `date` strictly: `CarbonImmutable::createFromFormat('Y-m-d', $value)` is lenient
    (it accepts `2026-8-4` and silently rolls an out-of-range value like `2026-13-45` over into a
    different date), so the parsed value is reformatted back to `Y-m-d` and compared against the raw
    input — anything that doesn't round-trip exactly drops the filter (ruling 8), never a 422.
    `resolveFilters()`'s "arrived directly" short-circuit widened to require `destination`,
    `outcome` **and** `date` all unresolved before it returns a `null` predicate — a `date` alone is
    a real narrowing and must run. `applyFilters()` builds `$start`/`$end` exactly once; a resolved
    `date` substitutes `[day, day + 1)` for the window's `[now - window, now)` at that single point,
    with no second range clause added elsewhere. The day bound is applied via a new private
    `applyRangeBound()` helper with `$halfOpen = true` (`>=`/`<`, never `whereBetween`), so no
    instant at a day boundary belongs to two days or to neither. **One thing the ruling doesn't spell
    out and had to be decided locally**: the *window*'s own existing bound could not be switched to
    the same half-open shape — `$end` there is `CarbonImmutable::now()` computed at request time, a
    moving target that a just-created row's `updated_at` (also second-precision, truncated the same
    way) can land on exactly, and a first attempt at a uniform half-open bound turned that up as a
    real regression (four pre-existing tests started failing — a delivery created and queried inside
    the same wall-clock second was excluded by a strict `<` against `now()`). `applyRangeBound()`
    therefore keeps the window bound's existing inclusive `whereBetween` shape (`$halfOpen = false`)
    and only the day bound is half-open — matching the ruling's actual concern (a day boundary must
    not double- or non-count a record) without changing the window bound's already-certified,
    already-tested behaviour.
  - **`app/Data/Analytics/EventListFilters.php`** (T21's file) — gained `public ?string $day`.
  - **`resources/js/types/analytics.ts`** — `EventListFilters.day: string | null`.
  - **`resources/js/pages/proxies/Show.vue`** (this task's own file) — the trend table's Delivery
    success and Attempt success cells are now each wrapped in a `Link` (`trendDayHref(point, unit)`)
    carrying `window` (still emitted, per ruling 10 — "the period a member returns to"), `date`
    (`point.date`, verbatim) and `outcome` (`delivery_failed`/`attempt_failed` per column). No
    `canDrillThrough` gate, for the same reason `terminalFailureHref()` above has none: this page
    only renders for a live proxy.
  - **`resources/js/pages/Dashboard.vue`** — **not touched for this ruling.** The C1 re-check states
    the Dashboard's team-grained trend chart is not a drill-through entry point at all (no single
    proxy resolves from a team-grained series), so its trend table's rows carry no link — only its
    Terminal-failures cell (already wired, first pass) does. T23's own task text names
    `Dashboard.vue` in its Files list for that cell, not for its trend rows; followed the ruling over
    the task text and record it here as directed by the ruling itself.
  - **`resources/js/data/analyticsLabels.ts`** — added `formatSeriesDate()`, moved here from a
    near-identical copy each of `Dashboard.vue` and `Show.vue` carried locally, because ruling 10 and
    plan Implementation Note 20 require the trend table's Date column and the day-narrowed Window
    chip to render a day exactly the same way — one formatter, so the two surfaces cannot disagree.
  - **`resources/js/pages/proxies/events/Index.vue`** (T24's file) — the day is **not** a fourth
    chip (ruling 10; design-11 Screen 4 fixes the chip row at three). `hasActiveFilters` widened to
    include `filters.day !== null`. The existing Window chip's value (`windowChipValue` computed)
    reads the day-formatted string when `filters.day` is present, otherwise its prior "last {window}"
    text — same chip, same `×`, no new chip. `filterHref('window')` now also drops `date` when
    dropping `window`, so that chip's `×` removes both together, exactly as the ruling states.

  New backend test group in `tests/Feature/Analytics/ProxyEventDrillThroughTest.php` (8 tests,
  named "Day narrowing"): exact record-set narrowing at both units; the half-open boundary
  (`>= start`/`< end`) with fixtures at the target day's exact midnight, one second before the next
  midnight, one second before the target midnight, and exactly the next midnight; five malformed
  `date` shapes (`2026-8-4`, `yesterday`, `2026-13-45`, an ISO-8601 timestamp, an empty string), each
  asserted to drop to no-day-narrowing and 200, never 422; a well-formed `date` outside the resolved
  window narrowing to that day rather than being dropped, including the "narrows to a visibly empty
  day" case; conjunctive composition with `destination` and `outcome`; `?date=` alone narrowing
  without either of the other two (the widened short-circuit); and survival across pagination.

  **Manual verification** (recipe in agent memory `manual_verification_recipe.md`): `public/hot`
  absent already (confirmed), ran `pnpm run build`, seeded a throwaway team via `sail tinker` with
  one proxy, one destination, and two failed deliveries three and four days ago (`updated_at`
  backdated via factory state). Logged in via Playwright, on the Proxy Show page with the trend
  table default-open (T27/T28 not yet landed — the "View as table" trigger toggles it *closed* at
  this stage, so the check reads the table's initial open state rather than clicking the trigger):

  - The three-days-ago row's Delivery success cell (`"0% (0/1)"`) is a real anchor:
    `?window=30d&date=2026-08-23&outcome=delivery_failed`; the Attempt success cell in the same row
    (`"No deliveries yet"` — no attempt row was seeded, only a delivery) is also a real anchor:
    `?window=30d&date=2026-08-23&outcome=attempt_failed` — confirming the link exists regardless of
    whether that unit's own rate is null, which is correct: the link targets the failure *count* at
    that unit, not that cell's own rate.
  - Clicking the Delivery success cell landed on the Events list with chip row exactly `"Window:
    Aug 23, 2026 × Outcome: Terminal failure (deliveries) ×"` and exactly one matching event (the
    three-days-ago one, not the four-days-ago one) — the day-narrowed Window chip renders the day,
    not "last 30 days," and the four-days-ago event is correctly excluded.
  - Clicking the Attempt success cell for the same row produced the same URL shape with
    `outcome=attempt_failed` and the chip read `"Outcome: Terminal failure (attempts)"`.
  - Direct navigation to the same three-parameter URL reproduced both the chip text and the single
    matching row exactly.
  - Clicking the Window chip's `×` (`aria-label="Remove window filter"`) navigated to
    `?outcome=delivery_failed` only — confirmed via a `page.on('request', ...)` listener on the real
    outgoing `GET` — both `window` and `date` dropped together in one click, per ruling 10.
  - Dark mode: toggled `document.documentElement.classList.add('dark')`, waited 400 ms, screenshotted
    the full Proxy Show page — no console/page errors, the two new links render with the same
    `hover:underline`-only styling (no distinct link colour) as every other drill-through link this
    feature already shipped (Dashboard's Terminal-failures cell, the Retry & replay tile), so no new
    contrast question is introduced.

  Cleaned up the throwaway team/proxy/destination/events/deliveries/user afterward (`forceDelete()`,
  children before parents).

  Verified: `composer lint`, `composer types:check` (PHPStan level 7, no suppressions),
  `./vendor/bin/sail test --filter "ProxyEventDrillThroughTest"` (16/16, including the 8 new
  day-narrowing tests), `./vendor/bin/sail test --filter "ProxyEvent"` (63/63), full
  `./vendor/bin/sail test --parallel` **844/844** (up from 836 after T22); `pnpm lint:check`,
  `pnpm types:check`, `pnpm format:check` all green (Prettier reformatted `Dashboard.vue` and
  `proxies/Show.vue` after the manual edits, re-verified).

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
- **Completion notes:** Implemented directly in `resources/js/pages/proxies/events/Index.vue`, the
  page's own new `filters: EventListFilters` prop (T21) driving everything below — no separate
  `FilterChips.vue` component (the task's own Files list names only this one file; the composition
  is small enough, and single-surface enough, to stay inline, matching design-11's own "no existing
  chip/tag primitive... built from `Badge` + a button" guidance literally).

  **Chip row** (`hasActiveFilters` computed — `true` iff `filters.destination !== null ||
  filters.outcome !== null`, matching T21's own "arrived directly" reading, so the chip row and the
  query narrowing can never disagree about whether filtering is active): up to three `Badge`s —
  Window (always present once the row renders, reading `windowLabel()` from `analyticsLabels.ts`,
  T12, so the wording stays in lockstep with `AnalyticsWindow::label()`), Destination (`v-if`
  `filters.destination`), Outcome (`v-if` `filters.outcome`, reading the server-resolved `label`
  verbatim — "Terminal failure (deliveries)"/"(attempts)" — prefixed with "Outcome: " in the
  template, per design-11 Screen 4's exact chip text). Each chip's remove control is a real
  `<button type="button">` (native `Enter`/`Space` handling, matching T15's sortable-header
  precedent) carrying a discernible `aria-label` — `"Remove window filter"`, `` `Remove destination
  filter: ${url}` `` (the destination's own url, per design-11 § Accessibility's exact wording),
  `"Remove outcome filter"` — never a bare icon-only `×`; the visible `×` glyph sits alongside the
  label, not instead of it.

  **`filterHref(remove)`** rebuilds the Events list URL from the *currently active* filter values
  minus the one being removed, so a removal is a genuine re-navigation (design-11 § Interactions —
  "not a client-side row filter") rather than client-side chip hiding; `remove: 'all'` (the
  "Clear filters" link) drops every parameter, landing on the bare, unfiltered route. `outcome`'s
  round-trip back to a query token (`outcomeQueryToken()`: `'delivery'` → `'delivery_failed'`,
  `'attempt'` → `'attempt_failed'`) is the inverse of `ProxyEventController::resolveOutcomeUnit()`
  (T21) — the only place that mapping exists on the frontend, since every other frontend href (T23)
  only ever *sets* the token, never needs to read one back out of a resolved `unit`.

  **Explanatory line** (`outcomeExplanatoryLine` computed, `null` when no Outcome chip is active):
  the delivery-grain wording is design-11 Screen 4's exact copy, verbatim; the attempt-grain wording
  is the same sentence with "delivery" swapped for "attempt" — T24's own task text names this as an
  open substitution ("adjusted per attempt-grain wording, C1(b))") without spelling out the second
  sentence, and a straightforward parallel construction is the only reading available, so it was
  implemented rather than escalated.

  **Empty-filtered state**: the existing "No events yet" card branches on `hasActiveFilters` — a
  filtered-empty arrival reads "No events match these filters" plus adjusted helper text and a
  **Clear filters** `Link` (`filterHref('all')`) in place of the unfiltered card's "View ingest URL"
  button (swapped, not appended — a member who drilled in via a filter needs to clear it, not find
  the ingest URL); an unfiltered empty arrival is byte-identical to the pre-#11 card. The chip row
  itself sits above this branch unconditionally, so it stays visible in the empty-filtered case
  exactly as the AC requires.

  **Manual verification** (recipe in agent memory `manual_verification_recipe.md`): `public/hot`
  absent already (confirmed), ran `pnpm run build`, seeded a throwaway team via `sail tinker` with
  one proxy, two destinations, and two events (one with a failed delivery to destination A, one with
  a succeeded delivery to destination B). Logged in via Playwright:

  - **Arrived unfiltered:** `[aria-label="Active filters"]` matched 0 elements, both events rendered
    in the table — visually and structurally identical to the pre-#11 surface (AC28).
  - **Outcome filter** (`?outcome=delivery_failed`): chip row read exactly `"Window: last 30 days ×
    Outcome: Terminal failure (deliveries) ×"`, the delivery-grain explanatory line rendered, and
    the table showed exactly the one matching event — screenshots inspected in both light and dark
    theme (both legible, chip and remove-glyph contrast fine in dark).
  - **Removing the Outcome chip:** clicking its remove button navigated to `?window=30d` (the
    `outcome` parameter genuinely dropped from the URL, not hidden client-side) and, since neither
    `destination` nor `outcome` remained resolved, the chip row disappeared entirely on the next
    render — confirming `window` alone never keeps the row "active," matching T21's own reading.
  - **Destination filter:** chip read `"Window: last 30 days × Destination: POST
    https://a.example.com/hook ×"`, and the destination remove button's `aria-label` read exactly
    `"Remove destination filter: https://a.example.com/hook"`.
  - **Empty-filtered state** (`?outcome=attempt_failed`, matching nothing in this fixture): heading
    read "No events match these filters," a **Clear filters** link was present, the chip row (and
    the attempt-grain explanatory line, "...matching attempt...") stayed visible above the empty
    card, and clicking **Clear filters** navigated to the bare `/events` route with no query string
    at all.

  Cleaned up the throwaway team/proxy/destinations/events/deliveries/user afterward
  (`forceDelete()`, children before parents).

  Verified: `pnpm lint:check`, `pnpm types:check`, `pnpm format:check` all green (one file needed a
  Prettier re-format after the manual edit, applied and re-verified).
  `./vendor/bin/sail test --filter "ProxyEvent"` re-run as a sanity check (55/55, unchanged) — this
  task touches no production PHP file.

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

