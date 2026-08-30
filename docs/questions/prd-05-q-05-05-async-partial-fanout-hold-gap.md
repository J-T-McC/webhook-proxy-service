# Question Q-05-05: Does AC8 require a GC hold covering the Async partial-fan-out window, or is the ADR-012 Decision 4 hold set complete as ruled?

- **Status:** **RESOLVED — Principal Engineer, 2026-08-25.** Two outcomes, one per
  instance of the defect: **(a)** the reviewer's partial-fan-out window — **hold set
  stands unchanged, deferred with recorded rationale and named triggers**; **(b)** a
  second, materially more reachable instance of the *same* H5 clause, discovered while
  verifying (a) — **corrected now** by a plan-level hold-set correction (a config
  default and one companion invariant; no data-model change, no ADR reversal, no
  requirement change). No question is routed onward to the Product Manager and no ADR is
  flagged for the Project Owner — see § Answer → *Why no Owner gate and no PM route*.
- **Raised by:** Reviewer (`docs/reviews/review-05-payload-storage-retention.md`,
  finding 2 — Minor; re-stated unchanged in the 2026-08-05 re-review's carried-forward
  follow-ups), explicitly routed to the Principal Engineer **rather than** the Senior
  Developer: it is a gap in the plan's hold set that was *faithfully implemented*, not
  an implementation deviation.
- **Owner (must answer):** **Principal Engineer** — technical. The hold set is a
  design artefact (plan-05 § Q-05-03(i); ADR-012 Decision 4; ADR-015 Decision 7), not a
  requirement. If PRD-05 AC8 or PRD-06 AC18 proved unachievable *as written*, that
  returns to the Product Manager as a requirement question, not a silent design change.
  It did not — see § Answer.
- **Raised:** 2026-08-05
- **Resolved:** 2026-08-25
- **Gates:** **None.** Non-blocking at #5 (PR #6 merged `ed421f1`), non-blocking at #6
  (PR #8 merged `e1c2894`), and does **not** gate #7's Technical Design. Recorded here
  because it has been open twenty days with no write-up.
- **Source:** PRD-05 **AC8** (as amended by Amendment A) and **AC6**; PRD-06 **AC17**,
  **AC18**; ADR-012 **Decision 4** (the named hold set H0–H4 and its sufficiency
  argument); ADR-015 **Decision 7** (H5); ADR-016 **Decision 2** (why no never-settling
  status may exist); plan-05 **§ Q-05-03(i)**, **§ Validation → Config sanity**,
  **§ Risks 3 and 4**; review-05 finding 2. Current code on `main`:
  `app/Actions/PurgeExpiredPayloads.php`, `app/Actions/DeliverStep.php`,
  `app/Actions/DeliverToDestination.php`, `app/Actions/ProcessIngestedWebhook.php`,
  `app/Actions/RetryDelivery.php`, `app/Actions/SweepDueRetries.php`,
  `app/Http/Controllers/ProxyEventReplayController.php`, `config/retention.php`.

---

## Context

### The mechanism as raised — re-verified against `main`, and it still holds

review-05 finding 2 described a state in which every erasure hold clears while a
destination's dispatch is still outstanding. Verified line by line on current `main`,
**not** inherited from the review:

1. `ProcessIngestedWebhook` creates one `deliveries` row per live destination, status
   `pending`, at pipeline entry (`ProcessIngestedWebhook.php:62-75`) — since #6; at #5
   no such row existed.
2. `DeliverStep` iterates those rows and, in Async mode, queues one
   `DeliverToDestination` job per row onto the `webhooks` queue
   (`DeliverStep.php:61-68`).
3. Each job writes its own `delivery_attempts` row with status `dispatched` **only when
   it runs** (`DeliverToDestination.php:79-89`).

So for a multi-destination Async event, this state is representable: destination A's
attempt row is terminal (`succeeded`) and A's delivery row is `succeeded`; destination
B's job is still on the queue with **no attempt row at all** and its delivery row still
`pending`. Evaluating `applyHolds()` (`PurgeExpiredPayloads.php:198-234`) at day 30:

| Hold | Predicate | Result in this state |
|---|---|---|
| H0 | `payload_cleaned_at IS NULL` | clear |
| H1 | `created_at <= cutoff` | clear (expired) |
| H2 | no non-`settled` `fifo_dispatches` row | clear — Async proxies own no such row |
| H3 | no `delivery_attempts` row with status `dispatched` | clear — A's row is `succeeded`, B has none |
| H4 | zero attempt rows ⇒ older than the horizon | clear — A's row satisfies the `whereExists`, so the age branch is never reached |
| H5 | no `retrying` delivery, and no `pending` delivery **younger than the horizon** | clear — B's `pending` row is 30 days old, far past the 60-minute horizon |

All six clear. The event is marked cleaned while B's dispatch is outstanding. **PRD-05
AC8's letter is not met in that window.** Confirmed.

### The Reviewer's exposure assessment — confirmed, with one correction #6 introduced

Confirmed: **no data is lost.** `DeliverStep` builds the `DeliveryUnit` with
`payload: $ctx->payload` (`DeliverStep.php:49-59`) — the queued attempt-1 job carries its
own bytes and never re-reads `webhook_events`. Erasing the parent row cannot make B's
send empty, partial, or reconstructed. Confirmed: the window requires a fan-out still
pending **30 days after capture**, so real-world exposure is effectively nil.

One correction the Reviewer could not have made, because #6 had not merged: there is now
a second-order consequence. If B's job runs after the erase and the send fails,
`settleDelivery()` transitions B to `retrying` and schedules `RetryDelivery`
(`DeliverToDestination.php:215-225`), which finds `payload_cleaned_at !== null` and
compare-and-sets B straight to `failed`, emitting `DeliveryExhausted` without ever
attempting (`RetryDelivery.php:56-62, 88-105`). So the erase silently **truncates B's
retry entitlement** — one attempt instead of the configured limit. That is
AC17-conformant by construction (no erased content is ever dispatched) and still not
data loss, but it is a real behavioural consequence of the gap that did not exist at #5.

### What #6 changed — and it is not what one would hope

The prompt for this question asked whether #6's `deliveries` rows make a cleaner hold
available than the one plan-05 reasoned about. The honest answer is **no, and #6 made
the situation slightly worse**, in two specific ways.

**A durable marker for B now exists — and H5 already reads it.** ADR-012 § Alternatives
rejected "a new per-event dispatch-completion marker table" as *disproportionate*
("pays a table plus a write per event for a signal H2/H3/H4 already derive"). #6 built
that marker anyway, for its own reasons, at per-destination grain. So the *cost*
objection is gone, and the hold that plan-05 lacked is now expressible. **But it is
already expressed**: ADR-015 Decision 7's H5 is exactly it, and it is already in
`applyHolds()`. There is no cleaner unused signal waiting to be picked up.

**H5's `pending` clause is age-qualified, deliberately, and cannot be tuned to close
this window.** ADR-015 Decision 7 states the reason verbatim: *"a pending row whose
first-attempt job was permanently lost must not immortalize a payload — after the
horizon it stops holding."* That is not an oversight; it is the same trade-off ADR-012
§ Alternatives already resolved once against the unbounded reading ("Hold *any event with
zero delivery attempts is held forever*… making that payload immortal — the opposite of
AC6. Rejected in favour of H4"), and the same trap ADR-016 Decision 2 cited when
refusing a `dead_lettered` `fifo_dispatches` status.

The structural point, which is the one that decides this question: **for the original
dispatch, B's `deliveries` row is created at ingest time.** The hold is evaluated at
ingest + 30 days. Therefore *any* horizon shorter than the retention window releases
that row, and any horizon at or beyond the window is an unbounded hold under another
name. **No value of `dispatch_horizon_minutes` can ever close instance (a).** The option
space is genuinely binary: accept the age heuristic, or hold unconditionally on
non-terminal deliveries and accept immortal payloads. Tuning is not a third option.

**Nothing ever terminalizes a stranded Async `pending` delivery.** Verified across the
whole sweeper surface: `SweepDueRetries` acts only on `retrying` deliveries (it contains
no reference to `pending` at all); `SweepStalledFifoDispatches` pass (c) *reads*
non-terminal deliveries to decide whether to release a held `fifo_dispatches` row, but
terminalizes no delivery. FIFO self-heals — a crashed advancer leaves a `claimed`
ordering row, the lease reaper returns it to `pending`, and the re-run of `DeliverStep`
re-drives the stranded delivery inline — so a FIFO `pending` delivery cannot strand
permanently. **Async has no equivalent healer.** A lost Async attempt-1 job leaves a
`pending` delivery row that no code path will ever move. This is precisely the state
ADR-015 Decision 7's horizon exists to stop from immortalizing a payload, and it
confirms that clause is load-bearing rather than decorative.

### Instance (b) — the same clause, a materially more reachable window, and a real read hop

Verifying (a) surfaced a second instance of the identical H5 weakness that the Reviewer
did not see, because it belongs to #6 and #6 had not been written when review-05 was
raised. It is against **PRD-06 AC18** ("a **replay dispatch in flight** is not eligible
for payload erasure while that work is outstanding"), not PRD-05 AC8, and it is worse on
every axis:

`ProxyEventReplayController::store()` creates the replay's `deliveries` rows with status
`pending` and `created_at = now()` inside a transaction guarded race-free on
`payload_cleaned_at` under `lockForUpdate()` (`:46-89`), then — for an **Async** proxy —
dispatches `ProcessIngestedWebhook::dispatch($ingestId, $dispatchUuid)->afterCommit()`
(`:94`). Between that commit and that job executing, the only thing holding erasure is
H5's `pending` clause, which expires after **60 minutes**.

- **The exposure window is ~an hour of queue latency, not 30 days.** A replay issued on
  an event within its final day, plus a worker backlog exceeding the horizon, plus the
  02:00 GC pass landing in between. That is an ordinary production incident, not a
  thought experiment.
- **This one *is* a by-reference read hop.** Unlike a `DeliverToDestination` job,
  `ProcessIngestedWebhook` rebuilds its `PipelineContext` from the stored row
  (`:77-84`) — exactly the single hop plan-05 § Q-05-03(i) says the hold set exists to
  protect. The AC17 guard (`:42-46`) turns it into a clean logged no-op rather than an
  empty dispatch, so still no data loss — but the replay silently produces nothing.
- **It is user-visible.** The user saw *"Replay started."* (`:97`). The replay's
  `deliveries` rows then sit `pending` forever, per the stranding analysis above.
- **FIFO is fully protected; only Async is exposed.** A FIFO replay also creates a
  `fifo_dispatches` row with status `pending` (`:81-88`), which H2 holds
  **unconditionally** — no age qualifier. The asymmetry is accidental, not designed.

Critically, and unlike (a): **the replay delivery row is created at replay time, not at
capture time.** Its age at the moment the hold is evaluated is minutes-to-hours, not
thirty days. So for (b) — and *only* for (b) — the horizon value is the whole mechanism,
and tuning it is a complete fix rather than a placebo.

---

## Question

1. **(a)** Does PRD-05 AC8 require a hold covering the Async partial-fan-out window — an
   event with at least one terminal attempt and at least one destination whose
   attempt-1 job has not yet run — or is the ADR-012 Decision 4 / ADR-015 Decision 7
   hold set complete as ruled?
2. **(b)** Does PRD-06 AC18's "replay dispatch in flight" hold survive an Async replay
   whose `ProcessIngestedWebhook` job is delayed past `dispatch_horizon_minutes`?
3. Is either gap reachable in a way that matters given #6's retention-interplay work,
   and should a correction be scheduled now or deferred?
4. Does either correction exceed the Principal Engineer's authority — requiring an ADR
   and the Project Owner gate, or a requirement question to the Product Manager?

---

## Options, with costs

| # | Option | Closes | Cost |
|---|---|---|---|
| **1** | **Hold set unchanged.** H5's `pending` clause keeps its age qualifier exactly as ADR-015 Decision 7 ruled. | nothing | AC8's letter unmet in the (a) window; AC18's letter unmet in the (b) window. Zero implementation cost, zero regression risk. Requires a recorded rationale and a revisit trigger — silence is not an acceptable form of this option. |
| **2** | **Unconditional hold on any non-terminal delivery** — drop the age qualifier, so any `pending` or `retrying` `deliveries` row holds erasure. | (a) and (b), exactly and literally | **Rejected.** A stranded Async `pending` delivery (lost job, no healer — verified above) makes its payload **immortal**. That directly contradicts PRD-05 AC6, contradicts PRD-06 AC18's own closing sentence ("a retry policy can never make a payload immortal"), and re-enters the trap ADR-012 § Alternatives and ADR-016 Decision 2 each rejected by name. It would also convert the one operation with no recovery path into one that silently stops running for affected events. |
| **3** | **Raise the horizon so the `pending` grace exceeds one full GC cycle** — `retention.dispatch_horizon_minutes` default 60 → 1440 (24 h), plus a companion invariant that the resolved horizon must be strictly less than the resolved retention window. | **(b) fully, for any realistic queue latency.** Provably cannot close (a). | One config default, one guard, one config-default assertion updated. No data-model change, no new dependency, no schema change, no ADR position reversed (no ADR fixes the *value*; plan-05 § Services names the key, not the number). Remaining cost: it is still a heuristic, and it narrows the horizon-to-window safety ratio from 720× to 30×, which the companion invariant must then defend — especially against V5. |
| **4** | **Terminalize stranded `pending` deliveries in a sweep, then hold unconditionally on non-terminal.** A sweeper CAS-es a `pending` delivery older than a staleness threshold to a terminal state; H5 then needs no age qualifier at all. | **(a) and (b), permanently and exactly**, and closes the stranded-`pending` state-machine hole as a bonus | The architecturally correct end state — it moves "is this job lost?" out of the retention path and into the delivery state machine, where that judgement belongs. But: it emits `DeliveryExhausted` for a delivery that was **never attempted**, which is a different fact from ADR-015's `failed` ("failure at/above the attempt limit") and would be surfaced to customers by #13; distinguishing the two honestly wants a terminal-reason column — a **data-model change and an Owner gate**; and it builds ahead of #11/#13 requirements that do not exist yet. Disproportionate today. |

---

## Impact if unresolved

- **(a):** none material. Twenty days open with no write-up is itself the cost: the
  finding was carried in `docs/status.md` as an unexplained Minor against #5, readable
  as an outstanding defect rather than a settled trade-off. Left unresolved it would be
  re-raised at every future review that reads `applyHolds()`.
- **(b):** a live, user-visible silent-failure path — a replay that reports success,
  delivers nothing, and strands its `deliveries` rows — reachable with roughly an hour
  of queue backlog on the day an event expires. Unresolved, it would most likely be
  found in production rather than in review.
- **Both:** the fact that no horizon value can close (a) is non-obvious. Unrecorded, the
  next engineer to meet this will reach for Option 3 as a fix for (a), ship it, and
  believe the gap closed when it has not moved at all.

---

## Downstream

- **#7 Enhanced-mode toggle (UX Design, next into Technical Design).** Unaffected. #7
  changes no dispatch, delivery, or retention path. This question does **not** gate
  plan-07.
- **V5 (per-team / per-tier retention window) — the sharpest trigger.** Every argument
  below rests on the window (30 d) dwarfing the horizon. If V5 ever admits a *short*
  window, both instances move from theoretical to live, and the Option 3 invariant
  becomes the thing standing between a tenant and an immortal payload. plan-05 Risk 3
  already flagged the same dependency for H4 ("load-bearing only if V5 ever configures a
  short window"); this is the same trigger, one clause over. Note also review-05 Nit 9:
  `RetentionPolicy::windowFor()` is `public` and overridable, so a V5 lever that does not
  call `parent::windowFor()` bypasses the guard — the horizon-vs-window invariant added
  here must be asserted where the *resolved* window is in hand, not where config is read.
- **#11 (Analytics) and #13 (Notifications) — the trigger for Option 4.** Both assume
  every delivery eventually reaches a terminal state. A stranded Async `pending`
  delivery never does: #11 would count it as perpetually in progress and #13 would never
  notify on it. When either is planned, Option 4 stops being build-ahead and becomes a
  requirement of theirs — at which point (a) closes for free, as a side effect.
- **Any future move of attempt-1 dispatch to by-reference.** ADR-015 Decision 5 already
  refused to carry payload bytes in *delayed retry* jobs. If that reasoning is ever
  extended to attempt-1 jobs (a plausible #10 hardening — "no decrypted payload in Redis
  at all"), `DeliverStep`'s queued jobs would re-read the event, and instance (a) stops
  being "AC8's letter unmet, nothing lost" and becomes a genuine silent-non-delivery
  window. **This inverts the entire exposure argument below and must reopen this
  question.**
- **#10 (Sensitive data handling).** Unchanged. Option 3 lengthens the period a payload
  may sit retained past its nominal expiry by up to 24 h in a narrow case; that is well
  inside AC2's 30-day product statement and is not a widening of any at-rest surface.

---

## Answer

### (a) The hold set is complete as ruled. No change. Deferred, with triggers.

**AC8 does not assert a requirement over this window — it explicitly delegates it.** Its
own closing parenthesis reads: *"(Bounding a permanently stuck event is #6's dead-letter
concern, not asserted here.)"* A destination whose attempt-1 job has not run thirty days
after capture is, by any reading, a permanently stuck dispatch. AC8 hands that case to
#6; #6 answered it, twice and deliberately — ADR-015 Decision 7 bounds a stuck `pending`
row's hold by age, and ADR-016 Decision 2 refuses any never-settling status precisely
because it would hold erasure forever. Both are Accepted by the Project Owner
(2026-08-12). This is not me reading "outstanding" narrowly to make a defect go away; it
is the AC's own carve-out landing on the answer #6 gave to it.

**And the trade-off runs the right way on the merits.** Only two states are
distinguishable at the database: B's job is on the queue, or B's job is lost. The GC
cannot tell them apart — the Redis queue is the only authority and it is not queryable
from `applyHolds()`. Age is the only available discriminator, and at day 30 it discriminates
correctly:

- If B's job is genuinely still queued after 30 days, the worker fleet has been down for
  a month and nothing in the product is delivering anything — holding this one payload
  changes nothing. And when B eventually runs, it delivers correctly from its own carried
  bytes regardless.
- If B's job was dropped — the reachable case — then B is *not* outstanding, it is gone,
  no hold can ever help it, and holding forever actively harms: an immortal payload,
  contra AC6, on the exact operation PRD-05 flag 6 made irreversible.

So the only case where holding would help cannot practically occur, and the case that
can occur is one where holding does damage. **Option 1.**

**Scheduling: deferred**, with a recorded rationale (this document) and three named
triggers, any one of which reopens it — (i) V5 lands a retention window short enough that
queue latency is a meaningful fraction of it; (ii) #11 or #13 requires every delivery to
reach a terminal state, which delivers Option 4 and closes this for free; (iii) attempt-1
dispatch moves to by-reference, which inverts the exposure argument. Absent those, this
is a settled trade-off, not an open defect, and `docs/status.md` should say so.

**The hold set, stated precisely and unchanged for (a)** — H0 `payload_cleaned_at IS
NULL`; H1 `created_at <= cutoff`; H2 no `fifo_dispatches` row for the event with status
`<> settled`; H3 no `delivery_attempts` row for the event's `ingest_id` with status
`dispatched`; H4 if the event has zero `delivery_attempts` rows, `created_at <= now() −
horizon`; H5 no `deliveries` row for the event with status `retrying`, and none with
status `pending` and `created_at > now() − horizon`. All six conjunctive, expressed once
in `applyHolds()`, and re-asserted inside the erase `UPDATE`'s own `WHERE` — the
compare-and-set (ADR-012 Decision 1). **No predicate changes.**

### (b) Corrected now, at plan level. Option 3.

**Correction 1 — `retention.dispatch_horizon_minutes` default `60` → `1440` (24 h).**
The principled anchor is the GC cadence: the pass runs daily at 02:00, so a grace of one
full cycle guarantees that **no dispatch created between two passes can be erased by the
very next pass**. Any dispatch then has at least 24 hours of queue latency to survive —
three orders of magnitude beyond normal, and comfortably beyond a working day's outage —
while remaining bounded at 1/30 of the retention window, so nothing is immortalized and
Option 2's trap is not re-entered.

Why this is a plan-level correction and not an ADR amendment: **no ADR fixes the value.**
ADR-012 Decision 4 and ADR-015 Decision 7 both name the config *key*
(`retention.dispatch_horizon_minutes`) and neither states a number. plan-05 § Services
names the key and calls it "env-overridable for dev/test only"; the `60` lives in
`config/retention.php:45`, which plan-05 owns. Changing it reverses no Owner-accepted
position and supersedes no ADR text.

**Correction 2 — a companion invariant, because correction 1 narrows the safety margin.**
The resolved horizon must be **strictly less than** the resolved retention window; at or
above it, H4 and H5 collapse into "hold everything, forever". Today that ratio is 720×
and the hazard is theoretical; at 1440 it is 30×, which is still safe but no longer
self-evidently so — and a V5 per-team window of a day or less would cross it. Assert it
where the window is actually in hand, i.e. beside `RetentionPolicy::cutoffFor($team)` in
`purgeForTeam()` (once per team, matching the existing resolve-once shape), and **fail
loud** — the same posture review-05 M-1 established for `retention.days`, for the same
reason: silently substituting a default on an irreversible operation masks an operator's
genuine intent. Placing it on the *resolved* window rather than on the config read also
means a V5 `windowFor()` override cannot bypass it (review-05 Nit 9).

**Correction 3 — documentation, no behaviour.** `config/retention.php:33-45` still
describes the key as serving H4 alone; it has served H5's `pending` clause since #6, and
that is now its only materially load-bearing role (H4's age branch is unreachable for any
expired event while the window dwarfs the horizon — for a 30-day-old row, `created_at <=
now() − 1440min` is trivially true, so H4 reduces in practice to its `whereExists`
branch). The docblock must name both holds and state that H5's clause is what the value
actually governs.

**Implementation notes for whoever picks this up.** This is a bug/chore-shaped change
(CLAUDE.md: "bugs/chores → senior-developer (fix + tests + record in `docs/fixes/`)"),
not a pipeline feature. Three code touches — the config default, the guard in
`purgeForTeam()`, the docblock — plus tests. `tests/Unit/Config/RetentionConfigTest.php:45`
pins the default at `60` and **must** be updated to `1440`; the H4/H5 acceptance and unit
cases are all written relative to `config('retention.dispatch_horizon_minutes')`
(`RetentionInFlightHoldsTest.php:94`,
`RetryReplayRetentionInterplayTest.php:240`,
`PurgeExpiredPayloadsTest.php:259`) and are unaffected by value. Add: a guard test that a
horizon ≥ the resolved window throws; and an AC18 acceptance test for the actual bug —
an Async replay whose `deliveries` rows are older than the *old* 60-minute horizon but
inside the new grace is **not** erased by a GC pass.

**Scheduling: now**, but standalone. Do not fold it into #7 — it shares no surface with
the mode toggle, and bundling a retention correction into a feature branch buries it.

### Why no Owner gate and no PM route

**No Owner gate.** Testing the correction against every CLAUDE.md trigger: no new
Composer or pnpm dependency; no stack change (`docs/stack/stack.md` untouched); **no
data-model change** — no table, no column, no index, no cast, no migration; no
security-sensitive surface — the change *lengthens* the period content is retained in
its existing encrypted-at-rest form and widens no read path; and it is not irreversible —
it constrains an irreversible operation, in the conservative direction, by a value that
can be reverted with one env var. No ADR position is reversed or superseded, so the
house rule that any ADR trips the gate is not engaged either. This is squarely inside the
Principal Engineer's stated authority over "architecture, data model, API contracts,
technology choices within the approved stack".

Where the Owner *would* be required, and is deliberately not being asked: **Option 4**
(terminal-reason column ⇒ data-model change; `DeliveryExhausted` semantics ⇒ #13-visible
behaviour change; both an ADR). Deferred per (a) above, with its trigger named.

**No PM route.** Requirement wording is the Product Manager's, and neither AC needs to
change. **AC8** is not unachievable as written — it explicitly delegates the permanently-
stuck case to #6, and #6 ruled on it; there is no ambiguity for the PM to settle and
nothing here reinterprets an Owner-approved AC. **AC18** is achievable as written and is
now met for every realistic latency; the failure was a design value, not a requirement.
If a future reader disagrees that AC8's parenthesis reaches instance (a) — the one
genuinely arguable step in this answer — *that* is the question to send to the Product
Manager, and it should be sent rather than re-litigated here.

### Documentation follow-ups (owned by the Principal Engineer, not done in this document)

1. **plan-05 § Q-05-03(i)** — its sufficiency claim ("Exactly one hop re-reads a captured
   row after capture… and H2/H4 cover it") is **incomplete as stated** since #6: the
   replay path adds a *second* by-reference read hop through
   `ProcessIngestedWebhook($ingestId, $dispatchUuid)`, covered by H2 under FIFO and by
   H5's `pending` clause under Async. Add a pointer to this document; do not rewrite the
   original wording (house rule — history is retained).
2. **plan-05 § Services** — record the horizon default change and the horizon-vs-window
   invariant beside the existing *Config sanity* invariant.
3. **`docs/status.md` row #5** — the carried Minor "PE: partial-fan-out Async hold gap"
   should read as resolved by this document: (a) settled trade-off, deferred with
   triggers; (b) corrected, scheduled as a standalone fix.
4. **ADR-012 and ADR-015** — **no change.** Neither is superseded, amended, or annotated
   by this answer. This is recorded explicitly so the absence is not read as an omission.
