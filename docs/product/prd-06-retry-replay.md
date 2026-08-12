# PRD: Retry & replay

- **Status:** Approved
- **Author:** Product Manager
- **Date:** 2026-08-12 · **Revised:** 2026-08-12 (Owner rulings on Q-06-01 and
  Q-06-02 rendered into AC1, AC2, AC9, AC14, AC20, AC22, new AC25, and the UX
  Direction — see the resolved question docs for the rulings verbatim)
- **Approved by / date:** Project Owner, 2026-08-12
- **Backlog item:** Roadmap #6 (`docs/product/roadmap.md`)

## Feature
A failed destination delivery is **retried automatically** under a bounded,
configurable backoff policy until it succeeds or reaches an explicit **terminal
failed state**, and a team member can **manually replay** a retained stored
payload to specific destinations or to all of them (R4).

## Definitions
Two distinct capabilities share this item; every criterion below names which one
it binds.

| Term | Meaning |
|---|---|
| **Retry** | **Automatic, system-initiated** re-attempt of a *failed* delivery to a destination, governed by a retry policy (attempt limit + backoff between attempts). No user action involved. |
| **Replay** | **Manual, user-initiated** re-send of a previously received event's stored raw payload to chosen destination(s) — specific ones or all (R4). Available regardless of whether the original deliveries failed. |

## Problem
Four gaps sit on top of #4/#5:

1. **One shot per delivery.** Since #4, a delivery that fails has failed forever
   after its single attempt; only the attempt record notes it. A transient
   destination outage (deploy, blip, rate-limit) permanently loses the delivery —
   the vision's core "Retry / replay" gap. (#4 AC9's redelivery handling is queue
   correctness, explicitly *not* retry — PRD-04 AC11.)
2. **Stored payloads have no user path.** #5 built the 30-day retrievability
   guarantee *specifically* so #6 could replay (PRD-05 Goals), and the Owner
   deferred the stored-payload surface to #6 (Q-05-01, Option B: "#6 needs a
   stored-payload selection surface regardless"). Today nothing lets a user see a
   proxy's received events or re-send one.
3. **The FIFO head-of-line bound was deferred here.** PRD-04 AC10 left the
   concrete bound on a failing FIFO head to #6; ADR-011 left the dead-letter seam
   open ("#6 attaches retry/backoff + dead-letter"). Until #6, a permanently
   failing head is only bounded by having exactly one attempt.
4. **#13 has no failure event to consume.** The roadmap makes notifications
   "usable earlier for failure alerts once #6 exists" — terminal-failure events
   must exist before #13 can alert on them.

## What earlier items delivered vs. what #6 adds

| Concern | Owner | State |
|---|---|---|
| Per-destination, per-attempt payload-free records + domain events, `attempt_number` | #1 (ADR-003) | Done — the stream retry/replay must reuse |
| Queued dispatch, Async/FIFO modes, exactly-once settlement under redelivery | #4 (ADR-011) | Done — the seam retry attaches to |
| 30-day retrievability guarantee; three-state retained/cleaned/never-captured signal | #5 (ADR-012/014) | Done — the guarantee replay builds on |
| Dispatched-output store (enhanced) | #5 (ADR-013) | Done — replay can distinguish received from sent |
| **Automatic retry with configurable backoff, bounded by a terminal state** | **#6** | **This PRD** |
| **Manual replay to specific/all destinations; the stored-event surface** | **#6** | **This PRD** |
| Notifications on failure | #13 | Not here — #6 only emits the events |
| Enhanced-mode toggle UI | #7 | Not here |
| Field obfuscation / sensitive-data display policy | #10 | Not here — #6's viewer uses a **whole-payload mask + reveal** (Owner ruling Q-06-02a); field-level detection/obfuscation stays #10, untouched |

## Goals
- A transiently failing delivery recovers **without user action**: failed
  attempts are re-tried on a backoff schedule instead of failing forever on
  attempt one.
- Retry is **bounded and honest**: after the attempt limit, the delivery enters
  an explicit, visible terminal failed state and emits an event — it never
  retries forever and never fails silently.
- A FIFO proxy's line is **never permanently wedged** by one poison event
  (closes the bound PRD-04 AC10 deferred here), and FIFO's intended
  ordered-means-waiting semantic is preserved, not silently broken by retry.
- A team member can **replay** any retained event to specific destinations or
  all of them (R4), reusing the #1 fan-out destination model.
- Retries and replays emit **the same delivery-attempt records and domain
  events** as first attempts (ADR-003) — #11 analytics and #13 notifications get
  one stream, no parallel path (roadmap #6 build-ahead).
- The #5 retention contract is honored end-to-end: replay works exactly within
  the retrievability window; a **cleaned** event is visibly expired and not
  replayable; **no retry or replay path can ever dispatch erased content**.
- The upstream sender remains unaffected: retry and replay are invisible to the
  #3 response contract.

## Users
- **Team member** — configures a proxy's retry policy; sees a proxy's received
  events and their delivery state; replays retained events; sees terminal
  failures instead of silent loss.
- **Team** — replay and retry-policy actions are team-scoped and
  permission-gated under the #2 model.
- **The product (system)** — schedules retries, enforces the attempt bound,
  emits the events #13 will consume.
- **Upstream sender** — unaffected; the #3/#4 response contract is unchanged.
- **Destination endpoint** — may receive the same event more than once (retry
  after ambiguous failure, manual replay); delivery remains at-least-once from
  the destination's perspective.

## User Stories
- As a team member, I want a failed delivery to be retried automatically with
  backoff, so a destination's transient outage doesn't permanently lose my
  webhook.
- As a team member, I want retries to stop after a limit and the delivery to be
  clearly marked as terminally failed, so I can see and act on real failures
  instead of the system retrying forever or failing silently.
- As a team member, I want to configure my proxy's retry behavior (vision:
  "configurable backoff strategies"), so retry pressure matches what my
  destinations tolerate.
- As a team member, I want to see a proxy's received events and re-send a
  retained one to one, several, or all of its destinations (R4), so I can
  recover a consumer that lost or mishandled an event — especially when the
  upstream sender is primitive and cannot re-send (vision).
- As a team member, I want a replay-ineligible event to say *why* (payload
  expired on schedule vs. never captured), so a missing payload reads as
  lifecycle, not data loss (PRD-05 AC10/AC21).
- As a team member with a FIFO proxy, I want a permanently failing event to be
  set aside (terminal, replayable) after its retries are exhausted, so one
  poison event doesn't block every event behind it forever.
- As the product (system), I want retries and replays to flow through the same
  attempt records and events as first attempts, so analytics (#11) and
  notifications (#13) consume one stream.

## UX Direction
#6 is the first user-facing surface over stored events, plus retry-policy
configuration. Direction only — screens, states, components, and copy are the
Designer's.

- **Per-proxy received-events surface: a list plus a masked payload viewer
  (Owner ruling, Q-06-02a).** For a proxy the user can view, a list of its
  received events showing non-content descriptors (e.g. received time, size,
  content type) and two kinds of state: **payload state** (retained / cleaned —
  per PRD-05 AC21) and **delivery state** per the attempt records (delivered /
  retrying / terminally failed). A retained event's payload **content is
  rendered, but hidden behind a whole-payload mask by default**, with an
  explicit user **reveal** action ("view password"-style toggle) that exposes
  the full raw payload. The mask is all-or-nothing: **no field-level secret
  detection and no per-field obfuscation** — that is #10's scope. Reveal is
  available to anyone who can view the surface (proxy read permission); there
  is no separate reveal permission. Optimize for deliberate exposure: content
  never renders unmasked without the user's explicit action.
- **Replay flow.** From an event, the user chooses **all destinations or a
  subset of the proxy's current destinations** (R4) and confirms. The experience
  must make consequence clear before confirming: replay sends **real traffic**
  to destinations, again — optimize for deliberate action, not one-click
  accidents.
- **Expiry is a normal state.** A cleaned event's replay affordance is
  unavailable/disabled with the reason (payload expired per the retention
  window) — presented as expected lifecycle, never as an error or as data loss
  (PRD-05 AC10).
- **Terminal failure is visible.** A delivery that exhausted its retries is
  identifiable on this surface (and is a natural replay candidate).
- **Retry-policy configuration** lives with the existing proxy create/edit form
  and is an **enhanced-mode capability** (Owner ruling, Q-06-01): two fields —
  attempt limit and backoff strategy (exponential default / fixed interval) —
  with the system default (5 attempts, exponential) applying wherever nothing
  is configured. Simple-mode proxies expose no retry configuration; the
  system-default policy simply applies. The experience must present the FIFO
  trade-off honestly where relevant: on a FIFO proxy, a retrying event *delays
  the line behind it* — the ordered-means-waiting consequence, consistent with
  PRD-04's UX Direction.

This section's presence makes the **Designer gate mandatory** (routing rule; see
Handoff).

## Acceptance Criteria

> **Numbering is append-only.** The Owner's 2026-08-12 rulings on Q-06-01 and
> Q-06-02 are rendered **in place** into AC1, AC2, AC9, AC14, AC20, AC22 (each
> tagged **(R)**) and append one new criterion — **AC25**, carrying its label
> explicitly, sitting in its thematic group — so the AC references in the
> question docs (Q-06-01/02/03 cite AC1–AC18) all stay valid. Nothing is
> renumbered.

**Retry (automatic)**

1. **(R) Failed deliveries are retried automatically — every proxy, both
   modes.** When a delivery attempt to a destination fails (the same failure
   outcome the attempt records already capture — ADR-003), the system schedules
   further attempts to that destination with **no user action**, per the
   governing retry policy. Automatic retry applies to **all proxies** —
   simple-mode and enhanced-mode alike (Owner ruling Q-06-01a, Option B).
   Wherever the user has configured nothing, the **system-default policy** of
   AC2 governs.
2. **(R) Backoff between attempts; configurability is enhanced-mode only.**
   Successive attempts for the same `(event, destination)` are separated by a
   backoff schedule. Per Owner ruling Q-06-01b (confirmed as proposed):
   - **Per-proxy knobs — enhanced mode only:** **attempt limit** and **backoff
     strategy**; nothing else is tunable at #6. Simple-mode proxies have **no**
     retry configuration — the system default applies, fixed.
   - **Offered strategies at MVP:** **exponential** (the default) and **fixed
     interval**.
   - **System default** (any proxy with nothing configured): attempt limit
     **5**, exponential backoff.
   - **System caps:** attempt limit never exceeds **10**; a policy's total
     backoff span is bounded **well inside the 30-day retention window**
     (AC18) — no configuration may retry unboundedly.
3. **Retry is per destination.** Only the destinations that failed for an event
   are retried. A destination that succeeded for that event is never re-sent by
   automatic retry; one destination's retries never add attempts for another
   (extends #1/#4 independent-destinations, PRD-04 AC10).
4. **Retry is bounded by an explicit terminal state.** After the policy's
   attempt limit, no further automatic attempt is ever made for that
   `(event, destination)`; the delivery enters an explicit **terminal failed**
   state that is directly represented and visible — never inferred from the
   absence of further attempts. A terminal delivery remains eligible for
   **manual replay** (AC10) while its payload is retained.
5. **Exhaustion emits an event.** Entering the terminal failed state emits a
   domain event on the same ADR-003 stream, carrying enough to identify team,
   proxy, destination, and event — the hook #13 consumes later. #6 sends **no
   notification** itself.
6. **FIFO stays ordered; head-of-line blocking is bounded.** On a FIFO proxy,
   retrying the line's head holds that proxy's line for the duration of its
   backoff — the intended, Owner-owned ordered-means-waiting semantic (ADR-005
   (c)/(d)) — but once the head reaches its terminal state (AC4) the line
   advances past it. One event can therefore delay its proxy's line, never wedge
   it permanently; other proxies are never affected (PRD-04 AC7 preserved). On
   an Async proxy, one event's retries never delay any other event's processing.
7. **Retries land in the same record/event stream.** Every automatic retry
   produces the same payload-free, per-destination delivery-attempt record and
   domain events as a first attempt (ADR-003), with an incremented attempt
   number, team-scoped. Exactly-once settlement per attempt under queue
   redelivery (#4 AC9) holds for retry attempts too. No parallel or
   reconstructed path (roadmap #6 build-ahead).
8. **Upstream sender unaffected.** Retry scheduling, retry outcomes, and
   terminal states never change, delay, or depend on the #3 upstream response
   (ADR-004); the sender cannot observe whether retries happened.

**Replay (manual)**

9. **(R) A team member can replay a retained event — both proxy modes.** From a
   proxy's received events, a user can manually trigger re-delivery of a stored
   event whose payload content is **retained** (within its #5 window, not
   cleaned — PRD-05 AC7 is the guarantee this builds on). Replay is available
   for **simple-mode and enhanced-mode proxies alike** (Owner ruling Q-06-02b,
   Option A — consistent with mode-independent capture, R2 override).
10. **Target selection — specific or all (R4).** The user chooses one, several,
    or all of the **proxy's current destinations**, selected from the same #1
    fan-out destination model — no separate replay target list, no arbitrary
    URLs.
11. **A replay is a new dispatch now, through the same path.** Replay
    re-processes the event's stored **raw payload** through the proxy's
    **current** configuration and the same processing/dispatch path as a live
    event (roadmap #5 build-ahead: replay "re-dispatch[es] the raw payload").
    It is ordered at the time of replay — on a FIFO proxy it joins the line as
    the newest work; it never re-inserts at the event's historical position and
    never re-runs any upstream response.
12. **Replays land in the same stream, identifiably.** Replay deliveries emit
    the same per-destination attempt records and events (ADR-003), and are
    **distinguishable as replays and traceable to the original received event**,
    so #11/#13 can tell live traffic from replays without a second path.
    (Representation is the Principal Engineer's; ADR-003 reserved this seam.)
13. **A failed replay retries like a live delivery.** A replay delivery that
    fails is subject to the same automatic retry policy and the same terminal
    state (AC1–AC7) as a live delivery — replays are not a second, weaker
    delivery class.
14. **(R) Replay is permission-gated, never role-gated.** Replay is a new
    team-scoped proxy action under the #2 permission model (PRD-02 / ADR-009 —
    which anticipated a replay permission case); no direct role check. Per
    Owner ruling Q-06-02c (Option A): **all three roles — Owner, Admin, and
    Member — hold the replay permission, with no Member ownership limit**
    (the Q-02-01 ownership rule does **not** apply to replay). The
    received-events surface itself is a read path and is gated by the existing
    proxy **read** permission per PRD-05 AC16; the AC25 reveal action carries
    **no distinct permission** beyond that read gate (Owner ruling Q-06-02a).
    Retry-policy configuration is proxy configuration and is gated by the
    existing **update** permission including the Member ownership rule (Q-02-01)
    — no new permission needed there.

- **AC25 (added — Owner ruling Q-06-02a). Payload content renders masked, with
  an explicit whole-payload reveal.** Where #6's surface presents a retained
  event's stored payload, the content is **hidden behind a whole-payload mask
  by default** and is exposed only by an **explicit user reveal action**
  ("view password"-style); activating the reveal exposes the **full raw
  payload**. Constraints, all Owner-ruled: the mask is **all-or-nothing** —
  #6 performs **no field-level secret detection and no per-field
  obfuscation/redaction** (that is #10's scope, untouched); the reveal is
  available to **any user who can view the surface** (existing proxy read
  permission, PRD-05 AC16) with **no distinct reveal permission**; content is
  never rendered unmasked without the user's explicit action. A **cleaned**
  event has no content to reveal — its presentation is AC15/AC16's, never an
  empty reveal.

**Retention interplay (the #5 contract, honored end-to-end)**

15. **A cleaned event is not replayable — and says so.** Once an event's payload
    content is erased on expiry, replay of that event is **unavailable**: the
    affordance is disabled/absent and the state is presented as *expired on
    schedule* (PRD-05 AC10/AC21), never as an error and never as a failed
    replay. This is forced by PRD-05 AC6 — after the expiry pass no payload
    content is retrievable "through **any** user-facing or system path,
    **including a #6 replay**" — so no alternative behavior (partial replay,
    replay-from-copy) is possible or permitted.
16. **The three payload states are visibly distinct.** Wherever #6 surfaces an
    event, **retained**, **cleaned**, and **never captured** (PRD-05 AC21) are
    distinguishable to the user; a cleaned event's surviving descriptors and
    delivery history remain readable (PRD-05 AC9/AC10).
17. **Nothing ever dispatches erased content.** No retry or replay path may
    deliver an empty, partial, or reconstructed payload for a cleaned event —
    eligibility is determined from the **explicit cleaned signal**, never
    inferred from missing/empty content (ADR-014 Decision 7 is the standing
    guard; asserted here as an observable requirement: a cleaned event produces
    zero new delivery attempts except by rejecting the request cleanly).
18. **Outstanding retries and in-flight replays hold erasure; terminal ones do
    not.** An event with scheduled or in-flight **automatic retries**, or a
    **replay dispatch in flight**, is not eligible for payload erasure while
    that work is outstanding — the same outstanding-dispatch semantic as PRD-05
    AC8, extended to #6's new dispatch forms (ADR-012 anticipated: "#6 attaches
    replay/dead-letter holds to the same list"). A delivery in its **terminal
    failed** state is *not* outstanding work: it holds nothing, retention
    applies normally, and once the event is cleaned the terminal delivery is
    simply no longer replayable (AC15). Retry backoff schedules must therefore
    be bounded well inside the retention window — a retry policy can never make
    a payload immortal.

**Scope boundaries**

19. **No notifications.** #6 emits the AC5 events; in-app/email alerting,
    channels, opt-outs are #13.
20. **(R) No mode toggle.** The one thing #6 gates on enhanced mode —
    per-proxy retry-policy configurability (AC2, Owner ruling Q-06-01a) —
    gates on the existing mode attribute (ADR-002); surfacing the
    simple/enhanced toggle is #7. #6 adds no way to change a proxy's mode.
21. **No mapping, no payload transformation.** Replay re-sends the stored raw
    payload through the current pipeline; #6 introduces no reshaping (#8) and no
    change to what #5 stores.
22. **(R) No sensitive-data policy.** #6 adds no field-level obfuscation,
    redaction, secret detection, or header display (all #10). The **only**
    payload-content exposure #6 adds is AC25's masked-by-default viewer with
    whole-payload reveal (Owner ruling Q-06-02a) — nothing beyond it; PRD-05
    AC16 (team-scoped, permission-gated read paths) binds every read path #6
    adds.
23. **No analytics surface.** Attempt records serve #11 later; #6 ships no
    stats, counts, or dashboards beyond the per-event delivery state its own
    surface needs.
24. **No numeric targets.** No retry-latency, throughput, or delivery-success
    SLA is asserted (V8 deferred — Owner, 2026-08-04). The AC2 policy caps
    (Owner ruling Q-06-01b) are product bounds, not performance guarantees.

## Out of Scope
Each points to the item that owns it.

- **Notifications on failure/terminal events** — #13 (consumes AC5's events).
- **Enhanced-mode toggle UI** — #7 (depends on this item).
- **Payload mapping / reshaping; replay-with-modified-payload** — #8. Replaying
  an *edited* payload is not in the roadmap at all — not built, not designed for.
- **Field obfuscation, sensitive-header policy, verification tokens** — #10.
  #6's whole-payload mask + reveal (AC25, Owner ruling Q-06-02a) is
  presentation, not policy: no field-level detection, classification, or
  redaction moves forward; #10's scope is untouched.
- **Analytics / stats dashboards** — #11.
- **Test payloads** — #14 (a test-payload send is not a replay).
- **Retention window changes, per-plan retention, record pruning** — settled at
  #5 (V5, D1); #6 changes nothing about retention itself.
- **Replay to arbitrary/ad-hoc URLs or non-current destinations** — the R4
  ruling is specific destinations or all, from the proxy's destination model.
- **Cross-team or cross-proxy replay** — everything stays team-scoped (PRD-02).
- **Scalable transport beyond Redis** — V3, unchanged (ADR-005 seam).

## Open Questions
Question IDs Q-06-0x. **Q-06-01 and Q-06-02 are both RESOLVED by the Project
Owner (2026-08-12)** and no longer stand against this PRD; they are recorded
below with their outcomes and remain readable in `docs/questions/`. **Q-06-03
is the only open question** — technical, for the Principal Engineer; it gates
technical design only, never requirement approval.

- **Q-06-01 (Owner) — Retry mode-gating, configurable dimensions, and defaults.
  RESOLVED 2026-08-12 — (a) Option B, (b) confirmed as proposed; no longer
  blocking.** Doc:
  `docs/questions/prd-06-q-06-01-retry-policy-scope-and-defaults.md`.
  **(a)** Baseline automatic retry for **all proxies**; simple-mode proxies get
  a fixed, non-configurable system default; per-proxy configurability is
  **enhanced-mode only**. **(b)** Knobs = attempt limit + backoff strategy;
  strategies at MVP = exponential (default) + fixed interval; system default =
  5 attempts, exponential; caps = 10 attempts and total backoff span bounded
  well inside the retention window. Rendered into **AC1, AC2** and the UX
  Direction.
- **Q-06-02 (Owner) — Replay surface content, replay mode-gating, and the
  replay permission bundle. RESOLVED 2026-08-12 — (a) Owner-supplied third
  option, (b) Option A, (c) Option A; no longer blocking.** Doc:
  `docs/questions/prd-06-q-06-02-replay-surface-modes-permission.md`.
  **(a)** The surface **renders payload content behind a whole-payload mask
  with an explicit reveal** ("view password"-style, full raw payload on
  reveal); no field-level detection/obfuscation (#10 untouched); reveal gated
  only by the proxy read permission, no distinct reveal permission. **(b)**
  Replay available in **both proxy modes**. **(c)** **All three roles** hold
  the replay permission, **no Member ownership limit**. Rendered into **AC9,
  AC14, AC22, new AC25** and the UX Direction.
- **Q-06-03 (Principal Engineer, technical — OPEN; non-blocking for approval,
  gates technical design).** Doc:
  `docs/questions/prd-06-q-06-03-retry-replay-composition.md`. Confirm at #6
  technical design: (i) retry/backoff attaches at the ADR-005/ADR-011 seams such
  that AC6 holds (retrying FIFO head holds only its own line; sweeper/lease
  respects a legitimately-retrying head; the anticipated dead-letter/terminal
  status is excluded from the lowest-pending scan); (ii) the AC18 holds register
  additively on ADR-012's named-hold list and every #6 read path guards on the
  ADR-014 cleaned signal (AC17); (iii) how replay traceability (AC12 — ADR-003's
  reserved `replay_of` seam) and per-proxy retry-policy persistence (AC2) are
  modeled, and whether either is a data-model change carrying the CLAUDE.md
  Owner gate at plan time; (iv) that replay-as-new-dispatch (AC11) composes with
  ADR-011's dispatch-by-reference without new machinery. Mechanisms are the
  Principal Engineer's, not resolved here.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#6 line + build-ahead; R4; #7/#10/#11/#13
  boundaries), `docs/product/vision.md` ("Retry / replay… configurable backoff
  strategies"; enhanced-mode trio; "Payload storage. For inspection, debugging,
  and replay"), `docs/product/prd-04-queued-processing.md` (AC9–AC11 —
  redelivery ≠ retry; deferred FIFO head bound), `docs/product/prd-05-payload-storage-retention.md`
  (AC6–AC10, AC16, AC21 + Amendment A — the retention contract #6 builds on),
  `docs/product/prd-02-role-based-collaboration.md` + Q-02-01 (permission model,
  ownership rule), `docs/questions/prd-05-q-05-01-payload-inspection-surface.md`
  (Owner: surface lands at #6), `docs/architecture/adr-003-…` (attempt records;
  replay seam), `docs/architecture/adr-005-…` (retry attaches on the Action's
  job; FIFO guardrails (c)/(d)), `docs/architecture/adr-011-…` (dead-letter
  seam; replay re-dispatches from the captured row),
  `docs/architecture/adr-012-…` (three-state lookup; additive holds),
  `docs/architecture/adr-013-…`/`adr-014-…` (cleaned-signal guard; "#6 cannot
  replay a cleaned event"), `docs/standards/documentation.md`.
- **Outputs:** this PRD;
  `docs/questions/prd-06-q-06-01-retry-policy-scope-and-defaults.md`
  (RESOLVED, Owner 2026-08-12 — Option B + config confirmed);
  `docs/questions/prd-06-q-06-02-replay-surface-modes-permission.md`
  (RESOLVED, Owner 2026-08-12 — masked viewer + reveal; both modes; all roles);
  `docs/questions/prd-06-q-06-03-retry-replay-composition.md` (OPEN, Principal
  Engineer, non-blocking).
- **Dependencies:** #4 (Done — the queue retry attaches to; FIFO semantics AC6
  extends) and #5 (Done — the retrievability guarantee and cleaned-state signal
  replay builds on). #6 does **not** depend on #7, #8, #10, #11, or #13, and
  must not pre-empt them.
- **Outstanding Questions:** **Q-06-03 (Principal Engineer) only** — gates
  technical design, never requirement approval. Q-06-01 and Q-06-02 —
  **RESOLVED** (Owner, 2026-08-12; rendered into this PRD); no requirement
  question remains open.
- **Next Agent:** **Designer.** This PRD carries a UX Direction section
  (received-events surface with masked viewer + reveal, replay flow,
  retry-policy configuration), so under the mechanical routing rule it must
  clear the UX Design gate before Technical Design — after the Project Owner
  approves this PRD, which is the only gate still standing. Q-06-03 then
  travels with the Principal Engineer at Technical Design.