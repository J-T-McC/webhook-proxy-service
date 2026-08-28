# PRD: Pause and resume dispatch

- **Status:** **Draft — awaiting Project Owner approval.** Not approved, and not approvable by the
  Product Manager. One thing in this document needs the Owner specifically rather than riding along
  with an ordinary requirements sign-off:
  1. **§ Consequences for approved documents** — this PRD **narrows PRD-05 AC8** and **narrows
     PRD-06 AC18**, the two criteria that today hold payload erasure open while a dispatch is
     outstanding. The Owner ruled the narrowing deliberately on 2026-08-27; it is named there
     rather than applied silently, and it is the one place where approving this PRD changes the
     meaning of an already-approved one.
- **Author:** Product Manager
- **Date:** 2026-08-27
- **Approved by / date:** —
- **Backlog item:** Roadmap **#15** (`docs/product/roadmap.md`), added 2026-08-27 on the Project
  Owner's ruling that this is **its own roadmap item, not #10 scope** — its value is independent of
  secret rotation, covering destination outages and maintenance windows.
- **Depends on:** **#4 (Done)** — the queued dispatch this pauses. **#6 (Done)** — retry and replay,
  both of which stop while paused. **#5 (Done)** — the retention window paused events keep aging
  under. **#2 (Done)** — the permission model AC6 reuses. **#3 (Done)** — the upstream response AC2
  leaves untouched.
- **Build-ahead status:** written against shipped code only. **#8 is Owner-deferred with zero
  implementation and #9 has not started**, so no criterion here depends on either. **#10 is in
  Requirements and unapproved**, so nothing here depends on it either — the Owner's ruling names
  secret rotation as *one* motivation among several, not as a prerequisite.
- **Next gate: the Designer.** `## UX Direction` is present, so a PM-approved `design-15` is a
  prerequisite for Technical Design.

## Feature
A member can **pause dispatch** for a proxy and **resume** it later. While paused, nothing leaves
for that proxy's destinations. **Ingest never pauses** — incoming webhooks are still accepted,
answered and captured. On resume, the waiting work drains in its original order.

## Definitions
Fixed vocabulary. Every criterion below uses these words exactly.

| Term | Meaning |
|---|---|
| **Paused** | A per-proxy state in which **no dispatch to any destination of that proxy occurs** — no original dispatch, no automatic retry, no replay (AC3). A property of the proxy, set and cleared by a member. |
| **Dispatch** | Any outbound send to a destination, in either processing mode: an original fan-out delivery, an automatic retry (PRD-06 AC1), or a manual replay (PRD-06 AC9). All three stop. |
| **Ingest** | Everything up to and including capture: accepting the request at the ingest URL, returning the #3 user-defined response, and writing the event record. **Never paused** (AC2). |
| **Waiting work** | Work that exists because dispatch was paused: events captured and not yet dispatched, and retries that came due and were not fired. Not a new record type — the same pending work the system already carries, held rather than started. |
| **Aging** | Advancing toward the #5 retention expiry. Paused events age exactly as unpaused ones do (AC9). |

## Problem
Four gaps, each traceable to a document or to shipped behaviour rather than asserted.

1. **There is no way to stop dispatch, at any grain.** No PRD, design, plan or ADR describes one,
   and the application code carries no pause concept — the only occurrences of the word are
   unrelated. A member watching a destination fail can do nothing except watch.
2. **A destination outage silently spends the retry budget, and the budget does not come back.**
   Since #6, every proxy retries a failed delivery up to **5 times by default** (ADR-015, Owner
   ruling Q-06-01a) and then enters an explicit **terminal failed** state (PRD-06 AC4), which is
   not retried again. A destination that is down for longer than one delivery's backoff schedule
   therefore converts a queue of recoverable work into a pile of terminally-failed deliveries, each
   of which now needs a **manual replay** to recover. The retry policy is built for a destination
   that is flaky, not for one that is off.
3. **A maintenance window has no product-level expression.** A member who knows their destination
   will be unavailable — because they are deploying it, reconfiguring it, or rotating a credential
   on it — has no way to tell the product to wait. The alternative available today is destructive:
   remove the destination from the proxy and add it back afterwards, which loses its configuration
   and is not what the member means.
4. **Every mechanism that can start work does so on a schedule, so "stop" cannot be a single
   check.** Dispatch is started from more than one place — the FIFO advancer, the FIFO sweeper's
   idle-proxy nudge, and the due-retry sweeper each start work without a member doing anything.
   A pause that is enforced at one of them is not a pause. **This is the substance of the technical
   design and is named as an Open Question (Q-15-01) rather than specified here.**

## Goals
- A member can stop outbound traffic for a proxy without losing an event, losing configuration, or
  spending the retry budget.
- Resuming is one action and needs no repair work: the waiting events dispatch by themselves, in
  order.
- **Nothing about ordering is re-engineered.** Order is already a property of how work is claimed,
  not of when it is claimed, so a long pause costs nothing in correctness.
- The one consequence a member cannot undo — a paused event's payload expiring on schedule — is
  stated **before** they pause, not discovered when they resume.
- A paused proxy reads as *deliberately paused by someone on your team*, never as broken, stuck or
  failing.

## Users
- **Team member** — pauses and resumes their proxies; is the one who knows the destination is down.
- **Team Owner / Admin** — same, without the Member ownership limit on configuration changes
  (Q-02-01 / ADR-009 Amendment A2.2).
- **Upstream sender** — a third-party system, **entirely unaffected**: it posts to the ingest URL
  and receives the same response it does today, paused or not.
- **Destination** — receives nothing while the proxy is paused, then receives the waiting work in
  order when it resumes.
- **The product (system)** — holds the paused state, and must honour it in every mechanism that can
  start work rather than only in the one a member triggers.

## User Stories
- As a team member whose destination is down, I want to pause dispatch, so my events queue up
  instead of burning through their retries and landing terminally failed.
- As a team member deploying a change to my receiver, I want to pause my proxy for the maintenance
  window and resume afterwards, so nothing is sent while it is down and nothing is lost.
- As a team member, I want ingest to keep working while dispatch is paused, so pausing is never a
  reason a webhook is dropped.
- As a team member, I want my paused events delivered **in the order they arrived** when I resume,
  so a pause does not reorder my consumer's world.
- As a team member, I want to be told, at the moment I pause, that paused events keep expiring on
  the retention clock — so I can decide how long a pause I can afford rather than finding out when
  I resume.
- As a team member, I want anyone on my team looking at the proxy to see that it is paused and when
  it was paused, so nobody spends an afternoon debugging a silence somebody else chose.

## UX Direction
Direction only. Screens, states, components and copy belong to the Designer (`design-15`).

**The primary flow is short and must stay short.** A member opens a proxy, pauses it, confirms, and
sees the proxy is paused. Later they resume it, in one action, and the proxy is no longer paused.
Pause is a control on the proxy, alongside its other configuration, because that is where a member
already goes when a proxy is misbehaving.

**What the experience optimises for, in priority order.**

1. **Paused must be unmistakable, and it must read as a choice rather than a fault.** A member
   arriving at a proxy that is sending nothing has to be able to tell *instantly* whether it is
   paused or broken. Three states already have to be distinguishable around delivery — delivered,
   retrying, terminally failed (PRD-06 AC16) — and **paused is a property of the proxy, not of any
   delivery**, so it must not be confusable with any of them, and above all not with terminal
   failure. It should be visible wherever a proxy is listed, not only on the proxy's own page.
2. **The confirmation at pause is the one screen in this feature that carries real information, and
   it must not be built as a speed bump.** The member is being told something they cannot undo:
   **events waiting while paused keep aging under the 30-day retention window and their payloads
   are erased on schedule, and an erased event cannot be dispatched when you resume** (AC9, AC10,
   AC11). That sentence is the reason the confirmation exists. A generic "are you sure?" would fail
   this requirement even though it technically confirms. **Resume needs no confirmation** —
   resuming causes exactly what the member intended when they paused.
3. **The member should be able to see that work is accumulating.** Pausing is a decision a member
   revisits, and they revisit it based on how much is waiting and how long it has been waiting. The
   surface should make the existence and the growth of waiting work visible; exactly what figure is
   shown, and where, is the Designer's.
4. **Resume must feel like release, not like repair.** After resuming, the member should see the
   proxy working again without being asked to retry, replay, or clear anything. This is what AC5's
   ordering guarantee buys and the interface should not obscure it by presenting recovery actions
   that are not needed.
5. **Replay is unavailable while paused, and says why.** A replay is a dispatch, so it cannot run
   while dispatch is paused. It follows PRD-06's existing treatment of an unavailable replay: the
   affordance is disabled **with the reason given**, presented as an expected consequence of the
   proxy being paused, never as an error and never as a silent no-op. A replay control that
   appeared to work and sent nothing would be the worst available option.

**Not the Designer's to decide, because they are ruled here:** that pause is **per proxy** and not
per destination (AC1, AC15); that ingest is never paused and the upstream response is unchanged
(AC2); that the confirmation happens at **pause** and not at resume (AC10); that replay is
unavailable rather than queued while paused (AC3); and that resuming requires no member action
beyond resuming (AC4).

## Acceptance Criteria

> **Numbering is append-only**, following the house rule set by PRD-05 and PRD-11.

### The pause state

1. **A member can pause dispatch for a proxy, and resume it.** The state is **per proxy**, explicit,
   and has exactly two values — paused or not. It is set and cleared by a member; nothing sets it
   automatically (AC16).
2. **Ingest never pauses.** A webhook arriving at a paused proxy is **accepted exactly as it is
   today**: same acceptance, same **user-defined #3 response**, same capture, same event record,
   same non-content descriptors. **No webhook is rejected, dropped, deferred or answered differently
   because a proxy is paused.** *(The product's zero-data-loss position, stated by the Project Owner
   on 2026-08-27: a member who wants ingestion stopped pauses the third party at source. This
   criterion is what makes pausing safe to reach for.)*
3. **While paused, nothing is dispatched to any destination of that proxy.** All three dispatch
   forms stop: the original fan-out delivery, an automatic retry that comes due (PRD-06 AC1), and a
   manual replay (PRD-06 AC9). **A replay is unavailable while paused**, with the reason given, in
   the same manner PRD-06 already makes replay unavailable for a cleaned event — it is not queued,
   and it does not silently do nothing.
4. **Resume releases the waiting work with no further member action.** On resume, the proxy's
   waiting work dispatches by itself. The member is not required to retry, replay, re-queue or
   clear anything to recover work that accumulated during the pause.
5. **Order is preserved across a pause of any length, and the ordering mechanism is not
   re-engineered.** A FIFO proxy resumes dispatching in the same order it would have used had it
   never been paused, however long the pause lasted. **This is already true and must be left
   alone:** FIFO order derives from the atomic claim in `AdvanceProxyFifoQueue`, not from timing
   (ADR-011, ADR-016, ADR-020). *(Stated as a criterion because it is a property a Reviewer must
   verify survived, and because "make ordering work across a pause" is exactly the work that must
   **not** be done.)*
6. **Pausing and resuming are gated by the existing proxy update permission**, including the Member
   ownership rule (Q-02-01, ADR-009 Amendment A2.2). **No new permission.** *(Same treatment PRD-06
   gave retry-policy configuration and PRD-10 gives verification configuration: this is proxy
   configuration.)*
7. **Existing proxies are unaffected and nothing is opted in.** Every proxy is unpaused when this
   ships. No migration, no backfill, no default pause.
8. **A pause delays; it never alters.** What a destination eventually receives after a resume is
   **byte-identical** to what it would have received had the proxy never been paused — same body,
   same headers, same method, same target set. Pause changes *when* work happens and nothing else.

### Retention — the consequence the Owner accepted deliberately

9. **Paused events keep aging under #5's retention window, and their payloads are erased in place
   as normal.** A pause creates **no hold and no extension**: an event waiting behind a pause
   expires on the same 30-day clock as any other (PRD-05 AC1, AC2) and is erased in place by the
   same garbage-collection pass (PRD-05 Amendment A). **The Project Owner ruled this deliberately on
   2026-08-27.** It narrows PRD-05 AC8 and PRD-06 AC18 — see § Consequences for approved documents.
10. **The consequence is stated at the moment of pausing, before the member commits.** Pausing
    requires an explicit confirmation that states, in terms a member can act on, that **events
    waiting while the proxy is paused continue to expire under the retention window, their payloads
    are erased on schedule, and an erased event will not be dispatched when the proxy resumes.**
    **The requirement is that the consequence be stated before the decision, not that a confirmation
    step exist** — a confirmation that does not say this does not satisfy this criterion.
    **Resuming requires no confirmation.**
11. **An event whose payload was erased while the proxy was paused is never dispatched on resume,
    and never blocks the work behind it.** PRD-06 AC17 stands unchanged: nothing dispatches erased
    content, and eligibility is read from the **explicit cleaned signal** (PRD-05 AC21), never
    inferred from missing or empty content. Such an event's waiting work is **resolved without
    dispatching**, and the proxy's remaining work continues past it in order. *(The second half is
    load-bearing: waiting work that could neither dispatch nor resolve would hold the queue behind
    it — the failure ADR-019 already identified in a different form, where a short-circuited step
    parks FIFO with no age escape and makes payloads immortal. **How this resolution is represented
    is the Principal Engineer's — Q-15-01(4).**)*
12. **An event that expired while paused is presented as expired, never as a delivery failure.** No
    dispatch was attempted, so **no delivery attempt record is created** for it (ADR-003 records are
    per attempt) and nothing about it is a failure of a destination. It reads under the existing
    three-state payload signal — retained / cleaned / never captured (PRD-05 AC21, PRD-06 AC16) —
    as **cleaned**, which PRD-05 AC10 already establishes as a normal state rather than an error.
13. **Pause itself erases nothing and extends nothing.** Pausing and resuming change no retention
    rule, no window, no expiry, and destroy no payload of their own. The only erasure that happens
    during a pause is the erasure that would have happened anyway.

### Visibility

14. **A paused proxy is visibly paused, and says when it was paused.** The state is shown wherever a
    proxy is presented, not inferable only from an absence of traffic, and it is **distinct from
    every delivery state** — in particular from terminal failure (PRD-06 AC16). Any team member who
    can view the proxy sees it; **pausing is a team-visible act**, because the member who finds the
    silence is often not the member who caused it.

### Scope boundaries

15. **No per-destination pause.** Pause is per proxy only. *(Not ruled by the Owner and not invented
    here. If a member needs one destination stopped, that is a separate requirement and would be
    its own decision. Stated so it is not read into AC1.)*
16. **No scheduled, automatic or conditional pause.** No maintenance-window scheduling, no
    auto-resume after a period, no auto-pause when a destination fails repeatedly, and no
    pause-until-a-condition. Pause is a member action and resume is a member action.
17. **No notifications about a paused proxy.** Nothing alerts a member that their proxy is still
    paused, that work is accumulating, or that a paused event has expired. **This is a real cost and
    it is stated, not glossed:** the member who forgets a pause sees nothing until they look. The
    remedy belongs to **#13** and is not designed here. *(Same posture PRD-10 AC46 takes for
    rejected inbound requests.)*
18. **No analytics for pause.** No paused-time figure, no waiting-work trend, no effect asserted on
    any #11 measure beyond what falls out of no attempts being made. *(#11 is merged; #15 adds
    nothing to it.)*
19. **No change to retry policy, backoff, the terminal state, or replay semantics** beyond the fact
    that none of them runs while paused (AC3). Retry counts, schedules and limits are PRD-06's and
    are untouched.
20. **No bulk or team-wide pause.** One proxy at a time.
21. **No numeric targets.** No throughput, latency, drain-rate or waiting-work-size number is
    asserted (**V8** remains deferred).
22. **Nothing here depends on #8, #9 or #10, and nothing here pre-empts them.** #8 is
    Owner-deferred, #9 has not started, and #10 is unapproved. Secret rotation is named by the Owner
    as **one motivation** for this item, never as a dependency of it.

## Consequences for approved documents

Recorded so nothing is narrowed silently — the rule PRD-05 Amendment A was written under. **Neither
document is edited by this PRD.** Both changes take effect only if the Owner approves it.

- **PRD-05 AC8 is narrowed.** AC8 says the payload for an event "whose dispatch has not completed —
  including one queued, pending, or claimed under #4's per-proxy FIFO ordering" is **not erased
  while that dispatch is outstanding, even if its window has elapsed.** A paused proxy's waiting
  events sit in precisely that condition, so AC8 as written would hold their payloads open for the
  entire length of the pause. Under **AC9** they are erased on schedule instead.
- **PRD-06 AC18 is narrowed in the same way and for the same reason.** AC18 extends the
  outstanding-dispatch hold to scheduled retries and in-flight replays. A retry that came due while
  paused is not fired (AC3) and does not hold erasure open.
- **Why the narrowing is the right way round, and why it is consistent with what those criteria are
  for.** AC18 ends by stating the principle directly: retry schedules "must therefore be bounded
  well inside the retention window — **a retry policy can never make a payload immortal**." A pause
  is member-controlled and unbounded. Under the unnarrowed rule, pausing a proxy would be a
  supported way for any member to hold payload content indefinitely, defeating the retention
  guarantee **#5** exists to provide and **#10 AC2** ratifies. The hold in AC8 and AC18 exists to
  stop a payload being erased out from under work that is **actively in progress**; work that is
  **deliberately stopped** is a different thing, and the Owner ruled it so.
- **The cost is real and is not hidden.** A proxy paused for longer than the retention window loses
  the payloads of the events that were waiting, and those events cannot be dispatched on resume
  (AC11). **AC10 is the entire mitigation** — the member is told before they pause, so the trade is
  theirs to make.
- **Nothing else is disturbed.** PRD-05's retention contract otherwise stands; PRD-06's retry
  policy, terminal state and replay semantics stand (AC19); ADR-003's attempt records, ADR-011 /
  ADR-016 / ADR-020's FIFO ordering, and #3's response contract are all relied on unchanged. #15
  adds **no** new permission (AC6) and **no** new payload store.

## Out of Scope
Each names where it goes, or why nothing owns it yet.

- **Per-destination pause** — AC15. A separate requirement, not ruled.
- **Scheduled pauses, auto-resume, and automatic pause on repeated failure** — AC16. Each is a
  different feature with its own decisions; none was ruled.
- **Notifying anyone that a proxy is paused or that waiting work is expiring** — AC17; **#13**.
- **Any analytics view of pause** — AC18; **#11**.
- **Pausing ingest, rejecting inbound requests while paused, or changing the upstream response** —
  AC2. Ruled out by the Owner on the zero-data-loss policy. A member who wants ingestion stopped
  pauses the third party at source.
- **Any retention hold, extension or exemption for paused work** — AC9, AC13. This is the ruling
  itself, not a deferral.
- **Bulk or team-wide pause** — AC20.
- **Throughput, latency or drain-rate targets** — AC21; **V8** deferred.
- **Anything depending on payload mapping (#8), multi-format ingestion (#9) or sensitive data
  handling (#10)** — AC22.

## Open Questions

- **Q-15-01 (Principal Engineer — technical) — OPEN, raised by this PRD. Gates technical design;
  non-blocking for requirement approval.** Doc:
  `docs/questions/prd-15-q-15-01-pause-dispatch-scheduler-interactions.md`. Four items, three of
  which are mechanisms that would each defeat AC3 independently if pause is not visible to them —
  `SweepStalledFifoDispatches` pass (b), `AdvanceProxyFifoQueue`, and `SweepDueRetries` — plus
  **(4)** how AC11's resolution of an expired-while-paused unit of work is represented.
  **These are named as requiring technical design and are deliberately not specified here.** If any
  finding contradicts a criterion in this PRD, that returns to the Product Manager as a requirement
  question, not a silent design change.
- **No question is owed to the Project Owner.** Every product decision in this PRD is either an
  Owner ruling of 2026-08-27 recorded verbatim in substance, or a Product Manager call derived from
  an approved document and marked as such. The one item that needs the Owner is not a question but
  a ratification: § Consequences for approved documents.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (**#15**, added 2026-08-27; V8) ·
  `docs/product/prd-04-queued-processing.md` and `docs/architecture/adr-011-per-proxy-fifo-dispatch-mechanism.md`
  (the dispatch mechanism this pauses, and the atomic claim AC5 rests on) ·
  `docs/architecture/adr-016-fifo-composition-under-retry-and-replay.md` (sweep passes (a) and (b),
  the subject of Q-15-01) · `docs/architecture/adr-020-fifo-advancer-job-duration-and-claim-lease-safety.md`
  (the current advancer and claim-lease behaviour) ·
  `docs/product/prd-05-payload-storage-retention.md` (AC1, AC2, **AC8**, AC10, AC21; Amendment A —
  the retention contract AC9 composes with and AC8 the narrowing names) ·
  `docs/product/prd-06-retry-replay.md` (AC1, AC4, AC9, AC15, AC16, **AC17**, **AC18** — retry, the
  terminal state, replay, and the erasure hold) ·
  `docs/architecture/adr-015-delivery-retry-mechanism.md` (the default 5-attempt policy and
  `SweepDueRetries`) · `docs/architecture/adr-003-delivery-attempt-records-and-events.md` (AC12's
  per-attempt records) · `docs/product/prd-03-decoupled-upstream-response.md` (the response AC2
  leaves untouched) · `docs/product/prd-02-role-based-collaboration.md` +
  `docs/architecture/adr-009-proxy-permission-mechanism.md` (the permission AC6 reuses) ·
  `docs/standards/documentation.md`.
- **Outputs:** this PRD ·
  `docs/questions/prd-15-q-15-01-pause-dispatch-scheduler-interactions.md` (**OPEN**, Principal
  Engineer).
- **Dependencies:** **#4, #5, #6, #3, #2 — all Done.** **#15 does not depend on #8, #9, #10, #11,
  #12, #13 or #14, and must not pre-empt them.**
- **Outstanding Questions:** **Q-15-01** — Principal Engineer, technical, non-blocking for
  requirement approval.
- **Next Agent:** **Designer.** `## UX Direction` is present, so under the mechanical routing rule
  a PM-approved `design-15` is a prerequisite for Technical Design — no exceptions. **The Designer
  must not start before the Project Owner has approved this PRD**, because the confirmation at pause
  (AC10) is the feature's central screen and its content depends on the Owner ratifying the
  retention narrowing.
