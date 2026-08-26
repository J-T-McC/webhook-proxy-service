# Technical Plan: Analytics / stats — item #11

- **Status:** **Approved (Principal-Engineer self-certified) — except the two items at
  *Handoff → Owner-approval flags (✋)*, which are the Project Owner's to rule on.** Like #8 and
  unlike #7, this plan **cannot** be self-certified in full: it adds four database indexes, and
  `CLAUDE.md` makes any data-model change an Owner-approval gate that no delegated plan gate
  covers; and it adopts the first charting library this project has ever had, which is a
  new-dependency gate on the same list. Everything else is self-certified and needs no further
  sign-off. Both gates are stated once, in full, where the Owner reads them — see **§ Data Model**
  and **§ Owner-approval flags (✋)**.
- **Author:** Principal Engineer
- **Date:** 2026-08-26
- **PRD:** `docs/product/prd-11-analytics.md` — **Approved** (Project Owner, 2026-08-26), 37
  acceptance criteria, ratifying **D-11-1..7**, plus **`## Amendment A`** (Product Manager,
  2026-08-26). **Amendment A governs where it and a criterion's literal wording differ**, and this
  plan is written that way: rates with a zero denominator are `null`, never `0%` (A(i)), and the
  daily series and the percentile are obliged at the **team and proxy** grains only (A(ii)).
  Numbering frozen; nothing here renumbers, adds, removes or weakens a criterion.
- **Design spec:** `docs/design/design-11-analytics.md` — **fully approved** (Product Manager,
  2026-08-26; design gate delegated per `CLAUDE.md`). All six corrections C1–C6 landed and **C1 is
  cleared** on the section-scoped re-check of Flow E and Screen 4. As with plan-08, the spec's
  **approval record governs over the spec body** where they could differ, and in particular the
  rulings on the nine flagged design calls are read as binding — including the two whose **trigger
  is mine**, calls 7 and 8, which this plan pulls (see § *Technical rulings* 4 and 5). This plan
  builds the surfaces the spec specifies and redesigns none of them.
- **Question resolved here:** `docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md`
  — **RESOLVED** (Principal Engineer, 2026-08-26), all ten items, including the two the design
  gate folded in: (9) the soft-deleted-parent drill-through and (10) the Outcome filter. The
  answers are the evidence base for §§ *Architecture*, *Technical rulings* and *Data Model*; this
  plan does not restate the working.
- **ADRs:** **none new.** ADR-003, ADR-012, ADR-014, ADR-015, ADR-016, ADR-017 and ADR-018 are all
  binding, all unamended, and none is superseded. The candidates were walked one by one against
  the ADR bar — see § *Why no ADR was warranted here when the previous item needed one*.
- **Approved by / date:** Principal Engineer, 2026-08-26 — **partial**, see Status.

## Overview

#11 is read-only. It adds no table, no column, no runtime behaviour on the ingest or delivery
path, and no new page. It adds **one service** that turns the records #1/#4/#6 already emit into
the figures the design specifies, **four indexes** so those aggregates read a bounded index range
instead of a table, and **props on three existing surfaces** — the Dashboard, the proxy Show page,
and the shipped Events list, which gains the ability to arrive pre-filtered.

Every figure is computed **live, per request**, from `deliveries` and `delivery_attempts`, with
the two counting units taken from **two disjoint tables** so that AC13/AC14's "never derived from,
converted into, or reconciled against the other" is a structural property rather than a rule
somebody has to keep. AC20's high-percentile figure is a **true, exact percentile** by the
nearest-rank method, computed live; there is no rollup, no cache and no derived record anywhere,
so **AC11's "as of" caption is not required and is not rendered**. Soft-deleted proxies and
destinations stay in every figure because no aggregate joins a parent at all — the aggregates
group by the `proxy_id` / `destination_id` integers on the fact rows, and labels are resolved
separately with `withTrashed()`.

The two things this plan needs the Project Owner for are the four indexes and the charting
library. Neither is pre-approved by the PRD, the design spec or the Owner's V7 ruling.

## What is already settled, and by whom

Restated once so every section below reads as a consequence rather than a choice:

- **The Project Owner (2026-08-26, `Q-11-01` = roadmap V7):** Tier 3; **both** counting units,
  labelled distinctly, never a toggle; per-destination split; daily trend; drill-through;
  retry/terminal/replay insight; latency with a high percentile; **export declined**.
- **The Project Owner (2026-08-26, `Q-11-02` = roadmap V8, deferral renewed):** no numeric target,
  no verdict layer, four definitions fixed.
- **The Project Owner (2026-08-26, D-11-5 / AC18):** statistics are retained **indefinitely** at
  #11. The technical half was left to me and is answered at `Q-11-03(1)`; § *Risks* R1 carries it.
- **The Product Manager (2026-08-26):** the seven PM-derived calls D-11-1..7, ratified by the
  PRD's approval; **Amendment A(i)** (a zero-denominator rate is not a percentage) and
  **A(ii)** (the daily series and the percentile are obliged at team and proxy grain only);
  and the design-gate rulings on all nine flagged design calls.
- **The Designer (2026-08-26, approved):** the screens, states, flows, labels, empty states,
  chip behaviour and accessibility rules of `design-11`, with C1–C6 landed.
- **The Principal Engineer (this plan and `Q-11-03`):** the query shape, the anchor timestamp, the
  percentile method, live-versus-rollup, the latency grain, the indexes, and the two drill-through
  feasibility answers.

Nothing in this plan reopens any of the above. Where the plan rules on something the upstream
artifacts left silent, it says so by name in § *Technical rulings* and states why the ruling stays
inside their assumptions.

## Architecture

### A. One service, two disjoint tables, no joins to a parent (AC1, AC5, AC10, AC13, AC14, AC23)

All aggregation lives in **one service**, `App\Services\DeliveryStatistics` — stateless, no HTTP
knowledge, consistent with `docs/standards/architecture.md`'s Services layer and with this
project's single-resolver habit (`RetryPolicy`, `StoredPayloadLookup`, `RetentionPolicy`). Every
controller that shows a figure calls it; no controller builds an analytics query of its own, and
no Eloquent model gains an analytics scope. That single choke point is what makes the invariants
below testable in one place instead of audited across three surfaces — and it is what lets a
future rollup (§ *Risks* R1) swap in behind an unchanged interface.

**The two units come from two tables and never meet.**

| Unit | Source table | Population | Denominator |
|---|---|---|---|
| **Delivery-level** (headline) | `deliveries` | `status IN ('succeeded','failed')` | those rows |
| **Attempt-level** (destination health) | `delivery_attempts` | `status IN ('succeeded','failed')` | those rows |

No query joins the two for a success/failure figure. `pending`/`retrying` deliveries and
`dispatched` attempts are excluded by the status predicate, never counted as failures (AC13).
Pre-#6 attempt rows (`delivery_id` NULL) are excluded from delivery-level figures and included in
attempt-level ones **structurally**, because the delivery-level query never reads
`delivery_attempts` and the attempt-level query never reads `deliveries` — there is no clause to
forget (`Q-11-03(4)`).

**Two derived figures bridge the tables, deliberately and only these two.** *Eventual success*
(AC19(a)) is delivery-grained and asks whether a succeeded delivery took two or more attempts, so
it tests `EXISTS (delivery_attempts WHERE delivery_id = deliveries.id AND attempt_number >= 2)` —
served by `UNIQUE(delivery_id, attempt_number)`. The Screen 1/2 *bridge sentence* counts failed
attempts belonging to the window's **succeeded deliveries** ("14 attempts failed before these
deliveries succeeded"), which is descriptive, not arithmetic converting one unit into the other —
the binding condition the Product Manager attached to flagged design call 1.

**No aggregate joins or eager-loads `proxies` or `destinations`.** Aggregates group by the
`proxy_id` / `destination_id` **integer columns on the fact rows**. Labels are resolved in a
second query over exactly the id set an aggregate returned, with `withTrashed()`, and a row whose
`deleted_at` is set renders the **Deleted** label (AC6, `Q-11-03(2)`). `withTrashed()` therefore
appears exactly twice in the whole feature, where its absence is obvious, rather than once per
aggregate where its absence is a silently wrong number. The FKs restrict, so a label always
resolves.

### B. The figure set, per grain, and the query that produces each (AC7, AC15, AC16, AC19, AC20)

Grains are **team**, **proxy** and **destination-within-a-proxy** (AC15). Windows are 24 h / 7 d /
30 d, default 30 d (AC17). Buckets are one point per day (AC16). Under **Amendment A(ii)** the
daily series and the percentile are obliged at **team and proxy** only; the destination grain
carries both units and an **average** duration.

| Figure | Grain(s) | Source | Shape |
|---|---|---|---|
| Delivery success rate + counts (AC7, AC13) | team · proxy · destination | `deliveries` | one `GROUP BY status, kind` per grain — also yields **terminal failure** (AC19(b)) and **live vs replay** (AC19(d)) with no extra query |
| Attempt success rate + counts (AC7, AC13) | team · proxy · destination | `delivery_attempts` | one `GROUP BY status` per grain, carrying `SUM(attempt_number > 1)` (**retry volume**, AC19(c)), `AVG(duration_ms)` and `COUNT(duration_ms)` in the same pass |
| Eventual success (AC19(a)) | team · proxy | `deliveries` + `EXISTS` on attempts | one count |
| Bridge-sentence failed attempts | team · proxy | `delivery_attempts` joined to the window's succeeded `deliveries` | one count |
| Daily series, both units (AC16) | team · proxy | both tables | two `GROUP BY <day>, status` queries; the server **fills missing days** |
| Average duration (AC20) | team · proxy · destination | `delivery_attempts.duration_ms` | folded into the attempt-level pass |
| 95th percentile (AC20) | team · proxy | `delivery_attempts.duration_ms` | one ordered `LIMIT 1 OFFSET` query — see § *Technical rulings* 4 |
| Per-proxy breakdown rows (Screen 1) | team | both tables | two `GROUP BY proxy_id, status` queries + one label lookup |
| Per-destination breakdown rows (Screen 3) | proxy | both tables | two `GROUP BY destination_id, status` queries + one label lookup |

**Query budget.** The Dashboard resolves in roughly nine to twelve aggregate queries and the proxy
Show page in roughly eight to eleven, every one of them a single grouped aggregate over an index
range. **No query runs per row, per proxy or per destination** — the review-02 M2 lesson is a
binding constraint here, not a preference, because a per-proxy figure on a team dashboard is the
most natural N+1 in the feature. § *Test strategy* asserts the count.

### C. Windowing, anchoring and buckets (AC16, AC17, AC18)

The **anchor** — the timestamp that decides which window and which day-bucket a record falls in —
is `updated_at` on both fact tables, read only for records already in a resolved/terminal status.
The full reasoning and the rejected alternative are at § *Technical rulings* 1. Two consequences
matter architecturally: a past bucket can never change once written, and the four new indexes lead
with the grain column, carry `status`, and range on `updated_at` (§ *Data Model*).

Day buckets are computed in SQL from the anchor (`DATE(updated_at)`), which is portable across
MySQL 8.0 and SQLite, and the **series is densified in PHP**: a day with no traffic is a real
point carrying zero counts and a `null` rate, never a gap the chart interpolates across. AC18's
"never present a truncated trend as a complete one" holds trivially at #11 — no horizon exists —
and the densification is what stops a sparse `GROUP BY` from *looking* like one.

### D. Read surfaces — extend, never annex (AC21, AC27, AC28; design § Scope note)

Three surfaces change and no page is created.

**1. `resources/js/pages/Dashboard.vue` (Screen 1).** `DashboardController::__invoke` keeps its
`pendingInvitations` prop and gains the team panel, the per-proxy breakdown rows and the daily
series. The four `PlaceholderPattern` blocks go.

**2. `resources/js/pages/proxies/Show.vue` (Screens 2 and 3).** `ProxyController::show` gains a
proxy-scoped panel and the per-destination breakdown rows. **The Destinations table is *not*
driven from `ProxyResource.destinations`.** Two independent reasons, both load-bearing:
`ProxyResource` is one class serving `index()`, `show()` **and** `edit()`, so anything added to it
lands on write surfaces too; and its `destinations` relation loads **live destinations only**,
which would silently drop exactly the deleted-destination row AC6 requires. The table is driven
from a separate `destinations` key on the analytics prop, whose row set is the **union** of the
proxy's live destinations and every `destination_id` with activity in the window, resolved
`withTrashed()`. A live destination with no traffic is a row reading "No deliveries yet"; a
deleted destination with traffic is a row labelled **Deleted**.

**3. `resources/js/pages/proxies/events/Index.vue` (Screen 4).** `ProxyEventController::index`
gains three optional filters — window, destination, outcome — and emits the active-filter chip
descriptors. Everything else on that surface is untouched: table columns, badges, the replay
action, the FIFO alert, pagination, the event detail, and the masked payload viewer with its
reveal (AC28; settled at #6/Q-06-02 and #10's to change).

### E. Drill-through — filter from the narrow side (AC10, AC21; design Flow E)

The entry points, their shapes and the filters each carries are fixed by `design-11` Flow E's
table and are not re-decided here. What is decided here is how the list is narrowed, and the shape
is a **subquery over the failing records**, not a correlated scan of the event list:

- **delivery grain** — `webhook_events.id IN (SELECT webhook_event_id FROM deliveries WHERE
  proxy_id = ? AND status = 'failed' AND updated_at BETWEEN ?)`, reading the new
  `deliveries (proxy_id, status, updated_at)` index;
- **attempt grain** — `webhook_events.ingest_id IN (SELECT ingest_id FROM delivery_attempts WHERE
  proxy_id = ? AND status = 'failed' AND updated_at BETWEEN ?)`, reading the new
  `delivery_attempts (proxy_id, status, updated_at)` index. A replay is dispatched under the
  **event's existing `ingest_id`** (`ProxyEventReplayController`), so this matches original,
  replayed and pre-#6 attempts alike — exactly the population AC13 puts in the attempt-level
  figure.

Each drill-through therefore selects events through **the same predicate as the figure it came
from**, which is what makes AC10 hold at the record-set level. `design-11`'s C1(b) statement — the
row count is not the figure's count, because one event can hold several matching deliveries —
remains exactly right and unchanged. Window and destination filtering need no subquery: the
destination is a column on the fact rows and the window applies to `webhook_events.received_at`
when no Outcome chip is active (§ *Technical rulings* 3).

**Deleted parents at this layer split** (`Q-11-03(9)`): a **deleted destination**'s *View events*
link stays live, because the destination travels as a query filter on a live proxy's route and
soft delete preserves the id. A **deleted proxy** takes `design-11`'s pre-approved degradation —
its Dashboard row keeps its figures and its **Deleted** label but its links are muted and
non-interactive — because making the proxy route resolve a trashed model would surface the shipped
Replay affordance against a deleted proxy, whose own `POST` route still binds a live one. § *Test
strategy* covers both halves.

## Technical rulings (named, recorded — not silent design)

Each of these settles something the PRD or the design spec left to Technical Design. Each states
why it stays inside the upstream artifacts' assumptions and therefore does **not** route back
upstream; where a ruling has a user-visible consequence, that consequence is named.

**1. The window anchor is `updated_at` on both fact tables, read only for resolved/terminal rows —
and the alternative is recorded as rejected, not overlooked.** PRD § Definitions is explicit:
delivery-level counts deliveries that **reached a terminal state** in the window; attempt-level
counts attempts that **resolved** in the window. Both are resolution-time statements, and
resolution time is exactly what `updated_at` holds, on both tables, for a different reason each
time. On `deliveries`, every status write is a compare-and-set keyed on a **non-terminal** prior
status (`DeliverToDestination::transition()` and `RetryDelivery`'s exhaustion CAS both key on
`pending`/`retrying`), so a terminal row cannot be transitioned again. On `delivery_attempts`, the
row is created `dispatched` and settled exactly once by `DeliverToDestination::send()`, and the
redelivery path (`resume()`) returns early unless the row is still `dispatched` — so a resolved
attempt is never rewritten either. GC writes neither table (ADR-012 Decision 5). In both cases
`updated_at` is frozen at the moment of resolution.

*Rejected: anchoring on `created_at`.* It is immutable, which would spare the index entry from
moving on each non-terminal transition, and it would attribute an outcome to the day the traffic
arrived. It was rejected because it makes **past buckets mutable**: a delivery created on Monday
and still retrying on Tuesday is absent from Monday's bucket today and present in it tomorrow, so
a chart a member looked at yesterday quietly changes. A trend whose history rewrites itself is
worse than a small write cost, and the write cost is bounded by the attempt limit per delivery.
*Consequence, stated rather than buried:* the four new indexes are maintained on a mutable column,
so a status transition moves an index entry. That is attached to the Owner gate in § *Data Model*.

*Invariant this ruling depends on, and which § Test strategy pins:* **no code may write a
`deliveries` or `delivery_attempts` row that is already in a terminal/resolved status.** It is
true today by construction; if it ever stops being true, every historical figure moves.

**2. Figures are computed live, per request. There is no rollup, no cache, no derived record —
and therefore no "as of" caption.** (`Q-11-03(6)`; pulls `design-11` flagged design call 8, whose
trigger the Product Manager assigned to me.) The smallest design that satisfies the criteria wins:
live computation satisfies all of them, while a rollup would add a write path, a schedule, a
staleness window and a reconciliation question to a feature whose premise (AC5) is that it writes
nothing. The percentile was the figure most likely to force a rollup and it does not (ruling 4).

*User-visible consequence, so the Designer and the Reviewer need not infer it:* the conditional
"Figures as of {timestamp}" slot on Screens 1 and 2 **renders nothing**. Under the Product
Manager's ruling on flagged call 8 the caption "may be omitted entirely" on the live branch, and
it is omitted. **AC11 is satisfied vacuously** — no number on the surface is served from a cached
or derived source. If a rollup is ever introduced, AC11 activates and the caption becomes
mandatory and concrete; that is recorded as the standing condition on any future rollup at
§ *Risks* R1.

**3. When an Outcome chip is active, the drill-through's window travels at the figure's own
anchor; otherwise it applies to `webhook_events.received_at`.** The Events list is a list of
**events**, so its natural window column is `received_at` — correct for the total-shaped
destination entry point. But a terminal failure reached today can belong to an event received last
week, so applying `received_at` to a failure drill-through would filter out the very records the
member clicked on: a silently wrong answer rather than a visible one. With an Outcome chip active
the window therefore moves **inside the subquery**, onto the same `updated_at` predicate the
figure used, which is also what makes AC10's reconciliation exact (§ *Architecture E*).

This is query semantics only. It changes no chip, no label, no copy and no entry point, so it
stays inside `design-11`'s approved Flow E and Screen 4 and does not return to the Designer.

**4. AC20's high-percentile figure is a true, exact percentile by the nearest-rank method —
`design-11`'s labelled substitute is *not* triggered.** (`Q-11-03(6)`; pulls flagged design call
7.) Neither MySQL 8.0 nor SQLite offers a percentile function, but neither is needed: with `n` =
the count of resolved attempts in the window at that grain — a number the attempt-level pass
already computes — the 95th percentile is the value at ordinal `CEIL(0.95 × n)`, read with
`ORDER BY duration_ms ASC LIMIT 1 OFFSET CEIL(0.95 × n) − 1`. The result is an observed duration,
deterministic and exact, on both engines, with no window-function dependency. `n = 0` is AC12's
"No data" case and the second query does not run.

*Consequences:* the latency block renders **"95th percentile"** with no approximation qualifier
and **must not** be labelled as one; the substitute the spec pre-designed (an approximation, or a
slowest-N list) is not built; and AC20's "a bare average does not satisfy this criterion" is met
by a real percentile rather than by a fallback. Per **Amendment A(ii)** the percentile is obliged
at team and proxy grain only — the Destinations table carries an average, as flagged design call 9
specifies.

**5. Every latency figure at every grain is computed over resolved attempts' `duration_ms`. The
per-delivery span is not displayed at #11.** (`Q-11-03(7)`.) PRD § Definitions gives *delivery
duration* two grains — the per-attempt HTTP send time, and first-attempt-start to terminal outcome
per delivery — and then binds the whole term with "**excludes queue wait time**". Those two halves
agree for a single-attempt delivery and disagree for a retried one: the per-delivery span is
dominated by **retry backoff**, which is scheduled waiting, not sending. A per-delivery figure
would therefore contradict both the definition's own exclusion and the caption the design spec
approves on screen — "Excludes time spent waiting in the queue."

The per-attempt half is also the only *recorded* duration and the one **AC29** traces to a column
by name. This ruling therefore stays inside the settled definition rather than reinterpreting it:
both halves remain correct as definitions, and a later item wanting the end-to-end span is asking
for a **different figure with a different caption**, which is the Product Manager's to request.

**6. A rate with a zero denominator is emitted as `null` by the server, never as `0`.** PRD
**Amendment A(i)** governs the wording; this is its server-side shape, and it matters because of a
trap this codebase has already been bitten by: frontend display helpers key off `null`, and a
resource that emits a plausible-looking number instead of `null` silently changes what every
unconfigured case renders. So `UnitFigure::rate` is `float|null`, `null` meaning *no deliveries in
this window*, and the Vue side renders "No deliveries yet". **Counts are always integers and
always rendered**, including `0` (C3): the four Retry & replay tiles, the Terminal-failures column
and the headline's `0 of 0 delivered` caption all read zero in an empty window. Latency is a
measure figure and reads "No data" (AC12, AC20).

**7. Every analytics query states its `team_id` predicate explicitly; none relies on the
middleware scope for a security property.** `ApplyTeamScope` registers `TeamScope` on `Proxy`,
`Destination` and **`DeliveryAttempt` only** — not on `Delivery` and not on `WebhookEvent`. Half
of #11's fact rows are therefore *not* team-scoped by the middleware, and a plan that leaned on it
would be correct for the attempt-level figures and wrong for the delivery-level ones. Every query
this feature issues carries its own `team_id` (or a `proxy_id` whose proxy was resolved through
the team-scoped, policy-gated binding). The residual `TeamScope` predicate on `DeliveryAttempt` is
harmless duplication; **no analytics query may call `withoutGlobalScope(TeamScope::class)`**.

**8. An absent or unrecognised `window` query parameter falls back to the 30-day default; it never
raises a validation error.** These are GET read surfaces with no form and no error bag, and a
member who edits a URL, follows a stale link or lands on a truncated one must get the page, not a
422. The parameter is resolved through a backed enum (`AnalyticsWindow`) with a default, which is
validation by construction rather than trust. The same rule applies to `destination` and `outcome`
on the Events list: an id that does not belong to this proxy, or an unknown outcome token, drops
the filter rather than failing the request — with the chip absent, so the surface never claims a
filter it did not apply.

**9. Day buckets are days in the application timezone.** No per-user timezone exists anywhere in
the schema, so there is nothing to render them in; `DATE(updated_at)` in the application timezone
is the only honest bucket available, and inventing a per-user timezone would be new capture and a
new requirement. AC8's obligation is met by the window statement the design already requires on
every figure-bearing block (C2), not by a timezone note.

## Data Model

**No new table. No new column. No change to any existing column, enum value, default or
constraint. No backfill and no data migration.** The entire change set is four indexes, added in
one migration, and this is the single place the Owner reads it — it is **Owner-approval flag 2**.

### The complete change set — four indexes, and nothing else

One migration, `database/migrations/2026_08_26_000001_add_analytics_indexes_to_delivery_tables.php`,
portable to MySQL 8.0 and SQLite alike (nothing here needs the raw-`ALTER` treatment
`webhook_events.body` required):

| Table | Index | Laravel default name | Serves |
|---|---|---|---|
| `delivery_attempts` | `(team_id, status, updated_at)` | `delivery_attempts_team_id_status_updated_at_index` | every team-grain attempt-level figure, retry volume, average duration, the percentile |
| `delivery_attempts` | `(proxy_id, status, updated_at)` | `delivery_attempts_proxy_id_status_updated_at_index` | every proxy-grain and destination-grain attempt-level figure, and the **attempt-grain Outcome drill-through** |
| `deliveries` | `(team_id, status, updated_at)` | `deliveries_team_id_status_updated_at_index` | every team-grain delivery-level figure, terminal failure, live-vs-replay |
| `deliveries` | `(proxy_id, status, updated_at)` | `deliveries_proxy_id_status_updated_at_index` | every proxy-grain and destination-grain delivery-level figure, and the **delivery-grain Outcome drill-through** |

Column order is deliberate and is the whole point of the gate: the grain column leads (equality),
`status` follows (a two-value `IN`), `updated_at` ranges last. That is what bounds every analytics
query by *traffic in the window* rather than by table size, and it is why AC18's indefinite
retention costs storage rather than query latency (`Q-11-03(1)`, § *Risks* R1).

`destination_id` is deliberately **not** in any of these. The destination grain is always *within
one proxy*, so the `(proxy_id, status, updated_at)` range is already the row set to group; adding a
fourth column would widen every entry on the delivery hot path to save a grouping step on a set
that is already small.

**Rollback is four `dropIndex` calls**, and the migration's `down()` is exactly that. Nothing
depends on these indexes for correctness — without them every figure returns the same number, more
slowly.

### Explicitly *not* in the change set — verified item by item

- **No new table**, derived, rollup, cached or otherwise (§ *Technical rulings* 2).
- **No new column** on `delivery_attempts`, `deliveries`, `webhook_events`, `proxies`,
  `destinations`, `dispatched_payloads` or `fifo_dispatches` — in particular **no `resolved_at` /
  `terminated_at` column**, which was considered and rejected: it would be new capture on the
  delivery path, which **AC29** and D-11-3 put outside #11, and `updated_at` already carries the
  fact (§ *Technical rulings* 1).
- **No index is dropped or altered.** `delivery_attempts (proxy_id, status)` becomes a strict
  prefix of the new `(proxy_id, status, updated_at)` and is therefore redundant — it is **kept
  anyway**, because dropping it is a non-additive change that would turn a single reversible
  decision into two, and because the space it costs is trivial. Reclaiming it is a separate, later
  decision with its own gate. `(team_id, created_at)`, `ingest_id`,
  `UNIQUE(delivery_id, attempt_number)`, `(webhook_event_id, status)`, `(status, next_attempt_at)`
  and `UNIQUE(dispatch_uuid, destination_id)` are all untouched and all still used.
- **No enum value added** to `delivery_attempts.status`, `deliveries.status`, `deliveries.kind`,
  `fifo_dispatches.status` or any other persisted enum.
- **No FK, no `onDelete` behaviour and no soft-delete flag changes.** Everything in this cluster
  stays RESTRICT, and `delivery_attempts` still has no `deleted_at` (ADR-003).
- **No backfill.** Pre-#6 `delivery_id` NULLs stay NULL (ADR-015's deliberate no-backfill, F4);
  nothing is fabricated for historical rows, per `docs/standards/architecture.md`.
- **No new permission, role or policy class** (AC24, D-11-2), and no new route middleware.

### Every figure traces to an existing column — verified column by column (AC29)

The PRD claims Tier 3 needs no new capture. I checked it rather than inherited it. **It holds for
every figure; nothing on the approved surfaces is unsupported.**

| Figure (AC) | Columns it reads | Exists? |
|---|---|---|
| Delivery success / failure, both counts and rate (AC7, AC13) | `deliveries.status`, `deliveries.team_id`, `deliveries.proxy_id`, `deliveries.destination_id`, `deliveries.updated_at` | Yes |
| Attempt success / failure (AC7, AC13) | `delivery_attempts.status`, `.team_id`, `.proxy_id`, `.destination_id`, `.updated_at` | Yes |
| Terminal failure (AC19(b)) | `deliveries.status = 'failed'` | Yes — a stored fact (ADR-015 D1) |
| Eventual success (AC19(a)) | `deliveries.status`, `delivery_attempts.delivery_id`, `.attempt_number` | Yes |
| Retry volume (AC19(c)) | `delivery_attempts.attempt_number` | Yes |
| Live vs replay (AC19(d)) | `deliveries.kind` | Yes |
| Average duration and 95th percentile (AC20) | `delivery_attempts.duration_ms` | Yes |
| Bridge sentence | `deliveries.status`, `delivery_attempts.status`, `.delivery_id` | Yes |
| Daily series (AC16) | the anchor, `updated_at`, on both tables | Yes |
| Deleted labelling (AC6) | `proxies.deleted_at`, `destinations.deleted_at` | Yes |
| Destination row identity (AC15) | `destinations.url`, `.http_method`, `.proxy_id` | Yes |
| Drill-through, delivery grain (AC21) | `deliveries.webhook_event_id`, `.status`, `.proxy_id`, `.updated_at` | Yes |
| Drill-through, attempt grain (AC21) | `delivery_attempts.ingest_id`, `.status`, `.proxy_id`, `.updated_at`, `webhook_events.ingest_id` | Yes |
| Events-list window filter (Screen 4) | `webhook_events.received_at` | Yes — survives erasure |

**One caveat, stated plainly rather than left as a footnote.** The anchor is `updated_at`, a
framework timestamp rather than a purpose-built column. It carries the fact correctly and it is
frozen once a row is terminal (§ *Technical rulings* 1), so AC29 holds without qualification — but
it is the one column in this table whose suitability rests on an **invariant** rather than on the
column's declared purpose, and that invariant is pinned by a test rather than by the schema. It is
the thinnest part of the "no new capture" claim and the Reviewer should know where it is.

### Security assessment attached to this gate

- **No new at-rest copy of anything.** The indexes contain `team_id`, `proxy_id`, `status` and
  `updated_at` — no payload content, no captured header, no URL, no token, no personal data. AC4's
  constraint on adding payload-carrying fields to attempt records is not approached, let alone
  relaxed.
- **No new egress.** No export ships (AC37), no API route is added, and every figure is delivered
  as an Inertia prop on a page already gated by `EnsureTeamMembership` + `ApplyTeamScope` +
  `ProxyPolicy`.
- **No new read path to payload content.** Nothing in this feature selects `webhook_events.body`
  or `headers`, and no analytics query hydrates a `WebhookEvent` model, so the `encrypted` casts
  are never invoked (`Q-11-03(8)`).
- **The honest cost:** these four indexes are maintained by the ingest/delivery write path — one
  entry per insert, and a moved entry on each status transition (bounded by the proxy's attempt
  limit). That is the price of the anchor ruling and it is named here rather than discovered in
  production.
- **Reversibility:** total. Four `dropIndex` calls restore the schema exactly.

## API

**No new route, no new endpoint, no JSON API.** This is an Inertia application (`docs/standards/
architecture.md` § API design) and every figure arrives as a prop on a page that already exists.
Three existing GET routes gain optional query parameters; nothing gains a mutation.

| Route | Change | Parameters |
|---|---|---|
| `GET /{current_team}/dashboard` (`dashboard`) | new props | `?window=24h\|7d\|30d` (default `30d`) |
| `GET /{current_team}/proxies/{proxy}` (`proxies.show`) | new props | `?window=…` — carried from the Dashboard so the period survives the drill-down (design § Interactions) |
| `GET /{current_team}/proxies/{proxy}/events` (`proxies.events.index`) | new filter props | `?window=…`, `?destination={id}`, `?outcome=delivery_failed\|attempt_failed` |

**Prop shapes.** Computed figures are readonly DTOs in `App\Data\Analytics\*` — the `Data/` layer,
not `Http/Resources/`, because they serialize a computation rather than an Eloquent model; keys are
camelCase, matching `ProxyPermissions`/`TeamPermissions` rather than the snake_case of
`ProxyResource`.

- `StatisticsPanel` — `window`, `delivery: UnitFigure`, `attempt: UnitFigure`,
  `bridgeFailedAttempts: int`, `retryReplay: RetryReplayFigures`, `latency: LatencyFigure`,
  `series: SeriesPoint[]`, `hasTraffic: bool`.
- `UnitFigure` — `succeeded: int`, `failed: int`, `total: int`, `rate: float|null`
  (**`null` when `total === 0`** — § *Technical rulings* 6).
- `RetryReplayFigures` — `eventualSuccess: int`, `terminalFailure: int`, `retryVolume: int`,
  `live: int`, `replay: int`. All counts, all always rendered, `0` in an empty window (C3).
- `LatencyFigure` — `averageMs: int|null`, `p95Ms: int|null`, `sampleCount: int`. Both values
  `null` when `sampleCount === 0`; the surface reads "No data" (AC12, AC20). `p95Ms` is `null` at
  the destination grain by design (Amendment A(ii)).
- `SeriesPoint` — `date` (ISO `Y-m-d`), plus each unit's `succeeded`/`failed`/`rate`. One point per
  day across the window, **densified**, never sparse.
- `ProxyBreakdownRow` — `id`, `name`, `isDeleted: bool`, `delivery: UnitFigure`,
  `attempt: UnitFigure`, `terminalFailures: int`, `canDrillThrough: bool`.
- `DestinationBreakdownRow` — `id`, `url`, `httpMethod`, `isDeleted: bool`, `delivery`, `attempt`,
  `latencyAverageMs: int|null`.
- `EventListFilters` (Events list) — the active chips: `window`, `destination` (`id`, `url`,
  `httpMethod`, `isDeleted`) or `null`, `outcome` (`unit`, `label`) or `null`. A filter that could
  not be resolved is **absent**, so a chip never claims a narrowing the query did not apply
  (§ *Technical rulings* 8).

`canDrillThrough` exists for exactly one reason and is worth naming so it is not mistaken for
display-logic creep: it is `false` for a soft-deleted proxy, encoding `Q-11-03(9)`'s ruling that a
deleted proxy's row keeps its figures but not its links. It is a **fact about the route**, not a
permission — permissions stay in `ProxyPermissions`, computed once per page.

## Services & Actions

**One new service, no new Action.** Nothing here is dispatchable, queued or pipeline-composed, so
`docs/standards/architecture.md`'s rule applies directly: prefer a Service.

**`App\Services\DeliveryStatistics`** — the single resolver for every number #11 displays, in the
same tradition as `RetryPolicy` and `StoredPayloadLookup`. Public surface:

- `forTeam(int $teamId, AnalyticsWindow $window): StatisticsPanel`
- `forProxy(Proxy $proxy, AnalyticsWindow $window): StatisticsPanel`
- `proxyBreakdown(int $teamId, AnalyticsWindow $window): list<ProxyBreakdownRow>`
- `destinationBreakdown(Proxy $proxy, AnalyticsWindow $window): list<DestinationBreakdownRow>`

Private helpers do the grouped aggregates, the percentile read, the densification and the
`withTrashed()` label lookups. **No other class may build an analytics query** — not a controller,
not a model scope, not a Vue component computing a rate from raw counts on the client. A rate
computed in two places is a rate that eventually disagrees with itself, and **AC10** requires the
same metric to read the same on every surface it appears on; one producer is how that is
guaranteed rather than tested for.

**`App\Enums\AnalyticsWindow`** — a string-backed enum (`24h`, `7d`, `30d`) carrying its own
`label()`, `days()`/`interval()` and `default()`. Not persisted anywhere, so it is not a data-model
change; it exists so an unrecognised query parameter is impossible to propagate rather than merely
validated against.

**`App\Http\Controllers\ProxyEventController`** gains a private filter resolver that turns the
three query parameters into (a) query predicates and (b) the `EventListFilters` chip descriptors,
from one place, so the chips and the query can never disagree about what was applied.

Unchanged and untouched: `ProcessIngestedWebhook`, `DeliverStep`, `DeliverToDestination`,
`RetryDelivery`, `SweepDueRetries`, `AdvanceProxyFifoQueue`, `SweepStalledFifoDispatches`,
`PurgeExpiredPayloads`, `RetryPolicy`, `StoredPayloadLookup`, `RetentionPolicy`,
`WebhookEventCapture`, `PipelineFactory`, every Action, every Event and every model.

## Validation

There is nothing to validate in the Form Request sense: #11 accepts no input, writes nothing, and
adds no mutation. Three read-parameter rules stand in its place, all resolved in the controller
before the service is called, none of which can fail the request (§ *Technical rulings* 8):

- **`window`** — `AnalyticsWindow::tryFrom($value) ?? AnalyticsWindow::default()`. Absent,
  malformed and hostile values all resolve to 30 days.
- **`destination`** — resolved with
  `Destination::withTrashed()->where('proxy_id', $proxy->id)->find($id)`. The `proxy_id`
  predicate is the authorization check that matters here: the proxy is already gated by
  `ProxyPolicy::view`, so a destination belonging to it is in scope and one that does not is
  simply not found. `withTrashed()` is what keeps a deleted destination's drill-through working
  (`Q-11-03(9)`). Unresolved ⇒ no filter and no chip.
- **`outcome`** — matched against the two known tokens (`delivery_failed`, `attempt_failed`).
  Anything else ⇒ no filter and no chip. The token also decides which of the two subqueries runs
  (§ *Architecture E*); there is no third grain and no free-text status.

**Authorization is unchanged and is stated here because "no new gate" is itself a criterion
(AC24, D-11-2).** The Dashboard sits inside the team-prefixed group behind `auth`, `verified`,
`EnsureTeamMembership` and `ApplyTeamScope`; the proxy pages additionally call
`$this->authorize('view', $proxy)` against the existing `ProxyPolicy::view`, which is
permission-based (`TeamPermission::ViewProxy`), never a role check. **No new permission, no new
policy, no new policy method, and no per-row policy evaluation anywhere** — the display flags come
from the page-level `ProxyPermissions` DTO already computed once per page, per ADR-009 Amendment B
and the review-02 M2 ruling.

Team-level figures aggregate over **the current team**, which under the existing model is the same
set as "the proxies the member may read": `ProxyPolicy::view` gates on a team-wide permission, so a
member either reads all of the team's proxies or none of them. There is no per-proxy read list to
intersect with, and #11 must not invent one (AC24).

## Risks

**R1 — Two permanently growing tables, by Owner ruling (AC18, D-11-5; F1).** *Impact:* storage,
backup/restore time and index footprint grow without bound. *Not* query latency: every figure is
bounded by a leading grain equality and a ≤30-day range on the new indexes, so a five-year-old
table answers a 30-day question by reading the same number of index entries as a five-week-old one
(`Q-11-03(1)`). *Mitigation now:* add the indexes **while the tables are small** — the same
`ALTER TABLE` at a hundred million rows is an operations event, and this is the concrete near-term
reason the gate should not be deferred. *Mitigation later, and it attaches without a re-model:* a
forward-only daily rollup keyed on (team, proxy, destination, day, unit) plus a detail horizon.
Three standing conditions on whoever builds it, recorded so they are not rediscovered: the rollup
must carry a **duration distribution, not just a mean**, because a percentile cannot be
reconstructed from counts once detail is pruned; **roll up before pruning, never after**; and a
rollup activates **AC11**, making the "as of" caption mandatory and concrete on every figures block
(§ *Technical rulings* 2). Every FK in the cluster is RESTRICT, so a prune deletes attempts before
deliveries and deliveries before events.

**R2 — The anchor invariant is not enforced by the schema.** *Impact:* if any future code writes a
`deliveries` or `delivery_attempts` row that is already terminal/resolved, its `updated_at` moves
and it silently changes bucket — rewriting history that a member has already seen. *Today it holds
by construction:* every status write is a compare-and-set keyed on a non-terminal prior status, and
GC never writes either table. *Mitigation:* a named test asserting a terminal row's `updated_at` is
unchanged by a re-driven settle and by a GC pass, plus an Implementation Note forbidding a blind
`save()` on either table — which plan-06 already made a binding invariant for a different reason.

**R3 — Outcome-filtered pagination on a proxy with many events and few failures.** *Impact:* the
Events list scans events until it fills a page of 15 matches; a proxy with a hundred thousand
events and three failures reads a lot of index to find them. *Mitigation:* the subquery drives from
the **narrow** side (§ *Architecture E*), and the window predicate inside it bounds the candidate
set — a 24-hour terminal-failure filter is inherently small. *Accepted, not eliminated:* at
current scale this is a non-issue, and the tuning step if it ever bites (a covering index on
`deliveries (status, updated_at, webhook_event_id)`) is additive and needs no re-model.

**R4 — Chart behaviour is unverifiable by automated test.** *Impact:* the project has **no
frontend test framework** (`docs/stack/stack.md`; deferred backlog task **T31**), so every chart
behaviour — series colours, dash styles, the accessible table fallback, theme switching — rests on
manual verification. *Compounded by review-07 Finding 8:* with `public/hot` present and a Vite dev
server running, a "verified against a fresh build" claim was served from the dev server, which is
exactly the condition under which the PR #12 colour bug hid. *Mitigation:* the chart-facing tasks
carry an explicit verify step that names removing `public/hot` and running `pnpm build` first; the
colour-resolution rule below removes the specific failure mode; and the accessible data table —
which is server-rendered from the same `SeriesPoint[]` — **is** covered by a backend test, so the
data behind the chart is tested even though the canvas is not.

**R5 — Write-path cost of four indexes on hot tables.** *Impact:* one extra index entry per insert
and a moved entry on each status transition, on the delivery path. *Bounded* by the proxy's attempt
limit; named in the gate's security assessment rather than discovered later. *Alternative
considered and rejected:* anchoring on the immutable `created_at` would avoid the moves and was
rejected for a stronger reason (§ *Technical rulings* 1).

**R6 — AC14 is the criterion most at risk during implementation, not during design.** *Impact:*
the unit-bearing labels the Designer fixed under correction C4 now appear on **two** surfaces
(Dashboard and Show), and a label that drifts on one of them re-creates precisely the 100%-vs-67%
confusion the whole feature exists to prevent. *Mitigation:* `resources/js/data/analyticsLabels.ts`
as the single source for every figure label and unit suffix — the design spec's own endorsed
recommendation, adopted here as a plan requirement rather than a suggestion — plus a test that no
success/failure figure renders without its unit.

**R7 — The natural N+1.** *Impact:* a per-proxy or per-destination figure computed by looping rows
is the obvious implementation and the wrong one. *Mitigation:* the service exposes only
whole-breakdown methods (`proxyBreakdown`, `destinationBreakdown`) returning every row from two
grouped queries; there is deliberately **no** `forDestination(...)` single-row method to call in a
loop. A query-count assertion pins it.

**R8 — Dark-mode colour resolution (the PR #12 lesson).** *Impact:* `getComputedStyle` returns a
custom property **verbatim**, and this project's production minifier has already rewritten
`hsl()` tokens to hex — a hand-written parser that matched `hsl(...)` therefore worked against the
dev server and failed silently in a build. *Mitigation:* the fix is already in this codebase and is
reused rather than reinvented — see § *Implementation Notes*.

## Dependencies

**No new Composer package. No change to `docs/stack/stack.md`'s language, framework, database,
testing or static-analysis rows.** One new **pnpm** dependency pair, which is
**Owner-approval flag 1**.

### The charting library — assessment, and what I recommend

The Project Owner **suggested** `@j-t-mcc/vue3-chartjs`
(https://github.com/J-T-McC/vue3-chartjs) in PRD-11 § Handoff, explicitly as a suggestion and not
a mandate, in no acceptance criterion. `design-11` designs against "a two-series line chart with
per-series colour and line-dash control is available" and names four capabilities for me to
confirm. A suggestion is not the gate clearing itself, so it is recorded here formally.

**Verified from this repository, not assumed:**

- **Vue 3.5.40**, **Vite 8.2.0**, **pnpm 10.19.0** with a committed lockfile. The library requires
  Vue 3 and Chart.js 4; the Vue floor is satisfied with room to spare.
- **No charting library exists today.** Adoption adds **two** packages: `chart.js` and the wrapper.
- **`--chart-1` … `--chart-5` already exist** in `resources/css/app.css`, defined for both themes
  (light at lines 112–116, dark at 156–160) and exposed to Tailwind as `--color-chart-N`. The
  design spec's choice of `chart-1`/`chart-2` needs no new token.
- **Inertia SSR is configured but not wired.** `config/inertia.php` sets `ssr.enabled => true`,
  **but there is no `resources/js/ssr.ts` entrypoint, no `ssr` input in `vite.config.ts`, and no
  `bootstrap/ssr` bundle** — the `build:ssr` script is starter-kit residue. Nothing in this
  application renders server-side today, so the spec's assumption (d) — "no SSR-specific behaviour
  is needed" — is **correct as of now**, and its stated reason (charts render after a fresh Inertia
  page load, never persisted across navigations) is also correct. It is correct *contingently*,
  though, so the plan requires the chart component to create its Chart instance in `onMounted`
  only, which makes it SSR-safe by construction if an entrypoint is ever added.
- **A new npm dependency gets no automated vulnerability scanning here.** `.github/dependabot.yml`
  covers **only** the `github-actions` ecosystem (`docs/stack/stack.md` records this as an open
  gap). Not a reason to decline, but the Owner should rule with it visible.
- **Nothing about the chart can be covered by an automated test** (R4).

**Fit: confirmed, with conditions. I do not find it a poor fit and I am not proposing an
alternative library.** The wrapper is a thin `<canvas>` component over Chart.js; the drawing,
theming and accessibility requirements in `design-11` are ordinary Chart.js line-chart work; and
the maintenance argument that would normally weigh against a small third-party wrapper is much
weaker here than usual, because the package is **the Project Owner's own** — the Owner controls its
release cadence and can fix it. Chart.js 4 itself is ESM and explicitly designed for tree-shaking
via manual registration, which is what makes the bundle cost controllable under Vite 8's Rollup
build.

**Four checks the adopting task must run before the dependency is committed** — stated as
verification steps because they are properties of the packages rather than of this repository, and
I will not assert them from memory:

1. **Resolution.** `pnpm add chart.js @j-t-mcc/vue3-chartjs` resolves `chart.js` at `^4` and
   satisfies the wrapper's Vue 3 peer against 3.5.40 with **no** peer warning and no `--force`.
2. **Registration and tree-shaking.** The app registers only what a line chart needs
   (`LineController`, `LineElement`, `PointElement`, `LinearScale`, `CategoryScale`, `Tooltip`,
   `Legend`) via `Chart.register(...)`, and the wrapper must **not** pull `chart.js/auto`
   internally. **This is the one finding that flips the recommendation**: if the wrapper imports
   the auto bundle, tree-shaking is lost and Option 2 below is the better decision.
3. **Bundle impact, measured not estimated.** Record the gzip delta of `pnpm build` before and
   after in the task's completion note. If the delta materially exceeds what a registered
   line-chart build should cost, check (2) first.
4. **Theming in both themes.** Series colours resolved per the rule in § *Implementation Notes*,
   verified against a **production build with `public/hot` removed** (R4), in light and dark, with
   non-text contrast checked per `design-11` § Accessibility.

**The alternative, named so the Owner can take it instead: adopt `chart.js` only.** The wrapper's
whole job — hold a `<canvas>` ref, construct a `Chart` in `onMounted`, `update()` on prop change,
`destroy()` on unmount — is roughly forty lines of a local `TrendChart.vue`, which this project
would arguably want to own anyway because it must also carry the token-resolution rule and the
`aria-hidden` canvas treatment. That halves the dependency count and removes an unscanned
third-party package from the bundle, at the cost of forty lines the Owner then maintains instead.
**My recommendation is Option 1 (adopt both), on the strength of check (2) passing; Option 2 is a
reasonable ruling and requires no plan change beyond deleting one package name from the task.**
Both options are within `docs/stack/stack.md` (Vue/Vite/pnpm frontend, no stack row changes).

## Implementation Notes

Binding constraints on the Senior Developer. Each traces to a criterion, an ADR or a named prior
defect; none is stylistic.

**Query discipline**

1. **No lock, no transaction, on any analytics path.** No `lockForUpdate()`, no `sharedLock()`, no
   `DB::transaction()` in the read path. A plain non-locking `SELECT` cannot interfere with the
   ADR-012 GC compare-and-set, ADR-015's status CAS, or ADR-016's advancer claim (`Q-11-03(8)`,
   AC5, AC27).
2. **No analytics query may select `webhook_events.body` or `headers`, and no aggregate may
   hydrate a `WebhookEvent` model.** Aggregates select aggregate expressions; the drill-through
   subqueries select `webhook_event_id` / `ingest_id` only. This keeps the `encrypted` casts out of
   the path entirely (AC1, AC4).
3. **No aggregate joins or eager-loads `proxies` or `destinations`.** Group by the `proxy_id` /
   `destination_id` columns on the fact rows. `withTrashed()` appears in exactly two places — the
   two label lookups — and nowhere else (AC6, `Q-11-03(2)`).
4. **Every query carries its own `team_id` (or a policy-gated `proxy_id`).** Never rely on
   `ApplyTeamScope`, which does not scope `Delivery` or `WebhookEvent` at all, and never call
   `withoutGlobalScope(TeamScope::class)` (AC23, § *Technical rulings* 7).
5. **`duration_ms` is nullable.** Guard the average, the count and the percentile with the same
   `whereNotNull('duration_ms')` so all three describe one population; a mean over one set and a
   percentile over another is a figure that disagrees with itself (AC10, AC20).
6. **Never a blind `save()` on `deliveries` or `delivery_attempts`** — already a plan-06 binding
   invariant for CAS correctness, and now also what keeps history from moving (R2).
7. **No per-row query anywhere**, including in a Blade/Vue loop or a resource `map`. Breakdown
   rows come from grouped queries (R7, review-02 M2).

**Prop and rendering discipline**

8. **A rate with a zero denominator is `null`, never `0`** — server-side, in the DTO. Counts are
   always rendered, including `0` (Amendment A(i), C3, § *Technical rulings* 6).
9. **The daily series is densified server-side.** Every day in the window is a point; a day with
   no traffic carries zero counts and a `null` rate (AC16).
10. **The Events-list paginator must carry the active filters** (`->withQueryString()`). The
    shipped list navigates with `router.get(link.url)` over `props.events.links`, so without it
    page 2 silently drops the filter a member arrived with.
11. **The Destinations table is driven by the analytics prop, not `ProxyResource.destinations`.**
    Adding anything to `ProxyResource` lands it on `index()`, `show()` **and** `edit()` at once,
    and its `destinations` relation loads live rows only — which would drop the deleted-destination
    row AC6 requires (§ *Architecture D*).
12. **No control, class, colour or icon anywhere may be conditioned on a figure's value.** A 12%
    card and a 99% card render with identical chrome (AC22(b); `design-11` § Interactions). This
    includes chart line colours, which encode **series**, never magnitude.
13. **Every success, failure and retry figure carries its unit in its own label**, sourced from
    `resources/js/data/analyticsLabels.ts` so the wording cannot drift between the Dashboard and
    the Show page (AC14(a)/(b), correction C4, R6).
14. **The chart canvas is `aria-hidden` and the "View as table" fallback is visible and
    server-rendered** from the same `SeriesPoint[]` the chart plots — not JS-injected, not
    hover-revealed (`design-11` § Accessibility).
15. **The chart component creates its `Chart` in `onMounted` and destroys it on unmount.** Nothing
    chart-related may run at module scope or during render, so the page stays renderable if an
    Inertia SSR entrypoint is ever added (§ *Dependencies*).

**Colour resolution — reuse the fix this codebase already has (R8)**

16. Resolve a series colour by reading the token verbatim
    (`getComputedStyle(document.documentElement).getPropertyValue('--chart-1')`) and then
    **normalising it through the browser** — assign it to a 2D canvas context's `fillStyle` and
    read the value back — rather than by pattern-matching the token text. This is exactly the
    technique already proven in `resources/js/components/welcome/canvasKit.ts` (`readTokens()` plus
    the normaliser behind `withAlpha()`), written there **because** a hand-rolled `hsl()` matcher
    worked against the dev server and failed against a minified build (PR #12). Put the analytics
    copy in `resources/js/lib/chartTokens.ts` rather than importing across from the welcome
    components; extracting the shared normaliser into `lib/` instead is acceptable and preferable
    if the Senior Developer would rather have one implementation. **Re-resolve and update the
    chart when the theme changes** (`useAppearance`), because a chart that caches its palette at
    init looks correct until someone toggles — the same failure `useCanvasIllustration` documents.

**Housekeeping**

17. `PlaceholderPattern` disappears from `Dashboard.vue` entirely; do not leave a placeholder panel
    beside real figures.
18. `composer lint`, `composer types:check` (PHPStan level 7), `pnpm lint:check`,
    `pnpm types:check` and `./vendor/bin/sail test` all green per task, per
    `docs/standards/planning.md`. DTO collection types carry `list<...>` annotations so level 7
    stays satisfied without suppressions.

## Test strategy

Backend only — the project has no frontend test framework (R4, backlog **T31**). Tests are
PHPUnit class-based under `tests/Feature` and `tests/Unit`, grouped by acceptance criterion.

**Separation and lifecycle (AC1, AC2, AC3, AC5)**

- **AC2 — payload expiry never changes a statistic.** Compute every figure at every grain, run
  `PurgeExpiredPayloads` over the events they cover, recompute, assert **numerically identical**.
  This is the criterion's own stated test and it is the single most important test in the feature.
- **AC3 — a cleaned event still counts.** A proxy whose events are all cleaned produces the same
  figures as one whose events are retained, and still resolves to its destinations in the
  breakdown.
- **AC5 — analytics writes nothing.** Snapshot row counts and `updated_at` values across
  `deliveries`, `delivery_attempts`, `webhook_events`, `dispatched_payloads` and
  `fifo_dispatches`, render every #11 surface, assert nothing changed.
- **AC1/AC4 — no payload content anywhere.** Assert the rendered Inertia props for all three pages
  contain no `body`/`headers` key and no captured content.

**Correctness of the two units (AC7, AC9, AC10, AC13, AC14)**

- **The canonical 100%/67% fixture**, built once and reused: one delivery, three attempts, two
  failed, one succeeded. Assert the delivery-level figure reads 100% (1 of 1) and the attempt-level
  figure reads 67% (2 of 3), at team and proxy grain, and that the bridge count is 2.
- **AC13 exclusions** — `pending` and `retrying` deliveries are absent from delivery-level counts
  and are **not** counted as failures; `dispatched` attempts are absent from attempt-level counts.
- **AC13 / F4 — pre-#6 rows.** A `delivery_attempts` row with `delivery_id = NULL` appears in the
  attempt-level denominator and in no delivery-level figure, and contributes zero to retry volume.
- **AC10 — one metric, one value.** The same window and subject produce the same delivery-level
  and attempt-level numbers on the Dashboard props and on the Show props.
- **AC19 — each of the four figures**: eventual success counts only succeeded deliveries with two
  or more attempts; terminal failure counts `deliveries.status = 'failed'`; retry volume counts
  attempts with `attempt_number > 1`; live-vs-replay splits on `deliveries.kind`, with a replay
  fixture asserting it never inflates the live figure.

**Windows, buckets and the anchor (AC16, AC17, § Technical rulings 1)**

- Each of 24 h / 7 d / 30 d selects the right records; an absent or garbage `window` parameter
  yields the 30-day default and a 200, never a 422.
- **Densification** — a window containing a day with no traffic still returns a point for that
  day, with zero counts and a `null` rate.
- **Anchor immutability (R2)** — a delivery that terminalized inside the window stays in its
  bucket after a re-driven settle attempt and after a GC pass; a terminal row's `updated_at` is
  asserted unchanged.
- A delivery created before the window but terminalized inside it **is** counted; one terminalized
  after the window is not.

**Latency (AC12, AC20)**

- **Exact percentile** — a fixture with known `duration_ms` values asserts the nearest-rank
  result exactly, including the small-`n` boundaries (`n = 1`, `n = 2`, `n = 20`).
- Average and percentile are computed over the **same** population (the `whereNotNull` guard).
- A window with no resolved attempts yields `averageMs === null`, `p95Ms === null`,
  `sampleCount === 0` — never `0`.
- Per **Amendment A(ii)**: `p95Ms` is present at team and proxy grain and `null` at the
  destination grain.

**Empty states (AC12, Amendment A(i), correction C3)**

- Zero traffic: every `UnitFigure.rate` is `null`, every count is `0`, all four Retry & replay
  counts are `0` (present, not hidden), latency is `null`.
- A team with no proxies at all renders the no-proxies state and issues no aggregate query.

**Deleted parents (AC6, `Q-11-03(2)`, `Q-11-03(9)`)**

- **The AC6 test:** compute every figure; soft-delete a proxy **and** a destination that both have
  activity in the window; recompute; assert the numbers are identical and both rows are present
  and flagged `isDeleted`.
- A deleted destination's **View events** link resolves and filters correctly
  (`Destination::withTrashed()` + `proxy_id` predicate).
- A deleted proxy's breakdown row carries `canDrillThrough === false`, and the events route for a
  trashed proxy still 404s — i.e. the degradation is real, not cosmetic.

**Drill-through (AC10, AC21, `Q-11-03(10)`)**

- **Delivery-grain outcome filter** returns exactly the events containing at least one delivery
  matching the figure's predicate; **attempt-grain** returns exactly the events containing at
  least one matching attempt, **including** an event whose overall delivery succeeded on a later
  attempt (the C1(b) case) and including a pre-#6 attempt row.
- The window travels at the figure's anchor when an outcome filter is active and on
  `received_at` when it is not (§ *Technical rulings* 3): a delivery terminalized today from an
  event received outside the window **is** returned by the failure drill-through.
- Filters survive pagination (`withQueryString`), and an unknown `destination` or `outcome` value
  drops the filter, renders no chip, and returns 200.
- The Events list without filters renders byte-identically to today's props (the shipped surface
  is unchanged, AC28).

**Access and scoping (AC23, AC24)**

- A member of another team sees none of this team's records in any figure, at any grain, on any
  page — asserted per surface, not once.
- A user without `TeamPermission::ViewProxy` is denied the proxy pages by the existing policy; no
  new permission exists (assert `TeamPermission::cases()` is unchanged).
- A team-less authenticated user gets zero rows, never global ones (the `TeamScope` sentinel
  behaviour, re-asserted through an analytics path).

**Mode independence (AC25, AC26)**

- A **Simple** proxy's retry figures are counted, not gated out.
- **FIFO** and **Async** proxies produce figures through the same path, and no prop, class or copy
  distinguishes them as better or worse.

**Performance shape (R3, R7)**

- A query-count assertion on the Dashboard and on Show: the number of queries does **not** grow
  with the number of proxies or destinations. This is the N+1 tripwire and it is the only
  performance assertion made — consistent with V8's renewed deferral, no query-time **target** is
  asserted anywhere (AC22(a), AC33).

## Explicitly out of scope for this plan

Named so the Task Planner does not infer them and the Reviewer can check the absence:

- **Any export, download, CSV, scheduled report, BI integration, live refresh or polling** —
  ruled out by the Owner (AC37). No affordance, no route, no serializer.
- **Any alert, threshold, notification or emitted analytics event** — #13 (AC31). #11 emits
  nothing, including on the terminal-failure figure.
- **Any per-event-type, per-map or payload-derived figure** — AC32, and not buildable from
  long-lived records (`Q-11-03(3)`).
- **Any statistics-retention window, cap, prune or rollup** — AC18 rules them out at #11; the
  shape a future one should take is recorded at § *Risks* R1 and is not built here.
- **Any new capture on the ingest or delivery path** — AC29/D-11-3. Including the
  `resolved_at`-style column considered and rejected in § *Data Model*.
- **Any change to retention, GC, holds, retry policy, replay, processing mode, the mode attribute,
  or the masked payload viewer and its reveal** — AC27/AC28; #5, #6, #7 and #10 own them.
- **Any second events surface, event-detail view or per-received-event statistic** — AC21/AC28.
- **A per-destination daily series or per-destination percentile** — permitted but not required by
  **Amendment A(ii)**; additive later, not built now.
- **A worst-first default sort, a verdict colour, a badge, a reference line or any evaluative
  wording** — AC22(b) and the binding conditions on flagged design calls 5 and 6.

## Milestones (task-breakdown-ready)

Sequenced so that **only two of the seven are gate-blocked**, and the Task Planner can order the
rest immediately.

| # | Milestone | Blocked by |
|---|---|---|
| **M1** | Analytics indexes migration (§ *Data Model*), with an assertion that every existing index survives | **✋ Owner flag 2** |
| **M2** | `AnalyticsWindow` enum, the `App\Data\Analytics\*` DTOs, and `DeliveryStatistics` with its unit tests — every figure at every grain, no surface yet | — |
| **M3** | Dashboard (Screen 1): controller props, two-unit headline, Proxies table, Retry & replay tiles, Latency block, window selector, empty states. Trend renders as its accessible table only | — |
| **M4** | Proxy Show (Screens 2 and 3): Analytics card in its specified position, extended Destinations table with deleted rows, empty states | — |
| **M5** | Events list (Screen 4) and drill-through (Flows B, C, D, E): filters, chips, count-to-rows copy, `withQueryString`, deleted-parent behaviour both halves | — |
| **M6** | Charting dependency and `TrendChart.vue`: the four verification checks, registration, token resolution, theme change, `aria-hidden` canvas beside the M3/M4 table | **✋ Owner flag 1** |
| **M7** | Whole-surface verification pass against a **production build with `public/hot` removed** (R4): both themes, all three windows, all empty states, contrast | M6 |

M3 and M4 deliberately ship the chart's accessible data table **before** the chart exists. That is
not a workaround: `design-11` § Accessibility makes the table the authoritative representation and
the chart the supplement, so building it first is the right order — and it means a `no` on Owner
flag 1 costs the feature a chart, not a milestone.

## Handoff

- **Inputs:** Approved **PRD-11** (37 ACs, D-11-1..7 ratified) and its **Amendment A**;
  fully-approved **design-11** with its approval record governing and C1–C6 landed and cleared;
  **`Q-11-03`** (RESOLVED here, all ten items); **`Q-11-01`** and **`Q-11-02`** (RESOLVED, Project
  Owner); ADR-003, ADR-010, ADR-012, ADR-013, ADR-014, ADR-015, ADR-016, ADR-017, ADR-018;
  `docs/reviews/review-06-retry-replay.md` and `review-07`'s Finding 8; `docs/standards/`
  (architecture, planning, documentation, design); `docs/stack/stack.md`; and the code on
  `feat/item-11-analytics` — the migration set, `DeliverToDestination`, `RetryDelivery`,
  `AdvanceProxyFifoQueue`, `PurgeExpiredPayloads`, `ProxyEventController`,
  `ProxyEventReplayController`, `DashboardController`, `ProxyController`, `ProxyResource`,
  `ProxyPolicy`, `ApplyTeamScope`, `TeamScope`, `routes/web.php`, `Dashboard.vue`,
  `proxies/Show.vue`, `proxies/events/Index.vue`, `resources/css/app.css`,
  `components/welcome/canvasKit.ts`, `vite.config.ts`, `config/inertia.php`, `package.json`.
- **Outputs:** this plan; the completed Answer block in
  `docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md` (**RESOLVED**). **No ADR** —
  see below. **No new question document**: nothing in PRD-11 or `design-11` needed a ruling from
  the Product Manager or the Designer.
- **Dependencies:** one new pnpm dependency pair, gated (§ *Dependencies*). No Composer change, no
  stack change, no new service, no infrastructure.
- **Outstanding Questions:** **none.** `Q-11-01`, `Q-11-02` and `Q-11-03` are all resolved. The
  two contingencies the design gate left for me are **pulled and recorded**: the latency
  substitute is **not** triggered (a true percentile is feasible) and the "as of" caption is
  **omitted** (figures are live). Item (10) **holds as designed**, so nothing returns to the
  Designer. Item (9) resolves half-and-half and uses only the fallback the spec pre-approved. Two
  items are recorded for awareness and block nothing: ADR-003's "own lifecycle" wording remains
  imprecise and is corrected by whichever future item introduces a bound (`Q-11-03(1)`), and the
  possibility of a cheap non-payload event-type attribute at a later item is recorded rather than
  lost (`Q-11-03(3)`).

### Owner-approval flags (✋) — **two outstanding**

Stated in full, as the house format requires, because this is the single place the Owner reads it.
**This plan is self-certified except for these two items.**

1. **✋ New dependency — the charting library.** Adopt **`chart.js` (^4)** plus
   **`@j-t-mcc/vue3-chartjs`**, as the Owner suggested in PRD-11 § Handoff and as `design-11`
   designs against — **two** pnpm packages into a project with no charting library today. My
   assessment is in § *Dependencies*: **fit confirmed, recommend adopting**, subject to four
   verification checks the adopting task runs before the packages are committed, of which one is
   decisive (if the wrapper pulls `chart.js/auto`, tree-shaking is lost). Verified from the repo
   rather than assumed: Vue 3.5.40 and Vite 8.2.0 satisfy the requirement; the `--chart-1`/
   `--chart-2` tokens already exist for both themes; **Inertia SSR is configured `enabled => true`
   but has no entrypoint and no bundle, so nothing renders server-side today** and the design's
   assumption holds — the plan still requires `onMounted`-only construction so a future SSR entry
   cannot break the page; and a new npm package receives **no automated vulnerability scanning
   here**, because Dependabot covers only `github-actions`. **The alternative ruling available to
   the Owner: adopt `chart.js` alone** and keep a ~40-line local wrapper — one package instead of
   two, at the cost of forty lines this project maintains. Either ruling is buildable with no plan
   change beyond a package name.
2. **✋ Data-model change — four indexes.** `delivery_attempts (team_id, status, updated_at)`,
   `delivery_attempts (proxy_id, status, updated_at)`, `deliveries (team_id, status, updated_at)`,
   `deliveries (proxy_id, status, updated_at)`, in one migration, with the exact definitions and
   the rejected alternatives in § *Data Model*. **Additive only: no table, no column, no enum
   value, no FK, no default and no existing index is added to, changed on, or removed from
   anything; no backfill; rollback is four `dropIndex` calls.** The security assessment attached to
   this gate is in § *Data Model* — no new at-rest copy of any data, no new egress, no new read
   path to payload content — and so is the honest cost: these indexes are maintained by the
   delivery write path, and the anchor column is mutable until a row is terminal. **Timing
   argument the Owner should weigh:** the same `ALTER TABLE` is cheap now and an operations event
   once the tables have grown under AC18's indefinite retention, so deferring this gate makes it
   more expensive rather than cheaper.

**Not tripped, verified item by item against `CLAUDE.md`'s major-decision list:** no Composer
dependency; no stack change (`docs/stack/stack.md` untouched — no row changes, and both dependency
options sit inside the existing Vue/Vite/pnpm frontend); **no new permission, role, policy or
policy method** (AC24); no new route and no new middleware; nothing irreversible — the feature is
read-only and the migration is four droppable indexes; no change to retention, GC, holds, erasure,
retry, replay, processing mode or the mode attribute (AC27); no new payload read surface and no
change to the #6 mask/reveal settlement (AC28); no new egress path (AC37). **V3, V5 and V8 are not
reopened**, and no number appears on screen as a target, baseline or reference (AC22(a)).

### Why no ADR was warranted here, when the previous item needed one

plan-08 carried ADR-019 because #8 created persisted entities, made a hard-to-reverse choice about
how mapping composes into the pipeline, and fixed a user-visible retry-versus-replay boundary that
three prior ADRs had each brushed against. #11's candidates were walked one by one against the same
bar and none clears it:

- **Live computation rather than a rollup** — decides no persisted shape, adds no entity, and is
  reversible by addition: a rollup can be introduced later behind the same service without
  unwinding anything, and the condition it would inherit (AC11's caption) is already written down.
- **The `updated_at` anchor** — a query-semantics reading of a definition the PRD already fixed,
  not a new decision about the data. It changes no column and no write.
- **The nearest-rank percentile** — an implementation of AC20 as written, on the engines the stack
  already names. Choosing a different percentile method later changes one query.
- **The four indexes** — additive, droppable, and already a `CLAUDE.md` Owner gate in their own
  right; an ADR would restate the gate rather than decide anything the gate does not.
- **The charting library** — likewise already an Owner gate; the assessment belongs where the Owner
  rules on it, and `docs/stack/stack.md` has no charting row to amend either way.
- **The latency grain (per-attempt, not per-delivery)** — resolves an internal inconsistency in an
  already-settled definition, in the direction the definition's own exclusion clause and the
  approved on-screen caption both point. It changes no requirement.

**And the ADRs this feature touches, walked explicitly so the answer is "considered", not
"overlooked":** **ADR-003** — its "retained on their own lifecycle" wording is aspirational and
unimplemented (F1), but it is imprecise rather than contradicted, and #11 implements nothing that
falsifies it; **no amendment, and no superseding ADR.** **ADR-012/ADR-014** — GC's write set and
the `payload_cleaned_at` guard are untouched, and ADR-012's own Impact line ("#11 is unaffected by
construction") is confirmed rather than relied upon. **ADR-015/ADR-016** — the CAS transitions and
the FIFO advancer are untouched; #11 depends on their terminal-state invariant and adds a test for
it rather than a change to it. **ADR-017** — the replay model is what makes the attempt-grain
drill-through work through `ingest_id`; unchanged. **ADR-018** — mode is not read by anything in
this feature (AC25).

### Certification (Principal Engineer, 2026-08-26)

I have verified that **PRD-11 is Owner-approved** (2026-08-26, 37 criteria, ratifying D-11-1..7)
and carries **Amendment A**, which governs over any criterion's literal wording it clarifies; and
that **design-11 is fully Product-Manager-approved** (2026-08-26) — the mandatory design gate for
the PRD's UX Direction — with all six corrections landed and **C1 cleared** on the section-scoped
re-check. I have written this plan against the design spec's **approval record** first and its body
second, and I have pulled the two contingencies the Product Manager assigned to me rather than left
them open. I have read ADR-003 and ADR-010–018, the relevant prior plans and reviews, and the
affected code on `feat/item-11-analytics`, and every claim in § *Data Model*'s AC29 trace and in
`Q-11-03`'s answers is a reading of that code.

Every section above traces to PRD-11 acceptance criteria and to the approved design. The nine named
technical rulings stay inside the upstream artifacts' assumptions; none reinterprets a requirement,
a design decision or an Owner ruling, and where one has a user-visible consequence — the omitted
"as of" caption, the un-substituted percentile label, the deleted-proxy link degradation — the
consequence is stated for the Designer and the Reviewer rather than left to be inferred. Nothing
here changes a requirement or reopens `Q-11-01`, `Q-11-02`, PRD-05's retention lifecycle, PRD-06's
retry/replay semantics, or PRD-07's mode model.

**I self-certify this plan under the delegated plan gate in `CLAUDE.md` — except for the two items
above, which I do not and cannot certify.** The carve-out is stated plainly: **#11 adds four
database indexes, and a data-model change is a Project Owner gate that no delegated gate covers;
and it adopts the project's first charting library, which is a new-dependency gate on the same
list.** The Owner must rule on (1) the charting dependency, choosing between adopting both packages
as recommended and adopting `chart.js` alone, and (2) the four-index change set exactly as
enumerated in § *Data Model*. Everything else in this plan needs no further sign-off.

- **Next Agent:** **Task Planner — after Owner approval of items 1 and 2.** M2, M3, M4 and M5 can
  be broken down and sequenced immediately; **M1 waits on flag 2** and **M6/M7 wait on flag 1**.

