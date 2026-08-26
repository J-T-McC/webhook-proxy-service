# Question Q-11-03: The stats lifecycle, the aggregation shape, and five schema findings

- **Status:** **RESOLVED — Principal Engineer, 2026-08-26.** All ten items are answered in
  § Answer at the end of this document, and the answers are carried into
  `docs/plans/plan-11-analytics.md`. The two answers with a downstream consequence are stated
  here for anyone who reads no further: **item (6) — figures are computed live, per request,
  and AC20's percentile is a true (exact, nearest-rank) percentile**, so `design-11`'s
  pre-designed latency substitute is **not** triggered and its conditional "as of" caption
  **is not required** and is omitted; **item (10) — the Outcome filter is achievable at both
  grains as designed**, so nothing returns to the Designer. Item **(9)** splits: a deleted
  **destination**'s drill-through stays live as `design-11` Screen 3 specifies; a deleted
  **proxy**'s takes the spec's pre-approved degradation. *(Previously: OPEN — non-blocking for
  PRD-11 approval and for the `design-11` gate; travels to Technical Design.)*
  *(Item **(9)** added by the Product Manager on 2026-08-26 at the `design-11`
  approval gate — the Designer's soft-deleted-parent drill-through feasibility question, folded in
  here as the Designer asked rather than raised as a separate doc. It blocks nothing: the fallback
  is pre-approved.)* *(Item **(10)** added by the Designer on 2026-08-26 while landing `design-11`'s
  **C1** correction — whether the Events list query can filter by delivery/attempt outcome at either
  grain. Unlike (9), no fallback is pre-approved: C1 requires the filter, so an infeasibility finding
  here returns to the Designer as a correction, not an additive degrade.)*
  *(Updated 2026-08-26 after the Owner's V7/V8 rulings. **Findings F1–F4 are unaffected and every
  question below still stands.** Three items got sharper rather than softer: **Tier 3's
  per-destination grain** makes item (2) more consequential, **AC20's percentile** makes item (6)
  materially more likely to bite, and item (7) is **corrected** — see below. AC cross-references
  were renumbered to match the revised PRD.)*
- **Raised by:** Product Manager
- **Owner (must answer):** **Principal Engineer** *(technical. Every item below is a feasibility,
  lifecycle or data-shape call; the Product Manager will not resolve any of them — `CLAUDE.md`:
  technical feasibility doubts become Open Questions for the Principal Engineer.)*
- **Raised:** 2026-08-26
- **Gates:** nothing in PRD-11's approval. Items (1), (3) and (4) may **inform** the Owner's
  `Q-11-01` ruling and should be read before it is made, but none of them changes a criterion
  that is written in full.
- **Related:** `Q-11-01` (V7 — dashboard scope; (a)Tier 3's percentiles and (d)'s horizon depend
  on item (1) and item (6) here). `Q-11-02` (V8 — a target the system cannot measure cannot be
  asserted; item (7)).

## Context

The roadmap's #11 build-ahead note makes one load-bearing claim: *"Stats are built from the
delivery-attempt records emitted since #1/#4 and kept separate from retained payloads (which
expire under #5 retention) so they stay long-lived and trendable — this is exactly why #1 must
emit attempt records rather than have #11 reconstruct them later."*

The Product Manager verified that claim against the current schema before writing PRD-11. **It
holds** — see PRD-11 § "Does the separation hold?" for the full table. Nothing #11 counts is
erased or deleted by retention or GC. The items below are what the verification turned up *around*
that conclusion: they are not objections to it, and none of them is a schema proposal.

## Questions

### (1) `delivery_attempts` and `deliveries` have no lifecycle at all — is unbounded growth acceptable, and for how long?

ADR-003 states attempt records are "**retained on their own lifecycle**, independent of payload
retention (#5)". **No such lifecycle exists.** There is no window, no GC pass, no cap, no prune
and no rollup for `delivery_attempts` or for `deliveries`; ADR-012 Decision 5 confirms GC "never
writes" `delivery_attempts`, and nothing else touches either table. In practice "long-lived"
currently means *forever, and growing at the rate of `destinations × attempts` per received
event*.

This is the same class of accepted concern as PRD-05's deferred **D1** (retained cleaned records
and never-pruned `fifo_dispatches` rows), but it has never been named for these two tables, and
#11 is the item that makes their permanence a *product promise* rather than an incidental
property.

- Is indefinite retention of both tables the position, and is it one you are content to state?
- If a bound is ever wanted, is it a window, a cap, or detail-plus-rollup — and does the choice
  need to be made *before* #11 ships, or can it attach later without re-modelling? (PRD-11 AC5
  forbids #11 mutating or deleting the source records; it does **not** forbid a later, separate
  lifecycle pass.)
- **The product half is now RULED** (`Q-11-01(d)`, Owner 2026-08-26): **records are kept
  indefinitely**; no statistics-retention window, cap or prune at #11 (PRD-11 **AC18**, D-11-5).
  That makes the growth consequence a stated product position rather than an omission — **and it
  makes your answer to the technical half load-bearing rather than advisory.** The question stands
  unchanged: is indefinite growth of these two tables acceptable, and if a bound is ever wanted, can
  it attach later without re-modelling?

### (2) Soft-deleted parents make history invisible without erasing it

`Proxy` and `Destination` both use `SoftDeletes`. Attempt and delivery rows survive a deletion
(the FKs restrict, and the parent row survives too) — but the `SoftDeletes` global scope removes
trashed parents from any default join or eager load, so an aggregate written the obvious way
**silently under-counts** rather than failing. This is already a known hazard in the codebase:
`ProxyEventController::index()`/`show()` both carry explicit `->withTrashed()` on `destination`,
and `Delivery::proxy()`'s docblock spells the trap out.

**PRD-11 AC6** states as a requirement that deleted proxies and destinations stay counted and are
labelled as deleted. Confirm that is achievable across every aggregation path without a
per-query `withTrashed()` footgun, and say what makes it structurally safe rather than
remembered — a naive omission here is a wrong number, not an error.

**Sharpened by the V7 ruling.** Tier 3 makes the **per-destination breakdown** a first-class figure
(PRD-11 **AC15**), which is precisely where a silently-dropped soft-deleted destination is least
visible and most misleading — the row simply is not there, and the totals still look plausible.

### (3) There is no long-lived event-type attribute — confirm; the Owner ruled on this reading

Nothing outside the payload body records what *kind* of event arrived. `webhook_events` carries
`method`, `content_type`, `byte_size`, `received_at`; `delivery_attempts` and `deliveries` carry
no such field. So a "success/failure per event type" breakdown — an obvious thing to want from a
webhook dashboard, and the first thing a Stripe user will ask for — can only come from payload
content, **which expires**.

PRD-11 **AC32** puts per-event-type analytics out of scope on exactly this basis. **The Owner
confirmed on 2026-08-26 that the exclusion stands and is not reintroduced under Tier 3** — i.e. the
ruling was made *on this reading of the schema*, so if the reading is wrong the ruling rests on a
false premise. Please confirm it, and — if a later item could persist a non-payload event-type
attribute cheaply at ingest — say so, so the possibility is recorded rather than lost. (#8 was the
nearest candidate via map selection, and #8 is deferred as of 2026-08-26.)

### (4) Pre-#6 attempt rows carry `delivery_id = NULL`

Deliberate, per ADR-015 (no backfill; pre-#6 events get no synthetic delivery rows). Any
delivery-grained figure cannot classify those rows — **and the Owner's `Q-11-01(c)` ruling makes a
delivery-grained figure the headline**, so this is now live rather than hypothetical.

PRD-11 **AC13** states the treatment (**PM-derived, D-11-7**): pre-#6 rows are **excluded from every
delivery-level figure, included in attempt-level figures, and the exclusion is stated, not silent**.
There is no production data, so this costs nothing today. Confirm it is clean, and say whether the
split treatment needs explicit handling anywhere else — in particular whether any figure could
double-report or mis-reconcile because the same row is in one unit's denominator and out of the
other's (PRD-11 **AC10** requires each unit to reconcile within its own record set).

### (5) No index supports a per-proxy, time-windowed aggregate

`delivery_attempts` currently indexes `(team_id, created_at)`, `(proxy_id, status)`, `ingest_id`
and `UNIQUE(delivery_id, attempt_number)`. `deliveries` indexes `(webhook_event_id, status)`,
`(status, next_attempt_at)` and `UNIQUE(dispatch_uuid, destination_id)`. A "per proxy, per
destination, per day, over 30 days" aggregate has no covering index on either table. Whether that
matters, and whether closing it is an index addition or an aggregation store, is entirely yours —
flagged only so it is not discovered at Task Planning. **Note that an index addition or a new
derived table is a `CLAUDE.md` data-model change and therefore an Owner gate at plan time**; the
PM expects one here, as at #8.

**The V7 ruling fixed the exact shape to plan against**, so this is no longer speculative: **team /
proxy / destination grains** (AC15), **daily buckets** (AC16), **windows up to 30 days** (AC17),
**two units over two different tables** (AC13), and **records kept indefinitely** (AC18) — so the
scanned range grows without bound while the queried window does not.

**A second Owner gate is also expected at plan time and is not pre-approved by anything:** the
Project Owner has **suggested** `@j-t-mcc/vue3-chartjs` for rendering the charts (Vue 3 + Chart.js
4; this project is Vue 3.5.40 with no charting library present, so adoption adds two npm packages).
It is a **suggestion, not a mandate**, appears in **no acceptance criterion** by design, and is a
**new-dependency `CLAUDE.md` Owner gate you record formally** — see PRD-11 § Handoff.

### (6) Live aggregation vs. a derived/rollup store — and what PRD-11 AC11 then obliges

If aggregation is computed live per request, figures are always current. If a rollup exists,
figures can lag, and **PRD-11 AC11** requires the surface to state as-of what time a non-live
figure is current. Which shape you choose therefore has a visible product consequence, and the
Designer needs to know before laying the surface out.

**Materially more likely to bite after the V7 ruling.** The Owner chose **Tier 3**, which includes a
**high-percentile delivery-duration figure** (PRD-11 **AC20**) — the one item MySQL does not compute
cheaply. AC20 is written to accept your answer rather than presume it: *if a true percentile is not
feasible, a substitute must still expose the tail and must be labelled for what it is (an
approximation, or a slowest-N view)* — **but a bare average does not satisfy the criterion**. So the
question is now three-way and needs an answer, not a preference: **live percentile, rollup-backed
percentile, or a labelled approximation?** If the answer is a rollup, AC11's as-of requirement
activates and the Designer must be told before `design-11` is laid out.

### (7) Are the V8 definitions accurate? — **corrected, and still needing your confirmation**

**Correction, recorded rather than quietly fixed.** This item originally asserted that
ingest-to-first-attempt latency "is not recorded anywhere". **That was imprecise.** Both timestamps
exist — `webhook_events.received_at` and the earliest attempt's `started_at` — so the interval *is*
derivable; what is missing is a **clean** measurement, because the interval conflates queue wait
with pipeline processing. **Throughput** remains genuinely unmeasurable: observed volume in a window
measures *offered traffic*, not *capacity*, and no load test exists.

The Owner **renewed the V8 deferral** on 2026-08-26 and **settled the definitions instead** (PRD-11
**AC22(c)** and § Definitions), written on the corrected reading:

| Term | Definition as settled | Displayed at #11 |
|---|---|---|
| Delivery success | terminal `succeeded` over (`succeeded` + `failed`); non-terminal excluded | Yes (AC13) |
| Delivery duration | HTTP send time per attempt; first-attempt-start to terminal per delivery; **excludes queue wait** | Yes (AC20) |
| Ingest-to-first-attempt latency | `received_at` → earliest `started_at`; **derivable but conflated** | No |
| Throughput | events/deliveries per unit time **under sustained load**; not measurable today | No |

**What is asked of you:** confirm these four are accurate against the schema and the pipeline, and
say so plainly if any is not. They now bind further than #11 — **a future V8 target and any V3
queue-choice argument are meant to inherit them rather than re-litigate them**, so an inaccuracy
here propagates into decisions on shipped work (#4) rather than staying local.

### (8) Does #11 disturb anything settled?

PRD-11 AC5 confines #11 to reading. Sanity-check that a read-only aggregation over
`delivery_attempts`, `deliveries` and `webhook_events` descriptors cannot interfere with: the
ADR-012 GC compare-and-set, ADR-015's CAS status transitions, ADR-016's FIFO advancer, or the
ADR-014 `payload_cleaned_at` guard — in particular that no analytics query may take locks or read
`body`/`headers` on any path.

### (9) Can a drill-through resolve a **soft-deleted** proxy or destination? *(added 2026-08-26 at the `design-11` gate)*

`design-11` (Flow B step 3, Screen 3's Deleted-row **View events** action) assumes that a
destination or proxy deleted within the window keeps a **working** drill-through link into the
existing Events list: the list route/controller must be able to resolve a soft-deleted parent for a
permission-gated read, and filter attempts/deliveries by that parent's id. This is the same
soft-delete visibility hazard as item (2), but at the *routing and authorization* layer rather than
the aggregation layer.

PRD-11 **AC6** requires only that the deleted record stays counted, attributable and labelled —
**not** that it stays navigable. The Product Manager ruled at the design gate that a **View events**
link on a deleted row is a read of history rather than an action against the deleted object, so it
is permitted by the UX Direction's "offer no actions against something that no longer exists"; but
it is not required by any criterion.

**What is asked of you:** say whether the link can stay live without a larger change. If it cannot,
`design-11` already specifies the fallback — the row's **View events** action degrades to the same
muted, non-interactive treatment its **Deleted** label already uses — and that degradation is
**pre-approved**, additive, and needs no design rework or re-approval.

### (10) Can the Events list query filter events by delivery/attempt outcome, at either grain? *(added 2026-08-26, landing `design-11`'s C1 correction)*

`design-11`'s Flow E (drill-through, revised to land correction **C1**) adds an
**Outcome** filter chip alongside the already-designed window and destination
chips, carried from every failure-shaped figure (a Proxies-table row's Terminal
failures cell, a proxy's Retry & replay Terminal failure tile, a trend-chart day/
unit cell). The list must select events that contain **at least one** delivery (or
attempt) matching a terminal-failure status, at whichever grain (delivery-level or
attempt-level) the source figure was denominated in.

The shipped Events list (`resources/js/pages/proxies/events/Index.vue` and its
controller) carries **no query filter today** — window and destination narrowing
are assumed straightforward (an indexed timestamp column, and the destination
relationship the route already resolves for the badge/detail views), but outcome
narrowing is a join/aggregate shape nothing on this surface has needed before, and
it needs to work at **both** grains independently.

**What is asked of you:** confirm whether an outcome filter at both grains is
achievable without a larger change, and say what it costs (an added join, an index,
or something structurally different). `design-11` designs the chip, its copy, and
which entry points carry it; it does not assume the query is cheap. If it is not
achievable as designed, that is a correction that returns to the Designer — this
question does not pre-approve a fallback the way item (9) does, because C1
requires the filter to exist (AC10), not merely permits it.

## Impact if unresolved

None on PRD-11's approval — every criterion it names is now written in full, and both Owner rulings
have landed. Unresolved at **Technical Design**, items **(2)**, **(5)** and **(6)** become
correctness and shape risks rather than open questions — and **(6) is the one that can reach back
into `design-11`**, because a rollup activates AC11's as-of requirement on the surface the Designer
is about to lay out. Items **(1)**, **(3)**, **(4)** and **(7)** now sit *behind* rulings that were
made on the readings they contain: if any reading is wrong, the ruling above it rests on a false
premise, so a correction is more valuable than a confirmation and should be stated loudly. **Item
(10)**, unlike (2)/(5)/(6), is not a risk to discover later — `design-11`'s C1 correction already
depends on its answer for the Outcome filter chip to be buildable as specified; an infeasibility
finding here is expected to travel straight back to the Designer rather than surface downstream.

## Answer

- **Answered By:** Principal Engineer
- **Answered:** 2026-08-26
- **Verified against:** the migration set (`delivery_attempts`, `deliveries`, `webhook_events`,
  `destinations`, `fifo_dispatches`), `app/Actions/DeliverToDestination.php`,
  `app/Actions/RetryDelivery.php`, `app/Actions/AdvanceProxyFifoQueue.php`,
  `app/Actions/PurgeExpiredPayloads.php`, `app/Http/Controllers/ProxyEventController.php`,
  `app/Http/Controllers/ProxyEventReplayController.php`, `app/Http/Middleware/ApplyTeamScope.php`,
  `app/Models/Scopes/TeamScope.php`, `app/Policies/ProxyPolicy.php`, `routes/web.php`,
  `resources/js/pages/proxies/events/Index.vue`, `resources/js/pages/proxies/Show.vue`,
  `resources/js/pages/Dashboard.vue`, `resources/css/app.css`, `vite.config.ts`,
  `config/inertia.php`, `package.json`. Every claim below is a reading of that code, not an
  assumption about it. The full working is in `docs/plans/plan-11-analytics.md`.

### (1) Indefinite retention of `delivery_attempts` and `deliveries` — the technical half

**Yes, indefinite retention is acceptable to state, and I state it.** The product half is ruled
(AC18, D-11-5); the technical half is that indefinite growth of these two tables costs **storage
and operational handling, not query latency** — and that distinction is what makes AC18 safe to
ship rather than a debt taken quietly.

**Why query cost does not follow the tables' growth.** Every figure #11 displays is bounded on two
axes at once: a leading equality on `team_id` or `proxy_id`, and a range of at most 30 days
(AC17) on the anchor timestamp. With the composite indexes this plan adds — `(team_id, status,
updated_at)` and `(proxy_id, status, updated_at)` on both tables — each query reads an index range
whose size is a function of *traffic in the window*, never of the row count behind it. A table
that has grown for five years and one that has grown for five weeks answer the same 30-day
question by scanning the same number of index entries. No figure scans the table, sorts the table,
or counts the table.

**What growth actually costs, in the order it will be felt.**

1. **Schema-change windows first, and this is the near-term consequence.** An `ALTER TABLE` that
   builds an index on `delivery_attempts` is cheap today and is an operations event at a hundred
   million rows. That is a direct argument for adding this plan's four indexes **now**, while
   both tables are small, rather than deferring them until a figure feels slow.
2. **Backup, restore and clone time**, which grows linearly and unboundedly, and which nothing in
   the product surfaces.
3. **Buffer-pool residency.** The hot working set is the tail of each new index; the rest of the
   table falls out of memory, which is correct and harmless — but the *index* footprint grows with
   the table even though only its tail is read.
4. **Storage cost**, last and most visible, and the only one anybody usually names.

**The growth rate, as a formula rather than an invented number.** `deliveries` grows at
`destinations × dispatches` (a dispatch being one original send or one replay);
`delivery_attempts` grows at `destinations × dispatches × attempts`, where attempts is between 1
and the proxy's resolved attempt limit. Both are therefore multiples of received-event volume,
with a per-row cost of the row plus one entry in each of its indexes — of which this plan adds
two per table, on columns that are narrow but, in the case of `updated_at`, **mutable**: a
non-terminal status transition moves the index entry. That write cost is bounded by the attempt
limit per delivery and is accepted deliberately (see the anchor ruling in the plan; the immutable
alternative was rejected because it lets a past bucket change after the fact).

**What would have to exist later, and whether it can attach without re-modelling: it can.** The
bound, if one is ever wanted, is **detail-plus-rollup, not a window and not a cap** — a window or
a cap over these two tables would delete the only record of what happened, which is precisely what
AC18 forbids presenting as a complete trend. The shape that attaches cleanly is a forward-only
daily rollup keyed on (team, proxy, destination, day, unit) carrying the outcome counts, the
duration sum and count, and — this is the part that must not be forgotten — **a duration
distribution, not just a mean**, because a percentile cannot be reconstructed from counts once the
detail rows are gone. Three properties of the #11 design keep that door open, and they are
deliberate:

- every figure is produced behind one service (`AnalyticsQuery`, § *Services* in the plan), so a
  rollup swaps in behind an unchanged interface and unchanged read surfaces;
- PRD-11 **AC5** forbids #11 mutating or deleting source records but explicitly does not forbid a
  later, separate lifecycle pass — nothing here writes to either table, so nothing here has to be
  unwound first;
- **AC18** already states the obligation such a pass would inherit: if a horizon is introduced,
  the surface must say where the data stops. That requirement is written before the horizon
  exists, which is the right order.

**Two structural constraints a future lifecycle pass must respect, recorded so they are not
rediscovered.** (a) Every FK in this cluster is RESTRICT (`constrained()` with no `onDelete`), so
a prune must delete `delivery_attempts` before `deliveries`, and `deliveries` before any
`webhook_events` row — and `fifo_dispatches` restricts `webhook_events` independently (ADR-012
Decision 5 already accepts that those rows are never removed, as deferred concern **D1**).
(b) Rolling up before pruning is not optional but ordering-critical: prune first and the
percentile is unrecoverable for the pruned period, permanently.

**On F1 and ADR-003's wording.** ADR-003's claim that attempt records are "retained on their own
lifecycle, independent of payload retention" is **aspirational, not implemented**, and #11 does
not implement it. It is imprecise rather than false — the records *are* independent of payload
retention, which is the load-bearing half — so it is **not** contradicted by anything #11 does and
needs no superseding ADR now. The honest reading, recorded here and in the plan, is: *retained
indefinitely; the lifecycle is the empty one.* Whichever future item introduces a bound should
carry the correction to ADR-003's wording in its own ADR, where there is an actual decision to
attach it to.

### (2) Soft-deleted parents — structurally safe, not remembered

**Achievable, and the safety is structural: no analytics aggregate joins `proxies` or
`destinations` at all.** The hazard in this item is real but it is a hazard of *joining* — the
`SoftDeletes` global scope removes trashed rows from a join or an eager load. Every #11 aggregate
groups by `proxy_id` / `destination_id`, which are **plain integer columns on the fact tables
themselves**. There is no relation on the query to forget `withTrashed()` on, so the failure mode
described in this item cannot occur: a trashed parent cannot be dropped from a query that never
mentions it.

Labels are then resolved in a **second, separate query** over the exact id set the aggregate
returned — `Proxy::withTrashed()->whereIn('id', $ids)` and
`Destination::withTrashed()->whereIn('id', $ids)` — and any id whose row has a `deleted_at` is
rendered with the **Deleted** label `design-11` Screen 3 and Flow B step 3 specify. A missing
label is impossible, not merely unlikely: the FKs restrict, so the parent row is guaranteed to
exist for every id an aggregate can return.

Three things make this enforced rather than remembered, and all three are in the plan:
`withTrashed()` appears exactly twice, in the two label lookups, where its absence is
immediately visible rather than diffused across every aggregate; a stated Implementation Note
forbids any analytics query joining or eager-loading `proxies`/`destinations`; and AC6 gets a
direct test — compute every figure, soft-delete the proxy and a destination, recompute, and assert
the numbers are **identical** and the rows are labelled.

**On the Tier 3 sharpening.** The per-destination breakdown is exactly where this would have been
invisible, and it is also the one place the shipped surface cannot supply the row set: Screen 3's
table must **not** be driven from `ProxyResource.destinations`, which loads only live
destinations. It is driven from the analytics payload — the union of the proxy's live destinations
and every destination id with activity in the window — which is why a destination deleted last
week is a row at all. See § *Read surfaces* in the plan; this is a correctness consequence of AC6,
not a presentation choice.

### (3) No long-lived event-type attribute — confirmed, and the ruling rests on a true premise

**Confirmed. The reading behind AC32 and behind the Owner's 2026-08-26 confirmation is correct.**
Column by column, the long-lived fields of `webhook_events` after erasure are `id`, `team_id`,
`proxy_id`, `ingest_id`, `method`, `content_type`, `byte_size`, `received_at`,
`payload_cleaned_at`, `created_at`, `updated_at`. `body` and `headers` are erased in place on
expiry (ADR-012 Decision 1, ADR-014). `delivery_attempts` and `deliveries` carry no event-kind
field of any sort. `content_type` survives erasure — it is denormalised before any cast, by
`WebhookEventCapture::contentTypeFrom()` — but it is a **MIME type**, not an event type:
`application/json` does not distinguish `invoice.paid` from `charge.refunded`. `method` is
likewise a transport fact. **Nothing outside the payload body or the erased headers records what
kind of event arrived.**

**Recorded so the possibility is not lost, per the request.** A later item could persist an
event-type attribute cheaply, and the precedent for exactly how already exists: `content_type` is
captured into its own column from the **in-memory** header array before the header cast, so it
survives header erasure. A per-proxy configured source — a header name for senders that put the
type in a header (`X-GitHub-Event`), or a JSON path for senders that put it in the body (Stripe's
`type`) — read once at capture and written to a narrow, indexed `event_type` column would give a
long-lived, non-payload attribute with the same survival properties. Two constraints on whoever
builds it: it is **new capture at ingest**, so PRD-11 **AC29**/D-11-3 puts it outside #11 and
returns it to the Owner as a requirement on a later item; and a value extracted from a body is a
**copy of payload content into a long-lived column**, which is a #10 sensitive-data question
before it is an analytics one. #11 builds none of it.

### (4) Pre-#6 `delivery_id = NULL` rows — clean, and no figure can double-report

**Confirmed clean, and the treatment needs no explicit handling anywhere**, because in this design
it is structural rather than filtered:

- **Delivery-level figures never touch `delivery_attempts`.** They aggregate `deliveries` rows by
  `status`. A pre-#6 attempt row has no `deliveries` row to belong to, so it is excluded by
  construction — no `whereNotNull('delivery_id')` clause exists to forget.
- **Attempt-level figures never join `deliveries`.** They aggregate `delivery_attempts` by
  `status` with a `proxy_id`/`team_id` predicate. A NULL `delivery_id` is invisible to that query,
  so pre-#6 rows are included by construction — again with no clause to forget.

**On AC10 and double-reporting: it cannot happen, and the reason is stronger than "we were
careful".** The two units are computed from **two disjoint tables**. No row is in both units'
denominators, because no row is in both tables. AC10's "each unit reconciles within its own record
set" is therefore satisfied structurally, and the drill-through preserves it (see item (10)):
a delivery-level figure drills through a `deliveries` predicate, an attempt-level figure through a
`delivery_attempts` predicate.

Two derived figures do bridge the tables, and both were checked rather than assumed.
**Eventual success** (AC19(a)) is delivery-grained and needs an attempt count per delivery, so it
groups attempts by `delivery_id`; NULL cannot group, so pre-#6 rows are excluded — consistent with
it being a delivery-level figure. **Retry volume** (AC19(c)) is attempt-grained and needs no join
at all: it counts attempts with `attempt_number > 1`. Every pre-#6 row carries `attempt_number = 1`
(pre-#4 there was at most one attempt per destination per event), so those rows contribute **zero**
to the numerator and one each to the attempt population — which is exactly AC13's stated treatment,
arrived at without a special case.

**One consequence worth naming rather than leaving to be discovered.** In dev/CI data that
contains pre-#6 rows, the attempt-level denominator can legitimately exceed anything reachable
from the delivery-level figures. That is correct, and it is a further reason the two units must
never be reconciled against each other (AC14(d)). No production data exists (F4, D-11-7), so this
costs nothing today.

### (5) Indexes — four, additive, and an Owner gate

**It matters, and closing it is an index addition, not an aggregation store** (see item (6) for
why no rollup is built). The four indexes are enumerated verbatim, with their rejected
alternatives, in `docs/plans/plan-11-analytics.md` § *Data Model*, and they are carried to the
Project Owner as **Owner-approval flag 2** of that plan, exactly as this item anticipated. They
are additive: no existing column, index, enum value or default is changed or removed, no backfill
runs, and rollback is four `dropIndex` calls.

The second gate this item anticipates — the charting dependency — is likewise recorded formally as
**Owner-approval flag 1** of the plan, with the fit assessment, the bundle and tree-shaking
position, the SSR finding, and the dark-mode colour-resolution rule attached to it. Neither gate
is pre-approved by anything, and neither is self-certified.

### (6) Live aggregation, a true percentile, and therefore no "as of" caption

**Answer to the three-way question, plainly: a live, exact percentile. No rollup store. No
approximation.**

**The percentile is feasible as a true percentile, on both engines this project runs.** It is
computed by the **nearest-rank** method, which needs no window function and no engine-specific
percentile function (MySQL 8.0 has none; SQLite is the local default): take the count `n` of
resolved attempts in the window at the grain — a number the surface is computing anyway for the
attempt-level figure — and read the value at ordinal `CEIL(0.95 × n)` with
`ORDER BY duration_ms ASC LIMIT 1 OFFSET CEIL(0.95 × n) − 1`. The result is an actual observed
duration, not an estimate, and it is deterministic. `n = 0` is the AC12/AC20 "No data" case and no
second query runs. This is a **true percentile**, so **AC20 is satisfied outright** and
`design-11`'s flagged design call 7 — the pre-designed labelled substitute — is **not triggered**;
the latency block renders "95th percentile" with no approximation qualifier, and it must not be
labelled as one.

**Cost, and why it does not force a rollup.** The sort is over the window's resolved attempts at
that grain only, reached through the new `(team_id, status, updated_at)` /
`(proxy_id, status, updated_at)` index range — bounded by traffic in at most 30 days, never by
table size (item (1)). The team-grain 30-day case is the largest, and it is a single-integer sort
of a bounded row set. If it ever stops being cheap, the escape hatch is the rollup described in
item (1), which attaches behind the same service without a re-model — that is the point of
answering item (1) the way I did.

**Figures are computed live, per request. No derived record, no cached figure, no rollup table.**
The reasons, in order of weight: the smallest design that satisfies the criteria wins, and live
computation satisfies all of them; a rollup would add a write path, a scheduler, a staleness
window and a reconciliation question to a feature whose entire premise (AC5) is that it writes
nothing; and PRD-11 **AC11** would then oblige a real timestamp on every figures block on every
screen, which is user-visible cost bought for no user-visible benefit. Nothing in Tier 3 needs
one — the percentile was the item that made a rollup likely, and it turned out not to need it.

**Consequence for `design-11`, stated so the Designer and the Reviewer need not infer it.**
Flagged design call 8's conditional "as of {time}" caption resolves to the **live** branch, and
under the Product Manager's ruling on that call the caption "may be omitted entirely — 'as of now'
is acceptable but adds nothing". **It is omitted.** The caption slot renders nothing on Screen 1
and Screen 2, and **AC11 is satisfied vacuously** — every number on the surface is computed at
request time from the source records, so there is no non-live figure for AC11 to govern. If a
rollup is ever introduced, AC11 activates and the caption becomes mandatory and concrete; the plan
records that as the condition on any future rollup.

### (7) The four V8 definitions — three accurate as written, one needs a boundary stated

**Delivery success — accurate.** Terminal `succeeded` over (`succeeded` + `failed`), non-terminal
excluded, is exactly what `deliveries.status` records; ADR-015 Decision 1 makes the terminal state
a stored fact and `DeliverToDestination::transition()` only ever compare-and-sets **from** a
non-terminal status, so a terminal row is final and the definition is computable without
inference.

**Ingest-to-first-attempt latency — accurate, including the correction.** Both timestamps exist
(`webhook_events.received_at`, and the earliest `delivery_attempts.started_at` reachable by
`ingest_id`), the interval is derivable, and it does conflate queue wait with pipeline processing.
Worth adding to the definition's record, because a future V8 target would trip over it: on a
**FIFO** proxy the interval also absorbs *time spent waiting behind other events in the line*,
which is neither queue-worker latency nor processing — three things in one number rather than two.
Not displayed at #11 either way.

**Throughput — accurate.** Observed volume in a window measures offered traffic. Nothing in the
schema records capacity, no load test exists, and `stack.md` records no performance budget or
load-test tooling at all. Not measurable today; the definition is right to say so.

**Delivery duration — accurate as a definition, but it has two grains and #11 must display only
one of them. This is the one place the settled wording needs a boundary drawn, and I draw it in
the plan rather than reinterpret the definition.** The definition reads "per attempt, the
wall-clock time of the HTTP send (`duration_ms`); per delivery, first attempt's start to terminal
outcome" and then says the measure **excludes queue wait time**. Those two halves are consistent
for a single-attempt delivery and **inconsistent for a retried one**: the per-delivery span from
first attempt to terminal outcome is dominated by the retry backoff — scheduled waiting, not
sending — so a per-delivery figure would contradict both the definition's own exclusion and the
on-screen caption `design-11` approves, "Excludes time spent waiting in the queue."

**Ruling, recorded in the plan as a technical ruling and not a requirement change:** every #11
latency figure at every grain is computed over **resolved attempts' `duration_ms`** — the
per-attempt half of the definition, which is the only *recorded* duration and the only one AC29
traces to a column. The per-delivery span is not displayed at #11. The definition itself stands
unamended: both halves remain correct as definitions, and a later item that wants the end-to-end
span is asking for a **different figure with a different caption**, not for this one computed
differently. If the Product Manager reads AC20 as obliging the per-delivery span, that is a
requirement question and returns to the Product Manager — but note that it cannot be satisfied
without contradicting the approved on-screen copy, so the copy would move with it.

### (8) Nothing settled is disturbed — confirmed, with the mechanism for each

Confirmed, item by item, and the confirmations are structural rather than a promise to be careful:

- **No locks on any path.** Every analytics query is a plain non-locking `SELECT`. No
  `lockForUpdate()`, no `sharedLock()`, no `DB::transaction()` anywhere in the read path — none is
  needed, because nothing is written. Under InnoDB a consistent non-locking read takes no row or
  gap locks, so it cannot block or be blocked by the ADR-012 GC compare-and-set `UPDATE`, ADR-015's
  status CAS transitions, or ADR-016's advancer claim.
- **No `body`, no `headers`, on any path.** No analytics query selects either column, and — the
  stronger guarantee — **no analytics query hydrates a `WebhookEvent` model at all**: aggregates
  select aggregate expressions, and the one place #11 touches `webhook_events` is the Events-list
  drill-through, which is the shipped list's own query with added predicates and which already
  never reads payload content (fetch-on-reveal, ADR-017 Decision 5). The `encrypted` casts are
  therefore never invoked. Stated as a binding Implementation Note in the plan and given a test.
- **The ADR-014 `payload_cleaned_at` guard is untouched.** #11 never reads it, never branches on
  it, and — per AC3 — never lets a payload state exclude a record from a count. `StoredPayloadLookup`
  remains the only interpreter of the cleaned state.
- **`fifo_dispatches` is never read by #11**, so the advancer's claim range under
  `(proxy_id, status, webhook_event_id)` is not touched even for reading.
- **Write-path impact is not nil and is not hidden:** the four new indexes are maintained by the
  ingest/delivery path on insert and on every status transition. That is the honest cost of this
  feature, it is bounded by the attempt limit per delivery, and it is attached to the Owner gate
  rather than buried.

### (9) Drill-through to a soft-deleted parent — yes for a destination, degrade for a proxy

The answer splits, because the two cases resolve through different mechanisms.

**A deleted destination: the link stays live, no change needed. `design-11` Screen 3 and Flow D
step 3 stand exactly as written.** The destination is not a bound route parameter — it travels as
a query filter on the proxy's own Events route, and the proxy is live. Resolving it for the chip
label is a single `Destination::withTrashed()->where('proxy_id', $proxy->id)->find($id)`, which is
also the authorization check that matters: the id must belong to *this* proxy, and the proxy is
already gated by `ProxyPolicy::view`. Soft delete preserves the id and the row, so the filter and
the label both work. This is the case the spec assumed, and the assumption is correct.

**A deleted proxy: take the spec's pre-approved degradation.** Making it work is technically
possible — `->withTrashed()` on the two events routes' `{proxy}` binding is a one-line change —
but it is not a one-line *consequence*. The Events page is a shipped surface that carries the
per-row **Replay** action and the ReplayDialog; resolving a trashed proxy there would render a
Replay affordance whose `POST` route still binds a live proxy only, so the button would appear and
then 404. That is precisely "an action against something that no longer exists", which the UX
Direction bars, and suppressing it means changing a #6-settled surface's behaviour — a bigger
change than this drill-through is worth, and one that AC27/AC28 would put back in front of the
Designer and the Product Manager anyway.

So: on the Dashboard's Proxies table, a **deleted proxy's row renders its historical figures
intact and labelled Deleted (AC6), with its name/View link and its Terminal-failures cell taking
the muted, non-interactive treatment** the spec pre-approved. AC6 requires the row to stay counted,
attributable and labelled — all three hold — and the Product Manager's design-gate ruling is
explicit that it does not require the row to stay navigable. **No design rework, no re-approval**,
per § Open Questions of `design-11`. If the Owner later wants a deleted proxy's events browsable,
that is its own small item: route `withTrashed()` plus a suppression rule for every write
affordance on that surface.

### (10) The Outcome filter at both grains — achievable as designed, at no schema cost

**It holds. Nothing returns to the Designer.** The filter is achievable at **both** grains
independently, it needs **no new index and no new table**, and it shares one path with the window
and destination filters.

The shape is a subquery on the id set, not a correlated `EXISTS` over the event list, and it is
driven from the **narrow** side — the failing records — so selectivity works for us rather than
against us:

- **Delivery grain:** narrow `webhook_events` to
  `whereIn('id', <select webhook_event_id from deliveries where proxy_id = ? and status = 'failed' and updated_at between ?>)`.
  This reads the same `deliveries (proxy_id, status, updated_at)` index the figure itself uses.
- **Attempt grain:** narrow to
  `whereIn('ingest_id', <select ingest_id from delivery_attempts where proxy_id = ? and status = 'failed' and updated_at between ?>)`.
  This reads the same `delivery_attempts (proxy_id, status, updated_at)` index the attempt-level
  figure uses. `ProxyEventReplayController` dispatches a replay under the **event's existing
  `ingest_id`**, so this path matches original, replayed **and** pre-#6 attempts of the event —
  which is exactly the population AC13 puts in the attempt-level figure.

Three properties fall out of that shape, and all three are worth having:

1. **The filtered list is defined by the same predicate as the figure**, so AC10's reconciliation
   holds at the record-set level, not just approximately. `design-11`'s C1(b) statement about row
   count remains exactly right and unchanged — one event can hold several matching deliveries — but
   the *set* of events shown is precisely the set containing the counted records.
2. **The window travels at the figure's own anchor when an Outcome chip is active** (inside the
   subquery, on `updated_at`), and on `webhook_events.received_at` when it is not. Without that,
   a terminal failure reached today from an event received last week would be filtered out by its
   own drill-through — a silent wrong answer rather than a visible one. This is a query-semantics
   ruling; it changes no chip, no label and no copy.
3. **No new component and no new index**, as the spec expected.

One implementation obligation, small but easy to miss and therefore recorded: the shipped
pagination renders `props.events.links` and navigates with `router.get(link.url)`, so the
paginator must carry the active filters forward (`->withQueryString()`) or page 2 silently drops
them. Named as a task-level obligation in the plan.
