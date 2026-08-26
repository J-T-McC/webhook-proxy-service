# Q-11-04: The Trend chart's per-day, per-unit drill-through has no supported query-parameter shape

- **Feature:** analytics (item #11)
- **Requested By:** Senior Developer
- **Directed To:** Principal Engineer
- **Required By:** T23 (`docs/tasks/analytics-tasks.md`), currently in progress on `feat/item-11-analytics`; blocks only the Trend-chart per-day/per-unit portion of that task
- **Priority:** Medium
- **Status:** Open

## Question

`docs/design/design-11-analytics.md` Flow C step 3 and the Flow E entry-point table both require the Trend chart's "View as table" accessible fallback to carry a per-day, per-unit drill-through link into the Events list — "proxy (current) · window narrowed to that single day · outcome = terminal failure, at the clicked cell's unit (delivery- or attempt-level)." `docs/plans/plan-11-analytics.md` § Architecture E, § API, § Services & Actions and § Validation all define the Events list's filter resolver as accepting exactly three query parameters — `window` (one of `AnalyticsWindow`'s three fixed values, `24h`/`7d`/`30d`), `destination`, `outcome` — with no fourth parameter and no mechanism for narrowing the window to a single calendar day. `AnalyticsWindow::tryFrom()` silently falls back to the 30-day default on any value it doesn't recognise (Technical ruling 8), so passing a literal date through the existing `window` parameter would not narrow anything — it would silently resolve to the wrong (30-day) window instead, which is exactly the "silently wrong answer" Technical ruling 3 says this feature must not produce.

Is a fourth query parameter (e.g. a `date` parameter, resolved and applied only when present, narrowing the outcome subquery's `BETWEEN` bound to that single calendar day instead of the resolved `AnalyticsWindow` interval) the intended mechanism, or is there a different shape you intend the per-day link to take? If a new parameter is the right answer, please confirm its name, its resolution rule (an absent or malformed value's fallback — presumably "no day-narrowing, behave as the window/outcome-only case" rather than a 422, consistent with ruling 8), and whether it composes with `destination` the same way `outcome` does.

## Context

This blocks only the Trend-chart entry point named in T23's own Description and Acceptance Criteria (`docs/tasks/analytics-tasks.md` T23) — the row "Trend chart's 'View as table' row, per day per unit ... → proxy · window narrowed to that single day · outcome=delivery_failed or attempt_failed at the clicked cell's unit." It does not block T23's other three entry points (Dashboard Proxies table's Terminal failures cell, Proxy Show's Retry & replay Terminal failure tile, the Destinations table's View events action), which map cleanly onto `ProxyEventController`'s existing three-parameter resolver (T21, already implemented and committed) and are being wired independently of this question. It also does not block T24 (filter-chip rendering on the Events list), which renders whatever `EventListFilters` the controller resolves regardless of how the request arrived, and works correctly today for every entry point except the one this question is about.

Both `plan-11-analytics.md` and `design-11-analytics.md` are fully approved documents, and this is a genuine shape disagreement between them rather than an ambiguity resolvable by re-reading either one more closely — plan-11's own § API table states, verbatim, "Three existing GET routes gain optional query parameters," and lists exactly `?window=…`, `?destination={id}`, `?outcome=delivery_failed|attempt_failed` for the Events list route; § Services & Actions states `ProxyEventController` "gains a private filter resolver that turns the three query parameters into..." (singular, definite "the three"). Neither document anywhere describes a day-granular window. Per the Senior Developer's escalation rule ("Plan conflicts with reality... → question doc to the Principal Engineer; pause the affected task"), this is directed to you as the plan's owner rather than guessed at, since inventing a fourth parameter is an extension of T21's approved public interface (the query-parameter contract), not a purely local implementation detail left open by the task.

## Answer

- **Answered By:** _pending_

