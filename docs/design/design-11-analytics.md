# Design Spec: Analytics / stats

- **Status:** **Fully Approved (Product Manager, 2026-08-26) — the Amendment B revision
  is approved with no corrections.** PRD-11 Amendment B (Product Manager, 2026-08-26)
  sets the trend series' bucket size **per window** — hourly on the 24-hour window
  (24 points), daily on the 7- and 30-day windows (7 and 30 points) — and narrows the
  per-bucket drill-through into Flow E to **day buckets only**; an hourly bucket owes
  no drill-through. This spec is revised to match, and the revision was re-gated on
  2026-08-26 — see **§ Amendment B re-approval (design gate)** at the end of this
  document for that verdict. **§ Amendment B changes**, immediately above it, lists
  every spot the Designer touched and why. **Nothing Amendment B does not name is
  reopened** — the two-unit labelling rule, the no-verdict rule, the empty-state
  treatments, the chart colour tokens, and the three-chip row on Screen 4 all stand
  exactly as the prior approval left them.
- **Prior status (fully approved 2026-08-26; superseded only where Amendment B says
  so):** all six required corrections (C1–C6) landed and cleared (design gate,
  delegated per `CLAUDE.md`). All nine flagged design calls were **accepted as
  designed**. See § Corrections landed and § C1 re-check outcome, at the end of this
  document, for that record — unchanged by this revision.
- **Author:** Designer
- **PRD:** `docs/product/prd-11-analytics.md` (Approved, Project Owner, 2026-08-26;
  **amended 2026-08-26 — Amendment A**, two clarifications the design gate raised:
  AC12's zero-denominator rate and the grain at which AC16/AC20 are obliged; **amended
  again 2026-08-26 — Amendment B**, the per-window bucket size and the day-grain-only
  drill-through obligation this revision carries)
- **Approved by / date:** **Product Manager, 2026-08-26** — twice. The original spec was
  approved on 2026-08-26 once corrections C1–C6 landed (§ Approval record, § Corrections
  landed, § C1 re-check outcome). The **Amendment B revision** was approved on 2026-08-26
  in a delta-scoped re-gate covering only what that revision changed
  (§ Amendment B re-approval). The design gate is delegated to the Product Manager per
  `CLAUDE.md`; the Designer never approves its own spec, and did not here.

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
  split, trend, drill-through, retry/terminal/replay insight, latency with a
  high percentile. **Both** the delivery-level and attempt-level figures are always
  shown together, distinctly labelled, never as a toggle/tab/dropdown pair (AC13/AC14).
- **No verdict (AC22).** No threshold colour, no badge, no reference line, no
  "good"/"bad" language, no ranking presented as fact. Colour may encode **category**
  (which unit, which outcome), never **judgement** (whether a number is acceptable).
- **No export (AC37).** Not deferred — not designed in, anywhere.
- **Bucket size varies by window; 24h/7d/30d windows, default 30d (AC16/AC17, PRD-11
  Amendment B(i)).** The 24-hour window buckets **hourly** (24 points); the 7-day and
  30-day windows bucket **daily** (7 and 30 points). Buckets partition the window —
  no gap, no overlap, no record double-counted, no empty bucket dropped — and every
  point names the period it covers (AC8). **The per-bucket drill-through into Flow E
  is obliged at day buckets only (Amendment B(ii))** — an hourly bucket owes no
  drill-through, and must never carry a day-grained one. See Flow C step 3 and
  Flow E for how a member still reaches the evidence on the 24-hour window.
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
- **AC13's pre-#6 exclusion (`delivery_id` NULL rows) gets no user-facing statement
  (Product Manager ruling, design gate).** The rows exist only in development and CI
  data — none exists in production (AC13, D-11-7, F4) — so AC13's "the exclusion is
  stated, not silent" obligation is discharged in the technical artifacts (ADR-015,
  PRD-11, this note) rather than on screen. If such rows ever reach production data,
  a user-facing statement becomes required and returns to the Designer.

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
each one. A trend chart — hourly on the 24-hour window, daily on the 7- and 30-day
windows — shows both figures as two lines across the window, and
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
5. **Member clicks the row's Terminal failures (deliveries) figure** — the one
   failure-shaped cell in this table — and goes straight to **Flow E** on that
   proxy's Events list, skipping Proxy Show, pre-filtered to that proxy, the current
   window, and terminal failure (delivery-level). The Delivery success and Attempt
   success cells are **not** links to Flow E — they are rates, not failure counts,
   and reaching their evidence still goes through the proxy's name/**View** into
   Flow C, same as step 4 (see § C1 in the Approval record).

### Flow C — "Is this proxy working, and is it getting worse?" (per-proxy Analytics, Show)
*(User stories: "figures per proxy"; "figures over time... a chronic problem from a
bad afternoon"; "how much retrying is going on"; "how many gave up entirely"; "how
long deliveries take.")*
1. Member opens a proxy (from Flow B, or directly). The new **Analytics** card
   renders immediately after the header, before **Ingest URL** (Screen 2).
2. Sees the same two-tier headline + bridge sentence, scoped to this proxy, for the
   carried-over (or default 30d) window.
3. Sees the **trend chart**: one line per unit, both present, both labelled, never
   a toggle between them (AC14(c)). The chart buckets **hourly** on the 24-hour
   window and **daily** on the 7- and 30-day windows (PRD-11 Amendment B(i)) — the
   chart canvas itself carries no click target at either bucket size, only its
   accessible table does (consistent with § Accessibility's "charts are not the
   only way to reach the data").
   - **At a day bucket** (7d/30d), **each row of the chart's "View as table"
     fallback is a link into Flow E** for that day, scoped to this proxy,
     narrowing the window to that single day, filtered to terminal failure at the
     row's unit (delivery-level from the delivery-rate cell, attempt-level from
     the attempt-rate cell) — unchanged from `Q-11-04`.
   - **At an hourly bucket** (24h), **a row's rate cells are not links.** They
     render as plain values — the same weight, colour and typography as every
     other table cell, no underline-on-hover, no `Link` wrapper — exactly the
     "not a link" treatment this spec already gives the Dashboard Proxies table's
     rate-shaped cells (Flow B step 5). This is not a disabled or greyed-out
     control; there is simply nothing to click, because Amendment B(ii) forbids
     a day-grained link on an hourly row outright and an hour-precise
     drill-through is not part of #11 (permitted, not required, and not built
     here). A member reaches the events behind an hourly figure through the
     three window-grain entry points that work unchanged on the 24-hour window —
     the Dashboard Proxies table's Terminal failures cell, this proxy's own
     Retry & replay Terminal failure tile (step 4 below), and the Destinations
     table's View events action (Flow D step 3) — landing on the 24-hour Events
     list, where each event's own timestamp is how a member finds a particular
     hour (per-event surface, unchanged, AC21).
   - **The table's first column follows the same split.** At a day bucket the
     header reads **"Date"** with a calendar-date value (e.g. `Aug 12, 2026`),
     unchanged. At an hourly bucket the header reads **"Hour"** with a value that
     states the calendar date together with the hour (e.g. `Aug 25, 2:00 PM`,
     naming the hour the bucket begins, by the same naming convention a day
     bucket's date names the day it spans) — **never a bare hour-of-day alone**.
     A rolling 24-hour window ordinarily crosses a calendar-date boundary, so an
     hour label without its date would force a member to infer which day a point
     falls on from its position in the table, which is exactly what Amendment
     B(i) forbids ("a member must never have to infer a point's period from its
     position").
4. Sees the **Retry & replay** row: eventual success, terminal failure, retry volume,
   live-vs-replay split (AC19) — read as "what sits behind the headline," visually
   subordinate to it. **The Terminal failure tile is a link into Flow E**, filtered
   to this proxy, the current window, and terminal failure (delivery-level) — the
   only one of the four tiles that is failure-shaped; eventual success, retry volume
   and live-vs-replay are not links.
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
   Events list filtered to that destination and the current window. **No outcome
   filter travels from here** — this row's figures are rates over *all* of that
   destination's traffic (Delivery success, Attempt success), not a failure count,
   so the action is total-shaped: it takes the member to that destination's events,
   not to its failures specifically (C1(a)).

### Flow E — "Show me the failures" (drill-through, Events list)
*(User stories: "go from a failure figure to the actual failed events"; AC10, AC21.)*

**Entry points and the filters each carries (C1(a)).** Every entry point below is
already scoped to one proxy — the Events list is per-proxy (AC21, AC28: no
cross-proxy events surface exists or is introduced here) — so a **team-level**
figure (Screen 1's headline, Screen 1's "Retry & replay" tiles, Screen 1's Trend
chart) is **not** a drill-through entry point: there is no single proxy for it to
land on, and aggregating a team-wide Events list is a new surface this spec does not
build. Only figures that already resolve to one proxy (a Proxies-table row, a
proxy's own Analytics card, a destination row) can drill through. **The Trend
chart's entry point is itself conditional on bucket size (PRD-11 Amendment B(ii))**
— present at a day bucket, absent at an hourly one — stated as two separate rows
below so neither case reads as an omission.

| Entry point | Shape | Filters carried into the Events list |
|---|---|---|
| Dashboard "Proxies" table — **Terminal failures (deliveries)** cell (Screen 1, Flow B step 5) | failure-shaped | proxy (that row's) · window · outcome = terminal failure, delivery-level |
| Dashboard "Proxies" table — Delivery success / Attempt success cells | rate-shaped | **not a link** — reach that proxy's evidence via Name/**View** → Flow C instead |
| Proxy Show "Retry & replay" — **Terminal failure** tile (Screen 2, Flow C step 4) | failure-shaped | proxy (current) · window · outcome = terminal failure, delivery-level |
| Proxy Show "Retry & replay" — Eventual success / Retry volume / Live vs replay tiles | not failure-shaped | **not links** |
| Proxy Show Trend chart's "View as table" row, per **day** bucket, per unit — **7d/30d windows only** (Screen 2, Flow C step 3) | failure-shaped | proxy (current) · window narrowed to that single day · outcome = terminal failure, at the clicked cell's unit (delivery- or attempt-level) |
| Proxy Show Trend chart's "View as table" row, per **hourly** bucket — **24h window only** (Screen 2, Flow C step 3) | **not an entry point** | **no link, at either unit** — an hourly row's rate cells are plain values (Amendment B(ii) forbids a day-grained link on an hourly row); reach the 24-hour window's evidence through the window-grain entry points above instead |
| Destinations table — **View events** action (Screen 3, Flow D step 3) | total-shaped (not failure-specific) | proxy (current) · destination · window — **no outcome filter** (Flow D step 3) |

Every failure-shaped entry point narrows the list to the failing records at its own
unit; the one total-shaped entry point (Destinations row) does not, because it is
not itself a failure count. Active filters render as removable chips above the table
(Screen 4) — window and destination reuse the two chip kinds this spec already
designs; **outcome is a third, same-shaped chip** ("Outcome: Terminal failure
(deliveries)" or "Outcome: Terminal failure (attempts)"), removable exactly as the
others are, and needs no new component.

**What the filtered list contains, and how it relates to the figure's count
(C1(b)).** AC21 fixes the drill-down floor at the **per-event** surface; AC10's
figures are denominated in **deliveries** or **attempts**. One event can carry
several deliveries to several destinations, so **the filtered list's row count is
not the figure's count** — an outcome-filtered list shows the **events that contain
at least one delivery (or attempt) matching the filter**, not one row per counted
delivery or attempt. A "Terminal failures: 12" tile can therefore land on fewer than
12 rows (one event with three failing deliveries is three failed deliveries but one
row) or, at the attempt-level filter, land on events whose overall delivery still
succeeded (a delivery that failed twice and succeeded on attempt three shows up
under an attempt-level failure filter even though the event's aggregate delivery
badge reads success). **This spec does not build a delivery-level or attempt-level
list to make the counts match row-for-row** — that would be a second events surface,
which AC28 forbids. Instead, Screen 4 states in copy what the filtered list is
showing (see Screen 4).

1. From any entry point in the table above, the member follows a link into that
   proxy's existing **Events** list.
2. The list opens pre-filtered per the table above: always by **window**, and by
   **destination** and/or **outcome** when the entry point carries them. Active
   filters render as removable chips above the table (Screen 4).
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
  dd "100%"                      (text-3xl font-semibold)  — or "No deliveries yet"
     "42 of 42 delivered · last 30 days"      (text-sm text-muted-foreground)
  dt "Attempt success — destination health"   (text-sm text-muted-foreground, mt-4)
  dd "67%"                       (text-lg font-medium)
     "28 of 42 attempts succeeded · last 30 days"
  p (italic, text-sm text-muted-foreground)
    "14 attempts failed before these deliveries succeeded — see Retry & replay below."

Card "Proxies"                    subtitle "Last {window}" (text-sm text-muted-foreground)
  Table: Proxy | Delivery success | Attempt success | Terminal failures (deliveries) | (View)
  {one row per readable proxy, sortable by column click}

Card "Trend"
  [dual-line chart: Delivery success — solid · Attempt success — dashed]
  bucket: one point per hour (24h window) or one point per day (7d/30d windows)
  <Collapsible> "View as table" → data table, same series, one row per bucket
    first column: "Hour" + date-qualified hour value on 24h · "Date" + calendar
    date on 7d/30d (see Flow C step 3's "table's first column" note — identical
    format here; this table carries no per-row link at either bucket size, per
    Flow E's "team-level figure is not a drill-through entry point")
  (chart's own axis states the period each point covers, in the bucket's own
  unit — hours on 24h, dates on 7d/30d — which is unambiguous for a date on its
  own but not for a bare hour in a window that crosses a calendar-date boundary;
  on the 24h window the axis states the date alongside the hour at the point
  where the window crosses into a new calendar day, so a member is never left to
  infer the date from a tick's position. No separate caption is added at either
  bucket size — see § Accessibility for the matching aria-label wording)

Card "Retry & replay"             subtitle "Last {window}" (text-sm text-muted-foreground)
  4 stat tiles: Eventual success (deliveries) | Terminal failure (deliveries) |
  Retry volume (attempts) | Live vs replay (deliveries)

Card "Latency"                    subtitle "Last {window}" (text-sm text-muted-foreground)
  dt "Average" / dd "340 ms"  —or—  dt "Average" / dd "No data"
  dt "95th percentile" / dd "1.2 s"  —or—  "No data"
  p "Excludes time spent waiting in the queue."
  <Collapsible> "View as table" (if a bucketed latency series is shown — see Handoff)
```

**States:**
- **Default (has traffic):** as above.
- **Zero deliveries in window, proxies exist:** a **rate** (a percentage) and a
  **count** (a raw number) take different treatments — only the rate reads "no
  data"; a count reads `0` (AC12, PRD-11 Amendment A(i)).
  - "Deliveries" card: both **rates** show "No deliveries yet" in place of the
    percentage (see *Flagged design call 2*); the **count** captions still read
    "0 of 0 delivered · last {window}" (a count, always shown); the bridge
    sentence is omitted (there is nothing to bridge).
  - "Proxies" table: each row's **Delivery success** and **Attempt success**
    columns (rates) show the same "No deliveries yet" treatment; the **Terminal
    failures (deliveries)** column — a count — reads `0`, not "No deliveries
    yet".
  - "Retry & replay" tiles: **render, not hidden or replaced.** All four are
    counts (AC19), so each reads `0` — "Eventual success (deliveries): 0",
    "Terminal failure (deliveries): 0", "Retry volume (attempts): 0", "Live vs
    replay (deliveries): 0 live · 0 replay" — with the card's existing
    explanatory line intact.
  - "Trend" card: the plotted series are **rates**, so the no-data treatment
    stands as drafted (flat "No data for this period" message in place of the
    chart) rather than an all-zero chart — a flat line at 0% would read as
    "100% failure," which is false.
  - "Latency" card: unchanged — a **measure** figure, "No data" (AC20).
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
  dt "Delivery success" / dd "100%" / "42 of 42 · last 30 days"
  dt "Attempt success — destination health" / dd "67%" / "28 of 42 · last 30 days"
  p (bridge sentence, as Screen 1)
  [dual-line trend chart, bucketed hourly on 24h / daily on 7d,30d] + "View as
    table" (this table's rows link into Flow E at a day bucket only — see Flow C
    step 3 and Flow E's entry-point table for the hourly non-link treatment and
    the first column's "Hour"/"Date" split)
  h3 "Retry & replay"            subtitle "Last {window}" (text-sm text-muted-foreground)
  4 stat tiles: Eventual success (deliveries) | Terminal failure (deliveries) |
  Retry volume (attempts) | Live vs replay (deliveries)
  h3 "Latency"                   subtitle "Last {window}" (text-sm text-muted-foreground)
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
Card "Destinations"                subtitle "Last {window}" (text-sm text-muted-foreground)
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
[Window: last 7 days ×]  [Destination: POST api.example.com/hook ×]
[Outcome: Terminal failure (deliveries) ×]                       FilterChips (NEW)
p (text-sm text-muted-foreground, shown only when an Outcome chip is active)
  "Showing events with at least one matching delivery — one event can hold more
   than one, so this list's row count won't match the figure's count exactly."
{existing table, existing pagination}
```

**States:**
- **Arrived via drill-through, filters applied, has rows:** chips render above the
  table, each with a remove (`×`) affordance that re-navigates without that filter;
  table shows only matching rows. **An Outcome chip is a third, same-shaped chip**
  (window, destination, outcome — up to three at once), reads "Outcome: Terminal
  failure (deliveries)" or "Outcome: Terminal failure (attempts)" depending on which
  entry point's unit it carried (see Flow E), and removes exactly as the other two
  do. **When an Outcome chip is active**, the explanatory line above renders,
  stating plainly that the list shows the **events containing** the matching
  deliveries/attempts, not one row per counted delivery/attempt (C1(b)) — so a member
  is never misled into expecting the row count to equal the figure they drilled
  from.
- **Arrived directly (no filter):** no chip row renders — visually identical to
  today.
- **Filtered set is empty:** the existing "No events yet" empty-state card renders,
  its copy adjusted to "No events match these filters" when at least one chip is
  active, plus a **Clear filters** link — never a dead end or an error.
- Everything else (loading, error, FIFO note, pagination) is **unchanged** from the
  shipped `design-06` spec.

**Dependency flagged to the Principal Engineer, not resolved here (C1(a) note).**
Window and destination filtering are straightforward — `webhook_events.received_at`
and the existing proxy↔destination relationship the events route already resolves.
**Outcome filtering is different**: it asks the Events list query to select events
that have at least one delivery (or attempt) matching a status at a given grain —
a join/aggregate shape the shipped list has never needed, since it carries no filter
today. This spec designs the chip, its copy, and the filters each entry point
carries; whether the query is cheap at both grains, and whether it can share a path
with the window/destination filters or needs its own, is a technical question. It is
raised as a new item on `Q-11-03` (see Open Questions) rather than assumed.

## Components
| Role | Component | Status |
|---|---|---|
| Window selector | `Button` group (3 buttons, one active via `aria-current="true"`), full-page navigation on click | **New small composition**, built from the existing `Button` primitive — no new `ui/*` primitive, same idiom as the Events-list pagination row |
| Headline / stat labels | `dl`/`dt`/`dd` | Reused — the pattern already established by the Response and Retry-policy cards |
| Bridge sentence | plain `p`, muted/italic | Reused text treatment |
| Proxies / Destinations breakdown tables | `Table`/`TableHeader`/`TableBody`/`TableRow`/`TableCell`/`TableHead` | Reused — same primitive as the Events list |
| Sortable column header | `TableHead` + a click handler + `aria-sort` | **New small composition** on an existing primitive — no new `ui/*` primitive |
| Trend / latency charts | Chart.js `^4` plus the Owner-suggested `@j-t-mcc/vue3-chartjs` wrapper | **New dependency — decided, both packages adopted.** Ruled by the Project Owner (2026-08-26): `chart.js` `^4` **and** `@j-t-mcc/vue3-chartjs`, both adopted; the local-wrapper alternative (`chart.js` alone behind an in-tree `TrendChart.vue`) was considered and explicitly not taken (`docs/plans/plan-11-analytics.md` § Owner rulings on both flags, § Owner ruling on T25's check-2 finding). This spec names chart *types*, states, and the accessible fallback; it does not assume a specific API |
| Chart data-table fallback | `Collapsible`/`CollapsibleTrigger`/`CollapsibleContent` | Reused — already shipped, first used in `design-06` |
| Retry & replay stat tiles | `Card` + `dl`/`dt`/`dd` | Reused pattern, new composition of it |
| Filter chips (Events list) — window, destination, **outcome** | small `Badge`-shaped composition with a remove control | **New small composition** — no existing chip/tag primitive in this app; built from `Badge` + a `button` with an `aria-label` (never an icon-only, unlabelled `×`). Outcome is a third instance of the same composition (**C1**), not a new component |
| Deleted-row label | muted `Badge variant="outline"` | Reused — same treatment `design-06` used for expired/never-captured states |
| Card/table window subtitle ("Last {window}") | plain `p`/`CardDescription`, muted, directly under the card or table title | **New small composition**, reused wherever a card or table displays a figure but sits away from the page-level `WindowSelector` (Proxies table, Retry & replay tiles, Latency card, Destinations table) — states the active window locally so it never has to be inferred from a control that may be off-screen (AC8; **C2**) |
| Trend accessible table — bucket-conditional row (Proxy Show only) | day bucket: `TableCell` wrapping a `Link`, unchanged from `Q-11-04`. Hourly bucket: plain `TableCell`, no `Link` — same "not a link" treatment already given the Dashboard Proxies table's rate cells (Flow B step 5) | **New conditional composition** on the existing linked-cell pattern — no new primitive, no disabled/greyed-out variant; the cell is either a link or an ordinary value, never a link-styled non-control (PRD-11 Amendment B(ii)) |
| Trend accessible table — first column | `TableHead` + `TableCell`, header and value format keyed to the row's bucket size | **New small composition** — header/value read "Date"/calendar-date at a day bucket, "Hour"/date-qualified-hour at an hourly bucket (see Flow C step 3); same component on the Dashboard's table, which additionally never links at either bucket size (Flow E) |

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
  surrounding figure carrying a short `aria-label` summary that names the bucket
  size along with the window, e.g. "Daily delivery and attempt success rate, last
  30 days — see table below for exact values" on the 7d/30d windows, and "Hourly
  delivery and attempt success rate, last 24 hours — see table below for exact
  values" on the 24h window) since the sibling table is the authoritative
  accessible representation.
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

**Second feasibility question for the Principal Engineer, raised by C1 (design-gate
correction):** whether the Events list query can filter to events containing at
least one delivery or attempt at a given outcome and grain (Flow E's Outcome chip,
Screen 4). The shipped list carries no query filter today; window and destination
narrowing are assumed straightforward (an indexed timestamp column, and the
destination relationship the route already resolves for the badge/detail views),
but outcome narrowing is a join/aggregate shape nothing on this surface has needed
before. This spec designs the chip, its copy, and which entry points carry it
(Flow E); it does not assume the query is cheap at both the delivery and the
attempt grain. Written into `Q-11-03` as a new item — see § Handoff.

## Handoff
- **Inputs:** `docs/product/prd-11-analytics.md` (Approved, esp. § Definitions, § UX
  Direction, AC6–AC22, AC25/AC26; **Amendment B**, Product Manager 2026-08-26 — the
  per-window bucket size and the day-grain-only drill-through obligation this
  revision carries); `docs/questions/prd-11-q-11-01-analytics-
  dashboard-scope.md` (RESOLVED — Tier 3, both units binding, drill-down = the
  proxy, windows/buckets, indefinite retention, pre-#6 row treatment);
  `docs/questions/prd-11-q-11-02-throughput-and-delivery-targets.md` (RESOLVED — no
  target, no verdict layer, the four fixed definitions); `docs/questions/prd-11-q-
  11-03-stats-lifecycle-and-aggregation.md` (**OPEN**, Principal Engineer — this
  spec is written to hold under either answer to items (5)/(6)/(9)/**(10)**, and
  names the one place, item 9 above, where a "yes" to feasibility is additive rather
  than a rework; **item (10) is new**, raised by this correction pass to land
  **C1** — whether the Events list query can filter events by outcome at a given
  grain, see § Open Questions above); `docs/design/design-06-retry-replay.md` (Events-list/detail shape this
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
- **Dependencies:** **the new-dependency pair is decided** — `chart.js` `^4` plus the
  Owner-suggested `@j-t-mcc/vue3-chartjs` wrapper — named in `docs/product/prd-11-
  analytics.md` § Handoff as a **new-dependency Owner gate**, ruled by the Project
  Owner on 2026-08-26: both packages adopted, the local-wrapper alternative
  considered and not taken (`docs/plans/plan-11-analytics.md` § Owner rulings on
  both flags, § Owner ruling on T25's check-2 finding). This spec did not itself
  approve the dependency — that approval sat with the Principal Engineer and the
  Owner — and was written against the assumption that a two-series line chart with
  per-series colour and line-dash control is available. **Specific library
  capabilities this spec assumed and asked the Principal Engineer to confirm:** (a) two-series line charts with
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
- **Outstanding Questions:** None. The prior approval left none blocking (see
  § Approval record below); this Amendment B revision raises no new one — bucket
  size and drill-through availability are both fully specified above, so nothing
  here awaits a PE feasibility answer the way C1 briefly did. Two feasibility
  questions from the prior approval are still folded into the Principal Engineer's
  already-open, non-blocking `Q-11-03` (items (9) and (10)), unaffected by this
  revision; the new-dependency Owner gate named in PRD-11's own Handoff is **ruled**
  (Project Owner, 2026-08-26 — both packages adopted, the local-wrapper alternative
  not taken; `docs/plans/plan-11-analytics.md` § Owner rulings on both flags), not
  something this spec itself approves.
- **Next Agent:** was the **Product Manager**, to approve this Amendment B revision
  against PRD-11 Amendment B (design gate, delegated per `CLAUDE.md`) — see § Amendment B
  changes below for the complete list of what changed and where.
  **Done: approved with no corrections, Product Manager, 2026-08-26** (§ Amendment B
  re-approval). This spec is **fully approved again**, and the next agent is the
  **Principal Engineer**, who amended `plan-11-analytics.md` for the same ruling in
  parallel and needs nothing further from this revision beyond what § Amendment B
  changes states. The hour wording `plan-11` Revision B was waiting on is settled here
  at all three places it named — see § Amendment B re-approval, "The three strings
  `plan-11` named".

## Approval record (design gate)

**Approved by: Product Manager · 2026-08-26 · with six required corrections (C1–C6).**

### Coverage verified against PRD-11's 37 acceptance criteria

Every criterion with a user-facing surface was traced to a screen, state or flow in this spec, and
the spec was checked in the other direction for requirements it invents that the PRD does not carry.

**Covered as designed.** AC7 (both units, team and per proxy — Screen 1's "Deliveries" card and
Screen 2's Analytics card); AC9 (retries and replays visible, not hidden — the "Retry & replay"
row); AC13 (both units always present, delivery-level as headline); AC14(c)/(d) (never a toggle,
tab or dropdown — stated in § Interactions and honoured on every screen); AC15 (all three grains —
team headline, Proxies table, Destinations table); AC16/AC17 (daily series, three windows, 30-day
default — the trend chart plus the `WindowSelector`); AC19 (all four of eventual success, terminal
failure, retry volume, live-vs-replay); AC20 (average plus a high percentile, with the
queue-wait exclusion stated on screen); AC21 (drill-through ends at the shipped per-event surface,
subject to **C1**); AC22 (no target, no verdict — flagged calls 5 and 6 are the load-bearing
defences, plus "no control is conditioned on a figure's value"); AC23/AC24 (team-scoped,
permission-gated, no new gate); AC25/AC26 (no mode or processing-mode filter, gate or differential
treatment); AC27/AC28 (the Events list and its masked viewer are extended, never rebuilt);
AC30 (no roadmap numbers and no unbuilt capability in any user-facing string);
AC31/AC32/AC34/AC35/AC36/AC37 (no affordance exists anywhere — the absence is the compliance, and
the scope note's explicit "no export affordance exists anywhere in this spec" is the right way to
evidence it).

**Covered but incompletely stated — the corrections.** AC8 (**C2**), AC10 (**C1**), AC12 (**C3**),
AC14(a)/(b) (**C4**), AC13's stated-exclusion clause (**C6**).

**No invented requirements found.** The additions this spec makes beyond the PRD's own language —
sortable columns, filter chips, the carried-forward window query parameter, the `analyticsLabels.ts`
file-organisation note — are all design detail in service of a stated criterion, none asserts a
verdict, and none adds a figure, a surface or an egress path. Specifically confirmed absent: **no
export affordance**, **no V8 verdict or threshold layer**, **no merged single success figure and no
unit toggle**, **no per-event-type breakdown**. The charting library is correctly named as an
assumption carrying a **new-dependency Owner gate** that this spec does not approve.

### Rulings on the nine flagged design calls — all nine accepted

1. **Two-unit headline as one card: large primary, smaller secondary beneath it, bridge sentence —
   ACCEPTED.** This is the correct resolution of the PRD's stated hardest problem, and it is the
   shape the UX Direction described: neither hiding the second figure, nor a toggle, nor equal
   prominence with no stated relationship. **Two binding conditions:** the attempt-level figure is
   never collapsible, dismissible or behind a "show more" — it renders whenever the headline does;
   and the bridge sentence stays **descriptive**, never arithmetic that converts one unit into the
   other (AC14(d)). The drafted sentence ("14 attempts failed before these deliveries succeeded")
   satisfies this — it describes attempts *within* those deliveries; a sentence of the form "which
   is 67% once you account for retries" would not.
2. **A zero-denominator rate reads "No deliveries yet" rather than `0%` — ACCEPTED, and the PRD is
   corrected rather than the design.** The Designer is right and AC12's literal wording was wrong:
   `0%` over zero deliveries asserts total failure, which is the same class of false number AC12
   already forbids for latency. **PRD-11 Amendment A(i)** (Product Manager, 2026-08-26) records the
   reading so the Reviewer does not later fail the implementation against the literal phrase. The
   ruling is bounded by **C3**: it governs *rates*, never *counts*.
3. **Analytics card inserted after the header, ahead of Ingest URL — ACCEPTED.** Nothing in the UX
   Direction reserves the existing card order; "extend, do not annex" is about not building a
   separate Analytics area, which this spec honours. Leading with "is this working" matches the
   primary flow the Direction optimises for. **Binding condition:** the zero-traffic collapsed state
   must stay the single message this spec specifies, so a brand-new proxy — where Ingest URL is the
   card the member actually came for — is not pushed down by an empty analytics block.
4. **Per-proxy figures on Show; the Events list gains only filter chips — ACCEPTED.** The UX
   Direction expressly allowed either surface, and keeping an already-approved surface untouched is
   the lower-risk half of that choice.
5. **Neutral default sort (name / creation order), user-sortable by column — ACCEPTED, and the
   reasoning is correct.** A built-in worst-first default is the product asserting a ranking, which
   is the judgement AC22(b) forbids; user-initiated sorting reaches the same evidence without the
   product declaring it. **Binding condition:** no sort control, label, tooltip or default may use
   evaluative wording ("worst", "best", "problem", "unhealthy") — column name plus
   ascending/descending only.
6. **Neutral `chart-1`/`chart-2` trend lines rather than red/green — ACCEPTED.** Correct reading of
   AC22(b): the trend chart is exactly where a "getting worse" reading would smuggle in a verdict.
   Note that the two lines encode *units*, not outcomes, so a category palette is right here;
   AC22(b)'s permission for category colour (succeeded vs failed) remains available elsewhere.
7. **Pre-designed labelled substitute for the latency tail — ACCEPTED as a contingency.** The
   requirement side is settled and unchanged: a substitute must expose the tail and be labelled for
   what it is, and **a bare average does not satisfy AC20**. **The trigger is not the Product
   Manager's to pull** — whether a true percentile is feasible, and which substitute is chosen if it
   is not, is the **Principal Engineer's** call at `Q-11-03(6)`. If a substitute lands, it does so
   under this approval; no re-approval is required provided it is labelled.
8. **Conditional "as of {time}" caption slot — ACCEPTED.** Designing so either answer fits without
   rework is the right response to an open question. **The trigger is the Principal Engineer's**
   (`Q-11-03(6)`): if the answer is a rollup, AC11 makes the caption **mandatory and concrete** (a
   real timestamp, not "recently"); if the answer is live computation, the caption may be omitted
   entirely — "as of now" is acceptable but adds nothing.
9. **Destinations card shows average latency only, no per-destination percentile — ACCEPTED, and
   the PRD is clarified.** **PRD-11 Amendment A(ii)** (Product Manager, 2026-08-26) records that
   AC16's daily series and AC20's percentile are obliged at the **team and proxy** grains; the
   destination grain carries the both-unit figures and an average. Adding a per-destination
   percentile later is additive and needs no gate.

**On the feasibility question folded in alongside `Q-11-03`** (whether a deleted destination's or
proxy's **View events** link can resolve a soft-deleted parent): the **requirement** half is ruled
here and the **feasibility** half is the **Principal Engineer's**. Ruling: a **View events** link on
a deleted row is a *read of history*, not an action against the deleted object, so it is permitted
by the UX Direction's "offer no actions against something that no longer exists" — which bars
edit, manage and delete affordances, not navigation into the past. AC6 requires the row to stay
counted, attributable and labelled; it does **not** require it to stay navigable. The question is
now written into the Principal Engineer's open question doc as **`Q-11-03(9)`**, and this spec's
stated fallback (the action degrades to the same muted, non-interactive treatment as its **Deleted**
label) is **pre-approved** — landing it needs no design rework and no re-approval.

### Six required corrections, returned to the Designer

**(C1) The drill-through does not land on the records behind the figure — Flow E carries no
outcome filter, and the count-to-rows relationship is unstated.** *(The one correction whose landed
text returns to the Product Manager; see the re-approval note.)*

Flow E step 2 says the Events list opens pre-filtered "**at minimum by the window** and — when the
entry point was a specific destination — by that **destination**". Neither filter narrows the list
to *failures*. So a member who clicks a "Terminal failures: 12" tile arrives at every event in the
window and must hunt for the twelve. That misses two things PRD-11 requires:

- **AC10** — "an aggregate reconciles with the records a member reaches by drilling into it". A
  window-and-destination filter does not reconcile with a failure figure; it reconciles with the
  total.
- **The UX Direction's primary flow** — the member "lands in the already-shipped per-event surface
  **at the events behind what they were looking at**", so that "a bad number is the start of a
  diagnosis rather than the end of one". Landing on the unfiltered window is the end of one.

**Required — two parts:**

**(a) Specify the filter set per drill-through entry point, and carry an outcome filter from every
failure-shaped one.** Enumerate the entry points this spec already names — the terminal-failure
tile, a Proxies-table cell, a Destinations-row cell, a trend-chart point via its data table — and
state for each which filters travel (window, destination, outcome, and any others). Every entry
point whose source figure is failure-shaped must narrow the list to the failing records; a
success-shaped or total-shaped entry point need not. The filter chips this spec already designs are
the right surface for it: an outcome chip is a fourth chip, removable exactly as the others are, and
requires no new component.

**(b) State how the figure's count relates to the rows shown, because the two cannot be
row-for-row identical.** AC21 fixes the floor of the drill-down at the **per-event** surface, while
AC10's figures are denominated in **deliveries** and **attempts** — one event with three failing
destinations is three failed deliveries but one row. The spec must say plainly what the filtered
list contains (the events *containing* the counted deliveries or attempts) and must not present the
list as if its row count should equal the figure. **Do not resolve this by building a delivery-level
or attempt-level list** — AC28 forbids a second events surface, and AC21 fixes the floor where it
is. A short statement in Flow E, and copy on the filtered list that names what is being shown, is
what is wanted.

*If part (a) proves impossible on the shipped Events list without a change that is genuinely
technical rather than presentational, that is a **Principal Engineer** question to raise against
`Q-11-03` — not something to leave unstated in the spec. Silence here is what this correction
exists to prevent.*

**(C2) Every figure-bearing block must state the window it covers, not inherit it from a
page-level selector.** AC8 requires that **no** number, rate or series point appears without its
unit **and its window** visible to the member. This spec satisfies it for the headline figures,
whose captions read "· last 30 days", and fails it everywhere else: the Dashboard **"Proxies"**
table, the **"Retry & replay"** tiles, the **"Latency"** card, and Screen 3's extended
**"Destinations"** table all carry figures with no window on them, relying on the `WindowSelector`
at the top of the page. On the Show page the Destinations card sits several cards below the
selector, so the window is routinely off-screen when the numbers are read — and the Destinations
card is the grain PRD-11 says a member actually acts on. **Required:** each card or table that
displays a figure states its active window in its own header or caption (e.g. a card subtitle "Last
30 days", or a caption on the table), so the window travels with the numbers rather than with the
control that set them. The `WindowSelector` stays exactly where this spec puts it — this adds a
statement, not a second control, and no per-card window selection is introduced.

**(C3) In an empty window, count figures must read `0` — only rates and measures take the "no
data" treatment.** Flagged design call 2 is accepted for **rates** (PRD-11 Amendment A(i)), but
Screen 1's zero-window state applies it too widely: it replaces the **"Retry & replay"** tiles and
the **"Trend"** card with a "No data for this period" message. Eventual success, terminal failure,
retry volume and live-vs-replay are **pure counts** (§ Definitions, "Count figure"), and AC12 —
unchanged on this point by Amendment A — requires them to read **zero**, with an indication of what
would populate them. Zero terminal failures is a true and useful statement; "no data" withholds it.
**Required, per element of Screen 1's "Zero deliveries in window" state and Screen 2's zero-traffic
state:**
- **"Retry & replay" tiles — must render, showing `0`** for each of the four figures, with the
  card's existing explanatory line. They are not replaced by a message and not hidden.
- **The Dashboard "Proxies" table's Terminal-failures column — `0`**, not "No deliveries yet";
  only that row's two *rate* columns take the no-rate treatment.
- **The headline's count captions — `0 of 0 delivered`** (already correct as drafted; keep).
- **Latency — "No data"** (already correct; it is a measure figure, AC20).
- **The trend chart — the no-data treatment stands as drafted.** Its plotted series are *rates*,
  so a flat line at 0% would assert total failure; this spec's own reasoning for that is correct
  and is ratified.
*Net effect: within Screen 2's collapsed empty card, the collapse itself remains approved (flagged
call 3's binding condition), so this correction governs Screen 1's zero-window state and any place a
count is shown at all — a count that is displayed must be displayed as `0`.*

**(C4) Every success, failure and retry figure must name its unit in its own label — four labels
currently do not.** AC14(a) requires the unit in the figure's own label, and AC14(b) makes an
unlabelled failure count a defect regardless of which unit it uses. The two headline figures and the
two rate table columns satisfy this ("Delivery success", "Attempt success — destination health").
These do not:
- **"Terminal failures"** (Dashboard "Proxies" table column, Screen 1);
- **"Eventual success"**, **"Terminal failure"**, **"Retry volume"** and **"Live vs replay"** (the
  four "Retry & replay" tiles, Screens 1 and 2).
Each is unit-bearing — eventual success, terminal failure and live-vs-replay are **delivery**-grained
(PRD-11 § Definitions), retry volume is **attempt**-grained — and a member reading the tile row
cannot tell which is which. **Required:** give each label its unit explicitly, in the label itself
and not in a tooltip or a card-level note (e.g. "Terminal failures (deliveries)", "Eventual success
(deliveries)", "Retry volume (attempts)", "Live vs replay (deliveries)"; exact wording is the
Designer's). *This is the single most load-bearing criterion on this surface: the tile row sits
directly beneath a headline in one unit and a secondary figure in the other, which is exactly where
an unlabelled count gets read in the wrong one.*

**(C5) The sample figures in Screens 1 and 2 do not agree with their own counts.** Screen 1 shows
`dd "96%"` above the caption `"42 of 42 delivered"`; 42 of 42 is **100%**, not 96%. Screen 2 repeats
the same pair. The attempt-level example is internally consistent (28 of 42 = 67%, and the bridge
sentence's 14 failed attempts reconciles), so only the delivery-level figure is wrong. **Required:**
make the delivery-level sample read `100%` with `42 of 42 delivered`, on both Screen 1 and Screen 2
— which also restores the exact 100%-vs-67% pairing PRD-11 § Problem 5 uses as the canonical
illustration of why both units are shown. *Why this is not cosmetic: an illustrative mock whose
arithmetic is wrong is the thing most likely to be copied verbatim into the implementation and into
a test fixture, and a figure that disagrees with its own caption is precisely the defect AC8 and
AC14(d) exist to prevent.*

**(C6) AC13's pre-#6 exclusion is stated nowhere in this spec — record the decision rather than
leave it absent.** AC13 requires that pre-#6 attempt rows (`delivery_id` NULL) are excluded from
every delivery-level figure and included in attempt-level ones, and that **"the exclusion is stated,
not silent"**. This spec designs no surface for that statement, and does not say it decided not to.
**Required:** add one line to § Decisions carried forward recording the Product Manager's ruling —
**no user-facing statement of the pre-#6 exclusion is designed**, because the affected rows exist
only in development and CI data and none exists in production (PRD-11 AC13, D-11-7, F4), so AC13's
"stated" obligation is discharged in the technical artifacts rather than on screen; **if such rows
ever reach production data, a user-facing statement becomes required** and returns to the Designer.
*Recording it makes the absence a decision the Reviewer can check rather than an omission they must
guess about.*

### Re-approval

- **C2, C3, C4, C5 and C6 land under this approval — no re-approval.** Each is stated concretely
  enough to implement without a follow-up question, and none reopens a design choice: C2 adds a
  statement, C3 and C4 correct labelling and empty-state treatment against named criteria, C5 fixes
  arithmetic in an illustration, C6 records a decision already taken. The Designer lands them and
  hands on.
- **C1 returns for a section-scoped re-check, not a re-approval.** Parts (a) and (b) require the
  Designer to *choose* the filter set per entry point and the copy that states the count-to-rows
  relationship — choices this gate has constrained but not made. When landed, the Product Manager
  re-reads **Flow E and Screen 4 only** and confirms them; the rest of the spec is approved and is
  not reopened. If C1's resolution turns out to need something from the Principal Engineer, that
  travels on `Q-11-03` and does not block the rest of the spec from proceeding.
  **Resolved 2026-08-26: the re-check was performed and C1 is cleared — see § C1 re-check
  outcome at the end of this document.**
- **Handing on is not blocked.** The Principal Engineer may begin Technical Design against the
  approved spec while C1 is landed — none of the six corrections changes the data a figure is
  computed from, the grains, the windows, or the surfaces involved, so nothing the Principal
  Engineer would decide is contingent on them.

### Non-blocking notes (no action required)

- **The `Q-11-03` citation in § Handoff now resolves.** The Handoff says this spec is written to
  hold under either answer to items "(5)/(6)/(9)", but `Q-11-03` had only eight items when this
  spec was written. The Product Manager has since written the Designer's soft-deleted-parent
  feasibility question into that doc as **item (9)**, exactly as § Open Questions asked, so the
  citation is now correct as it stands. No edit needed.
- **The `analyticsLabels.ts` data-const recommendation is endorsed as written** — non-gating, and
  consistent with the `design-06` precedent for shared label/variant pairs. C4's unit-bearing
  labels are a good argument for it: those strings now appear on two surfaces and must not drift.
- **Replay actions on a drill-through from a deleted destination.** A filtered Events list still
  renders the per-row replay action `design-06` shipped. #11 changes nothing there, and whether
  replaying to a deleted destination is meaningful is a **#6** question, not this gate's — noted
  only so it is not mistaken for something C1 introduced.
- **The colour-resolution note in § Handoff is correct and worth keeping.** It is a technical note
  for the Principal Engineer with no UI-visible consequence, and this gate makes no ruling on it.

### Corrections landed (Designer, 2026-08-26)

| Correction | Status | Where |
|---|---|---|
| **C2** — window stated per figure-bearing block | **Landed, no re-approval needed** | Screen 1 mockup (Proxies/Retry & replay/Latency card subtitles), Screen 2 mockup (Retry & replay/Latency subtitles), Screen 3 mockup (Destinations card subtitle), Components table (new "Card/table window subtitle" row) |
| **C3** — counts read `0` in an empty window, only rates/measures take "no data" | **Landed, no re-approval needed** | Screen 1 § States, "Zero deliveries in window" bullet, rewritten per element |
| **C4** — unit named in each of the five labels | **Landed, no re-approval needed** | Screen 1 mockup (Terminal failures (deliveries); four Retry & replay tile labels), Screen 2 mockup (same four tile labels) |
| **C5** — sample figures corrected to 100%/67% | **Landed, no re-approval needed** | Screen 1 mockup, Screen 2 mockup |
| **C6** — AC13 pre-#6 exclusion decision recorded | **Landed, no re-approval needed** | § Decisions carried forward, new bullet |
| **C1** — filter set enumerated per drill-through entry point, outcome filter carried from failure-shaped figures, count-to-rows relationship stated | **Landed and cleared** — section-scoped re-check of Flow E and Screen 4 performed by the Product Manager, 2026-08-26; see § C1 re-check outcome below | Flow B (new step 5), Flow C (steps 3–4 annotated), Flow D (step 3 annotated as total-shaped, no outcome), Flow E (rewritten: entry-point table, count-to-rows statement), Screen 4 mockup and States (Outcome chip, explanatory copy, PE dependency note), Components table (Filter chips row), § Open Questions (new PE feasibility paragraph), § Handoff (`Q-11-03` citation, Outstanding Questions); the underlying feasibility question is raised as **new item (10)** on `docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md` |

### C1 re-check outcome (Product Manager, 2026-08-26)

Scope of this re-check was **Flow E and Screen 4 only**, as reserved at § Re-approval.
C2–C6 were not reopened, and no other section of the spec was re-examined.

**C1 is cleared. Nothing further is required of the Designer.**

- **(a) Filters per entry point — satisfied.** Flow E now enumerates every drill-through
  entry point in one table, each with its shape and the exact filters it carries, and
  states why team-level figures (the Dashboard headline, the Dashboard "Retry & replay"
  tiles, the Dashboard Trend chart) are **not** entry points: no single proxy resolves
  from them, and a team-wide events list would be the second events surface AC28 forbids.
  A member starting from a team-level figure still reaches evidence by way of the
  Proxies table row for the proxy in question, which is a drill-through entry point.
- **(b) Outcome filter from failure-shaped figures — satisfied.** All three failure-shaped
  entry points (the Proxies table's Terminal failures (deliveries) cell, the proxy's
  Retry & replay Terminal failure tile, and the Trend chart's per-day/per-unit table
  cell) carry an outcome filter at the unit the source figure is denominated in. A member
  reading a failure figure therefore lands on that figure's failures, not on the whole
  window — which is what **AC10** asks for.
- **(c) Count-to-rows relationship — satisfied.** Flow E states that an outcome-filtered
  list shows the **events containing at least one matching delivery or attempt**, not one
  row per counted delivery or attempt, and gives both directions of the mismatch (fewer
  rows than the count; attempt-level matches inside an event whose delivery ultimately
  succeeded). Screen 4 carries that statement into member-visible copy, rendered only
  while an Outcome chip is active. Per **AC10** each unit reconciles within its own unit;
  the copy prevents a member from reading the row count as the figure.
- **Entry-point classification — confirmed as designed, including the one the Designer
  flagged.** The Destinations row's **View events** action is correctly **total-shaped**
  and correctly carries **no outcome filter**: that row's figures are Delivery success,
  Attempt success and Latency (avg) — rates and a measure over all of that destination's
  traffic, not a failure count. Attaching an outcome filter there would narrow the list to
  records the row's own figures do not denominate, which would break AC10's reconciliation
  rather than serve it. Every other entry point is classified correctly, and the two
  rate-shaped Dashboard cells are correctly not links.
- **AC21 / AC28 — nothing forbidden landed.** Screen 4 extends the shipped Events list
  only: filter chips above an otherwise unchanged table, the existing empty-state card
  with adjusted copy and a Clear filters link, and the existing event detail at the floor.
  No second received-events list, no second event-detail view, and **no change whatsoever
  to the masked payload viewer, its reveal behaviour, or its gate** (settled at
  PRD-06 AC25 / Q-06-02, and #10's to change).

**Feasibility dependency, noted not held.** Whether the Events list query can filter events
by delivery/attempt outcome at either grain is a technical question, correctly routed to the
Principal Engineer as `Q-11-03` **item (10)** rather than assumed. Routing it was the right
call and is **not** a reason to hold C1: this gate rules on the design as specified. If the
Principal Engineer finds the filter unachievable as designed, that returns to the Designer as
a fresh correction — item (10) deliberately pre-approves no fallback, because AC10 requires
the outcome filter to exist.

## Owner-directed presentation changes (Project Owner, 2026-08-26)

Recorded so this specification does not silently drift from what ships. These were directed by
the Project Owner after reviewing the built feature, outside the design gate and without a
pipeline pass. **None of them changes a figure, a grain, a unit, a rate, a bucket, a
drill-through obligation, or any acceptance criterion** — they are presentation only, which is
why they were taken as Owner instruction rather than returned through Requirements.

1. **"95th percentile" is now rendered "p95".** The label lives in
   `resources/js/data/analyticsLabels.ts` as `LATENCY_P95_LABEL`, still the single source of
   every unit-bearing string. Both this document's Screen 1 and Screen 2 mockups still write
   the long form; the short form is what ships. The figure is unchanged — it remains the exact
   nearest-rank 95th percentile ruled in `Q-11-03`.

2. **Screen 2's single "Analytics" card is split into four sibling cards** — Deliveries, Trend,
   Retry & replay, Latency — matching the four the Dashboard already renders. This document's
   Screen 2 describes one combined card; that is superseded here. The reason is consistency: the
   two screens presented the same figures in visibly different containers, and the Dashboard's
   shape was chosen as the common one. Screen 2's other cards (Ingest URL, Response,
   Destinations, Retry policy) are untouched and still follow the analytics block.

3. **The Dashboard's Proxies table moves below the analytics cards**, beneath Latency, so both
   screens open with the same four cards in the same order before diverging into
   screen-specific content.

4. **Screen 2's window selector moves from inside the Analytics card to the page header**, where
   the Dashboard's already sits. This is what this document already meant by calling the selector
   "page-level", and it keeps the selector reachable in the zero-traffic state — the property
   `T19` preserved for the same reason.

5. **Spacing and type scale were opened up** across both screens: card headings move from
   `text-sm font-medium` to `text-base font-semibold`, the two-tier Deliveries list gains an even
   vertical rhythm in place of a `gap-1` list patched with `mt-4`, and the latency figures move
   from `text-sm` to `text-lg font-medium` so a measure does not read as body copy. No token,
   colour or chart style changed.

6. **The window selector's active state now uses the filled `default` button variant.** It
   previously used `variant="outline"` plus `bg-accent`, which is also the hover colour for an
   outline button, so the current window was hard to distinguish from a hovered one. The same
   defect and the same fix applied to the paginator on the proxies and events lists.

**Not reopened by any of the above:** the two-unit rule, the no-verdict rule, Amendment A(i)'s
zero-denominator treatment, Amendment B's bucket sizes and day-grain-only drill-through, the
`aria-hidden` canvas with no click target, and corrections C1–C6.

## Amendment B changes (Designer, 2026-08-26)

Raised by the Project Owner from the built feature's behaviour: on the 24-hour window the
trend chart rendered a single point at the far left of an otherwise empty chart, because a
daily series across a 24-hour window can only ever produce one or two points. PRD-11
Amendment B rules **(i)** the bucket size depends on the window — hourly on 24h, daily on
7d/30d — and **(ii)** the per-bucket drill-through is obliged at day buckets only; an hourly
bucket owes none, and must never carry a day-grained one. This section is the complete record
of what changed in this spec to carry that ruling, matching the seven spots PRD-11 Amendment B
named as the Designer's plus everything else this revision found.

**The two decisions this revision had to make that PRD-11 Amendment B left to the Designer:**

1. **How a 24-hour trend row reads without a drill-through link.** It reads as a plain value —
   the same weight, colour and typography as every other cell in the table, no underline, no
   `Link` wrapper. This is the identical treatment this spec already gives the Dashboard
   Proxies table's rate-shaped cells (Flow B step 5) and the "Retry & replay" tiles that are
   not failure-shaped (Flow C step 4) — a precedent already established for "not every cell in
   a stats table is a link," so no new visual language was invented. It is **not** a disabled
   control, a greyed-out link, an empty action column, or copy phrased as a limitation — per
   Amendment B(ii)'s explicit instruction, and per this spec's own standing rule that no control
   is ever conditioned on a figure's value (§ Interactions).
2. **How a member still reaches the events behind a 24-hour figure.** Not through the trend
   table at all — through the three window-grain entry points PRD-11 Amendment B itself names
   as already working unchanged on the 24-hour window: the Dashboard Proxies table's Terminal
   failures cell, the proxy's own Retry & replay Terminal failure tile, and the Destinations
   table's View events action. All three carry the 24-hour window into Flow E exactly as they
   do for 7d/30d, landing on the per-event surface, where each event's own timestamp is how a
   member finds a particular hour (AC21, unchanged, PRD-06 AC25). Flow C step 3 and the Flow E
   entry-point table state this explicitly rather than leaving it implied.

**The accessible table's first column.** Header and value format now switch on bucket size,
same component on both Dashboard and Proxy Show: at a day bucket, header **"Date"** with a
calendar-date value, unchanged. At an hourly bucket, header **"Hour"** with a value naming the
calendar date together with the hour (e.g. `Aug 25, 2:00 PM`) — never a bare hour-of-day. A
rolling 24-hour window ordinarily crosses a calendar-date boundary, so an hour label without
its date would force a member to infer which day a point falls on from its position in the
table — exactly what Amendment B(i) forbids ("a member must never have to infer a point's
period from its position"). This is a new decision beyond simply relabelling: a bare "2 PM"
column would have satisfied AC8's letter (every point has *a* label) while still leaving a
member to guess the date from position, which is the same failure mode Amendment B(i) names.

**Whether "the axis already states the window, no separate caption needed" still holds.** For
day buckets, yes, unchanged — a calendar date is unambiguous on its own, and 7/30 date labels
in sequence convey the window's span without help. **For hourly buckets, the reasoning does
not carry over unmodified**, for the same reason as the table's first column: bare hour-of-day
tick labels ("2 PM", "3 PM", …) do not by themselves disambiguate which calendar day they
belong to, and a rolling 24-hour window will typically cross one. Rather than add a caption
(which the original reasoning was specifically written to avoid), this spec resolves it the
same way as the table: the axis states the date at the tick where the window crosses into a
new calendar day, so the date is visible exactly where it is needed and nowhere else — the
axis still states the window it spans without a separate caption, on either bucket size, once
that one qualification is added. See Screen 1's Trend card mockup and § Accessibility.

**Every location PRD-11 Amendment B named, and where it landed:**

| # | Location named by Amendment B | Status | Where |
|---|---|---|---|
| 1 | § Summary's "Daily buckets; 24h/7d/30d windows" line | **Landed** | § Decisions carried forward, "Bucket size varies by window" bullet (this spec has no separate "§ Summary"; the equivalent line lived in § Decisions carried forward) |
| 2 | Screen 1's Trend card — "one row per day" | **Landed** | Screen 1 mockup, Trend card block |
| 3 | Screen 2's Analytics card trend and its "View as table" fallback | **Landed** | Screen 2 mockup, trend chart line |
| 4 | Accessible table's first column — header and value format | **Landed** | Flow C step 3 (full statement), Components table (new "Trend accessible table — first column" row), Screen 1/2 mockups (cross-reference) |
| 5 | Flow C step 3 — "the **daily** trend chart" and its per-row link "narrowing to that single day" | **Landed** | Flow C step 3, rewritten with day/hourly sub-bullets |
| 6 | Flow E's entry-point table row for the Proxy Show trend "View as table" row | **Landed** | Flow E preamble (new sentence) and entry-point table (split into two rows: day-bucket entry point, hourly-bucket non-entry-point) |
| 7 | The chart's own axis labelling — must state hours on 24h; check the "no separate caption" reasoning | **Landed, reasoning qualified, not abandoned** | Screen 1 mockup (Trend card, axis note), § Accessibility (aria-label example, bucket-conditional) |

**Found beyond the seven named locations, and fixed:**

- **Overview paragraph's "A daily trend chart shows both figures as two lines across the
  window"** — the feature-level summary at the top of the spec, unnamed by PRD-11 Amendment B
  but carrying the same stale "daily" claim. Now states the chart is hourly on 24h, daily on
  7d/30d.
- **§ Decisions carried forward's "Tier 3, both units, both binding" bullet** — listed "daily
  trend" as shorthand for one of Tier 3's elements. The word is dropped (now "trend") since the
  bucket size is no longer uniformly daily; the bullet was a restatement of the Owner's ruling,
  not itself a granularity requirement, so nothing else about it changes.
- **Components table** — gained two new rows (the bucket-conditional link/non-link cell, and
  the bucket-conditional first column) rather than folding the change silently into the existing
  "Sortable column header" row, so a reader scanning Components sees both new behaviours named.
- **§ Handoff's PRD input line and Outstanding Questions/Next Agent** — updated to cite
  Amendment B and to make clear this revision, not only the original spec, is what returns to
  the Product Manager.

**What Amendment B does not touch, confirmed unchanged:** the two-unit labelling rule (AC14),
the no-verdict rule (AC22), the empty-state treatments (AC12, Amendment A(i), corrections
C3/C4), the chart colour tokens (*Flagged design call 6*), and the three-chip row on Screen 4
(C1). None of the six prior corrections (C1–C6) is reopened by anything in this revision — no
edit above touches Flow D, Screen 3, the Destinations card, or any figure's unit, empty state,
or colour.

**Escalated rather than decided:** nothing. Every decision PRD-11 Amendment B left open —
the "not a link" presentation, the route to the evidence on 24h, the accessible table's hour
value format, and whether the axis-caption reasoning survives — was a detail-level UX call
within an already-ruled requirement (Amendment B(ii) states the obligation; this spec states
the presentation), which is squarely inside the Designer's decision authority. No requirement
gap and no feasibility doubt surfaced while making them.

**Status:** this revision was **not self-approved** — it returned to the **Product Manager**
per § Handoff, Next Agent, above, and was **approved with no corrections on 2026-08-26**.
See § Amendment B re-approval (design gate), immediately below.

## Amendment B re-approval (design gate)

**Approved by: Product Manager · 2026-08-26 · no corrections required.**

**Scope of this gate.** This is a **delta re-approval of the Amendment B revision only**.
The rest of this spec was fully approved earlier the same day, once corrections C1–C6
landed and C1 cleared its section-scoped re-check, and nothing else has moved since. What
was re-read here: the status block, § Decisions carried forward's bucket bullet, the
Overview paragraph, Flow C step 3, Flow E's preamble and entry-point table, Screen 1's
Trend and Latency card blocks, Screen 2's trend line, the two new Components rows,
§ Accessibility's chart bullet, § Handoff, and § Amendment B changes in full. Every one of
those passages was checked against PRD-11 § Amendment B, which the Product Manager authored.
**No section outside that list was reopened, and this gate makes no ruling on any of them.**

**Verdict: the revision carries Amendment B faithfully and completely.** Every one of the
seven locations Amendment B named is changed, the five the Designer found on its own are
genuine finds that would each have shipped a stale "daily" claim, and the three substantive
calls the revision had to make inside the ruling are all correct. The rulings on those three
follow.

### Call 1 — how an hourly row reads without a drill-through: **ACCEPTED as designed**

Amendment B(ii) requires that the absence of a link "must not render a disabled control, a
dead link, an empty action column that looks like a missing value, or an explanation phrased
as a limitation". The revision's answer — the rate cells render as **plain values**, same
weight, colour and typography as any other cell, no underline, no `Link` wrapper, no
`aria-disabled`, no muted styling, no "unavailable" copy — satisfies that constraint on every
clause, and satisfies it in the strongest available way: **there is no control at all**, so
there is nothing that can read as a broken one. Reusing the treatment this spec already gives
the Dashboard Proxies table's non-linking rate cells (Flow B step 5) and the three
non-failure-shaped Retry & replay tiles (Flow C step 4) is the right instinct — it invents no
new visual language, and it means the "not every cell in a stats table is a link" convention
a member meets on the Dashboard is the same one they meet here.

**On whether a member is left wondering why some rows link and others do not:** they are not,
because **within any single rendering of the trend table every row is treated identically**.
The bucket size is a property of the window, so a 24-hour table is all hourly and links
nowhere, and a 7-day or 30-day table is all daily and links everywhere. A member never sees a
table where some rows are links and their neighbours are not — the case that would genuinely
demand an explanation. Across a window switch the treatment does change, but a window switch
is a full navigation that reloads the surface, and this spec has never obliged a screen to
explain what a different window would have shown. The Dashboard's trend table, which links at
neither bucket size (Flow E, "a team-level figure is not a drill-through entry point"), is
unaffected and stays as the C1 re-check left it.

*This gate deliberately does not require any copy explaining the absence.* Amendment B(ii)
forbids "an explanation phrased as a limitation", and at this grain any such copy would be one.

### Call 2 — the hour value format and the "Hour" header: **ACCEPTED as designed**

`Aug 25, 2:00 PM` is the right value and a bare `2:00 PM` would have been wrong. The Designer's
reasoning is exactly the reasoning Amendment B(i) was written to force: a rolling 24-hour
window ordinarily crosses a calendar-date boundary, so a bare hour-of-day leaves a member to
work out which of two days a row belongs to **from where it sits in the table** — which is
precisely what "a member must never have to infer a point's period from its position"
forbids. The revision's own note that a bare "2 PM" would have satisfied AC8's letter while
breaching Amendment B(i) is correct, and catching that is the substance of this call rather
than a restatement of it.

The format reads well in a table cell: it is short, it sorts visually in the order the rows
are already in, and it carries no punctuation a member has to parse. **The convention that the
value names the hour the bucket *begins* is accepted**, and it is the same convention a day
bucket's date already uses for the day it spans, so one rule covers both grains.

**The header change to "Hour" is right.** The first column names the *period* a row covers,
and at an hourly bucket that period is an hour; "Date" over a row describing one hour would be
the misleading label, not the safe one. The header naming a period while the value carries a
date qualifier is not a mismatch — the qualifier is there to disambiguate *which* hour, not to
redefine what the column holds. "Date" stays at day grain, unchanged.

### Call 3 — the chart axis, and no caption: **ACCEPTED as designed, with the reasoning recorded**

The Designer re-checked a piece of previously-approved reasoning under new conditions instead
of assuming it still held, found that it holds for day buckets and does not hold unmodified
for hourly ones, and fixed it at the point of failure. That is the right method and it reached
the right answer. Qualifying the axis at the tick where the window crosses into a new calendar
day — rather than adding a caption — keeps the original decision intact instead of trading it
away, and puts the date where a member needs it and nowhere else.

**This gate is content with no caption**, and records why, so the decision is checkable later
rather than re-argued:

- the chart canvas is **not the authoritative representation of the data** and never has been
  (§ Accessibility: the canvas may be `aria-hidden`, and the paired "View as table" is the
  authoritative accessible representation). Every row of that table is fully date-qualified
  under call 2, so a member who needs an unambiguous period for any point has one, always;
- the window is stated independently of the chart, by the page-level `WindowSelector` and by
  correction **C2**'s per-card "Last {window}" subtitle, so no member depends on the axis to
  learn what period they are looking at;
- a date appearing at the midnight tick is a conventional, well-understood axis treatment, and
  it resolves the whole axis rather than one tick: ticks left of it are the earlier day, ticks
  right of it the later one.

**One residual, ruled and not returned.** The spec's claim that a member is "never left to
infer the date from a tick's position" is true of the accessible table absolutely, and true of
the chart by the day-boundary convention just described — but a tick to the *left* of the
crossing does take its date from being left of it. **Date-qualifying the first tick as well is
permitted and not required**, and adding it later is additive rather than a design change.
This is not a correction: no figure is misread, the authoritative surface is unambiguous, and
AC8 is discharged by the table and the window subtitle rather than by the axis.

### The three strings `plan-11` named — all three are covered, so no question document is owed

`plan-11-analytics.md` (Revision B) states that if this revision omits an hour wording for any
of three named strings, a question document goes back to the Designer rather than an
implementer inventing one. The Principal Engineer escalated this to the design gate. **All
three are covered by this revision. No question document is required, and no implementer needs
to invent a string.**

| `plan-11`'s string | Covered where | The wording this spec fixes |
|---|---|---|
| **(a)** the chart's accessible summary — `trendChartAriaLabel()` currently renders "**Daily** delivery and attempt success rate…", which is false on the 24-hour window | § Accessibility, "Charts are not the only way to reach the data" | **Bucket-conditional.** "**Hourly** delivery and attempt success rate, last 24 hours — see table below for exact values" on the 24h window; "**Daily** delivery and attempt success rate, last 30 days — see table below for exact values" on the 7d/30d windows. The bucket word and the window phrase both vary; the window phrase is illustrative for 30d and reads "last 7 days" on 7d, as it already did before this revision |
| **(b)** the trend table's first-column header | Flow C step 3 ("the table's first column follows the same split"); Components table, "Trend accessible table — first column"; Screen 1 and Screen 2 mockups | **"Hour"** at an hourly bucket, **"Date"** at a day bucket. Same component and same rule on both the Dashboard's table and Proxy Show's |
| **(c)** a point's period label | Flow C step 3; § Amendment B changes, "The accessible table's first column" | **Date-qualified hour** at an hourly bucket — `Aug 25, 2:00 PM`, naming the hour the bucket begins, **never a bare hour-of-day**. Calendar date at a day bucket — `Aug 12, 2026` — unchanged |

**The PE's finding on `trendChartAriaLabel()` is correct and this gate confirms it**: the
current string is false on the 24-hour window and must not survive into the built surface. The
replacement is specified above and needs nothing further from the Designer or the Product
Manager.

**One thing (a) does not cover, named so nobody reads it as covered.** This spec fixes the
*wording* of the summary and the table; it does not name the chart's **axis tick** strings,
beyond requiring that the axis states the period in the bucket's own unit and carries the date
at the day-boundary tick (call 3 above). Tick formatting inside the charting library is an
implementation detail, not a copy decision, and it is not one of the three strings `plan-11`
reserved.

### On the historical approval record being left unedited: **the Designer's judgment is accepted**

The revision deliberately left § Approval record, § Corrections landed and § C1 re-check
outcome untouched, two stale "daily series" lines and all, on the grounds that editing a dated
record of a past gate would misrepresent what was true at that gate. **That judgment is right
and this gate adopts it as the rule for this document.** Those sections are not a description
of the current design; they are the record of a verdict given on 2026-08-26 against the spec as
it stood that morning, and the phrase "AC16's daily series" is an accurate quotation of what
AC16 then said. Rewriting it would make the record claim the gate considered something it could
not have considered, which is a worse defect than a stale word in an archival section — and it
is the same discipline PRD-11's own amendments follow, where Amendment A and Amendment B are
appended and govern over the criteria they name rather than rewriting them in place.

**What makes it safe to read:** the status block at the top of this document states that the
prior status is superseded where Amendment B says so, § Decisions carried forward now states
the per-window bucket size as a current rule, and § Amendment B changes enumerates every
touched location. A reader who reaches the historical sections has passed all three. **No
correction is required, and none is invited** — a later agent must not "tidy" those sections.

### Non-blocking notes (no action required)

- **Empty buckets get more visible at hourly grain, and no new rule is needed.** A sparse proxy
  on the 24-hour window will have many hours with no traffic, so the zero-denominator rate rule
  (AC12, PRD-11 Amendment A(i) — not rendered as a percentage at all) and the no-dropped-buckets
  rule (Amendment B(i)) will fire far more often than they ever did at day grain. Both rules
  already exist, both already govern, and Amendment B(i) says so explicitly ("AC12 and
  Amendment A(i) govern its value exactly as they do today"). This gate raises no correction:
  an empty **day** in a 30-day window posed the identical question under the approved spec, and
  the answer has not changed. Noted only so it is not mistaken at implementation or review time
  for something Amendment B left open.
- **The two new Components rows were the right call.** Adding a bucket-conditional link/non-link
  row and a bucket-conditional first-column row, rather than folding either into an existing
  row, means a reader scanning § Components sees both new behaviours named. Both correctly state
  that no new `ui/*` primitive and no disabled variant is introduced.
- **§ Handoff's "Outstanding Questions: None" is accurate for this revision.** Bucket size and
  drill-through availability are both fully specified; `Q-11-03` items (9) and (10) are unchanged
  and non-blocking; the charting dependency gate was ruled by the Project Owner on 2026-08-26 and
  is no longer open, though this spec's own Components row still describes it as ungated — a
  pre-Amendment-B line this gate does not reopen.
- **Nothing here changes PRD-11.** The review found no defect in Amendment B itself: every
  question the revision had to answer was answerable inside the amendment as written, and the
  Designer escalated nothing. `docs/product/prd-11-analytics.md` is untouched by this gate.
