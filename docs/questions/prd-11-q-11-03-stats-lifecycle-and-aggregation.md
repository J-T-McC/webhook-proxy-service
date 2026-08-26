# Question Q-11-03: The stats lifecycle, the aggregation shape, and five schema findings

- **Status:** **OPEN — non-blocking for PRD-11 approval; travels to Technical Design.**
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

## Impact if unresolved

None on PRD-11's approval — every criterion it names is now written in full, and both Owner rulings
have landed. Unresolved at **Technical Design**, items **(2)**, **(5)** and **(6)** become
correctness and shape risks rather than open questions — and **(6) is the one that can reach back
into `design-11`**, because a rollup activates AC11's as-of requirement on the surface the Designer
is about to lay out. Items **(1)**, **(3)**, **(4)** and **(7)** now sit *behind* rulings that were
made on the readings they contain: if any reading is wrong, the ruling above it rests on a false
premise, so a correction is more valuable than a confirmation and should be stated loudly.

## Answer

- **Answered By:**
- **Answered:**
