# Question Q-11-01: Detailed analytics / dashboard scope (roadmap **V7**, vision Open Question 7)

- **Status:** **RESOLVED** (Project Owner, 2026-08-26) — **roadmap V7 is closed**. Folded into
  `docs/product/prd-11-analytics.md` **AC13–AC21** and **AC37**, with **AC7, AC9 and AC12
  rewritten** and **AC4, AC6, AC10, AC25, AC26, AC29** re-checked or clause-corrected as
  consequences (see that PRD's § "Re-check of the ruling-independent criteria").
- **Raised by:** Product Manager
- **Owner (must answer):** **Project Owner** *(product/scope decision. `docs/product/roadmap.md`
  § Open Questions: "**V7. Detailed analytics / dashboard scope** — deferred; **must be settled
  before #11's PRD**", and #11's own line: "dashboard detail deferred — see Open Question V7".
  `vision.md` Open Question 7: "Detailed analytics / dashboard scope — deferred to planning."
  No vision, roadmap, PRD, ADR or prior ruling states any of the answers below, and the Product
  Manager will not invent them (`CLAUDE.md`: never invent requirements).)*
- **Raised:** 2026-08-26
- **Gates:** *(when open)* **AC13–AC21** of `docs/product/prd-11-analytics.md` (numbered AC13–AC18
  when this doc was raised), and — through them — the Designer, who cannot lay out a dashboard
  without knowing what is on it. The claim made when this was raised — that everything else "holds
  under every option below" — **was tested rather than trusted once the ruling landed, and three
  criteria did not survive it** (AC7, AC9, AC12). See the Answer block and PRD-11's re-check table.
- **Related:** `Q-11-02` (V8 — whether any *target* is asserted; independent of what is
  *measured*). `Q-11-03` (Principal Engineer — technical feasibility, the stats lifecycle, and
  the aggregation shape; any answer here is feasible-or-not on that doc's terms, not this one's).
- **Source:** `docs/product/roadmap.md` #11 ("Users can see **success and failure counts** and
  **drill down per webhook**, with stats kept separately from retained payloads so they remain
  long-lived and trendable") and its Build-ahead note; `vision.md` § What It Must Do
  ("**Analytics / stats.** Decoupled from retained payloads so they can be long-lived and
  trendable: successes, failures, and per-webhook drill-down. (Dashboard detail is deferred to
  planning.)"); § Problem ("Existing solutions … often lack **replay, stats, and visibility**.
  Some 'blindly send' downstream with no insight into delivery attempts **or timings**.").

## Context

Three sentences of source material exist for this feature, and they fix only that counts of
successes and failures exist, that a user can drill down "per webhook", and that stats outlive
payloads. Everything that makes a dashboard a dashboard — grain, breakdown, time buckets,
horizon, drill-through, export — is undecided. The Owner has been building toward this and asked
about "dashboard analytics" earlier, so the options below are a concrete ladder, not a survey.

**What the shipped system already gives you for free.** These are not proposals; they exist:

| Already shipped | Where | Consequence for #11 |
|---|---|---|
| Per-attempt outcome records (status, `http_status`, `error_summary`, `attempt_number`, `started_at`, `duration_ms`), per destination, for every attempt including retries and replays | `delivery_attempts` (ADR-003, ADR-015) | The whole aggregation source. Never erased, never soft-deleted, GC never writes it. |
| Per-(dispatch × destination) delivery state: `pending`/`retrying`/`succeeded`/`failed`, plus `kind` = `original`\|`replay` | `deliveries` (ADR-015, ADR-017) | Makes "did it *eventually* get through" and "live vs replay" answerable without a new record. |
| Per-event descriptors that survive payload erasure: received time, `byte_size`, `method`, `content_type` | `webhook_events` (ADR-014) | Received-volume trends are free and survive expiry. |
| **A per-event drill-down surface, with per-destination delivery state and every attempt** | PRD-06 AC25, `design-06`, `proxies/{proxy}/events` | **The bottom of the drill-down already exists.** #11 links into it; it must not build a second one. |
| A `Dashboard` page with placeholder panels and no content | `resources/js/pages/Dashboard.vue` | A team-level surface exists and is empty. |

**What the shipped system cannot give you, and this is decisive below.** There is **no
long-lived record of what *kind* of event arrived.** Outside the payload body — which expires
under #5 retention — nothing anywhere records an event type. Any breakdown "per event type"
would therefore have to read expiring payload content, which is exactly what the roadmap's
build-ahead note forbids, or require a new attribute captured at ingest that no roadmap item has
ruled. It is named in option (a) below as **excluded and why**, not quietly omitted.

## Question

### (a) How much dashboard, at MVP? — a tier ladder

Each tier **includes everything above it**. Cost rises roughly with tier; so does what a user can
actually conclude.

- **Tier 1 — Counts and rates only.** For the team and for each proxy: how many events were
  received, how many deliveries were attempted, how many succeeded, how many failed, and the
  resulting rate — each over one selectable window. **No** per-destination split, **no** time
  buckets (one number per window, not a series), **no** export.
  *Buys:* "is this proxy working?" *Does not buy:* "is it getting worse?", "which destination is
  the problem?" — which is most of what makes stats worth having.

- **Tier 2 — Counts, rates, per-destination split, a daily trend, and drill-through.
  (PM recommendation.)** Tier 1 plus: within a proxy, the same figures **broken down per
  destination**; a **time-bucketed series** (daily buckets) across the selected window so the
  numbers form a trend rather than a snapshot; an **average delivery duration** figure; and
  **drill-through from any figure into the existing per-event surface** (PRD-06 AC25) rather than
  into a new screen.
  *Buys:* "is it getting worse, and which destination is dragging it down, and show me the
  actual failures." That is the roadmap's "long-lived and **trendable**" made real.
  *Does not buy:* latency distribution, and — see the counterweight — an honest view of what
  retry already fixed.

- **Tier 3 — Tier 2 plus retry/terminal insight and latency distribution.** Adds: the split
  between deliveries that **eventually succeeded after retrying** and those that reached the
  **terminal failed state** (`deliveries.status`, already recorded); attempt-count distribution
  (how much retrying is going on); **live vs replay** split (`deliveries.kind`, already
  recorded); and a **high percentile** of delivery duration alongside the average.
  *Buys:* "nothing was actually lost, we just retried a lot" — a materially different, and
  usually more truthful, story than Tier 2's failure count tells. *Costs:* percentiles are the
  one item here MySQL does not compute cheaply (feasibility → `Q-11-03`), and it is more surface
  for the Designer.

- **Tier 4 — Tier 3 plus export.** CSV export of the aggregate figures and/or the underlying
  attempt rows, for a selected proxy and window.
  *Buys:* the user's own spreadsheet, and an escape hatch from whatever the dashboard does not
  show. *Costs:* an export is a second, permanent contract over the same data — it needs its own
  permission reasoning, its own column list, and (per PRD-11 AC4) a guarantee it carries no
  payload content. It is the only tier that adds a new egress path.

- **Tier 5 — something else the Owner names.**

**Explicitly not offered at any tier, and why:**
- **Per event type** (`charge.succeeded` vs `invoice.paid`) — **not buildable from long-lived
  data.** See § Context. It becomes possible only if some later item persists a non-payload
  event-type attribute; the nearest candidate is #8's map selection, and #8 is deferred. If the
  Owner wants this, the answer is not a tier — it is a new requirement on a future item, and the
  Owner should say so here so it is recorded rather than lost.
- **Per-map / per-mapping statistics** — #8, deferred.
- **Alerting on any of these numbers** — #13.

### (b) What does "drill down per webhook" mean?

The roadmap and vision both say "per webhook". The word is ambiguous in this product, and the
two readings build different things.

- **Option A — "webhook" = the proxy (PM recommendation).** Aggregate figures are per proxy;
  "drill down" means going from a team-level figure to that proxy's figures, and from there into
  the **already-shipped** per-event list and per-event detail (PRD-06 AC25). #11 builds **no new
  event-level surface**. *Basis:* the product calls the configured object a "webhook proxy", the
  bottom of the drill-down already exists and is approved, and the roadmap's own phrasing pairs
  "counts" with "drill down" as two levels, not three. *Consequence:* there is no per-received-event
  *statistic* — an individual event already has its full delivery detail, which is arguably the
  same thing.
- **Option B — "webhook" = the individual received event.** #11 adds statistics at the level of a
  single received event (e.g. its own success/failure ratio across destinations and attempts).
  *Consequence:* largely duplicates the PRD-06 event-detail surface, which already shows every
  destination and every attempt for that event; risks the second events surface PRD-11 AC25
  forbids.
- **Option C — both, explicitly.**

### (c) What does a "success" or "failure" figure actually count?

This is the single most consequential sub-question, because with #6 shipped the three answers
give **visibly different numbers for identical, healthy traffic**.

- **Option A — attempts.** Denominator is `delivery_attempts` rows. A delivery that failed twice
  and succeeded on the third attempt contributes 2 failures and 1 success. *Consequence:* a
  perfectly healthy proxy behind a flaky destination shows a ~67% failure rate while losing
  nothing. Simplest to compute; most likely to alarm.
- **Option B — deliveries (PM recommendation).** Denominator is `deliveries` rows in a terminal
  state. The same delivery contributes exactly one success. *Consequence:* the headline number
  answers "did it get through", which is the vision's headline success signal ("no lost data /
  no missed webhooks"). Retry volume is then a *separate* figure (Tier 3), not something hidden
  inside the headline. Undefined for pre-#6 attempt rows, which carry no `delivery_id` — see (e).
- **Option C — events.** Denominator is received events; an event "succeeded" only if every
  destination succeeded. *Consequence:* one bad destination out of five makes 100% of events
  "failed"; hides which destination, so it only works alongside a per-destination split.
- **Option D — show more than one, each explicitly labelled.** e.g. a delivery-level headline
  with an attempt-level figure beside it. *Consequence:* more honest, more screen, more to
  explain; PRD-11 AC8/AC9 already require every figure to name its unit, so this is affordable.

### (d) Trend horizon, bucket granularity — and how long stats live at all

- **Windows offered.** Which of: last 24 hours / 7 days / 30 days / 90 days / all time? (PM
  recommendation: **24 h, 7 d, 30 d**, with 30 d the default — it matches the retention window
  users already understand, without implying stats expire with it.)
- **Bucket granularity.** Hourly for short windows and daily for long ones, or daily only? (PM
  recommendation: **daily only** at MVP; hourly is a second axis of cost for a question — "which
  hour did it break" — the per-event surface already answers exactly.)
- **How long are the underlying records kept?** **This has never been decided.** ADR-003 says
  attempt records are "retained on their own lifecycle, independent of payload retention" — but
  **no such lifecycle exists**: there is no window, no GC, no cap, no rollup, and nothing prunes
  `delivery_attempts` or `deliveries`. Today "long-lived" means *forever, and growing*. That is a
  position arrived at by omission, not by ruling.
  - **Option A — keep everything, indefinitely (PM recommendation for MVP).** *Basis:* it is what
    the system does today; the roadmap asked for long-lived and trendable; a stats window is a
    product lever better set once there is real volume to justify it. *Consequence:* unbounded
    row growth in two tables, with no cap and no target — the same class of accepted concern as
    PRD-05's deferred **D1**, and a real one at scale (technical consequences → `Q-11-03(1)`).
  - **Option B — set a stats-retention window now** (e.g. 12 months of detail, then discard).
    *Consequence:* bounds growth, but a trend that silently truncates is the thing the roadmap
    said not to build, and it makes stats expire — which is the very property #11 exists to
    avoid, just on a longer clock.
  - **Option C — keep detail for a window, roll older data into summaries.** *Consequence:* the
    right long-term answer and the most expensive one; it introduces a derived store whose
    correctness must be maintained, which is a design question, not an MVP one.

### (e) Anything else worth ruling now

- **Deleted proxies and destinations.** Both are soft-deleted, so their history survives — but
  it disappears from any naive query. PRD-11 **AC6** states, as a PM-derived call, that history
  **stays counted and stays labelled as deleted**. Named here so the Owner can overrule it with
  the consequence visible: the alternative is that deleting a destination retroactively changes
  last month's numbers.
- **Pre-#6 attempt rows** carry no `delivery_id` (deliberate — ADR-015, no backfill), so a
  delivery-grained figure under (c) Option B cannot classify them. There is no production data,
  so the practical answer is "exclude them and say so" — but it should be *stated*, not
  discovered. (PM recommendation: exclude, and state it in AC13.)

## PM recommendation, in one line

**(a) Tier 2, (b) Option A, (c) Option B, (d) 24 h / 7 d / 30 d, daily buckets, records kept
indefinitely (Option A).** That builds the smallest thing that actually answers the questions the
vision says competitors fail to answer — *is it working, is it getting worse, which destination,
and show me the failures* — reuses the per-event surface #6 already shipped instead of
duplicating it, and puts a headline number on screen that means "did it get through" rather than
"how many HTTP calls went badly".

**The honest counterweight the Owner should weigh.** Tier 2 deliberately omits the retry/terminal
split, and that is its weakest point rather than a neutral trade: with automatic retry shipped
(#6), a delivery-level success rate *already absorbs* retries silently, so Tier 2 can show a
reassuring 100% while a destination is failing on two of every three attempts and burning the
retry budget — and nothing on the dashboard says so. Tier 3 is the tier where the numbers stop
being able to mislead in either direction. If the Owner's real question is *"is my integration
healthy"* rather than *"did anything get lost"*, **Tier 3 is the correct first cut and Tier 2 is
a false economy** — its extra content is the retry, terminal and replay data the system already
records, so the added cost is presentation and query work, not new capture. Latency percentiles
are the one genuinely expensive item in Tier 3 and can be dropped from it without disturbing the
rest.

Second counterweight, smaller: **(d) Option A is the "do nothing" answer to a question nobody has
answered yet.** Choosing it is fine, but it should be chosen knowingly — it commits the product
to two permanently growing tables with no cap, in a feature whose entire premise is that these
records are never thrown away.

## Impact if unresolved

PRD-11 cannot be approved. **AC13** (counting unit), **AC14** (breakdown grains), **AC15** (time
buckets), **AC16** (horizon), **AC17** (drill-down depth and target) and **AC18** (export) have
no concrete content; the **Designer gate cannot open**, because a dashboard's layout is almost
entirely a function of these answers; and the Principal Engineer cannot size the aggregation at
Technical Design. Everything else in PRD-11 — the payload/stats separation requirement, the
truthful-figure rules, scoping and permissions, mode independence, the interactions with #5/#6/#7,
and the scope boundaries — is written in full and is unaffected by any option above.

## Answer

- **Answered By:** **Project Owner**
- **Answered:** **2026-08-26**

**(a) Scope — Tier 3.** Counts and rates, the per-destination split, the daily trend and the
drill-through, **plus** retry/terminal/replay insight and latency. **The Owner took the PM's stated
counterweight over the PM's recommendation**: a delivery-level rate silently absorbs retries and can
read 100% while a destination fails two attempts in three, and Tier 3's extra content is data the
system already records — so the added cost is presentation and query work, not new capture.
→ **PRD-11 AC15, AC16, AC19, AC20, AC21.**

**Export (Tier 4) declined.** #11 ships no CSV or other download of figures or underlying rows.
→ **PRD-11 AC37**, and listed under § Out of Scope as ruled-out rather than deferred.

**(c) Counting unit — BOTH, labelled distinctly.** The **delivery-level** figure is the headline
("did it eventually arrive"); the **attempt-level** figure sits beneath it as the destination-health
signal. **This is a binding requirement, not a presentation preference:** the two must be separately
labelled, must never be presented as interchangeable, and **no single unlabelled "success rate" may
appear anywhere** in the product. Both units are defined precisely in PRD-11 § Definitions so the
Designer and the Principal Engineer inherit one vocabulary. The consequence the PM identified is
stated in the PRD itself (§ Problem 5), because it is exactly the confusion the labelling exists to
prevent: **the same healthy traffic reads 67% failure per-attempt and 100% success per-delivery, and
both are correct.** → **PRD-11 AC13 + AC14** (AC14 is written as four review-checkable clauses).

**(b), (d) and (e) — not separately ruled; delegated to the Product Manager.** The Owner directed
that they be settled in line with Tier 3 and the PM's own recommendations, and that **each be marked
as a PM-derived call in PRD-11's D-11 table so that approving the PRD ratifies them** — none is left
open. Settled as:

| Sub-question | Settled as | Recorded as |
|---|---|---|
| **(b)** what "drill down per webhook" means | **Option A** — "webhook" = the **proxy**; drill-through ends at the already-shipped per-event surface (PRD-06 AC25); no per-received-event statistic and no second events surface | **D-11-6** → AC21 |
| **(d)** windows and bucket granularity | **24 h / 7 d / 30 d, default 30 d**; **daily** buckets only | **D-11-4** → AC16, AC17 |
| **(d)** how long the underlying records are kept | **Option A — indefinitely.** No statistics-retention window, cap or prune at #11; a truncated trend may never be presented as complete. Named consequence carried into the PRD: **two permanently growing tables with no cap** — PRD-05 **D1**'s class of accepted concern, with the technical half open at `Q-11-03(1)` | **D-11-5** → AC18 |
| **(e)** deleted proxies/destinations | Stay counted, **labelled as deleted** — sharpened, because Tier 3's per-destination grain is where the omission would be least visible | **D-11-1** → AC6 (unchanged) |
| **(e)** pre-#6 attempt rows (`delivery_id` NULL) | **Excluded** from delivery-level figures, **included** in attempt-level figures, and the exclusion **stated, not silent** | **D-11-7** → AC13 |

**F3 stands, confirmed by the Owner.** **Per-event-type breakdown is excluded, with the reason
stated** — no long-lived event-type attribute exists outside the payload body, which expires under
#5 retention, so any such figure would either read expiring content (forbidden by AC1) or require
new capture at ingest that no roadmap item has ruled. **It is not quietly reintroduced under Tier
3.** → **PRD-11 AC32**, and § Out of Scope.

**Downstream:**
- **AC13–AC21** are now concretely testable; the `PENDING V7` tags are removed. Criteria renumbered
  from the old AC20 onward (+3) to make room; nothing downstream cites these numbers (no design,
  plan, tasks or review artifact exists for #11) and all three `Q-11-0x` docs were updated in the
  same change.
- **Three criteria did not survive contact with the ruling and were rewritten** — the same failure
  mode PRD-08's AC11 hit, caught the same way, by re-testing rather than assuming:
  - **AC7** read "the **unit** these counts use is AC13", phrased when the unit was a single open
    choice. Ruling (c) makes it **both**, so the phrasing was false. AC7 now states both units.
  - **AC9** read "may include, exclude, or split [retries and replays] — but never leave it
    implicit", a meta-rule standing in for an unmade decision. Retry is now the *defining
    difference* between the two units, so AC9 is concrete rather than conditional.
  - **AC12** read "no traffic is zero, not an error". **Tier 3 introduced latency, and a latency
    figure over a window with no resolved attempts is not zero** — `0 ms` is a false number, not an
    empty one. AC12 now distinguishes **count figures** from **measure figures**. This defect was
    created by the ruling and found by the re-check.
- **Three criteria gained clauses:** **AC10** (each unit reconciles within its own unit, never
  against the other's — 12 failed deliveries and 12 failed attempts are different record sets),
  **AC25** (AC19's retry figures are counted for **Simple** proxies too: retry *configurability* is
  enhanced-only per PRD-06 AC2, retry *behaviour* is not), **AC26** (FIFO's serialised dispatch
  legitimately yields different durations from Async's, and that difference is never a fault —
  otherwise the figure smuggles in the verdict `Q-11-02` forbids).
- **AC4's export clause** was reconciled: it now binds any future egress while noting none ships.
- **AC29 was confirmed, not assumed:** every Tier 3 figure traced to a column that already exists —
  latency → `duration_ms`, retry volume → `attempt_number`, eventual/terminal → `deliveries.status`,
  live-vs-replay → `deliveries.kind`. **The richer tier still requires no new capture at delivery
  time.**
- **F1, F2 and F4 are unaffected** and remain with the Principal Engineer at `Q-11-03`. F2 is
  **sharpened** by Tier 3's per-destination grain; AC20's percentile makes the rollup question
  (`Q-11-03(6)`) materially more likely to bite.
- **Roadmap V7 is closed.** `docs/status.md` is the Orchestrator's to update.
