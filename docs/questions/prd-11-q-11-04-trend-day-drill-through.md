# Q-11-04: The Trend chart's per-day, per-unit drill-through has no supported query-parameter shape

- **Feature:** analytics (item #11)
- **Requested By:** Senior Developer
- **Directed To:** Principal Engineer
- **Required By:** T23 (`docs/tasks/analytics-tasks.md`), currently in progress on `feat/item-11-analytics`; blocks only the Trend-chart per-day/per-unit portion of that task
- **Priority:** Medium
- **Status:** **RESOLVED** (Principal Engineer, 2026-08-26)

## Question

`docs/design/design-11-analytics.md` Flow C step 3 and the Flow E entry-point table both require the Trend chart's "View as table" accessible fallback to carry a per-day, per-unit drill-through link into the Events list — "proxy (current) · window narrowed to that single day · outcome = terminal failure, at the clicked cell's unit (delivery- or attempt-level)." `docs/plans/plan-11-analytics.md` § Architecture E, § API, § Services & Actions and § Validation all define the Events list's filter resolver as accepting exactly three query parameters — `window` (one of `AnalyticsWindow`'s three fixed values, `24h`/`7d`/`30d`), `destination`, `outcome` — with no fourth parameter and no mechanism for narrowing the window to a single calendar day. `AnalyticsWindow::tryFrom()` silently falls back to the 30-day default on any value it doesn't recognise (Technical ruling 8), so passing a literal date through the existing `window` parameter would not narrow anything — it would silently resolve to the wrong (30-day) window instead, which is exactly the "silently wrong answer" Technical ruling 3 says this feature must not produce.

Is a fourth query parameter (e.g. a `date` parameter, resolved and applied only when present, narrowing the outcome subquery's `BETWEEN` bound to that single calendar day instead of the resolved `AnalyticsWindow` interval) the intended mechanism, or is there a different shape you intend the per-day link to take? If a new parameter is the right answer, please confirm its name, its resolution rule (an absent or malformed value's fallback — presumably "no day-narrowing, behave as the window/outcome-only case" rather than a 422, consistent with ruling 8), and whether it composes with `destination` the same way `outcome` does.

## Context

This blocks only the Trend-chart entry point named in T23's own Description and Acceptance Criteria (`docs/tasks/analytics-tasks.md` T23) — the row "Trend chart's 'View as table' row, per day per unit ... → proxy · window narrowed to that single day · outcome=delivery_failed or attempt_failed at the clicked cell's unit." It does not block T23's other three entry points (Dashboard Proxies table's Terminal failures cell, Proxy Show's Retry & replay Terminal failure tile, the Destinations table's View events action), which map cleanly onto `ProxyEventController`'s existing three-parameter resolver (T21, already implemented and committed) and are being wired independently of this question. It also does not block T24 (filter-chip rendering on the Events list), which renders whatever `EventListFilters` the controller resolves regardless of how the request arrived, and works correctly today for every entry point except the one this question is about.

Both `plan-11-analytics.md` and `design-11-analytics.md` are fully approved documents, and this is a genuine shape disagreement between them rather than an ambiguity resolvable by re-reading either one more closely — plan-11's own § API table states, verbatim, "Three existing GET routes gain optional query parameters," and lists exactly `?window=…`, `?destination={id}`, `?outcome=delivery_failed|attempt_failed` for the Events list route; § Services & Actions states `ProxyEventController` "gains a private filter resolver that turns the three query parameters into..." (singular, definite "the three"). Neither document anywhere describes a day-granular window. Per the Senior Developer's escalation rule ("Plan conflicts with reality... → question doc to the Principal Engineer; pause the affected task"), this is directed to you as the plan's owner rather than guessed at, since inventing a fourth parameter is an extension of T21's approved public interface (the query-parameter contract), not a purely local implementation detail left open by the task.

## Answer

- **Answered By:** Principal Engineer, 2026-08-26
- **Recorded in:** `docs/plans/plan-11-analytics.md` § *Technical rulings* **10** (new), with
  ruling 8 amended in place and §§ *Architecture E*, *API*, *Services & Actions*, *Validation*,
  *Implementation Notes* (new items 19–20) and *Test strategy* brought into line. The plan carries
  a § *Revision A* table at the top and a § *Re-certification at Revision A* block at the bottom,
  so the plan and this answer cannot drift apart.

**A fourth query parameter is the intended mechanism, and it is named here. The day-narrowing is
not ruled out.** Your reading of the conflict was right in every particular, including that routing
a date through `window` would resolve to the 30-day default and answer a different question
silently. The full ruling is in the plan; what follows is the contract you asked for.

### 1. The mechanism

- **Name:** **`date`**. Value is an ISO-8601 calendar date in `Y-m-d` form. Deliberately the same
  string `SeriesPoint.date` already carries, so a trend row's link is built from that row's own
  `date` value verbatim and there is no second date format to keep in step with the first.
- **Resolution:** parsed strictly — the value is accepted only if it round-trips through `Y-m-d`
  exactly, so `2026-8-4`, a timestamp, a relative word and anything else are not dates here.
  **Absent or malformed means no day-narrowing**: the request resolves precisely as it does today,
  with the range the resolved `AnalyticsWindow` gives it. **Never a 422**, never an exception — your
  expectation was correct and is exactly the reasoning of Technical ruling 8, which is now amended
  to name `date` alongside `destination` and `outcome`. The value either becomes a date object or is
  discarded, so no unparsed string ever reaches a query.
- **Effect:** a resolved `date` **replaces** the window's range bound with the half-open interval
  `[that day 00:00, the next day 00:00)` in the application timezone. Half-open matters: write it as
  `>= start` and `< end`, **not** as an inclusive `whereBetween`, so no instant at a day boundary
  belongs to two days or to neither. That interval is the same partition Technical ruling 9's
  `DATE(updated_at)` bucket produces, which is what makes a day cell's figure and that day's
  drill-through describe the same record set — AC10, at the day grain.
- **Which column it bounds:** whichever column **Technical ruling 3** already selects, with no
  branch of its own — `deliveries.updated_at` or `delivery_attempts.updated_at` inside the outcome
  subquery when an outcome resolved, `webhook_events.received_at` when none did. In
  `ProxyEventController::applyFilters()` this is one substitution at the single place `$start` and
  `$end` are built; nothing else in that method changes.
- **Composition:** conjunctive, and **identical to the way `destination` and `outcome` compose with
  each other** — all four parameters are resolved independently, each applies independently, and one
  that cannot be resolved is dropped without disturbing the others.
- **`window` still travels.** It is still resolved and still emitted, because it is the period a
  member returns to when the day filter is removed; it simply does not bound the query while a
  `date` is resolved.
- **One edge case worth stating, because you will have to choose otherwise:** a well-formed `date`
  that falls **outside** the resolved window is neither an error nor dropped — it narrows to that
  day. That is what "narrowed to that single day" says, the entry point never produces one, and
  narrowing to a day with nothing in it is visible ("No events match these filters") where silently
  widening back to the whole window would not be.

### 2. Two things in the existing code this touches

- **The "arrived directly, no filter" short-circuit widens to four.** `resolveFilters()` currently
  returns a `null` predicate when `$destination === null && $outcomeUnit === null`. It must also
  require that `date` is unresolved. A `date` on its own is a real narrowing and must run.
- **`EventListFilters` gains `day`** (ISO `Y-m-d`, or `null`). Pagination needs nothing new:
  `->withQueryString()` carries a real query parameter already.

### 3. The chip — the day is not a fourth chip

`design-11` Screen 4 fixes the chip row at three ("window, destination, outcome — up to three at
once") and Flow E describes the day as **the window** narrowed, not as a new filter. So a resolved
`date` renders as the **value of the existing Window chip**, in that screen's already-approved
`[Window: {value} ×]` template, and that chip's `×` drops `window` and `date` together. The value
rendered is the same day string the trend table's own Date column renders for that row, sourced
from `resources/js/data/analyticsLabels.ts` per the plan's R6, so the two surfaces cannot disagree
about how a day is written. This uses the approved chip template and the approved three-chip row
rather than adding to either, so **nothing here returns to the Designer**, and `design-11` needs no
change.

### 4. Scope — which trend table, and the chart

- **The entry point is the Proxy Show trend table only.** Flow E's table names "Proxy Show Trend
  chart's 'View as table' row" (Screen 2, Flow C step 3), and the C1 re-check states that the
  **Dashboard's** Trend chart is not a drill-through entry point at all, because no single proxy
  resolves from a team-grained series. The Dashboard trend table's rows therefore carry **no** link.
  T23 lists `Dashboard.vue` among its files for that page's Terminal-failures cell, which you have
  already wired — not for its trend rows.
- **T27 and T28 need nothing from this ruling.** Flow C step 3 says the canvas "carries no click
  target, only its accessible table does"; plan Implementation Note 14 makes the canvas
  `aria-hidden`; and T27's own acceptance criteria already require no `tabindex` and no click
  handler on it. T28's acceptance criteria already require the table's per-day links to be
  unaffected by the chart's arrival, which this ruling satisfies by putting the links only on the
  table. **Giving the canvas a per-point click target would be a design change, not an
  implementation choice** — if a future item wants one, that is the Designer's call through the
  Product Manager, not yours or mine.

### 5. Authority — why this did not need a gate

Walked item by item against `CLAUDE.md`'s major-decision list, and recorded in the plan so it can be
checked rather than taken on trust: no new dependency; no stack change; **no data-model change** —
the day bound reads a strictly narrower range of the same `(proxy_id, status, updated_at)` indexes
the Owner already approved, and adds no column, no index and no migration; **no security surface** —
the parameter carries no authorization, cannot widen any result set, and is parsed or discarded
before it reaches a query, on a route already gated by `EnsureTeamMembership`, `ApplyTeamScope` and
`ProxyPolicy::view`; nothing irreversible. It is an API-contract call inside the Principal
Engineer's decision authority, so it is ruled here on plan authority, with no Owner approval sought
and none needed. It also clears no ADR bar: it decides no persisted shape and applies ruling 3 to a
narrower range rather than making a new decision about the data.

### 6. What this unblocks

**T23's fifth entry point, and nothing else changes.** No task is re-planned, no new task is
created, and T24 is unaffected except that the Window chip now has a second value shape to render.
The plan's § *Test strategy* gains a named day-narrowing group — day-cell/drill-through record-set
equality at both units, the two day boundaries, malformed and out-of-window values, composition with
`destination` and each `outcome` unit, survival across pagination, and `?date=` alone still
narrowing.

**Escalating that you raised this rather than guessing was the right call.** Inventing the parameter
inside T23 would have extended an approved public interface with no record of why the fallback
behaves as it does, and the half-open bound and the not-a-fourth-chip consequence are both things a
reasonable implementation would have got differently.

