# Design Spec: Analytics / stats

- **Status:** Draft — pending Product Manager approval
- **Author:** Designer
- **PRD:** `docs/product/prd-11-analytics.md` (Approved, Project Owner, 2026-08-26)
- **Approved by / date:** _pending_

> **Scope note.** #11 adds **no new page and no new "Analytics" area** (UX Direction —
> "extend, do not annex"). It makes two existing surfaces show what has always been
> recorded, and extends a third's read capability:
> **(1)** the team **Dashboard** (`resources/js/pages/Dashboard.vue`) — today four
> `PlaceholderPattern` blocks — becomes the team-level figures surface;
> **(2)** the proxy **Show** page (`resources/js/pages/proxies/Show.vue`) gains one new
> **Analytics** card (the per-proxy figures) and an **extension** of the existing
> **Destinations** card (the per-destination breakdown, AC15);
> **(3)** the existing **Events** list (`resources/js/pages/proxies/events/Index.vue`)
> gains the ability to arrive **pre-filtered** (by window and, where relevant, by
> destination) so a figure's drill-through lands on the evidence, not just the list
> (AC21) — no new list, no new detail page, no change to the masked payload viewer
> (AC28, settled at #6/#10). **No export affordance exists anywhere in this spec**
> (AC37) — there is deliberately no button, menu item, or link that downloads a
> figure or a row set.

## Decisions carried forward (Owner rulings; not re-litigated here)
Restated so this spec's choices read as consequences, not inventions:
- **Tier 3, both units, both binding (Q-11-01, Q-11-02).** Counts, per-destination
  split, daily trend, drill-through, retry/terminal/replay insight, latency with a
  high percentile. **Both** the delivery-level and attempt-level figures are always
  shown together, distinctly labelled, never as a toggle/tab/dropdown pair (AC13/AC14).
- **No verdict (AC22).** No threshold colour, no badge, no reference line, no
  "good"/"bad" language, no ranking presented as fact. Colour may encode **category**
  (which unit, which outcome), never **judgement** (whether a number is acceptable).
- **No export (AC37).** Not deferred — not designed in, anywhere.
- **Daily buckets; 24h/7d/30d windows, default 30d (AC16/AC17).**
- **Deleted proxies/destinations stay in past figures, labelled as deleted, with no
  actions against them (AC6).**
- **"Drill down per webhook" = the proxy; the floor is the already-shipped per-event
  surface (AC21).** No new event-level surface, no per-received-event statistic.
- **Retry figures cover Simple proxies too (AC25); a Sync/FIFO duration difference is
  not a fault (AC26).**
- **Count figures are zero when empty; measure figures (latency) read "no data,"
  never `0 ms` (AC12).**
- **Records may be live-computed or served from a rollup; if not live, the surface
  states as-of what time it's current (AC11) — the technical shape is `Q-11-03(6)`,
  still open, and this spec is written to be correct under either answer** (see
  *As-of, conditionally* under Components).

## Scope boundaries (confirmed, not designed here)
- **AC23/AC24 — team-scoped, permission-gated, no new permission.** Every figure on
  every screen below covers the acting member's current team and only the proxies
  they can already read; nothing here adds a role, a gate, or a distinct analytics
  permission.
- **AC25/AC26 — mode/processing independence.** No mode or processing-mode filter,
  toggle, or gate is introduced. A Simple proxy's retry figures appear identically to
  an Enhanced one's; a FIFO proxy's different latency/retry shape is presented with
  the same treatment as an Async proxy's — never flagged, annotated, or coloured
  differently for it.
- **AC27/AC28 — nothing settled elsewhere changes.** No control here alters
  retention, retry policy, replay, the mode toggle, or the masked payload viewer.
  Drill-through **reuses** the existing Events list and event detail; it duplicates
  neither.
- **AC31/AC32/AC34–37 — no alerts, no per-event-type breakdown, no billing, no
  cross-team view, no export, no live refresh, no scheduled reports.** None of these
  has an affordance anywhere in this spec; their absence is the compliance.

## Overview
A team member lands on the **Dashboard** and sees, before anything else, how many of
their team's deliveries got through over the last 30 days (the default window) — a
large **delivery success** figure — with a visibly smaller **attempt success** figure
directly beneath it and one sentence bridging the two ("14 attempts failed before
these deliveries succeeded — see Retry & replay below"), so the same healthy traffic
can never be misread as either "everything is fine" or "something was lost." Below
that, a table lists every proxy the member can see with the same two figures and a
terminal-failure count, letting them spot the one that looks wrong without opening
each one. A daily trend chart shows both figures as two lines across the window, and
a row of plain-language facts — how many deliveries succeeded only after a retry, how
many gave up entirely, how much retrying happened, how much of it was a manual replay
— sits below the chart as *what's behind that number*, never competing with it.
Latency shows an average and a slower-tail figure, and reads "no data" rather than
`0 ms` when nothing resolved in the window. Opening a proxy shows the identical shape
of figures scoped to that proxy, in a new **Analytics** card placed right after the
header — because "is this working" is why a member opens a proxy — and the existing
**Destinations** card grows two new columns so the same two units appear **at the
destination**, which is the grain a member actually acts on. From any failure figure,
a link carries the member into the already-shipped Events list, pre-filtered to the
window (and, from a destination row, to that destination), so a bad number is the
start of a diagnosis, never a dead end. Nothing here asserts a target, colours a
number by whether it's "good," or offers a way to export it.

## User Flows

### Flow A — "Is everything working?" (team headline, Dashboard)
*(User story: "see how many deliveries succeeded and failed for my team.")*
1. Member opens **Dashboard**. Default window is **30 days** (AC17).
2. Sees the team's **delivery success** figure (large) with its count
   ("42 of 42 delivered"), the **attempt success** figure (smaller, directly below,
   labelled "Attempt success — destination health") with its count, and a bridge
   sentence naming the gap between them if one exists (Screen 1(b)).
3. **No deliveries in the window:** headline reads "No deliveries yet" in place of a
   percentage; the explanation names what would populate it (Screen 1, Empty state).
4. **No proxies at all:** the whole page shows a single empty-state card pointing at
   proxy creation — never the headline, table, or chart shells (Screen 1, No-proxies
   state).

### Flow B — "Which proxy looks wrong?" (per-proxy breakdown, Dashboard)
*(User stories: "those figures per proxy... which integration is unhealthy";
"a member who suspects something is broken reaches the evidence without knowing
where to look.")*
1. Below the headline, the member sees the **Proxies** table: one row per proxy they
   can read, with **Delivery success**, **Attempt success**, and **Terminal
   failures** columns, plus a link into that proxy.
2. Default order is alphabetical by name — **not** ranked by performance (see
   *Flagged design call 5*). The member can click a column header to sort by it,
   ascending or descending, client-side, on data already on the page.
3. A proxy deleted within the window still appears, labelled **Deleted**, with its
   historical figures intact and no link to manage it (AC6; drill-through
   availability is a Principal Engineer feasibility question — see Open Questions).
4. Member clicks a proxy's name or **View** → **Proxy Show** (Flow C), carrying the
   currently selected window as a query parameter so the next page opens on the same
   period, not a reset default.

### Flow C — "Is this proxy working, and is it getting worse?" (per-proxy Analytics, Show)
*(User stories: "figures per proxy"; "figures over time... a chronic problem from a
bad afternoon"; "how much retrying is going on"; "how many gave up entirely"; "how
long deliveries take.")*
1. Member opens a proxy (from Flow B, or directly). The new **Analytics** card
   renders immediately after the header, before **Ingest URL** (Screen 2).
2. Sees the same two-tier headline + bridge sentence, scoped to this proxy, for the
   carried-over (or default 30d) window.
3. Sees the **daily trend chart**: one line per unit, both present, both labelled,
   never a toggle between them (AC14(c)).
4. Sees the **Retry & replay** row: eventual success, terminal failure, retry volume,
   live-vs-replay split (AC19) — read as "what sits behind the headline," visually
   subordinate to it.
5. Sees the **Latency** block: average and a high-percentile figure, or "No data" if
   nothing resolved in the window (AC12, AC20).
6. **Zero traffic for this proxy in the window:** the whole card collapses to a
   single empty-state message; no flat-lined chart, no zeroed tiles (Screen 2, Empty
   state).

### Flow D — "Which destination is the problem?" (per-destination breakdown, Show → Destinations card)
*(User stories: "which destination is the problem"; AC15, AC6.)*
1. On the same Show page, the existing **Destinations** card now shows, per
   destination row, **Delivery success**, **Attempt success**, and **Latency (avg)**
   for the same window as the Analytics card above it (Screen 3).
2. A destination removed since is still a row, labelled **Deleted**, with its
   historical figures intact. Its **View events** link stays live and filters by that
   destination's id exactly as a current destination's does: drill-through needs the
   destination to be *identifiable*, not *manageable*, and a soft delete preserves the
   id. See Screen 3 for the row states, and Open Questions for what this assumes.
3. Member clicks **View events** on a destination row → **Flow E**, landing on the
   Events list filtered to that destination and the current window.

### Flow E — "Show me the failures" (drill-through, Events list)
*(User stories: "go from a failure figure to the actual failed events"; AC21.)*
1. From any failure-shaped figure (a terminal-failure tile, a destination's Delivery
   success cell, a trend-chart point via its data table), the member follows a link
   into the proxy's existing **Events** list.
2. The list opens pre-filtered: at minimum by the **window** the member was looking
   at, and — when the entry point was a specific destination — by that
   **destination** too. Active filters render as removable chips above the table
   (Screen 4).
3. The member reaches the same list, delivery badges, and event detail (masked
   payload, delivery history) that already ship — nothing new is built at this
   floor (AC28).
4. **Filtered set is empty** (e.g., a stat implied failures existed but none remain
   readable — should not normally happen, but the surface must not error): the
   existing empty-state card renders, with the active filter chips still shown so the
   member can clear them (Screen 4, Empty-filtered state).

## Screens & States

### Screen 1 — Dashboard (`resources/js/pages/Dashboard.vue`)
Replaces the four placeholder panels entirely.

```
Dashboard                                                    h1
[24h] [7d] [30d*]                              WindowSelector (page-level)
"Figures as of {timestamp}"                     (conditional — see As-of note)

Card "Deliveries"
  dt "Delivery success"          (text-sm text-muted-foreground)
  dd "96%"                       (text-3xl font-semibold)  — or "No deliveries yet"
     "42 of 42 delivered · last 30 days"      (text-sm text-muted-foreground)
  dt "Attempt success — destination health"   (text-sm text-muted-foreground, mt-4)
  dd "67%"                       (text-lg font-medium)
     "28 of 42 attempts succeeded · last 30 days"
  p (italic, text-sm text-muted-foreground)
    "14 attempts failed before these deliveries succeeded — see Retry & replay below."

Card "Proxies"
  Table: Proxy | Delivery success | Attempt success | Terminal failures | (View)
  {one row per readable proxy, sortable by column click}

Card "Trend"
  [dual-line chart: Delivery success — solid · Attempt success — dashed]
  <Collapsible> "View as table" → data table, same series, one row per day

Card "Retry & replay"
  4 stat tiles: Eventual success | Terminal failure | Retry volume | Live vs replay

Card "Latency"
  dt "Average" / dd "340 ms"  —or—  dt "Average" / dd "No data"
  dt "95th percentile" / dd "1.2 s"  —or—  "No data"
  p "Excludes time spent waiting in the queue."
  <Collapsible> "View as table" (if a daily latency series is shown — see Handoff)
```

**States:**
- **Default (has traffic):** as above.
- **Zero deliveries in window, proxies exist:** "Deliveries" card shows "No
  deliveries yet" in place of the percentage (see *Flagged design call 2*); the
  count line reads "0 of 0 delivered · last {window}"; the bridge sentence is
  omitted (there is nothing to bridge). "Proxies" table still lists every proxy,
  each row showing the same "No deliveries yet" treatment. "Trend," "Retry &
  replay," and "Latency" cards render their own empty states (flat "No data for
  this period" message in place of a chart/tiles) rather than an all-zero chart —
  a flat line at 0% would read as "100% failure," which is false.
- **No proxies at all:** the entire page below the header is a single centered
  `Card` — "No proxies yet," helper text pointing at proxy creation, no window
  selector (nothing to window over). Matches the existing empty-state idiom
  (`events/Index.vue`'s "No events yet").
- **Loading/navigating:** Inertia's global progress bar only, per the app's
  existing no-client-fetch convention — the window selector is a normal navigation,
  not an in-page async update.
- **Error:** the existing page-level fallback (`design-01` Screen 3 convention).

### Screen 2 — Proxy Show — new "Analytics" card
Inserted **immediately after the header block**, before **Ingest URL** (see
*Flagged design call 3*):

```
h1 {proxy.name}  [Mode badge] [Processing badge]        (existing, unchanged)
p  {modeSummary}                                         (existing, unchanged)

Card "Analytics"                                          (NEW)
  [24h] [7d] [30d*]                       WindowSelector (page-level for this page)
  "Figures as of {timestamp}"             (conditional)
  dt "Delivery success" / dd "96%" / "42 of 42 · last 30 days"
  dt "Attempt success — destination health" / dd "67%" / "28 of 42 · last 30 days"
  p (bridge sentence, as Screen 1)
  [dual-line trend chart] + "View as table"
  4 stat tiles: Eventual success | Terminal failure | Retry volume | Live vs replay
  dt "Average latency" / dd  |  dt "95th percentile" / dd
  p "Excludes time spent waiting in the queue."

Card "Ingest URL"          (existing, unchanged)
Card "Response"            (existing, unchanged)
Card "Destinations"        (existing, EXTENDED — Screen 3)
Card "Retry policy"        (existing, unchanged)
```

**States:**
- **Default (has traffic):** as above, identical shape to Screen 1's cards but
  scoped to this proxy.
- **Zero traffic for this proxy in the window:** the entire Analytics card
  collapses to one message: "No deliveries to this proxy in the last {window}.
  Figures appear once it receives and delivers a webhook." — no chart shell, no
  zeroed tiles, no latency block. Matches the "never a broken state" requirement
  (UX Direction) without implying anything is wrong with a quiet, healthy, or
  brand-new proxy.
- **Loading/error:** as Screen 1.

### Screen 3 — Proxy Show — "Destinations" card, extended
The existing card (`resources/js/pages/proxies/Show.vue`, currently a plain `ul`)
becomes a `Table` so multiple stat columns fit per row, reusing the exact `Table`
primitive already used on the Events list:

```
Card "Destinations"
  Table
    Destination | Delivery success | Attempt success | Latency (avg) | Actions
    {one row per destination, current + deleted}
```

**Row content:**
- **Destination** — unchanged `Badge` (method) + truncated URL.
- **Delivery success** / **Attempt success** — same shape as the headline figures,
  compact: `96% (42/42)`. Column headers carry the unit — no per-cell relabelling
  needed, satisfying AC14(a) structurally (a screen reader announces "Delivery
  success: 96% (42/42)" via table header association).
- **Latency (avg)** — average only at this grain (see *Flagged design call 9*); "No
  data" if nothing resolved for this destination in the window.
- **Actions** — **View events** (links into Flow E, filtered to this destination and
  the current window) for a live destination; a muted **Deleted** label plus **View
  events** (same link, still functional — historical attempts remain attributable
  to the destination's id even after soft-delete) for a removed one. No edit/manage
  action ever appears here — this card is read-only for destinations by construction
  (destinations are managed on the edit form).
- Order is **unchanged** from today (creation order) — not re-sorted by performance
  (see *Flagged design call 5*'s reasoning, applied here too).

**States:**
- **Zero traffic for a given destination:** that row's Delivery/Attempt columns
  read "No deliveries yet" / "—", **not** `0%` (same reasoning as the card-level
  empty state, applied per-row); the row is not hidden.
- **No destinations configured:** existing empty behaviour, unchanged by this spec.

### Screen 4 — Events list — drill-through filters
Extends `resources/js/pages/proxies/events/Index.vue`, unchanged otherwise (table
columns, badges, replay action, pagination, FIFO note all exactly as `design-06`
shipped them):

```
Events for "{Proxy name}"                                          h1
[Window: last 7 days ×]  [Destination: POST api.example.com/hook ×]   FilterChips (NEW)
{existing table, existing pagination}
```

**States:**
- **Arrived via drill-through, filters applied, has rows:** chips render above the
  table, each with a remove (`×`) affordance that re-navigates without that filter;
  table shows only matching rows.
- **Arrived directly (no filter):** no chip row renders — visually identical to
  today.
- **Filtered set is empty:** the existing "No events yet" empty-state card renders,
  its copy adjusted to "No events match these filters" when at least one chip is
  active, plus a **Clear filters** link — never a dead end or an error.
- Everything else (loading, error, FIFO note, pagination) is **unchanged** from the
  shipped `design-06` spec.

## Components
| Role | Component | Status |
|---|---|---|
| Window selector | `Button` group (3 buttons, one active via `aria-current="true"`), full-page navigation on click | **New small composition**, built from the existing `Button` primitive — no new `ui/*` primitive, same idiom as the Events-list pagination row |
| Headline / stat labels | `dl`/`dt`/`dd` | Reused — the pattern already established by the Response and Retry-policy cards |
| Bridge sentence | plain `p`, muted/italic | Reused text treatment |
| Proxies / Destinations breakdown tables | `Table`/`TableHeader`/`TableBody`/`TableRow`/`TableCell`/`TableHead` | Reused — same primitive as the Events list |
| Sortable column header | `TableHead` + a click handler + `aria-sort` | **New small composition** on an existing primitive — no new `ui/*` primitive |
| Trend / latency charts | Chart.js (via the Owner-suggested `@j-t-mcc/vue3-chartjs` wrapper) | **New dependency** — a Principal Engineer / Owner gate per `CLAUDE.md`, not approved here. This spec names chart *types*, states, and the accessible fallback; it does not assume a specific API |
| Chart data-table fallback | `Collapsible`/`CollapsibleTrigger`/`CollapsibleContent` | Reused — already shipped, first used in `design-06` |
| Retry & replay stat tiles | `Card` + `dl`/`dt`/`dd` | Reused pattern, new composition of it |
| Filter chips (Events list) | small `Badge`-shaped composition with a remove control | **New small composition** — no existing chip/tag primitive in this app; built from `Badge` + a `button` with an `aria-label` (never an icon-only, unlabelled `×`) |
| Deleted-row label | muted `Badge variant="outline"` | Reused — same treatment `design-06` used for expired/never-captured states |

**No new `ui/*` primitive is introduced.** The only new dependency this spec's
components imply is the charting library itself, which is a named Owner-suggested,
PE-gated decision (see Handoff), not a Designer addition.

**Data-const recommendation (non-gating).** Column headers, stat labels, and their
unit wording ("Delivery success," "Attempt success — destination health") are each
used on at least two screens (Dashboard, Show). Recommend a single source
(`resources/js/data/analyticsLabels.ts`) for this copy so a wording change can't
drift between the two homes — the same reasoning that justified
`proxyProcessingModes.ts` and its siblings. File-organization note for the Senior
Developer, not a Designer requirement.

## Interactions
- **Window selector** is a normal link/navigation (Inertia `router.get` with a
  `window` query parameter), not client-side state — consistent with this app
  having no async client-fetch pattern. Switching windows on the Dashboard or a
  proxy's Show page reloads that page's data; the selection is carried forward when
  a drill-through link is followed (Flow B step 4, Flow D step 3) so the member
  never has to re-pick it.
- **The two units are never toggled, tabbed, or dropdown-selected between** — every
  screen renders both, always, at the layout weights specified (headline vs
  secondary; separate table columns; separate chart lines). No control on any
  screen in this spec switches which unit is showing (AC14(c)).
- **Sorting the Proxies table** is a client-side re-sort of rows already on the
  page (no new request) — ascending/descending toggle on repeated header clicks,
  default sort (name, ascending) restored on page reload.
- **Chart "View as table"** is collapsed by default; expanding it does not affect
  the chart above it — same independent-`Collapsible` behaviour `design-06`
  established for attempt history.
- **Filter chip removal** re-navigates (a normal link, not a client-side row
  filter) so the server-side query and the URL stay the single source of truth for
  what's currently shown — consistent with "no parallel client state" elsewhere in
  this app.
- **No control anywhere in this spec is conditioned on a figure's value** — a 12%
  delivery-success card and a 99% one render with identical chrome, weight, and
  colour; only the numbers differ (AC22(b)).

## Accessibility
- **Every figure has an accessible name via structural association, not colour or
  position alone.** Prose figures use `dl`/`dt`/`dd` (dt = label, dd = value +
  caption) so a screen reader announces label and value together ("Delivery
  success: 96%, 42 of 42 delivered, last 30 days"). Table figures rely on
  `<th scope="col">` header association (already how `TableHead` renders) so a cell
  is announced with its column's label, not bare.
- **Charts are not the only way to reach the data (binding requirement).** Every
  chart is paired with a same-data accessible table behind a **visible** "View as
  table" toggle (not hidden until hover, not JS-injected only after an event the
  user can't discover) — the chart canvas itself may be marked non-essential to
  assistive technology (e.g., `aria-hidden="true"` on the canvas element, with the
  surrounding figure carrying a short `aria-label` summary, e.g. "Daily delivery
  and attempt success rate, last 30 days — see table below for exact values") since
  the sibling table is the authoritative accessible representation.
- **Non-colour encodings are mandatory, not supplementary.** The trend chart's two
  lines are distinguished by **line style** (solid vs dashed) in addition to
  colour; the live-vs-replay stat is expressed as **two labelled numbers** ("42
  live · 3 replay"), never a colour-only split; every badge (Deleted, category
  labels) carries its full text, per the project's existing "colour is never the
  sole carrier of meaning" rule.
- **Colour is fixed per series/category, never per magnitude.** The delivery-line
  and attempt-line each get one fixed token regardless of the number they're
  currently showing (see *Flagged design call 6*) — nothing here recolours a line
  or a tile based on whether its value is "good."
- **Filter chip removal control** carries a discernible `aria-label` ("Remove
  destination filter: {url}"), never a bare icon-only `×`, per the existing
  icon-only-control rule.
- **Sortable column headers** expose `aria-sort` (`ascending`/`descending`/`none`)
  and are operable via keyboard (`Enter`/`Space` on a focusable header control, not
  a bare `click` handler on a `<th>`).
- **Live regions:** the window selector's navigation is a full page load (already
  announced by the browser/Inertia's own page-title update); no additional
  `aria-live` region is needed there. The Events list's filter-chip row, when it
  appears as a result of a drill-through navigation, is likewise part of the new
  page's initial render — no dynamic-appearance announcement is needed since
  nothing changes without a navigation.
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`. Non-text contrast (the
  trend-chart line colours against card backgrounds, in both themes) must be
  verified against the **actual resolved** colour, not the token string — see the
  colour-resolution note in Handoff.

## Responsive Behavior
- **Stat cards (headline, latency, retry tiles):** stack vertically below `sm`,
  exactly as every existing Show-page card does; the two-tier headline block
  remains single-column at all widths (it was never side-by-side).
- **Proxies / Destinations tables:** same horizontally-scrollable container as the
  existing Events table — no stacked-card fallback (`docs/standards/design.md`
  → "Tables have no responsive stacking variant").
- **Trend/latency charts:** full-width within their card at all breakpoints; a
  minimum height is fixed (not user-resizable) so the chart never collapses to
  illegible on narrow viewports — the "View as table" fallback is the resilient
  path at very small widths, not a redrawn chart.
- **Window selector:** the 3-button group wraps to two lines rather than
  truncating below `sm`; never collapses into a `Select` (keeping it visually
  identical to the pagination-button idiom this app already ships).
- **Filter chips:** wrap onto multiple lines rather than scrolling horizontally,
  consistent with how badges wrap elsewhere in this app.
- **Minimum supported width:** 360px, per the standing default in
  `docs/standards/design.md` — no feature-specific override.

## Open Questions
None blocking approval. Nine flagged, reversible judgment calls for the Product
Manager's design-gate attention (matching the `design-06`/`design-07` precedent),
plus explicit notes on what depends on the still-open `Q-11-03`:

1. **The two-unit headline is one card: a large primary figure, a visibly smaller
   secondary figure directly beneath it, and a bridge sentence connecting them —
   never two equal-weight tiles, never separate cards.** This is the resolution to
   the PRD's stated "hardest design problem." *Trade-off:* equal-weight side-by-side
   tiles would read as "two independent facts" rather than "one headline, one
   supporting signal," risking exactly the ambiguity AC14 forbids; two separate
   cards would risk reading as two dashboards. The chosen shape makes the
   relationship explicit in both weight and prose.
2. **A zero-denominator rate ("no deliveries yet") replaces the percentage entirely
   rather than showing `0%`.** *Basis:* `0%` for zero traffic reads as "everything
   failed," which is false — the same class of problem AC12 names for latency, applied
   to a rate whose denominator is also zero. *Trade-off:* PRD-11's own Definitions
   table classifies "rates over counts" as count figures with a natural empty value
   of zero, which read literally would call for `0%`. This spec takes the UX
   Direction's "explanation of what would populate them, never a broken state" as the
   controlling intent and reads AC12's zero-for-counts rule as governing the raw
   counts (0 succeeded, 0 total), not a rate computed from them. Flagged because it
   is a considered departure from a literal reading, not an oversight.
3. **The Analytics card is inserted right after the header, ahead of Ingest URL** —
   reordering the existing Show-page card sequence rather than appending at the
   bottom. *Basis:* "is this working" is the reason a member opens a proxy from the
   Dashboard's drill-through path; leading with it matches the primary flow.
   *Trade-off:* every prior spec (`design-03`, `design-06`, `design-07`) only ever
   appended new cards; this is the first that reorders. If the Product Manager
   prefers appending after Retry policy to preserve "extend never reorder," that is
   a same-shaped, low-risk swap.
4. **Per-proxy figures live on the Show page (not the Events list); the Events list
   gains only filter chips, no analytics chrome.** The PRD's UX Direction allowed
   either surface. *Trade-off:* keeps the Events list exactly as `design-06` shipped
   it (lower risk to an already-approved surface) at the cost of a slightly heavier
   Show page.
5. **Neither the Proxies table (Dashboard) nor the Destinations table (Show)
   defaults to a "worst first" sort — both default to a neutral order (name /
   creation order) and are sortable by column on user click.** *Basis:* a built-in
   worst-first default is the product itself asserting an implicit ranking, which
   sits close to the judgement AC22(b) forbids; user-initiated sorting achieves the
   UX Direction's "reach the evidence without knowing where to look" goal without
   the product declaring it. *Trade-off:* a member has to click once rather than
   arrive pre-sorted onto the worst offender.
6. **Trend-chart lines use the existing neutral `chart-1`/`chart-2` tokens, not
   `destructive`/`secondary`-style red/green.** *Basis:* a dip in the delivery-rate
   line must not itself look like an alarm colour — that would smuggle in the
   verdict AC22(b) forbids via the trend chart specifically, which is the one place
   a "worse over time" reading is most visually tempting to colour-code.
   *Trade-off:* less immediately scannable as "good/bad at a glance," which is the
   deliberate point of a facts-only surface.
7. **A latency tail fallback is pre-designed:** if the Principal Engineer finds a
   true percentile infeasible at `Q-11-03(6)`, this spec's latency block accepts a
   labelled substitute in the same slot (e.g., "Approx. 95th percentile" or a
   short "5 slowest deliveries" list with destination, duration, and a link into
   Flow E) — AC20 requires the substitute be labelled for what it is; a bare average
   does not satisfy it. Flagged because it pre-designs for an outcome that depends
   on an answer not yet given.
8. **An "as of {time}" caption slot exists on every figures block but renders
   conditionally.** If `Q-11-03(6)` resolves to live computation, this caption may
   read "as of now" or be omitted entirely; if it resolves to a rollup, AC11 makes
   the caption **mandatory** and concrete. This spec is written so either answer
   fits without a design change.
9. **The Destinations card shows average latency only, not a per-destination
   percentile.** *Basis:* keeps the row compact and avoids presuming a
   per-proxy-per-destination-per-day percentile is cheap to compute (`Q-11-03(5)`/
   `(6)`) at the grain with the least aggregate volume to smooth over. If the
   Principal Engineer confirms it's cheap, adding a second latency column is
   additive and does not disturb this spec.

**Feasibility question for the Principal Engineer (not a PM design call — folded in
alongside the already-open, non-blocking `Q-11-03`):** whether a **deleted**
destination's or proxy's historical figures remain reachable via a drill-through
link (Flow B step 3, Screen 3's Deleted-row "View events" link) depends on whether
the Events list route/controller can resolve a soft-deleted parent for a
permission-gated read. AC6 requires the figure to stay attributable; this spec
assumes the link stays live and specifies no fallback UI for the case where it
can't — if `Q-11-03(2)`'s soft-delete-visibility work finds it infeasible without a
larger change, the row's **View events** action degrades to the same muted,
non-interactive label the destination's own **Deleted** badge already uses, and
that is additive, not a rework of this spec.

## Handoff
- **Inputs:** `docs/product/prd-11-analytics.md` (Approved, esp. § Definitions, § UX
  Direction, AC6–AC22, AC25/AC26); `docs/questions/prd-11-q-11-01-analytics-
  dashboard-scope.md` (RESOLVED — Tier 3, both units binding, drill-down = the
  proxy, windows/buckets, indefinite retention, pre-#6 row treatment);
  `docs/questions/prd-11-q-11-02-throughput-and-delivery-targets.md` (RESOLVED — no
  target, no verdict layer, the four fixed definitions); `docs/questions/prd-11-q-
  11-03-stats-lifecycle-and-aggregation.md` (**OPEN**, Principal Engineer — this
  spec is written to hold under either answer to items (5)/(6)/(9), and names the
  one place, item 9 above, where a "yes" to feasibility is additive rather than a
  rework); `docs/design/design-06-retry-replay.md` (Events-list/detail shape this
  spec drills into unchanged; `dl`/`dt`/`dd` and `Collapsible` precedent);
  `docs/design/design-07-enhanced-mode-toggle.md` (house format; header-caption-vs-
  card precedent applied to *Flagged design call 3*'s reasoning);
  `docs/standards/design.md` (component reuse rules, accessibility baseline,
  responsive defaults, the "colour is never the sole carrier of meaning" rule this
  spec extends to charts); `resources/js/pages/Dashboard.vue`,
  `resources/js/pages/proxies/Show.vue`, `resources/js/pages/proxies/events/
  {Index,Show}.vue`, `resources/js/components/ui/{table,card,badge,button,
  collapsible}/*` (current implementation studied for this spec).
- **Outputs:** this design spec.
- **Dependencies:** **one new dependency pair is assumed** — Chart.js 4 plus the
  Owner-suggested `@j-t-mcc/vue3-chartjs` wrapper — named in `docs/product/prd-11-
  analytics.md` § Handoff as a **new-dependency Owner gate the Principal Engineer
  records formally at plan time**; this spec does not approve it, only designs
  against the assumption that a two-series line chart with per-series colour and
  line-dash control is available. **Specific library capabilities this spec assumes
  and asks the Principal Engineer to confirm:** (a) two-series line charts with
  independent line-dash styling per series; (b) per-series colour supplied as a
  resolved CSS colour value, not a token string the library re-parses (see the
  colour-resolution note below); (c) the chart canvas can be marked
  non-essential to assistive technology (`aria-hidden`) without the library
  fighting that with its own ARIA wiring; (d) no SSR-specific behaviour is needed,
  since every chart in this spec renders after a fresh Inertia page load, never
  as a persisted, reactively-updated component across navigations. No new `ui/*`
  primitive is introduced; `Collapsible` is reused, already shipped.
- **Colour-resolution note, defensive by design (PR #12 lesson).** Wherever a chart
  or stat tile needs an actual colour value (not a Tailwind class) — e.g., to pass
  into the charting library's series-colour option — resolve it by reading
  `getComputedStyle` on a live DOM element carrying the token via a CSS property
  (`color: var(--chart-1)`) and using the **browser-resolved** `rgb()`/`rgba()`
  output, never by pattern-matching the token's source text. `getComputedStyle`
  returns custom properties **verbatim** (so `--chart-1` itself would come back as
  literal token text, not a colour), and this project's production minifier has
  previously rewritten `hsl()` source into hex, silently breaking a hand-written
  parser that only matched `hsl(...)` and dropped alpha in the process. Resolving
  through browser style computation on a real element sidesteps both failure modes
  because the output format is never assumed. This is a technical note for the
  Principal Engineer, not a design requirement with a UI-visible consequence.
- **Outstanding Questions:** None blocking this spec's approval. Nine flagged,
  reversible judgment calls above for the Product Manager's design-gate review; one
  feasibility question folded into the Principal Engineer's already-open,
  non-blocking `Q-11-03`; the new-dependency Owner gate is named but not approved
  here, per PRD-11's own Handoff.
- **Next Agent:** **Product Manager**, to approve this spec against PRD-11 (design
  gate, delegated per `CLAUDE.md`). On approval, hands to the **Principal
  Engineer** for technical design, which also carries the open, non-blocking
  `Q-11-03` and the new-dependency and data-model Owner gates named in PRD-11's
  Handoff.
