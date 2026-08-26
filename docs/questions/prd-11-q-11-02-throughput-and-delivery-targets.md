# Question Q-11-02: Throughput / latency / delivery-success targets (roadmap **V8**, vision Open Question 8)

- **Status:** **RESOLVED** (Project Owner, 2026-08-26) — **the question is answered; roadmap V8 is
  NOT closed.** The Owner **renewed the deferral** and **settled the definitions**. V8 stays open on
  the roadmap and stays filed against **#4 (Done)** as well as #11. Folded into
  `docs/product/prd-11-analytics.md` **AC22** and **AC33**, with the definitions fixed in that PRD's
  § Definitions.
- **Raised by:** Product Manager
- **Owner (must answer):** **Project Owner** *(product decision. A numeric target is a promise the
  product makes to its users; no vision, roadmap, PRD, ADR or prior ruling sets one, and the
  Product Manager will not invent one.)*
- **Raised:** 2026-08-26
- **Gates:** *(when open)* **AC22** of `docs/product/prd-11-analytics.md` — numbered AC19 when this
  doc was raised — and **AC33**, the scope-boundary criterion it finalises.
- **Reach beyond #11 — read this before answering.** The roadmap files V8 against **#4 *and*
  #11**: *"V8. **Throughput / latency / delivery-success targets** — none set; affects **#4 and
  #11**."* #4's own build-ahead note says the FIFO/Async design "must accommodate a later scalable
  queue/streaming choice beyond Redis (V3) and **as-yet-unset throughput targets (V8)**". #4 is
  **Done**. A ruling that sets a number therefore lands on a shipped feature, and — because a
  throughput number is the main thing that would decide Redis-vs-Kafka — it also puts pressure on
  **V3**, which is currently deferred with no owner-facing consequence.
- **Related:** `Q-11-01` (V7 — what is *measured*; independent of whether anything is *promised*).
  `Q-11-03` (Principal Engineer — feasibility; a target the system cannot measure cannot be
  asserted).
- **Source:** `docs/product/roadmap.md` § Open Questions V8; `vision.md` Open Question 8
  ("Specific throughput / latency / delivery-success targets — none set yet") and § How We'll Know
  It's Succeeding: *"There are **no hard targets set yet**, but **throughput and processing
  scalability matter**."*

## Context

**The precedent is three deep and deliberate.** Every PRD since #6 has closed with the same
criterion, each time on an explicit deferral rather than an oversight:

| PRD | Criterion | Wording |
|---|---|---|
| PRD-06 | AC24 | "No numeric targets. No retry-latency, throughput, or delivery-success SLA is asserted (V8 deferred — Owner, 2026-08-04). The AC2 policy caps are **product bounds, not performance guarantees**." |
| PRD-07 | AC25 | "No numeric targets. #7 asserts no throughput, latency, switch-propagation, or delivery-success number (V8 remains deferred — Owner, 2026-08-04)." |
| PRD-08 | AC34 | "No numeric targets. #8 asserts no throughput, latency, payload-size, map-count, or mapping-duration number (V8 remains deferred — Owner, 2026-08-04)." |

**Why the question cannot simply be deferred a fourth time by default.** #11 is the first item
that *puts numbers about delivery performance on a screen*. Every earlier item could stay silent
because it displayed nothing. A dashboard that shows a 96.4% delivery-success rate implicitly
invites the question "is that good?", and the product will answer it either with a stated target,
with a colour, or with nothing — and **a colour is a target**. So the deferral has to be renewed
knowingly this time, and its consequence for the dashboard stated.

**What setting a target actually obliges.** Naming it plainly, because this is the part that is
easy to under-price:

1. **It becomes a testable acceptance criterion.** A Reviewer must be able to verify it, and can
   block a release when it is unmet. "99.5% delivery success" is not a slogan in this
   organisation; it is a gate.
2. **It requires a definition before it requires a number.** Over what window, on what
   denominator, excluding what? Delivery success measured per attempt and per delivery differ by
   tens of points on identical traffic (`Q-11-01(c)`). A number without its definition is
   unverifiable and therefore unenforceable.
3. **It requires measurement of things nothing measures today.** Delivery *success* is derivable
   from records that exist. **Throughput** (events per unit time under load) and **ingest-to-first-attempt
   latency** are not: nothing records queue wait time, and no load test exists. Asserting either
   would create work in #4's shipped surface, not just #11's.
4. **It reaches the queue choice.** A throughput number is the thing that decides whether Redis
   is sufficient — i.e. it reopens **V3** in substance even if not in name.
5. **An unmet target is a broken promise.** The product is currently pre-demo with no production
   traffic, so any number set today is set without evidence.

**What *not* setting one obliges.** Also not free:

1. The dashboard shows facts and renders no verdict — no "healthy/unhealthy" badge, no red/green
   threshold, no SLA line on a trend. (`Q-11-01` Tier 2/3 are unaffected either way; only the
   *judgement layer* is.)
2. **#13** later has no product-defined failure threshold to alert on. It can still alert on the
   `DeliveryExhausted` terminal event (a fact, already emitted), but not on "success rate dropped
   below X" — that would need this ruling.
3. The vision's "throughput and processing scalability matter" stays an aspiration with nothing
   behind it, and #4's build-ahead accommodation for V8 remains untested.

## Question

- **Option A — Renew the deferral: #11 asserts no target. (PM recommendation, composed with
  Option D.)** PRD-11 AC19 reads like AC24/AC25/AC34 before it: no throughput, latency, or
  delivery-success number is asserted. The dashboard displays measured figures and **passes no
  judgement on them** — no threshold colour, no healthy/unhealthy verdict, no SLA reference line.
  *Basis:* there is no production traffic to set an honest number against; the precedent is
  consistent and was set deliberately three times; and a fact-only dashboard is genuinely useful
  without a verdict layer. *Consequence, named:* the dashboard cannot tell a user whether their
  number is good, and #13 inherits no threshold to alert on.

- **Option B — Set non-binding "observability baselines".** Reference numbers displayed for
  orientation (e.g. a reference line on the trend), explicitly labelled as not a guarantee and
  explicitly not a Reviewer gate. *Basis:* gives the user a sense of scale without a commitment.
  *Consequence, and it is the reason this is not the recommendation:* **a number on a screen is
  read as a promise regardless of its label.** A "non-binding" target that turns a figure red is
  operationally identical to a binding one, minus the discipline of having had to justify it. It
  buys the obligation without buying the rigour.

- **Option C — Set binding targets now.** Real, verifiable numbers for delivery success and/or
  latency and/or throughput, inherited by **#4** as well as #11. *Basis:* the vision says
  scalability matters and the product is meant to be treated as a serious SaaS platform; targets
  are how that stops being a posture. *Consequence:* obliges items 1–5 above — definitions,
  measurement of things not measured today, a follow-up onto shipped #4, likely pressure on V3,
  and a promise made without evidence. If the Owner chooses this, the strong advice is to bound
  it to **delivery success only** (derivable from records that already exist) and leave throughput
  and latency deferred, rather than set three numbers where only one is measurable.

- **Option D — Defer the *number*, decide the *definitions*. (PM recommendation, composed with
  Option A.)** #11 fixes what a delivery-success figure means — its denominator, its window, and
  what it excludes — as ordinary requirements (which `Q-11-01(c)` and (d) settle anyway), so that
  a future V8 ruling is a **number typed into an existing definition** rather than a redesign of
  the measurement. *Basis:* it costs nothing extra, it removes the largest hidden cost of setting
  a target later, and it is the part of V8 that is genuinely #11's business. *Consequence:* V8
  stays open on the roadmap, and stays flagged against #4.

- **Option E — something else the Owner names.**

## PM recommendation, in one line

**Option A composed with Option D** — assert no target, and let #11's definitions do the
groundwork so V8 can later be answered with a number instead of a project. The dashboard shows
what happened; it does not grade it.

**The honest counterweight the Owner should weigh.** This is the **fourth** deferral of V8, and
the first one where deferring has a visible product cost rather than none: it means shipping the
analytics feature — the whole point of which is to answer "is this working" — while refusing to
say what "working" is. The vision states plainly that "throughput and processing scalability
matter"; four deferrals in, nothing in the product reflects that, and **#4 shipped its queue
design against an explicitly unset target**, which means the Redis-vs-Kafka question (V3) is also
being carried on an assumption rather than a requirement. If the Owner intends to demo this as a
serious SaaS platform, the argument for Option C — narrowed to **delivery success only** — is
that it is the one number the system can already measure honestly, and setting it converts three
open questions from indefinite to answered. The argument against remains: no production traffic
exists, so the number would be a guess, and a guessed SLA is worse than a stated absence.

**On Option B specifically: the PM recommends against it in either direction.** If a target is
worth showing, it is worth committing to (C); if it is not worth committing to, it should not be
on the screen (A). Option B is the only answer here with no coherent position behind it.

## Impact if unresolved

PRD-11 cannot be approved. **AC19** has no content, and **AC30** (the scope-boundary criterion
that mirrors PRD-06 AC24 / PRD-07 AC25 / PRD-08 AC34) cannot be finalised — it currently carries
the deferral wording provisionally. The Designer is affected too, though less: whether any figure
carries a threshold state (a colour, a badge, a reference line) is a design decision that cannot
be made before this ruling, and reversing it later is a rework of every figure on the surface.
Nothing else in PRD-11 depends on this. **A ruling here also settles or renews V8 for #4**, whose
roadmap build-ahead note names it explicitly.

## Answer

- **Answered By:** **Project Owner**
- **Answered:** **2026-08-26**

**Option A composed with Option D — the PM's recommendation, accepted in full.**

**(1) Renew the deferral. No numeric target is set.** Nothing promises what the system cannot yet
measure. #11 asserts no throughput, latency, delivery-success, freshness or query-time number.
→ **PRD-11 AC22(a)**, and **AC33** as the house-standard scope boundary alongside PRD-06 AC24,
PRD-07 AC25 and PRD-08 AC34.

**(2) No number on screen in any labelled form — the recommendation *against* Option B was accepted
with it.** No baseline, no reference line, no "typical" figure, however captioned: **a number is
read as a promise regardless of its label.** This carries a design consequence stated as a
requirement rather than left to taste — **no verdict layer**: no red/green pass-fail colour, no
healthy/unhealthy badge, no SLA line, no "good"/"bad" language, no ranking of a proxy or destination
as acceptable. Colour may encode **category** (succeeded vs failed) and never **judgement**.
→ **PRD-11 AC22(b)**, and § UX Direction, where it binds the Designer directly.

**(3) The definitions are settled now, so a later target is a number rather than a fresh argument.**
Four terms are fixed in PRD-11 § Definitions — two of them measured and displayed, two defined and
deliberately not:

| Term | Settled meaning | Displayed at #11? |
|---|---|---|
| **Delivery success** | Deliveries reaching terminal `succeeded` over (`succeeded` + `failed`) in the window; non-terminal deliveries excluded, never counted as failures | **Yes** — the headline unit (AC13) |
| **Delivery duration** | Per attempt, the wall-clock HTTP send (`duration_ms`); per delivery, first attempt's start to terminal outcome. **Excludes queue wait time** | **Yes** (AC20) |
| **Ingest-to-first-attempt latency** | `webhook_events.received_at` → earliest attempt's `started_at`. **Derivable today** — but it conflates queue wait with pipeline processing | **No** — defined for a future target only |
| **Throughput** | Events ingested or deliveries attempted per unit time **under sustained load**. Observed volume measures *offered traffic*, not *capacity*; no load test exists | **No** — not measurable today |

A future V8 target that **redefines** any of these is reopening a settled decision, not setting a
number. → **PRD-11 AC22(c)**.

*Correction carried into `Q-11-03(7)`, recorded rather than quietly fixed:* this doc originally
stated that ingest-to-first-attempt latency "is not recorded anywhere". That is **imprecise** — both
timestamps exist, so the interval is derivable; what is missing is a *clean* measurement, because
the interval conflates queue wait with pipeline work. The definition above is written on the
accurate reading, and `Q-11-03(7)` was updated to match.

**(4) The reach the PM flagged holds, and is recorded so it is not rediscovered.**
- **This is the fourth deferral of V8** — PRD-06 AC24, PRD-07 AC25, PRD-08 AC34, and now PRD-11
  AC22 — **and the first with a visible product cost**: the dashboard renders no verdict, and **#13
  inherits no threshold to alert on** beyond the `DeliveryExhausted` terminal event ADR-015 already
  emits. Earlier items could stay silent because they displayed nothing; #11 displays.
- **V8 remains open on the roadmap and remains filed against #4 (Done) as well as #11.** A future
  ruling that sets a throughput number lands on shipped queue work.
- **The definitions settled here are the ones a V3 ruling would use.** A throughput number is the
  main input to the Redis-vs-more-scalable-queue question, so **V3 does not need to re-litigate what
  "throughput" means** — only what the number is. Recorded here deliberately so the next person to
  pick up V3 or V8 inherits the vocabulary instead of rebuilding it.

**Downstream:**
- **AC22** is concrete and testable in three parts; the `PENDING V8` tag is removed. **AC33** is
  finalised from provisional deferral wording to the ruled position and points at AC22.
- **Re-checked, not assumed:** the no-verdict rule interacts with two criteria the PM had marked
  ruling-independent. **AC26** gained a clause — FIFO's serialised dispatch legitimately yields
  different durations from Async's, and presenting that difference as a defect would smuggle in
  exactly the judgement AC22(b) forbids. **AC12** was rewritten for a separate reason (Tier 3's
  latency), but its "no data, never `0`" rule matters here too: a fabricated `0 ms` would be a
  number the product asserts without evidence.
- **Roadmap V8 is NOT closed** — the deferral was renewed. `docs/status.md` is the Orchestrator's to
  update, and should record V8 as still open against **#4 and #11**.
