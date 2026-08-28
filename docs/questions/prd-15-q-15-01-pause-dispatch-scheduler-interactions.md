# Q-15-01: How does a pause become visible to the three mechanisms that start dispatch on their own?

- **Feature:** pause and resume dispatch (item #15)
- **Requested By:** Product Manager (raised by `docs/product/prd-15-pause-and-resume-dispatch.md`)
- **Directed To:** **Principal Engineer**
- **Required By:** Before `plan-15`. **Non-blocking for requirement approval** of PRD-15 — the
  criteria stand whatever the mechanisms turn out to be — but nothing can be built until these are
  ruled.
- **Priority:** High. Items 1–3 each independently defeat **PRD-15 AC3** if unanswered, and item 1
  does so within sixty seconds.
- **Status:** **OPEN.**

## Why this is a question rather than a requirement

PRD-15 AC3 states the requirement: **while a proxy is paused, nothing is dispatched to any of its
destinations.** That is a product statement and it is settled.

What is not settled is that **dispatch is not started from one place.** Three mechanisms start work
on a proxy without a member doing anything, and each was built — correctly — on the assumption that
a proxy with work waiting is a proxy that wants work started. A pause enforced at a single call site
that these mechanisms bypass is not a pause; it is a pause that lasts until the next scheduler tick.

**The Product Manager is naming these, not resolving them.** How pause becomes visible to each —
a predicate, a status value, a scope, a guard, something else entirely — is the Principal Engineer's
to rule, and so is whether the answer is one mechanism or three.

## The four items

### 1. `SweepStalledFifoDispatches` pass (b) — the idle-proxy nudge — would defeat the pause within sixty seconds

Pass (b) dispatches an advancer **every minute** for any proxy holding pending rows with **no live
claim and no held row** (ADR-016 § *Sweeper rules extend, not change*). It exists as the liveness
net: a proxy with work and nothing in flight is stuck, and the nudge unsticks it.

**A paused proxy sits in exactly that state.** Pending rows, no claim, and — unless pause is made
visible to the sweeper — nothing that reads as *held*. So a member pauses a proxy and, on the next
tick, the sweeper starts dispatching it again. **This is the item most likely to be missed, because
the pause would appear to work when tested by hand and fail a minute later.**

Note the precedent: ADR-016 already added exactly one predicate to this pass so that a proxy with an
`awaiting_retry` row is treated as *held, not idle*. Whether a paused proxy is the same shape of
answer is the Principal Engineer's call, not the Product Manager's.

### 2. `AdvanceProxyFifoQueue` must not claim or dispatch for a paused proxy

The advancer is where the FIFO claim is taken. A paused proxy's rows must not be claimed and must
not be dispatched. Two properties from PRD-15 have to survive whatever the answer is:

- **AC5 — ordering is not re-engineered.** Order derives from the atomic claim, not from timing, so
  a proxy paused for a week drains in the same order it would have. Nothing here may introduce a
  timing dependence in order to make pause work.
- **AC4 — resume needs no member action.** After a resume, the waiting work has to start dispatching
  by itself. Whatever prevents a claim while paused must not also prevent the first claim after a
  resume, or leave the proxy waiting for the next sweeper tick when the member expects release.

### 3. `SweepDueRetries` would otherwise fire due retries on a paused proxy

`SweepDueRetries` runs **every minute** and re-dispatches `RetryDelivery` for any `retrying`
delivery whose `next_attempt_at` has passed (ADR-015). A retry is a dispatch under PRD-15's
definition, so it stops while paused. The delayed job that ADR-015 pairs the sweeper with is part of
the same question.

**A requirement that constrains the answer, from PRD-15 AC19:** retry counts, schedules and limits
are PRD-06's and are untouched by #15. A paused proxy must not spend attempts it did not make. What
happens to a retry that came due during a pause — deferred, rescheduled, or something else — is the
Principal Engineer's to rule, but **it must not consume the delivery's retry budget while the proxy
is paused**, because avoiding exactly that is the reason the feature exists (PRD-15 § Problem 2).

### 4. How is AC11's expired-while-paused unit of work resolved?

**PRD-15 AC9 through AC12** rule that a paused event keeps aging and is erased on schedule, that it
is never dispatched on resume (PRD-06 AC17 stands), that it must **not block the work behind it**,
and that it is presented as **cleaned** rather than as a delivery failure, with **no delivery
attempt record** created for it.

The requirement is stated. **The representation is not, and is yours.** Concretely: a FIFO ordering
row exists for that event and something has to happen to it, or the queue parks behind an event that
can never dispatch — the failure ADR-019 already identified in a different form, where a
short-circuiting step leaves FIFO at `awaiting_retry` with no lease, no age escape, and payloads
that become immortal (a PRD-05 AC6 breach).

## What is explicitly NOT being asked

**Pass (a), the orphaned-claim reaper, needs no change and is not part of this question.** It
already resets `claimed` rows past their ninety-second lease to pending. A proxy paused mid-claim
therefore releases its lease rather than holding one, and **that is the desired behaviour**. It is
named here so it is not "fixed".

Also not asked, because they are ruled in PRD-15 and are not open:

- whether ingest pauses — **it never does** (AC2);
- whether pause is per destination — **it is per proxy** (AC1, AC15);
- whether paused events get a retention hold — **they do not** (AC9), which is the narrowing of
  PRD-05 AC8 and PRD-06 AC18 recorded in PRD-15 § *Consequences for approved documents*;
- whether ordering needs new machinery — **it does not** (AC5).

## Answer

*(To be completed by the Principal Engineer. If any finding contradicts a criterion in PRD-15, that
returns to the Product Manager as a requirement question rather than being resolved as a design
choice.)*
