# PRD: Analytics / stats

- **Status:** **Approved (Project Owner, 2026-08-26).** V7 (`Q-11-01`) is **closed**; V8
  (`Q-11-02`) is **renewed, not closed** — it stays open against **#4 and #11**. Approving this
  PRD **ratified the four PM-derived calls D-11-4..7**, which were presented to the Owner
  explicitly: daily buckets with a 30-day default; statistics kept **indefinitely** with no cap;
  "per webhook" meaning the proxy; and pre-#6 rows excluded from delivery-level figures. On
  **D-11-5** the Owner ruled separately to **leave statistics indefinite at #11** — the growth
  question was put with its named consequence (two permanently growing tables, PRD-05 D1's class
  of concern, compounded by F1) and deliberately left to the PE at `Q-11-03(1)` rather than
  scoped into this feature. **Next gate: the Designer** — `## UX Direction` is present and a
  PM-approved `design-11` is a prerequisite for Technical Design.
- **Amendment A (Product Manager, 2026-08-26)** — two clarifications raised by the `design-11`
  approval gate: **AC12**'s empty-state rule as it applies to a rate with a **zero denominator**,
  and the **grain** at which AC16's daily series and AC20's percentile are obliged. **No criterion
  is added, removed, renumbered or weakened**; both clarify what an existing criterion already
  required, and both are recorded so the Reviewer does not read a literal phrase against a design
  the Product Manager approved. See § Amendment A, after the acceptance criteria.
- **Author:** Product Manager
- **Date:** 2026-08-26 · **Revised:** 2026-08-26 — the Owner's V7 and V8 rulings rendered into
  **AC13–AC21** (V7) and **AC22** (V8), with **AC33** finalised under the V8 ruling. **AC7, AC9
  and AC12 were rewritten and AC10, AC25 and AC26 gained clauses** after re-checking every
  supposedly ruling-independent criterion against the concrete answers — see the AC preamble for
  what was checked and what did not survive contact. Criteria renumbered from the old AC20 onward
  (+3); no criterion was removed and no requirement changed by the renumbering.
- **Approved by / date:** Project Owner, 2026-08-26
- **Backlog item:** Roadmap #11 (`docs/product/roadmap.md`). Selected as next up by the Project
  Owner on 2026-08-26 after **#8 was deferred** (not withdrawn — its artifacts are complete and
  parked). This is a **resequencing only**: roadmap numbers are stable identifiers and are not
  renumbered. #11's dependency is **#4 (Done)**, so nothing blocks it.
- **Build-ahead status:** none needed. Unlike PRD-07 and PRD-08, this PRD is not written against
  work in flight — every item it rests on (#1, #3, #4, #5, #6) is **Done**, and #7 is in Review
  with all its decisions frozen. #8, #9, #10, #12, #13 and #14 are neither depended on nor
  pre-empted.

## Feature
A team member can see **how many deliveries succeeded and how many failed** for their team and for
each proxy, over time and per destination, **how much retrying and replaying sits behind those
numbers**, and **how long deliveries take** — and can drill down from any figure to the individual
events behind it. Every number is built from the **delivery-attempt and delivery records** emitted
since #1/#4, so statistics **survive the expiry of the payloads they describe** and stay long-lived
and trendable.

## Definitions
Fixed vocabulary; every criterion below uses these words exactly. **The two success units and the
three V8 terms are definitions the Owner ruled must be fixed here**, so the Designer, the Principal
Engineer and any future V8 or V3 ruling inherit one vocabulary rather than re-arguing it.

| Term | Meaning |
|---|---|
| **Attempt** | One `delivery_attempts` record: a single HTTP send to one destination, with its outcome, HTTP status, error summary, attempt number and duration (ADR-003). Payload-free by construction. Retries and replays produce attempts of the same shape. |
| **Delivery** | One `deliveries` record: the work of getting **one dispatch to one destination**, across however many attempts it takes, ending in an explicit terminal `succeeded` or `failed` (ADR-015). Carries `kind` = `original` \| `replay`. |
| **Delivery-level figure** | The **headline** unit. Counts **deliveries that reached a terminal state** in the window: `succeeded` over (`succeeded` + `failed`). Non-terminal deliveries (`pending`, `retrying`) are **excluded, not counted as failures** — they have not finished. Answers *"did it eventually arrive?"* |
| **Attempt-level figure** | The **destination-health** unit. Counts **attempts that resolved** in the window: `succeeded` over (`succeeded` + `failed`). Unresolved attempts (`dispatched`) are excluded. Answers *"how hard is the system working to get it there?"* |
| **Eventual success** | A delivery that reached terminal `succeeded` **after two or more attempts** — i.e. retry did its job. Visible as a distinct figure (AC19), never folded silently into the headline. |
| **Terminal failure** | A delivery that reached `failed` — the retry budget was exhausted and nothing arrived (ADR-015 Decision 1: a stored fact, never a derivation). |
| **Delivery duration** | The **measured** latency #11 shows: per attempt, the wall-clock time of the HTTP send (`duration_ms`); per delivery, first attempt's start to terminal outcome. **Excludes queue wait time**, which nothing records. |
| **Ingest-to-first-attempt latency** | **Defined here, not displayed at #11.** Elapsed time from an event being received to its first delivery attempt starting. Derivable from `webhook_events.received_at` and the earliest attempt's `started_at`, but it **conflates queue wait with pipeline processing**, so it is a definition for a future V8 target — not a Tier 3 figure. |
| **Throughput** | **Defined here, not measured and not measurable today.** Events ingested or deliveries attempted per unit time **under sustained load**. Observed volume in a window measures *offered traffic*, not *capacity*; no load test exists. This is the definition a future V8 target — and any V3 queue-choice argument — would use. |
| **Received event** | One `webhook_events` record: one webhook that arrived at a proxy's ingest URL. Its **descriptors** survive payload erasure; its **content** does not. |
| **Descriptor** | A non-content field of a received event. Used exactly as PRD-06 AC22/AC25 use it. |
| **Payload content** | A captured raw body or captured inbound headers, and the stored dispatched output. Subject to #5 retention; erased in place on expiry (ADR-012, ADR-014). |
| **Cleaned** | An event whose payload content has been erased on expiry, its record retained with an explicit cleaned state (PRD-05 AC21, Amendment A). One of three payload states with **retained** and **never captured**. |
| **Long-lived record** | A record that no retention window, GC pass or delete path removes: an attempt, a delivery, and a received event's descriptors. The only records #11 may count. |
| **Statistic / figure** | Any number, rate or series #11 displays. |
| **Count figure** | A figure whose natural empty value is **zero** (counts, rates over counts). |
| **Measure figure** | A figure that summarises observations and has **no meaningful value when there are none** — delivery duration in particular. Its empty state is *no data*, never `0`. |
| **Window** | The period a figure covers (24 hours, 7 days, or 30 days — AC17). |
| **Bucket** | A subdivision of a window that turns one figure into a series: **one point per day** (AC16). |
| **Drill-down** | Moving from an aggregate figure to the records behind it, ending at the **already-shipped** per-event surface (PRD-06 AC25). |
| **Mode** | The simple/enhanced axis (ADR-002). Never the `processing_mode` (Async/FIFO) axis — the two stay orthogonal (PRD-07 § Definitions). |

## Problem

The vision names **stats and visibility** as one of the things competitors get wrong: *"Existing
solutions tend to reinvent the wheel and often lack **replay, stats, and visibility**. Some
'blindly send' downstream with no insight into delivery attempts or timings."* Six items have
shipped and replay exists (#6). Stats do not. Concretely:

1. **The records exist and nothing reads them.** Every delivery attempt since the first commit has
   recorded its outcome (ADR-003), and since #6 every delivery has recorded an explicit terminal
   state (ADR-015). The data to answer "is this working" has been accumulating for a month and
   there is no surface that aggregates it.
2. **A user can inspect one event, and can conclude nothing about many.** PRD-06 AC25 shipped a
   per-proxy received-events list and a per-event delivery detail. Both answer *"what happened to
   this webhook"*. Neither answers *"how is this proxy doing"*, *"is it getting worse"*, or
   *"which destination is the problem"*.
3. **The team Dashboard is literally placeholder panels.** `resources/js/pages/Dashboard.vue`
   renders four `PlaceholderPattern` blocks. A team-level surface exists and says nothing.
4. **Payloads expire; understanding must not.** Retention erases payload content 30 days after
   capture (PRD-05, ADR-012). If understanding lived in payloads it would expire with them. The
   roadmap anticipated this and made #1 emit attempt records for the purpose. #11 is where that
   foresight either pays off or is quietly wasted.
5. **Shipping retry before analytics made a single "success rate" actively misleading.** This is
   the problem the Owner's V7 ruling addresses head-on, and it is stated here because it is the
   confusion the whole labelling rule exists to prevent:

   > **The same healthy traffic reads as 67% failure and as 100% success.** A delivery that failed
   > twice and succeeded on the third attempt is **one delivery that arrived** and **three attempts,
   > two of which failed**. A per-attempt figure calls that traffic 67% failed. A per-delivery
   > figure calls it 100% succeeded. **Both are correct.** A product that shows one of them, or
   > shows either without saying which it is, tells the user something false about their system —
   > either "you are losing data" when nothing was lost, or "everything is fine" when a destination
   > is failing two attempts in three and burning the retry budget.

   The Owner's ruling is therefore that **both units are shown, separately labelled, and neither is
   ever presented as *the* success rate** (AC13, AC14).

### Does the separation hold? — verified against the current schema

The roadmap's build-ahead note is the load-bearing constraint on this item: *"Stats are built from
the delivery-attempt records emitted since #1/#4 and kept **separate from retained payloads**
(which expire under #5 retention) so they stay **long-lived and trendable** — this is exactly why
#1 must emit attempt records rather than have #11 reconstruct them later."* The Product Manager
verified the claim against the migrations, models and ADRs rather than assuming it.

**Verdict: the separation holds. Nothing #11 counts is erased or deleted by retention or GC —
including everything the Owner's Tier 3 ruling added.**

| Record #11 counts | Touched by retention / GC? | Evidence |
|---|---|---|
| `delivery_attempts` — outcome, `attempt_number`, `duration_ms` | **No.** Never deleted, no soft delete, no retention path anywhere. GC reads it and **writes it never**. | Migration docblock: "Payload-free by construction (ADR-003): there is no body/payload column and no `deleted_at` — a delivery attempt is an immutable, always-retained fact." ADR-012 Decision 5: "GC's writes are confined to `webhook_events` and `dispatched_payloads`; it reads `fifo_dispatches` and `delivery_attempts` and writes neither." ADR-012 Impact: "#11 is unaffected by construction (AC9)." |
| `deliveries` — terminal `status`, `kind` (`original`/`replay`) | **No.** No soft delete, no GC, no prune. | ADR-015 Decision 1; migration: "No soft delete, no payload column." |
| `webhook_events` **descriptors** | **No — but only because of PRD-05 Amendment A.** Expiry is an **erase-in-place `UPDATE`**: it writes `body = NULL`, `headers = NULL`, `payload_cleaned_at = NOW()` and nothing else. `id`, `team_id`, `proxy_id`, `ingest_id`, `method`, `content_type`, `byte_size`, `received_at`, `created_at` all survive. `byte_size` records the **plaintext received size**, so received-volume is trendable after the payload is gone. | ADR-012 Decision 1 and § Revision A ("Removal is a hard delete → **Dropped.** Erasure is a conditional in-place `UPDATE`; the captured record is retained (AC11)"). |
| `fifo_dispatches` | **No.** Never removed. | ADR-012 Decision 5: "`fifo_dispatches` rows are now never removed." (Accepted as deferred concern **D1**.) |
| `error_summary` on an attempt | Retained forever — and carries **no payload content**: the exception message only, truncated to 247 characters (`Str::limit($e->getMessage(), 247)`), never a destination response body. | `DeliverToDestination.php:161-163`, ADR-003. |

**Re-verified against the Tier 3 ruling.** The Owner chose the richer tier; every figure it adds
still comes from long-lived records — latency from `duration_ms`, retry volume from
`attempt_number`, eventual-success/terminal-failure from `deliveries.status`, live-vs-replay from
`deliveries.kind`. **Tier 3 needs no new capture and touches no payload** (AC29 re-checked and
holds — see the AC preamble).

**The one thing to notice, and the reason AC1/AC2 exist.** The received-event descriptors survive
today as a **consequence** of the Owner's 2026-08-05 Amendment A ruling, not as a guarantee made to
#11. Under ADR-012's original design — hard delete of the captured record — a received-event count
would have **decayed silently** as payloads expired: last month's "events received" would shrink
every night. Nothing anywhere currently states that #11 depends on erase-in-place. **AC1 and AC2
make it a stated requirement**, so a future change to that decision (a V5 retention tier, a D1
growth prune, a storage-region rework under V6) cannot take statistics with it without tripping an
explicit criterion.

**Four findings that are not erasure, routed to the Principal Engineer as `Q-11-03` — all still
OPEN and unaffected by the V7/V8 rulings:**

- **F1 — the "own lifecycle" ADR-003 promised does not exist.** ADR-003 says attempt records are
  "retained on their own lifecycle, independent of payload retention". There is no window, no GC,
  no cap, no rollup. "Long-lived" currently means *forever and growing*, arrived at by omission
  rather than by ruling. **AC18 now makes indefinite retention an explicit product position**
  (D-11-5); the technical consequence stays with the PE at `Q-11-03(1)`.
- **F2 — soft-deleted parents make history invisible without erasing it.** `Proxy` and
  `Destination` both use `SoftDeletes`, so their global scope drops trashed rows from any default
  join. Attempt rows survive a deletion, but an aggregate written the obvious way **under-counts
  silently instead of failing**. Already a known hazard: `ProxyEventController` carries explicit
  `->withTrashed()`, and `Delivery::proxy()`'s docblock spells the trap out. → **AC6**,
  `Q-11-03(2)`. **Tier 3's per-destination split makes this more load-bearing, not less.**
- **F3 — there is no long-lived record of *what kind* of event arrived.** Outside the payload body,
  nothing records an event type. "Success/failure per event type" is **not derivable from surviving
  records**; it could only read expiring payload content, which is exactly what the build-ahead
  note forbids. **The Owner confirmed this stands and is not reintroduced under Tier 3.** →
  **AC32**, `Q-11-03(3)`.
- **F4 — pre-#6 attempt rows carry `delivery_id = NULL`** (deliberate, ADR-015, no backfill), so a
  delivery-grained figure cannot classify them. → **AC13 now states the treatment explicitly**
  (D-11-7); `Q-11-03(4)`.

## What earlier items delivered vs. what #11 adds

| Concern | Owner | State |
|---|---|---|
| Per-attempt outcome records, per destination, payload-free, emitted from the first commit | #1 (ADR-003) | Done — **the aggregation source**; #11 reconstructs nothing |
| `duration_ms` recorded on every attempt | #1 (ADR-003) | Done — **Tier 3's latency figures need no new capture** |
| Explicit per-delivery terminal state (`succeeded`/`failed`), never inferred | #6 (ADR-015) | Done — **Tier 3's eventual-success / terminal-failure split** |
| True incrementing `attempt_number` + `delivery_id` on every attempt | #6 (ADR-015) | Done — **Tier 3's retry volume, and the attempt-level unit** |
| Replay identifiable and traceable, on the same stream, via `deliveries.kind` | #6 (AC12, ADR-017) | Done — "#11/#13 distinguish replays by a join, not a second pipeline" |
| Erase-in-place retention: the record survives, the content does not | #5 (Amendment A, ADR-012/014) | Done — **why descriptors are still countable after expiry** |
| Per-proxy received-events list and per-event delivery detail | #6 (AC25, `design-06`) | Done — **the bottom of #11's drill-down; #11 links to it, never rebuilds it** |
| Mode-independence of attempt records and domain events | #7 (AC7) | Done — #11 needs no mode gate |
| A team Dashboard page with placeholder panels | #1 / starter kit | Exists and is empty |
| **Two separately-labelled success units, and the rule that neither stands alone** | **#11** | **This PRD** — Owner ruling, 2026-08-26 |
| **Team, per-proxy and per-destination figures, on a daily trend** | **#11** | **This PRD** (Tier 3) |
| **Retry, terminal-failure, replay and latency insight** | **#11** | **This PRD** (Tier 3) |
| **Drill-through from an aggregate figure into the existing event surface** | **#11** | **This PRD** |
| Alerting on any of these numbers | #13 | Not here — #11 emits no notification and defines no threshold |
| Per-event-type / per-map statistics | #8, #9, #12 | Not here — and **not buildable today** (F3) |
| Data export | — | **Not here.** Tier 4 was ruled out (AC37) |
| Sensitive-field policy on anything displayed | #10 | Not here |

## Goals
- A member can answer **"is this proxy working, and is it getting worse"** without opening a single
  event.
- A member can tell **"nothing was lost" from "nothing is wrong"** — the two are different, and
  before this item the product could express neither.
- Statistics **outlive the payloads they describe**. Payload expiry is invisible to every number.
- Every figure is **honest about what it counts** — its unit, its window, and what is inside it.
- #11 is **read-only over the existing record stream**. No parallel path, no second events surface,
  no new fact captured at delivery time (roadmap build-ahead, verbatim).
- Analytics is **available to every proxy**, simple and enhanced, Async and FIFO alike.
- The feature **shows facts and grades none of them** — no target, no verdict (Owner V8 ruling).

## Users
- **Team member (operator)** — the primary user. Wants to know whether deliveries are getting
  through, whether that is changing, and which proxy or destination to look at when it is not.
- **Team member (proxy owner/author)** — wants the same figures scoped to a proxy they configured,
  and a route from a bad number to the events behind it.
- **Team** — statistics are team-owned data; every figure is team-scoped (R1).
- **The product (system)** — aggregates existing records; writes nothing to them.
- **Upstream sender and destination endpoint** — unaffected. #11 changes no runtime behaviour.

## User Stories
- As a team member, I want to see how many deliveries succeeded and failed for my team, so I know
  whether anything needs my attention before someone tells me it does.
- As a team member, I want those figures per proxy and per destination, so I know **which**
  integration — and which endpoint — is unhealthy.
- As a team member, I want to see the figures over time rather than as a single number, so I can
  tell a chronic problem from a bad afternoon.
- As a team member, I want to know **how much retrying is going on behind a healthy-looking number**,
  so a destination failing two attempts in three does not stay invisible until the retry budget runs
  out.
- As a team member, I want to know how many deliveries **gave up entirely**, because that — not a
  failed attempt — is the number that means something was lost.
- As a team member, I want to see how long deliveries take, so "no insight into timings" stops being
  true of this product too.
- As a team member, I want to go from a failure figure to the actual failed events, so a bad number
  is the start of a diagnosis rather than the end of one.
- As a team member, I want each figure to tell me what it counts, so I never mistake a retry storm
  for data loss — or data loss for a retry storm.
- As a team member, I want last quarter's numbers to still be there even though last quarter's
  payloads are gone, so I can see a trend rather than a 30-day window that keeps resetting.
- As a team member, I want a proxy I deleted last month to still appear in last month's totals, so
  deleting something today does not rewrite history.
- As the product (system), I want analytics to read the records #1/#4/#6 already emit, so no second
  recording path exists to keep consistent.

## UX Direction

**#11 is a UI feature — the dashboard *is* the item.** The roadmap says "**Users can see** success
and failure counts and **drill down** per webhook". This section's presence makes the **Designer
gate mandatory**: `design-11` must be written and **Product-Manager-approved before Technical
Design**. Direction only — screens, states, components, chart forms and copy are the Designer's.

- **Primary flow: from "how is everything" to "show me the failures", in three steps.** A member
  arrives at team-level figures, identifies the proxy that looks wrong, opens that proxy's figures
  — where the per-destination split says *which endpoint* — and lands in the **already-shipped**
  per-event surface (PRD-06 AC25) at the events behind what they were looking at. Optimise for
  *"a member who suspects something is broken reaches the evidence without knowing where to look"*.
- **The two units are the central design problem of this surface, not a labelling detail.** The
  Owner ruled both are shown: a **delivery-level headline** ("did it arrive") with the
  **attempt-level figure beneath it** ("how hard did we work to get it there"). The same healthy
  traffic reads 100% and 67% respectively — see § Problem 5 — so the experience must make it
  *impossible* to read one as the other. The design must not resolve this by hiding the second
  figure, by putting it behind a toggle that makes them look like two views of one number, or by
  giving them equal prominence with no stated relationship. Neither may ever appear as an
  unlabelled "success rate" (AC14). This is the hardest thing on the surface to get right and it is
  the Designer's central brief.
- **Two homes, and neither is new.** The team-level figures belong on the existing **Dashboard**
  page, which today renders placeholder panels. The per-proxy figures belong on the existing proxy
  **Show** page or its events surface. #11 should not introduce a separate "Analytics" area that
  competes with surfaces a member already uses — the numbers belong where the thing they describe
  already lives. **Extend, do not annex.**
- **Never build a second events surface.** PRD-06 shipped the event list, the per-destination
  delivery state, the attempt history and the masked payload viewer with its reveal behaviour. #11
  links into all of it and duplicates none of it. The payload viewer, its mask and its reveal are
  settled at #6/Q-06-02 and are **#10's** to change, not #11's.
- **Retry and replay are shown as their own story, not folded into the headline.** Eventual success
  after retry, terminal failure, retry volume, and the live-vs-replay split are what make the
  headline trustworthy (AC19). They should read as *"here is what sits behind that number"* rather
  than as four more tiles competing with it.
- **Latency is a measure, not a count — and it has a different empty state.** A window with no
  resolved attempts has **no** duration to report; showing `0 ms` would be a wrong number, not an
  empty one (AC12, AC20). The tail matters more than the average — a 95th-percentile figure beside
  the mean is the point — because an average hides exactly the slow destination a member is looking
  for.
- **No verdict. The Owner ruled this explicitly.** The surface shows **facts and grades none of
  them**: no red/green threshold, no healthy/unhealthy badge, no SLA reference line, no
  "good"/"bad" language, and **no number presented as a target in any labelled form**. This is a
  binding design constraint, not a stylistic note — colour and iconography are how a UI asserts a
  target without stating one, and asserting one is reserved to the Owner (AC22). Colour may encode
  *category* (succeeded vs failed) but never *judgement* (acceptable vs unacceptable).
- **Zero traffic is a normal state, not an empty error.** A new proxy, a quiet week, and a team
  that has not sent anything yet all show zeros — with an explanation of what would populate them —
  never a broken state, an endless spinner, or an error. A proxy whose only events are cleaned has
  intact numbers and must not read as degraded.
- **Deleted things stay in the picture, labelled.** A destination removed last week still appears in
  last month's per-destination breakdown, marked as deleted (AC6). It must read as history, not as a
  configuration error, and must offer no actions against something that no longer exists.
- **Truthful presentation, inherited unchanged from #7.** PRD-07 AC12 binds this item. #11 is
  read-only, so it reduces to: **no internal roadmap numbers in user-facing text**, and **no claim
  of a capability that is not built**. Nothing here may imply per-event-type analytics, payload
  mapping (#8 — **deferred**, so PRD-07 AC12's constraint against implying it still stands),
  multi-format ingestion (#9), change detection (#12), or alerting (#13) exists.
- **No workflow builder, no report builder.** #11 shows a fixed set of figures. A user-configurable
  metric builder, saved views, custom queries or exports are not in this product (AC37).

## Acceptance Criteria

> **All criteria are concrete and testable.** V7 and V8 were RESOLVED by the Project Owner on
> 2026-08-26; the `PENDING` tags are gone. Three things are recorded here rather than made
> silently.
>
> **(1) Every criterion previously claimed to hold "under any V7/V8 answer" was re-checked against
> the concrete rulings, not assumed** — the same discipline that caught PRD-08's AC11. Three did
> **not** survive contact and were rewritten; three gained a clause; the rest hold as written. See
> § Open Questions → "Re-check of the ruling-independent criteria" for the full list and what was
> found.
>
> **(2) AC7 was rewritten, not merely re-checked.** It read "the **unit** these counts use is
> AC13", written when the unit was a single open choice. The Owner ruled **both** units are shown,
> so a criterion phrased around one unit is now wrong. AC7 states both.
>
> **(3) AC12 was rewritten.** It read "no traffic is zero, not an error". Tier 3 introduced
> **latency**, and a latency figure over a window with no resolved attempts is **not zero** — `0 ms`
> would be a false number, not an empty one. AC12 now distinguishes count figures from measure
> figures. This is a defect the ruling created and the re-check caught.
>
> Criteria are renumbered from the old AC20 onward (+3) to make room for AC13–AC21. Nothing
> downstream cites these numbers — no design, plan, tasks or review artifact exists for #11 — and
> the three `Q-11-0x` docs were updated in the same change.

**Statistics survive payload expiry — the separation requirement**

1. **Every statistic is derived only from long-lived records.** A figure may be computed from
   attempts, deliveries, and received-event **descriptors** — records that no retention window, GC
   pass or delete path removes. **No figure may be computed from payload content** (a raw body,
   captured inbound headers, or a stored dispatched output), directly or indirectly. *(Roadmap #11
   build-ahead, verbatim.)*
2. **Payload expiry never changes a statistic.** Erasing an event's payload content under PRD-05
   retention leaves every #11 figure **numerically identical**. Directly testable: compute any
   figure, run the garbage collector over the events it covers, recompute — the figures match.
   Statistics are long-lived and trendable **because** of this criterion, not incidentally to it.
3. **A cleaned or never-captured event still counts, and stays attributable.** The three payload
   states (PRD-05 AC21) are irrelevant to aggregation: a cleaned event contributes to every figure
   exactly as a retained one does, and still resolves to its proxy and its destinations in every
   breakdown. A payload state is **never** a reason to exclude a record from a count, and no figure
   may be qualified or footnoted by one.
4. **No payload content enters any statistic, store or surface.** #11 adds no payload content or
   captured header to any aggregate, cached figure, derived record or screen. ADR-003's payload-free
   constraint on attempt records is **not relaxed** — no field that could carry payload or sensitive
   data may be added to them for analytics' sake. This binds any egress #11 might grow later even
   though it ships none (AC37). Sensitive-data policy is **#10** and is unchanged here.
5. **#11 reads the record stream; it never mutates it.** Analytics never creates, updates or deletes
   a delivery attempt, a delivery, a received event, a dispatched-output row, or a FIFO ordering
   row, and changes no record's lifecycle, retention or hold behaviour. *(Whether a derived or
   rollup store exists at all is technical — `Q-11-03(6)` — but no answer may alter the source
   records.)*
6. **Deleting a proxy or a destination does not remove it from history.** *(**PM-derived —
   D-11-1**.)* A figure covering a past period includes activity of proxies and destinations that
   have since been deleted; such a record remains attributable and is **labelled as deleted**, never
   dropped from the count and never reduced to "unknown". Deleting something today must not
   retroactively change last month's numbers — including in the **per-destination breakdown**
   (AC15), where the omission would be least visible and most misleading. *Basis: a trend that
   silently loses a leg is not a trend; soft-delete already retains the rows, so this is a
   requirement on how they are read (F2), not a request to keep new data.*

**What the numbers say**

7. **Success and failure figures exist, in both units, for the team and for each proxy.** For a
   proxy the member can view, the product shows the **delivery-level** figure (deliveries that
   arrived vs. gave up) **and** the **attempt-level** figure (sends that succeeded vs. failed) over
   a stated window, with the corresponding rates; the same pair exists aggregated across the team's
   proxies. *(Roadmap #11: "Users can see success and failure counts." Owner ruling, 2026-08-26:
   both units, labelled distinctly. Definitions of the two units are fixed in § Definitions.)*
8. **Every figure names what it counts and over what period.** No number, rate or series point
   appears without its **unit** and its **window** being visible to the member. A figure whose unit
   or window a member must infer does not satisfy this criterion.
9. **Retries and replays are visible in the figures, never hidden inside them.** The two units
   differ precisely by retry: the delivery-level figure **absorbs** retries (a delivery that
   succeeded on attempt three is one success), the attempt-level figure **exposes** them (the same
   delivery is two failures and one success), and AC19 shows the retry volume that explains the
   gap. Replays are counted and **separately identifiable** via the existing `original`/`replay`
   distinction (PRD-06 AC12), never silently merged into live traffic. No figure may leave its
   treatment of retries or replays implicit.
10. **Figures are consistent and reconcile, each within its own unit.** The same metric, over the
    same window, for the same subject shows the **same value on every surface it appears on**; and
    an aggregate reconciles with the records a member reaches by drilling into it — a
    delivery-level figure of 12 failures leads to 12 **deliveries**, an attempt-level figure of 12
    failures leads to 12 **attempts**, and neither is checked against the other's record set.
11. **A figure that is not live says as of when it is current.** If any number is served from a
    cached or derived source rather than computed at request time, the surface states the time it
    reflects. A member must never be unable to tell whether a number is current. *(Whether this
    case arises is `Q-11-03(6)`; Tier 3's percentile makes it more likely; the requirement binds
    either way.)*
12. **An empty window is empty in the right way: zero for counts, "no data" for measures.**
    A proxy, destination or team with no activity shows **zero** for every **count figure**, with an
    indication of what would populate it. A **measure figure** — delivery duration (AC20) — over a
    window with no resolved attempts shows **"no data"** and **never `0`**: zero milliseconds is a
    false statement, not an empty one. In neither case is the absence of data rendered as a failure,
    a broken surface, or a missing feature.

**The dashboard — as ruled by the Project Owner, 2026-08-26 (`Q-11-01`, roadmap V7 = Tier 3)**

13. **Two counting units, both defined, both shown.** Every success/failure figure is denominated
    either **per delivery** or **per attempt**, as defined in § Definitions, and **both are
    present**: the delivery-level figure as the headline, the attempt-level figure alongside it as
    the destination-health signal. Concretely, and testably:
    - **Delivery-level** counts only deliveries that reached a **terminal** state in the window.
      `pending` and `retrying` deliveries are **excluded**, never counted as failures — they have
      not finished.
    - **Attempt-level** counts only attempts that **resolved** in the window. `dispatched` attempts
      are excluded.
    - **Pre-#6 attempt rows carry no `delivery_id`** (deliberate, ADR-015, no backfill) and are
      therefore **excluded from every delivery-level figure and included in attempt-level figures**;
      the exclusion is stated, not silent. *(**PM-derived — D-11-7**; F4. Dev/CI data only — no
      production data exists.)*
    - Neither unit is derived from, converted into, or reconciled against the other.
14. **The two units are labelled distinctly and are never interchangeable — a binding requirement,
    not a presentation preference.** *(Owner ruling, 2026-08-26, stated as binding.)* Verifiable at
    review as four checks:
    **(a)** wherever a success or failure figure appears, its unit is **named in the figure's own
    label** — not in a tooltip alone, not in a legend elsewhere on the page, not in help text;
    **(b)** **no unlabelled "success rate", "failure rate", "success count" or equivalent appears
    anywhere** in the product — a figure whose unit is not stated is a defect, regardless of which
    unit it actually uses;
    **(c)** the two are never presented as **the same figure in two views** — not as a unit toggle,
    a dropdown, a tab pair, or a setting that swaps one for the other, because each of those frames
    them as alternatives rather than as two facts;
    **(d)** no copy, arithmetic or visual treatment implies one can be substituted for, converted
    into, or reconciled against the other.
    *Why this is binding: the same healthy traffic reads 100% and 67% (§ Problem 5). The labelling
    is the entire defence against the product asserting something false.*
15. **Figures break down by team, by proxy, and by destination within a proxy.** All three grains
    are available for the success/failure figures in both units. The per-destination split is what
    turns "this proxy is failing" into "**this endpoint** is failing" and is the grain a member acts
    on. AC6 binds it: a destination deleted since still appears, labelled as deleted.
16. **Figures are time-bucketed into a daily series across the window.** Each figure is available
    as a series of **one point per day**, not only as a single number for the whole window, so a
    chronic problem is distinguishable from a bad afternoon. *(Daily granularity is **PM-derived —
    D-11-4**: the Owner ruled Tier 3 but did not rule granularity. Hourly is a second axis of cost
    for a question — "which hour did it break" — the per-event surface already answers exactly.)*
17. **Three windows are selectable: last 24 hours, last 7 days, last 30 days; 30 days is the
    default.** *(**PM-derived — D-11-4**.) Basis: 30 days matches the retention window members
    already understand, giving the trend a familiar frame without implying statistics expire with
    payloads — a confusion AC18 exists to prevent.*
18. **The underlying records are kept indefinitely; a trend never truncates silently.** *(**PM-derived
    — D-11-5**.)* No statistics-retention window, cap, prune or rollup-with-discard is introduced at
    #11: attempts and deliveries are kept, and a figure over a window is computed from every record
    in it. **The product must never present a truncated trend as a complete one** — if a horizon is
    ever introduced, the surface must say where the data stops. *Basis: the roadmap's "long-lived and
    trendable"; ADR-003's "retained on their own lifecycle". **Named consequence the Owner is asked
    to ratify:** two permanently growing tables with no cap and no target — the same class of
    accepted concern as PRD-05's deferred **D1**, and the technical half stays open at `Q-11-03(1)`.*
19. **Retry, terminal-failure and replay insight is shown alongside the headline.** Four things,
    each derivable from records that already exist:
    **(a) eventual success** — deliveries that reached `succeeded` **after two or more attempts**,
    i.e. what retry rescued;
    **(b) terminal failure** — deliveries that reached `failed`, the number that actually means
    something did not arrive (ADR-015: a stored fact, never inferred);
    **(c) retry volume** — how much retrying sits behind the window's deliveries;
    **(d) live vs replay** — the same figures split by the existing `original`/`replay` distinction
    (PRD-06 AC12, `deliveries.kind`), so manual replays never inflate or deflate live-traffic
    figures unnoticed.
    *This is what makes the headline trustworthy: without (a) and (c), a delivery-level figure can
    read 100% while a destination fails two attempts in three — the failure mode the Owner's Tier 3
    ruling exists to close.*
20. **Delivery duration is shown, with its tail, not only its average.** The product shows the
    **average** delivery duration and a **high-percentile** figure (e.g. the 95th) for the window
    and grain, because an average hides exactly the slow destination a member is hunting.
    - The figure is a **measure figure**: AC12's "no data" rule governs its empty state.
    - Its meaning is fixed in § Definitions and **excludes queue wait time**; nothing may present it
      as end-to-end latency.
    - *If the Principal Engineer establishes that a true percentile is not feasible
      (`Q-11-03(6)`), a substitute must still expose the tail and must be labelled for what it is
      (e.g. an approximation, or a slowest-N view). **A bare average does not satisfy this
      criterion.***
21. **Drill-through ends at the existing per-event surface; "per webhook" means the proxy.**
    *(**PM-derived — D-11-6**, resolving the roadmap's and vision's ambiguous "drill down per
    webhook".)* Aggregate figures are per team, per proxy and per destination (AC15); "drill down"
    means moving from a figure to the **already-shipped** per-event list and per-event detail
    (PRD-06 AC25), carrying the context the member was looking at. **#11 builds no new event-level
    surface and no per-received-event statistic** (AC28). *Basis: the product calls the configured
    object a webhook proxy; the bottom of the drill-down already exists and is approved; a
    per-event statistic would largely restate the event-detail surface, which already shows every
    destination and every attempt for that event.*

**Targets — as ruled by the Project Owner, 2026-08-26 (`Q-11-02`, roadmap V8: deferral renewed,
definitions settled)**

22. **No numeric target is asserted, and no figure carries a verdict — but the definitions are
    fixed.** Three parts, all testable:
    **(a) No target.** #11 asserts no throughput, latency, delivery-success, freshness or query-time
    number, and **no number appears on screen in any labelled form as a target, baseline or
    reference** — including a "non-binding" one, because a number is read as a promise regardless of
    its label (Owner ruling, accepting the PM's recommendation against Option B).
    **(b) No verdict layer.** No figure carries a threshold state: no red/green pass-fail colour, no
    healthy/unhealthy badge, no SLA reference line, no "good"/"bad" language, no ranking of a proxy
    or destination as acceptable or unacceptable. Colour may encode **category** (succeeded vs
    failed) and never **judgement**.
    **(c) The definitions are settled here.** **Delivery success** (§ Definitions, AC13),
    **delivery duration** (§ Definitions, AC20), **ingest-to-first-attempt latency** and
    **throughput** (§ Definitions — both defined, neither displayed at #11) are fixed by this PRD so
    a later V8 ruling is **a number typed into an existing definition, not a fresh argument**. A
    future target that redefines any of them is reopening a settled decision, not setting a number.
    *Recorded so the trend is visible rather than rediscovered: this is the **fourth** deferral of
    V8 (PRD-06 AC24, PRD-07 AC25, PRD-08 AC34, and now here) and **the first with a visible product
    cost** — the dashboard renders no verdict, and **#13 inherits no threshold to alert on** beyond
    the `DeliveryExhausted` terminal event that ADR-015 already emits. V8 remains filed against
    **#4 (Done)** as well as #11, and the definitions above are the ones a **V3** queue-choice
    argument would use.*

**Access, scoping and independence**

23. **Team-scoped, always.** Every figure covers the acting member's current team only. No
    cross-team data appears in any aggregate, series or drill-down, and no figure may be computed
    across teams (R1; existing team-scoping mechanisms bind unchanged).
24. **Permission-gated, never role-gated; no new permission.** *(**PM-derived — D-11-2**.)* Viewing
    a proxy's figures is gated by the existing team-scoped proxy **read** permission under the #2
    model, and a member sees team-level figures aggregated over the proxies they may read. #11
    introduces **no new permission**, no new role, and no distinct analytics gate; there is no
    direct role check anywhere. *(PRD-05 AC16's standing constraint on read paths binds every
    surface #11 adds. No export ships (AC37), so #11 adds no new egress path to gate.)*
25. **Analytics is mode-independent — including its retry figures.** Figures cover **Simple** and
    **Enhanced** proxies alike. #11 adds nothing to the enumerated set at PRD-07 AC6, requires no
    change to the `mode` attribute, and adds no gate, sub-toggle or toggle surface. **AC19's retry
    figures are counted for Simple proxies too**: retry *configurability* is enhanced-only (PRD-06
    AC2), but retry *behaviour* is mode-independent (PRD-06 AC1, PRD-07 AC7) — a Simple proxy
    retries under the system default and its retries must be counted, not gated out.
26. **Analytics is processing-mode independent, and a difference between modes is not a fault.**
    Async and FIFO proxies are counted identically; no figure is available for one and not the
    other. **FIFO's serialised dispatch legitimately produces different duration and retry figures
    from Async's**, and nothing may present that difference as a defect, a warning, or a reason to
    prefer one mode — consistent with PRD-07 § UX Direction ("Enhanced must never suggest anything
    about ordering or throughput") and AC22(b)'s no-verdict rule.

**Interaction with settled items**

27. **#11 changes nothing that is already settled.** Retention, the 30-day window, the GC and its
    holds (#5); automatic retry, its policy, its terminal state and manual replay (#6); the mode
    toggle and its semantics (#7); the decoupled upstream response (#3); fan-out (#1); Async/FIFO
    processing (#4) — all untouched. #11 adds no runtime behaviour to the ingest or delivery path.
28. **The existing per-event surface is the floor of the drill-down, and is not rebuilt.** #11
    builds **no second received-events list**, no second event-detail view, and no second payload
    viewer; it does not duplicate or alter the masked viewer, its reveal behaviour, or its gate
    (settled at PRD-06 AC25 / Q-06-02, and **#10's** to change).
29. **#11 requires no new fact to be captured at delivery time.** Every figure is derivable from
    what ADR-003 and ADR-015 already record — **re-verified against the Owner's Tier 3 ruling**:
    latency from `duration_ms`, retry volume from `attempt_number`, eventual success and terminal
    failure from `deliveries.status`, live-vs-replay from `deliveries.kind`. *(Roadmap build-ahead:
    analytics is "built from the delivery-attempt records emitted since #1/#4 rather than
    reconstructed later" — and, equally, rather than requiring new capture now.)* **A figure that
    cannot be derived from those records is out of scope for #11 and returns to the Owner as a new
    requirement on a later item; it does not become a silent addition to the ingest path.**
    *(**PM-derived — D-11-3**.)*
30. **Truthful presentation, inherited from PRD-07 AC12.** #11 is read-only, so the rule reduces to:
    **no internal roadmap numbers in user-facing text**, and **no claim of a capability that is not
    built**. Nothing may imply per-event-type analytics, payload mapping (#8 — **deferred**, so
    PRD-07 AC12's constraint against implying it still stands), multi-format ingestion (#9), change
    detection (#12), or alerting (#13) exists.

**Scope boundaries**

31. **No notifications, alerts or thresholds that act.** #11 displays; it never notifies. In-app and
    email alerting, channels, severities and opt-outs are **#13**. #11 defines no alert condition
    and triggers nothing — including on AC19's terminal-failure figure, which is the most
    alert-shaped thing on the surface. *(#13 will consume the `DeliveryExhausted` and
    delivery-attempt events ADR-003/ADR-015 already emit — not an analytics-specific stream.)*
32. **No per-event-type, per-map, or payload-derived analytics.** Breaking figures down by the
    *kind* of event received is **not built here — and is not buildable from long-lived records
    today** (F3: no event-type attribute exists outside the payload body, which expires). **The
    Owner confirmed on 2026-08-26 that this exclusion stands and is not reintroduced under Tier 3.**
    Per-map and per-mapping statistics are **#8** (deferred). Any figure requiring payload content
    is forbidden outright by AC1.
33. **No numeric targets — finalised under the Owner's V8 ruling (2026-08-26).** #11 asserts no
    throughput, latency, delivery-success, freshness or query-time number, in line with the standing
    precedent (PRD-06 AC24, PRD-07 AC25, PRD-08 AC34). **V8 is not closed — the deferral was
    renewed**, and it remains filed against **#4** as well as #11. AC22 carries the full ruling,
    including the definitions that survive it and the fourth-deferral record.
34. **No billing, metering, quota or cost analytics.** Usage counts here are operational visibility,
    never entitlement or consumption accounting. Payment and billing are out of scope product-wide
    (vision § Explicitly Out of Scope); retention-as-a-subscription-lever is V5, deferred at #5.
35. **No cross-team, platform-wide, or administrative analytics.** No operator/admin view across
    teams, no per-user activity analytics, no audit trail of who viewed what. No roadmap item claims
    any of them.
36. **No sensitive-data behaviour.** Field-level obfuscation, encryption policy, verification tokens
    and the mask/reveal settlement stay **#10** and are unchanged (AC4, AC28).
37. **No export, no live refresh, no scheduled reports, no third-party analytics.** **Data export
    was explicitly ruled out** (Owner, 2026-08-26 — Tier 4 declined), so #11 ships no CSV or other
    download of figures or underlying rows. Nor does it ship websocket/polling auto-refresh, emailed
    or scheduled reports, external analytics or BI integration, a user-configurable metric builder,
    or saved views. No roadmap item claims any of them, and the vision's exclusion of a
    workflow-builder-style UI applies in spirit.

## Amendment A — Product Manager, 2026-08-26

Raised by the `design-11` approval gate. Both clauses clarify an existing criterion; **no criterion
is added, removed, renumbered or weakened**, and no requirement the Project Owner did not state is
introduced. Where this section and a criterion's literal wording differ, this section governs.

**(i) AC12 — a rate whose denominator is zero is *undefined*, not `0%`.** § Definitions classifies
"rates over counts" as **count figures** whose natural empty value is zero. That classification is
correct for the *counts* and wrong for the *rate computed from them*: `0%` over zero deliveries
asserts that everything failed, which is exactly the false statement AC12 exists to prevent — the
same defect AC12 already names for latency. AC12 is therefore read as:

- **Raw counts always read `0`** in an empty window (`0 of 0 delivered`, `0` terminal failures,
  `0` retries, `0` replays), with an indication of what would populate them. A count figure is
  never replaced by a "no data" treatment — a count of zero is a true and useful statement.
- **A rate whose denominator is zero is not rendered as a percentage at all.** It takes the same
  no-false-number treatment AC12 gives a measure figure (wording such as "No deliveries yet"),
  never `0%` and never `100%`.
- **Measure figures are unchanged** — AC20's durations read "no data", never `0`.

*Basis: the UX Direction's "zero traffic is a normal state, not an empty error … with an
explanation of what would populate them", read together with AC8's requirement that every figure be
honest about what it counts. Ratifies `design-11`'s flagged design call 2, which raised the conflict
rather than resolving it silently.*

**(ii) AC15/AC16/AC20 — the grain at which a daily series and a percentile are obliged.** AC15 names
three grains (team, proxy, destination). AC16 ("each figure is available as a daily series") and
AC20 ("average **and** a high-percentile figure … for the window and grain") do not name a grain,
and a literal cross-product reading would oblige a per-destination daily series and a
per-destination percentile. That cross product was never ruled: the Owner's Tier 3 ruling lists the
per-destination split and the daily trend as **separate** elements of the tier, not as a matrix, and
AC16/AC17's granularity is **PM-derived (D-11-4)**, so its scope is the Product Manager's to state.
AC16 and AC20 are therefore obliged at the **team and proxy** grains. The **destination** grain
carries the both-unit success/failure figures (AC15, AC13/AC14 in full) and an **average** duration
for the window; a per-destination daily series and a per-destination percentile are **permitted and
not required**, and adding either later is additive rather than a scope change.

*This narrows no figure the Owner ruled: every element of Tier 3 remains present, at the grain where
a member reads it. Ratifies `design-11`'s flagged design call 9.*

## Out of Scope
Each points to the item that owns it.

- **Data export of any kind** — **ruled out by the Owner, 2026-08-26** (Tier 4 declined). Not
  deferred to another item; simply not built (AC37). AC4 would bind it if it were.
- **Alerting on any figure, and any notification** — **#13**. #11 emits none (AC31).
- **Per-event-type breakdown** — not owned by any item, and **not buildable from long-lived records
  today** (F3). If the Owner wants it, it becomes a new requirement on a future item, not a #11
  addition (AC32).
- **Per-map / per-mapping statistics** — **#8**, deferred 2026-08-26 (AC32).
- **Structure-change or drift statistics** — **#12**.
- **Sensitive-field policy, obfuscation, encryption changes** — **#10** (AC36).
- **Retention, GC, storage-shape or payload-lifecycle changes** — settled at **#5**; #11 changes
  none of them (AC27). A retention window for *statistics themselves* is ruled **not introduced**
  at #11 (AC18, D-11-5); its technical half stays open at `Q-11-03(1)`.
- **Retry/replay semantics, values or surfaces** — settled at **#6**; #11 reads their records and
  changes nothing (AC27).
- **The mode toggle or any mode semantics** — settled at **#7**; #11 adds nothing to the governed
  set (AC25).
- **A second received-events surface, per-event statistics, or a second payload viewer** — settled
  at **#6**; #11 links into the existing one (AC21, AC28).
- **Numeric targets and any verdict layer** — **V8, deferral renewed** (AC22, AC33). Not #11's to
  set; the definitions a later ruling needs are fixed here.
- **Billing, metering, quotas, cost** — out of scope product-wide (AC34).
- **Cross-team / admin / per-user analytics, audit trails** — no roadmap item claims them (AC35).
- **Live refresh, scheduled reports, BI integrations, custom metric builders** — no roadmap item
  claims them (AC37).

## Open Questions
Question IDs Q-11-0x. **No question blocks approval.** Both Owner questions are resolved; the one
remaining question is technical and travels to Technical Design.

- **Q-11-01 (Project Owner) — Detailed analytics / dashboard scope. `= roadmap V7` / vision Open
  Question 7. RESOLVED 2026-08-26; no longer blocking.** Doc:
  `docs/questions/prd-11-q-11-01-analytics-dashboard-scope.md`. **(a) Tier 3** — the Owner took the
  PM's stated counterweight over the PM's recommendation: counts, per-destination split, daily
  trend and drill-through, **plus** retry/terminal/replay insight and latency. **Export (Tier 4)
  declined.** → AC15–AC21, AC37. **(c) Both counting units, labelled distinctly** — delivery-level
  headline, attempt-level beneath it, as a **binding requirement** rather than a presentation
  preference → **AC13 + AC14**, with the 67%/100% consequence stated in § Problem 5. **(b), (d) and
  (e) were not separately ruled**; the Owner directed the PM to settle them in line with Tier 3 and
  the PM's own recommendations and to mark each as a PM-derived call → **D-11-4** (windows and daily
  granularity, AC16/AC17), **D-11-5** (records kept indefinitely, AC18), **D-11-6** ("per webhook" =
  the proxy; drill-through into the existing surface, AC21), **D-11-7** (pre-#6 rows excluded from
  delivery-level figures, AC13). **F3 confirmed to stand**: per-event-type is excluded, with the
  reason stated, and is not reintroduced under Tier 3 (AC32). **Roadmap V7 is closed by this
  ruling.**
- **Q-11-02 (Project Owner) — Throughput / latency / delivery-success targets. `= roadmap V8` /
  vision Open Question 8. RESOLVED 2026-08-26; no longer blocking. V8 itself is NOT closed — the
  deferral was renewed.** Doc:
  `docs/questions/prd-11-q-11-02-throughput-and-delivery-targets.md`. **Option A composed with
  Option D**, as the PM recommended: **no numeric target is set**, and **no number appears on screen
  in any labelled form** — the PM's recommendation *against* a non-binding baseline was accepted
  with it. The **definitions are settled here** (delivery success, delivery duration,
  ingest-to-first-attempt latency, throughput) so a later target is a number rather than a fresh
  argument, and so a **V3** queue-choice ruling does not re-litigate them. → **AC22, AC33**.
  **Recorded deliberately: this is the fourth deferral of V8 and the first with a visible product
  cost** — no verdict layer on the dashboard, and #13 inherits no threshold to alert on. **V8
  remains open on the roadmap and remains filed against #4 (Done) as well as #11.**
- **Q-11-03 (Principal Engineer, technical) — The stats lifecycle, the aggregation shape, and the
  schema findings. OPEN — non-blocking for approval; travels to Technical Design.** Doc:
  `docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md`. **Findings F1–F4 are
  unaffected by the V7/V8 rulings.** Carries: the missing lifecycle ADR-003 promised (F1 — now with
  AC18 stating indefinite retention as the *product* position, leaving the growth consequence to the
  PE); the soft-delete invisibility hazard behind AC6 (F2 — **sharpened** by Tier 3's
  per-destination grain); the absent event-type attribute (F3 — confirmed by the Owner); pre-#6
  `delivery_id` NULLs (F4 — now stated in AC13); the missing index for a per-proxy, per-destination,
  daily aggregate and the **data-model Owner gate** the PM expects at plan time; **live aggregation
  vs. a rollup store, now materially more likely given AC20's percentile**, and what AC11 then
  obliges; whether AC20's percentile is feasible, approximable, or a no; and confirmation that a
  read-only aggregation cannot disturb the ADR-012 GC compare-and-set, ADR-015's CAS transitions, or
  the ADR-014 cleaned-state guard.

### Re-check of the ruling-independent criteria (action taken, not assumed)

Every criterion previously claimed to hold "under any V7/V8 answer" was re-tested against the
concrete rulings. PRD-08's AC11 is the precedent for why: a criterion written against an open
question can be quietly falsified by the answer.

| Criterion | Verdict | What was checked |
|---|---|---|
| AC1 | **Holds** | Every Tier 3 addition — `duration_ms`, `attempt_number`, `deliveries.status`, `deliveries.kind` — is a long-lived record. Tier 3 needs no payload content. |
| AC2 | **Holds** | Re-tested per figure: none of Tier 3's additions is written or nulled by the GC. The erase statement touches `body`, `headers`, `payload_cleaned_at` only. |
| AC3, AC5 | **Hold** | Unaffected by scope or targets. |
| AC4 | **Holds, clause reconciled** | Export is now out of scope (AC37), so AC4's export clause described nothing #11 builds. Reworded to bind any future egress while noting none ships — the constraint is worth keeping, the false implication is not. |
| AC6 | **Holds, strengthened** | Tier 3's **per-destination** grain makes the soft-delete omission (F2) more consequential, not less — a deleted destination vanishing from a breakdown is the least visible and most misleading form of the bug. Clause added. |
| **AC7** | **REWRITTEN** | Read "the **unit** these counts use is AC13", written when the unit was one open choice. The Owner ruled **both** units are shown, so the phrasing was falsified. Now states both. |
| AC8 | **Holds, load-bearing** | Was a general truthfulness rule; under the both-units ruling it is now the mechanism AC14 depends on. |
| **AC9** | **REWRITTEN** | Read "may include, exclude, or split them — but never leave it implicit", a meta-rule standing in for an unmade decision. The ruling makes retry the *defining difference* between the two units, so the criterion is now concrete rather than conditional. |
| AC10 | **Holds, clause added** | With two units on one surface, "an aggregate reconciles with the records behind it" became ambiguous — 12 delivery-failures and 12 attempt-failures are different record sets. Clause added: each unit reconciles **within its own unit**, never against the other's. |
| AC11 | **Holds, more likely to bind** | AC20's percentile materially raises the chance of a derived/rollup store (`Q-11-03(6)`), which is exactly the case AC11 governs. No wording change needed. |
| **AC12** | **REWRITTEN** | Read "no traffic is zero, not an error". Tier 3 added **latency**, and `0 ms` for a window with no resolved attempts is a **false number**, not an empty one. Now distinguishes count figures from measure figures. **This is a defect the ruling created, caught by the re-check.** |
| AC23 (was 20), AC24 (was 21) | **Hold** | Team scoping unaffected. On permissions: Tier 4's export was the one thing that would have raised a new egress gate, and it was declined — so D-11-2 stands unchanged. |
| AC25 (was 22) | **Holds, clause added** | AC19 counts **retries**, and retry *configurability* is enhanced-only (PRD-06 AC2). A reader could wrongly gate retry figures to Enhanced proxies. Clause added: retry *behaviour* is mode-independent, so Simple proxies' retries are counted. |
| AC26 (was 23) | **Holds, clause added** | Tier 3 added latency, and FIFO's serialised dispatch legitimately yields different durations from Async. Clause added: a difference between processing modes is a normal consequence, never a fault — otherwise the figure would smuggle in the verdict AC22(b) forbids. |
| AC27 (was 24), AC28 (was 25) | **Hold, more load-bearing** | Tier 3's drill-through makes "no second events surface" the criterion most at risk during design; unchanged in wording, reinforced by AC21. |
| AC29 (was 26) | **Holds — and confirmed, not assumed** | Each Tier 3 figure traced to an existing column: latency → `duration_ms`; retry volume → `attempt_number`; eventual/terminal → `deliveries.status`; live-vs-replay → `deliveries.kind`. The richer tier still requires **no new capture**. Stated in the criterion. |
| AC30 (was 27) | **Holds** | #8's deferral means PRD-07 AC12's "must not imply mapping exists" constraint still binds — re-confirmed. |
| AC31 (was 28) | **Holds, sharpened** | AC19(b)'s terminal-failure figure is the most alert-shaped thing on the surface; the criterion now names it so the boundary with #13 is unmistakable. |
| AC32 (was 29) | **Holds — Owner-confirmed** | F3 stands; per-event-type is not reintroduced under Tier 3. |
| AC33 (was 30) | **Finalised** | Rewritten from a provisional deferral to the ruled position, pointing at AC22 for the full ruling. |
| AC34–AC36 (was 31–33) | **Hold** | Unaffected. |
| AC37 (was 34) | **Holds, extended** | Now also carries the export exclusion, which the Owner ruled out explicitly. |

**PM-derived requirement calls — stated, not invented.** Each is derived from an existing Owner
ruling, from the roadmap, or from the vision; is named in the criterion it drives; and is listed
here so the Owner approves with them visible rather than buried. **None has been put to the Owner.**
**D-11-4..7 exist because the Owner ruled the V7 headline and explicitly directed the Product
Manager to settle the four unruled sub-questions in line with it** — they are recorded as PM calls
rather than as Owner decisions for exactly that reason. Approving this PRD ratifies all seven;
overruling any one is an edit to a single criterion, not a reopening.

| ID | Criterion | Derived from |
|---|---|---|
| **D-11-1** | AC6 — a deleted proxy or destination stays counted in past periods, labelled as deleted | Roadmap #11 "long-lived and **trendable**" — a trend that silently loses a leg is not a trend; soft-delete already retains the rows (F2) |
| **D-11-2** | AC24 — the existing proxy **read** permission is the gate; no new permission, no new role | PRD-02 / ADR-009 permission model; PRD-05 AC16's standing constraint on read paths; PRD-08 AC3's identical call for map management |
| **D-11-3** | AC29 — #11 requires no new fact captured at delivery time; a figure that needs one returns to the Owner rather than becoming a silent ingest-path addition | Roadmap #11 build-ahead ("built from the delivery-attempt records emitted since #1/#4"); ADR-003's payload-free constraint |
| **D-11-4** | AC16, AC17 — **daily** buckets; windows of 24 h / 7 d / 30 d, defaulting to 30 d | `Q-11-01(d)`, not separately ruled; PM recommendation. 30 d matches the retention window members already understand; hourly buckets answer a question the per-event surface already answers exactly |
| **D-11-5** | AC18 — records kept **indefinitely**; no statistics-retention window at #11; a truncated trend is never presented as complete | `Q-11-01(d)`, not separately ruled; PM recommendation. Roadmap "long-lived and trendable"; it is what the system does today. **Named consequence: two permanently growing tables, no cap — PRD-05 D1's class of concern** |
| **D-11-6** | AC21 — "drill down per webhook" means **the proxy**, drilling into the already-shipped per-event surface; no per-received-event statistic | `Q-11-01(b)`, not separately ruled; PM recommendation. The product calls the configured object a webhook proxy; the drill-down floor exists and is approved (PRD-06 AC25) |
| **D-11-7** | AC13 — pre-#6 attempt rows (`delivery_id` NULL) are excluded from delivery-level figures, included in attempt-level ones, and the exclusion is stated | `Q-11-01(e)`, not separately ruled; PM recommendation. ADR-015's deliberate no-backfill (F4); dev/CI data only |

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#11 line + Build-ahead note; #1's and #4's Build-ahead
  notes naming #11 as the consumer of attempt records; § Open Questions **V7** and **V8**; § Notes
  on Scope Boundaries), `docs/product/vision.md` (§ What It Must Do "Analytics / stats"; § Problem
  "lack replay, stats, and visibility … no insight into delivery attempts or timings"; § How We'll
  Know It's Succeeding; § Explicitly Out of Scope; Open Questions **7** and **8**),
  `docs/product/prd-01-walking-skeleton.md` (fan-out; attempt records from the first commit),
  `docs/product/prd-05-payload-storage-retention.md` (AC4/AC9/AC10/AC16/AC21 + **Amendment A** — the
  erase-in-place ruling that makes descriptors survive),
  `docs/product/prd-06-retry-replay.md` (AC1/AC2 retry behaviour vs. configurability; AC12 replays
  identifiable; AC16 the three payload states; **AC25** the received-events surface #11 drills into;
  **AC23** "attempt records serve #11 later"; AC24 the numeric-target precedent),
  `docs/product/prd-07-enhanced-mode-toggle.md` (AC6/AC7 the governed set and mode-independence;
  AC12 truthful presentation; **AC24** "analytics is #11"; **AC25** the numeric-target precedent;
  § UX Direction on the two axes),
  `docs/product/prd-08-payload-mapping.md` (**AC33** "per-map or per-event analytics stay #11";
  AC34; and the house format this PRD follows), `docs/architecture/adr-003-delivery-attempt-records-and-events.md`
  (the record shape, `duration_ms`, and the "own lifecycle" claim F1 tests),
  `docs/architecture/adr-012-payload-retention-and-garbage-collection.md` (§ Revision A; Decision 1
  erase-in-place; **Decision 5**; Impact "#11 is unaffected by construction"),
  `docs/architecture/adr-014-captured-entity-erasure-and-header-encryption.md`,
  `docs/architecture/adr-015-delivery-retry-mechanism.md` (the `deliveries` entity, terminal state,
  `delivery_id`, "#11 aggregates real multi-attempt records"),
  `docs/architecture/adr-016-fifo-composition-under-retry-and-replay.md`,
  `docs/architecture/adr-017-replay-dispatch-and-payload-read-surface.md` (Decision 2 — "#11/#13
  distinguish replays by a join, not a second pipeline"; Decision 5 the read surface #11 extends),
  `docs/architecture/adr-002-simple-enhanced-mode-attribute.md`,
  `docs/design/design-06-retry-replay.md`, `docs/standards/documentation.md`, and the schema itself
  — `database/migrations/2026_07_30_000003_create_delivery_attempts_table.php`,
  `2026_08_04_000002_create_webhook_events_table.php`,
  `2026_08_05_000001_alter_webhook_events_for_payload_erasure.php`,
  `2026_08_12_000001_create_deliveries_table.php`,
  `2026_08_04_000004_create_fifo_dispatches_table.php`, plus `app/Models/{Proxy,Destination,
  Delivery,DeliveryAttempt}.php` and `app/Actions/DeliverToDestination.php`.
- **Outputs:** this PRD;
  `docs/questions/prd-11-q-11-01-analytics-dashboard-scope.md` (**RESOLVED**, Project Owner,
  2026-08-26 — roadmap V7 closed; folded into AC13–AC21 and AC37);
  `docs/questions/prd-11-q-11-02-throughput-and-delivery-targets.md` (**RESOLVED**, Project Owner,
  2026-08-26 — V8 **deferral renewed, not closed**; folded into AC22 and AC33);
  `docs/questions/prd-11-q-11-03-stats-lifecycle-and-aggregation.md` (**OPEN**, Principal Engineer
  — technical, travels to Technical Design).
- **Dependencies:** **#4 (Done)** per the roadmap. In practice #11 also reads what **#1 (Done)**,
  **#5 (Done)** and **#6 (Done)** emit — attempt records with `duration_ms`, the erase-in-place
  retention contract, and the `deliveries`/replay model — all frozen and approved. **#7** is in
  Review; #11 depends on no #7 decision beyond AC7's mode-independence statement, which is approved
  and stable. #11 does **not** depend on **#8** (deferred 2026-08-26), #9, #10, #12, #13 or #14, and
  must not pre-empt them.
- **Owner direction, non-binding — not a requirement and deliberately in no acceptance criterion.**
  For rendering the charts the Project Owner **suggests** their own Chart.js wrapper,
  **`@j-t-mcc/vue3-chartjs`** (https://github.com/J-T-McC/vue3-chartjs). This is a **suggestion to
  the Designer and Principal Engineer, not a mandate** — a PRD states what the product must do, not
  which package renders it, so no criterion above names it and none should. Pre-checked by the
  coordinator: the library needs **Vue 3 + Chart.js 4**; this project is on **Vue 3.5.40 with no
  charting library present**, so adoption adds **two npm packages**. That makes it a
  **new-dependency Owner gate under `CLAUDE.md`**, which the **Principal Engineer records formally
  at plan time** — it is not pre-approved by this PRD or by the Owner's V7/V8 rulings.
- **Outstanding Questions:** **none blocking.** **Q-11-01 (V7)** and **Q-11-02 (V8)** are
  **RESOLVED** (Project Owner, 2026-08-26) and rendered into AC13–AC22 and AC33, so every criterion
  is concretely testable and the Designer has a settled scope to design against. **Q-11-03 (Principal
  Engineer) — OPEN, non-blocking**; it travels to Technical Design exactly as Q-07-02 did for #7 and
  Q-08-03 for #8, and the PM **expects a data-model Owner gate at plan time** (an index addition or
  a derived aggregation store are both `CLAUDE.md` data-model changes) **in addition to** the
  new-dependency gate noted above. The only items still awaiting an Owner position are the seven
  PM-derived calls **D-11-1..7**, which the act of approving this PRD ratifies.
- **Next Agent:** **Designer**, once the Project Owner approves this PRD. This PRD carries a
  `## UX Direction` section — the team-level dashboard surface, per-proxy and per-destination
  figures, the daily trend, the retry/terminal/replay story, the latency measure with its distinct
  empty state, the drill-through into the existing events surface, and above all the **two-unit
  labelling problem** — so under the mechanical routing rule the **UX Design gate is mandatory
  before Technical Design, no exceptions**, and a **PM-approved `design-11` is a prerequisite** for
  the Principal Engineer. Q-11-03 then travels with the Principal Engineer at Technical Design.
