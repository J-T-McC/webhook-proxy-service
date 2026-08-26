# PRD: Enhanced-mode toggle

- **Status:** **Approved — amended** (Amendments A and B, Product Manager, 2026-08-25).
  Not reopened, not downgraded; the original Owner approval stands and is unchanged.
- **Author:** Product Manager
- **Date:** 2026-08-21 *(revised 2026-08-21 to fold in the Q-07-01 ruling)* ·
  **Amended:** 2026-08-25 (Amendments A and B)
- **Approved by / date:** Project Owner, 2026-08-21 *(approved as revised, incl.
  the Q-07-01 ruling in AC13/AC14/AC17 and the consistency edits to AC6, AC12,
  AC16, AC20, AC21. Q-07-02 was OPEN at approval — now **RESOLVED**, Principal
  Engineer 2026-08-25, with **ADR-018 Accepted** by the Project Owner the same day.)*
- **Amendment A / date:** **Product Manager, 2026-08-25** — AC14(b)'s no-dormant-values
  rule is **scoped to read surfaces**; the create/edit form is a write surface and may
  carry a proxy's preserved retry values so AC14's restoration promise can be kept on
  the upgrade save. Resolves `docs/questions/prd-07-q-07-03-dormant-policy-restoration-surface.md`
  (Option A). Affects **AC12** (closing sentence) and **AC14(b)** only; adds no criterion,
  removes none, renumbers none. **The Owner's Q-07-01(b) ruling is not narrowed** — see
  **§ Amendment A**.
- **Amendment B / date:** **Product Manager, 2026-08-25** — factual correction to one
  UX Direction bullet: the Show page presents Mode in its **header area** (a badge), not
  in a "Details card". Raised by the Designer at the design gate (`design-07` flagged
  call 2) against the shipped page. **No acceptance criterion is touched and no
  requirement changes** — see **§ Amendment B**.
- **Backlog item:** Roadmap #7 (`docs/product/roadmap.md`)

## Feature
A team member can **switch a proxy between Simple and Enhanced mode**, and that
one choice decides — truthfully and visibly — which optional pipeline steps run
for that proxy's events.

## Definitions — two independent axes, never conflated
A proxy carries **two** unrelated mode attributes. #7 touches only the first.

| Axis | Values | Governs | Established by |
|---|---|---|---|
| **Mode** (simple/enhanced) | Simple *(default)* / Enhanced | **Which optional pipeline steps run** for the proxy's events | #1 — ADR-002 |
| **Processing** (`processing_mode`) | Async *(default)* / FIFO | **Ordering and concurrency** of dispatch | #4 — ADR-011, ADR-016 |

They are orthogonal (PRD-04 AC-boundary; PRD-04 UX Direction states this
explicitly). All four combinations are valid: a proxy may be Simple + FIFO,
Enhanced + Async, and so on. #7 changes **nothing** about Processing. Wherever
this PRD says "mode" unqualified it means the simple/enhanced axis.

## Problem
The mode concept has existed since #1 (ADR-002) and has been used as a gate by #5
and #6, but it has never been made real for the user. Four gaps:

1. **The switch is a raw field, not a feature.** `mode` is settable on the
   existing create/edit form because #1 permitted an ungated, partially-functional
   selector (PRD-01 § *Mode selector (item #1)* — pre-MVP latitude, explicitly not
   held to an acceptance bar). Nothing has ever specified what switching an
   **existing** proxy means, what it changes, or what it costs.
2. **The presentation is now untrue.** The shipped help text reads "Enhanced-mode
   behaviours (mapping, storage, retries) are not yet functional." Since #5 and #6
   that is wrong three ways: dispatched-output storage **is** enhanced-only and
   live; automatic retry applies to **every** proxy and is not enhanced-gated;
   only retry **configurability** is. `design-06` flagged this copy as stale and
   the Product Manager endorsed a correction at that gate with a copy constraint;
   #7 is where the corrected, complete presentation lands.
3. **Enhanced mode is unreachable as a decision.** #5 and #6 both deferred the
   toggle here by name (PRD-05 AC18, PRD-06 AC20). A user cannot presently answer
   "what do I get if I turn this on, and what do I lose if I turn it off" from
   anywhere in the product.
4. **The step set is about to grow.** Mapping (#8) depends on #7; multi-format
   ingestion (#9) and change detection (#12) follow. If the toggle is wired to
   today's two behaviours in an ad-hoc way, every later item re-opens it.

## What earlier items delivered vs. what #7 adds

| Concern | Owner | State |
|---|---|---|
| `mode` as a first-class, non-nullable proxy attribute, default `simple`; `PipelineFactory` reads it as the composition gate | #1 (ADR-002) | Done — the attribute #7 makes user-meaningful |
| An ungated, unspecified mode selector on the create/edit form; Mode row on Show | #1 (PRD-01 § Mode selector — pre-MVP latitude) | Done, but **specified by nothing** |
| Dispatched-output store gated on enhanced mode | #5 (PRD-05 AC12/AC14, ADR-013) | Done — enhanced-only behaviour #1 of 2 |
| Per-proxy retry-policy configurability gated on enhanced mode | #6 (PRD-06 AC2, Q-06-01) | Done — enhanced-only behaviour #2 of 2 |
| Raw capture, retention/GC, automatic retry, replay, the received-events surface | #3/#5/#6 | Done and **mode-independent** — #7 must not re-gate them |
| **Switching an existing proxy between modes as a specified, disclosed, permission-gated action** | **#7** | **This PRD** |
| **Wiring the governed-step set to the mode, extensibly** | **#7** | **This PRD** |
| Mapping as an enhanced step | #8 | Not here — #7 leaves the seam, builds nothing |
| Multi-format ingestion; change detection | #9, #12 | Not here |

## Goals
- A team member can **turn enhanced mode on or off for an existing proxy** and
  understand, before committing, exactly what changes.
- The toggle's presentation is **true on the day it ships**: it names what
  Enhanced does today and claims nothing that is not built.
- The mode decides which optional steps run — and **only** that. Every
  mode-independent guarantee established at #3/#5/#6 stays mode-independent.
- Switching is **safe**: no event in flight is lost, errored, or duplicated by a
  mode change, under either Async or FIFO processing.
- Switching is **reversible and non-destructive** — a proxy can move back and forth
  freely; a downgrade deletes no stored data and discards no saved configuration
  (Q-07-01 ruling), and what it *does* change is disclosed rather than discovered.
- The governed-step set stays **extensible**: #8, #9 and #12 add steps to it
  without re-modelling the attribute, the toggle, or the gate.
- The two mode axes stay **distinct** to the user: choosing Enhanced never implies
  anything about FIFO/Async, and vice versa.

## Users
- **Team member** — chooses a proxy's mode; needs to know what each mode gives and
  costs, and to change it later without fear.
- **Team** — mode is a property of a team-owned proxy; changing it is gated by the
  #2 permission model.
- **The product (system)** — composes each event's pipeline from the proxy's mode.
- **Upstream sender** — unaffected. The #3 response contract does not depend on
  mode and is not changed here.
- **Destination endpoint** — unaffected. Fan-out, transport, and payload structure
  are identical in both modes until #8 exists.

## User Stories
- As a team member, I want to turn enhanced mode on for an existing proxy, so I
  get its extra capabilities without recreating the proxy or losing its history.
- As a team member, I want to turn enhanced mode back off, so a proxy I no longer
  need extras on stops doing the extra work.
- As a team member, I want the mode control to tell me what Enhanced actually does
  **today**, so I am not choosing against a promise the product cannot keep yet.
- As a team member, I want to be told what a downgrade does **and does not** change
  **before** it happens, so turning a setting off is never a surprise — and so I am
  not scared off by a consequence that does not exist.
- As a team member, I want mode and processing (FIFO/Async) to read as two
  separate choices, so I do not believe Enhanced changes my delivery ordering.
- As a team member mid-traffic, I want to change mode without losing the events
  already queued or retrying, so the switch is never a reason to drop a webhook.
- As the product (system), I want mode to remain the single selector for optional
  steps, so mapping (#8) and later steps attach to it rather than adding a second
  gate.

## UX Direction
#7 is a **UI feature** — the toggle *is* the item, and it is the user's only way
to reach every enhanced capability #5, #6 and later items build. It adds no new
page: it makes two existing surfaces correct and consequential. Direction only —
screens, states, components, and final copy are the Designer's.

- **Primary flow: change the mode of an existing proxy.** From the existing proxy
  **edit** form (`design-01` Screen 2, extended by `design-04` and `design-06`),
  the member changes **Mode**, sees what that changes, and saves. The same control
  serves proxy creation, where the choice is consequence-free. Optimise for an
  **informed, reversible** decision: the user must be able to answer *what do I get
  if I turn this on* and *what changes if I turn it off* without leaving the form.
- **Enhanced reads as an additive capability set, described in present tense.**
  The control states what Enhanced enables **today** and nothing else. Binding
  copy constraints, carried forward from the Product Manager's `design-06` design
  gate: **no internal roadmap numbers** in user-facing text, and **no implication
  that payload mapping exists**. Accurate today, and the full list: Enhanced
  stores the payload actually dispatched, separately from the payload received;
  and Enhanced lets this proxy configure its own retry attempts and backoff
  strategy. Equally required, because users get it wrong: **automatic retry,
  payload capture, retention and replay apply to every proxy regardless of mode.**
- **Downgrade discloses what it changes — and what it does not — before it takes
  effect.** Under the Q-07-01 ruling a downgrade is **non-destructive**: nothing
  stored is deleted, nothing configured is discarded. The disclosure's job is
  therefore to *correct* the fear a user brings to an off-switch, not confirm it.
  Three points, all required, none allowed to be discovered afterwards: **(i)**
  enhanced-only steps (AC6) stop running for events processed after the switch;
  **(ii)** dispatched outputs already stored are **kept** and expire on their normal
  30-day schedule, unchanged (AC13); **(iii)** saved retry configuration is **kept
  but inactive** while the proxy is Simple — the system default governs meanwhile —
  and applies again, with its previous values, if Enhanced is turned back on
  (AC14). Because nothing is lost, the disclosure's weight should be proportionate:
  whether it is inline help, a confirmation step, or both, and whether the copy is
  reassuring or merely factual, is the Designer's call. The requirement is that a
  member understands those three points **before** saving. Upgrading needs no
  equivalent treatment.
- **The Show page states the proxy's mode and what it currently means.** *(B)* The
  proxy's mode is already shown on the detail page, in the **header area** — as a badge
  alongside the proxy name, not in a card. *(Amendment B: this bullet previously said
  "the Mode row already exists in the Details card"; no card of that name exists on that
  page. Factual correction only — nothing this bullet requires changes.)* It should carry
  the same present-tense
  meaning as the form, sized for a detail row — and must **not** duplicate the
  Retry policy card `design-06` already specifies; that card stays the single home
  for retry values, and Mode should reference rather than restate it.
- **A Simple proxy never shows dormant enhanced configuration.** Because AC14 lets
  a Simple proxy carry retry-policy values that have no effect, every read surface
  must show only the policy **actually in force** — for a Simple proxy, the system
  default. `design-06` Flow G step 2 already specifies exactly this (a simple-mode
  Retry policy card shows the fixed default plus a note that configurability is an
  Enhanced capability), so this is a constraint the Designer must **preserve**, not
  a change to make.
- **Keep the two axes visually and semantically separate.** Mode and Processing
  sit adjacently on the form today. The experience must make them read as two
  independent choices — not a tier and its sub-option — and Enhanced must never
  suggest anything about ordering or throughput.
- **Bring Mode up to the form's first-class field pattern.** Mode is the one field
  on this form still on the legacy treatment while Processing, Response status and
  the #6 retry fields are fully described (label + help + error wiring). As the
  headline control of this item it should match its neighbours.
- **No new surface.** No dedicated mode page, no navigation entry, no per-capability
  sub-toggles, no Index-page mode control. The form and Show page are the whole
  footprint.

This section's presence makes the **Designer gate mandatory** (routing rule; see
Handoff).

## Acceptance Criteria

> **Numbering is frozen.** Amendment A edits two criteria **in place** and adds none, so
> every existing cross-reference (ADR-018, Q-07-01/02/03, `design-07`, review-06 Minor 8)
> stays valid. Criteria amended by Amendment A are tagged **(A)**; see § **Amendment A**
> for the ruling and exactly what changed.

**The toggle**

1. **A proxy's mode can be changed after creation.** A team member can switch an
   existing proxy from Simple to Enhanced and from Enhanced to Simple. The change
   persists on the proxy and takes effect for that proxy's subsequent events
   without recreating the proxy, its destinations, its ingest URL, or its history.
2. **Mode is settable at create and at edit.** Creating a proxy selects a mode;
   editing a proxy changes it. Both paths write the same single attribute
   (ADR-002); there is no separate "upgrade" object, record, or workflow.
3. **Simple remains the default.** A proxy created with no explicit choice is
   Simple (ADR-002).
4. **Existing proxies are untouched by this item.** Every proxy that exists when
   #7 ships keeps the mode value it already has. #7 performs no reassignment,
   backfill, or bulk change of any proxy's mode, and no proxy's observable
   behaviour changes as a result of #7 alone — only as a result of a user
   subsequently changing that proxy's mode. *(No migration question arises: `mode`
   has been non-nullable with a `simple` default and user-settable since #1 —
   ADR-002, PRD-01 § Mode selector — so every existing proxy already carries an
   explicit, intended value.)*
5. **Changing mode is permission-gated, never role-gated.** Mode is proxy
   configuration, so changing it is gated by the existing team-scoped proxy
   **update** permission under the #2 model, including the Member ownership rule
   (Q-02-01) — exactly as PRD-06 AC14 gates retry-policy configuration. #7
   introduces **no new permission**. Viewing a proxy's mode is gated by the
   existing **read** permission.

**What the mode governs**

6. **Mode governs exactly the enhanced-only steps, and the set is enumerated.** As
   of #7 the complete set of behaviour conditional on Enhanced is:
   **(a)** the dispatched-output store — the payload actually dispatched is stored
   separately from the raw input (PRD-05 AC12/AC14); and
   **(b)** per-proxy retry-policy configurability — attempt limit and backoff
   strategy (PRD-06 AC2). Nothing else in the product is conditional on mode. A
   Simple proxy runs neither; an Enhanced proxy runs both. *(A Simple proxy may
   still **hold** persisted retry-policy values from an earlier Enhanced period;
   they have no effect and are not shown — AC14.)*
7. **Mode-independent behaviour stays mode-independent.** #7 introduces **no** new
   mode gate. All of the following continue to apply identically in both modes, as
   their owning PRDs established: raw payload capture (PRD-03 AC7, R2 override);
   the 30-day retention window and garbage collection (PRD-05 AC4); at-rest
   protection of stored bodies and headers (PRD-05 AC15/AC22); automatic retry
   itself under the fixed system default (PRD-06 AC1); manual replay (PRD-06 AC9);
   the received-events surface and its masked payload viewer (PRD-06 AC25); the
   decoupled upstream response (PRD-03); fan-out to all destinations with the same
   payload structure (PRD-01, R3); per-destination delivery-attempt records and
   domain events (ADR-003); Async/FIFO processing (PRD-04).
8. **Enhanced mode is not an entitlement.** Selecting Enhanced requires no plan,
   tier, subscription, quota, credit, or team-level enablement, and is not limited
   by proxy count. Billing is out of scope product-wide (vision) and retention
   tiering stays deferred (V5).
9. **The mode in force when an event is processed governs that event.** An event's
   pipeline is composed from the proxy's **current** mode at the time that event is
   processed — not from a mode captured at ingest and not from a mode captured when
   the proxy was created. *(Derived, not invented: ADR-002 has the composition gate
   read `mode` at pipeline-build time; PRD-05 AC12 conditions the output store on
   the proxy "being" in enhanced mode; PRD-06 AC11 already rules that replay runs
   through the proxy's **current** configuration. Mechanism and feasibility are the
   Principal Engineer's — Q-07-02(2).)*
10. **A mode change never loses, errors, or duplicates an in-flight event.** Events
    queued, claimed under FIFO ordering, in flight under Async, awaiting a
    scheduled retry, or mid-replay at the moment of a switch are all still
    delivered, still settle exactly once per destination per attempt (PRD-04 AC9),
    and still emit their attempt records (ADR-003). A switch is never a reason for
    a webhook to be dropped, stalled, or double-delivered, and never surfaces to
    the user as an error.
11. **Mixed treatment across a switch is a normal outcome, not a fault.** A
    consequence of AC9: an event received before a switch may be processed after
    it, so a single proxy's event history may contain events treated under either
    mode, and an event may even have one delivery attempt made under one mode and a
    later retry under the other. This is expected and must not be reported as an
    error, inconsistency, or data problem anywhere the user sees it.

**Truthful presentation**

12. **The mode control describes what each mode does today.** Wherever mode is
    presented, the description is accurate as of the shipped build: it names the
    AC6 capabilities as what Enhanced adds, and it does **not** claim, imply, or
    pre-announce any capability that is not built — payload mapping in particular.
    The currently shipped help text ("Enhanced-mode behaviours (mapping, storage,
    retries) are not yet functional") is superseded by this criterion. User-facing
    text carries **no internal roadmap numbers**. *(Carries forward the Product
    Manager's constraint recorded at the `design-06` design gate.)* **(A)**
    Truthfulness covers **state as well as capability**: a **read** surface displays
    what is actually in force for that proxy, never a stored value that has no effect
    (AC14(b)); a **write** surface — the create/edit form — displays what will be in
    force **if the member saves what it currently shows**, and must never show one
    thing while the save does another. Both readings forbid the same failure: the
    product telling a member that a value governs their proxy when it does not.
13. **A downgrade erases nothing, and the user is told so before it takes effect.**
    *(Q-07-01(a) — Project Owner ruling, 2026-08-21.)* Switching a proxy from
    Enhanced to Simple leaves every dispatched-output payload already stored for
    that proxy's past events **in place and unaltered**. Retention expiry remains
    the **only** eraser: those outputs live out their normal 30-day window and are
    erased by the existing expiry pass (PRD-05 AC4/AC5/AC11), and PRD-05 AC21's
    three payload states stay distinguishable. The downgrade introduces no second
    erasure trigger, deletes no event, delivery, or attempt record, and changes no
    retention window; the proxy simply stops producing **new** dispatched outputs
    for events processed after the switch (AC9). PRD-05's single-erasure-trigger
    lifecycle is therefore unchanged and **no PRD-05 amendment is required**. Before
    the change is saved, the user is told that nothing is deleted. Accepted
    consequence, explicitly ruled: an enhanced-produced dispatched output may
    outlive the proxy's enhanced state by up to 30 days, and that is a normal
    outcome rather than a fault — nothing may report it as one.
14. **A downgrade preserves saved retry configuration, dormant, and a later upgrade
    restores it.** *(Q-07-01(b) — Project Owner ruling, 2026-08-21; the Owner chose
    preservation over the Product Manager's recommendation to discard.)* A proxy's
    persisted retry policy (attempt limit + backoff strategy, PRD-06 AC2) is
    **kept** when the proxy is saved as Simple, is **inert** for as long as the
    proxy is Simple, and is **in force again with its previous values** if the proxy
    is later saved as Enhanced — without the member re-entering anything. Three
    obligations follow, each independently verifiable:
    **(a) Simple always resolves the system default.** While a proxy is Simple its
    retry behaviour is the fixed system default (5 attempts, exponential — PRD-06
    AC1/AC2) regardless of any persisted per-proxy values. **Nothing may resolve
    retry behaviour from persisted policy values without first establishing that the
    proxy is Enhanced.** A Simple proxy with a stored policy and a Simple proxy that
    never had one behave identically. *(Requirement only — the mechanism is the
    Principal Engineer's, routed in Q-07-02(4).)*
    **(b) (A) Dormant values are never presented as though they applied.** While a
    proxy is Simple, no **read** surface — Show, index, the event or delivery
    surfaces, or any response shaped for one of them — carries or presents its
    persisted retry-policy values. Those surfaces show only the policy actually in
    force, i.e. the system default. Presenting a dormant value there would breach AC12
    and AC16. `design-06` Flow G step 2 already satisfies this (a simple-mode Retry
    policy card shows the fixed default plus a note that configurability is an
    Enhanced capability) and needs no change.
    *The **create/edit form is a write surface**, not a read surface, and is excluded
    from this rule under exactly four conditions, all binding (Amendment A, resolving
    Q-07-03):* **(i)** it may carry the proxy's persisted retry values whatever the
    proxy's current mode, so AC14's restoration promise can be kept in the single save
    that performs an upgrade; **(ii)** while the form's Mode reads Simple it **renders
    no retry-policy value at all** — a value carried but never rendered presents
    nothing; **(iii)** once the member selects Enhanced in the form, the preserved
    values are shown as the configuration that **will** be in force on save, which is
    true, and may be tuned in that same save; **(iv)** a save made with Mode = Simple
    never changes the persisted values — it neither overwrites nor clears them. This
    is the **only** carve-out; any other response that grows a dormant retry value
    breaches this criterion.
    **(c) The disclosure states preservation, not loss.** Before a downgrade is
    saved, the user is told that saved retry configuration is kept but stops
    applying while the proxy is Simple, and applies again if Enhanced is turned back
    on.
    **`design-06` Flow F is not in conflict and is unchanged.** Flow F governs
    **in-form, in-session** behaviour — hidden retry fields still clear to their
    default sentinel so no stale value can be submitted for a Simple proxy, and
    values typed in the current session are still not restored when the member
    toggles back before saving. AC14 governs **persistence** — what an
    already-saved proxy carries across a saved mode change. Both hold
    simultaneously; neither overrides the other.
15. **Mode and Processing are presented as independent choices.** Nothing in the
    product presents the simple/enhanced axis and the Async/FIFO axis as a single
    setting, a tier and its option, or a dependency of one on the other, and all
    four combinations remain selectable (PRD-04 UX Direction).
16. **Show reflects the proxy's mode and its current meaning.** A proxy's detail
    view states which mode it is in and what that means for it today, consistent
    with AC12, without duplicating the retry-policy presentation `design-06`
    already specifies — and, for a Simple proxy, presenting the system default in
    force rather than any dormant per-proxy value (AC14(b)).
17. **Switching is unrestricted.** *(Q-07-01(c) — Project Owner ruling,
    2026-08-21.)* Subject only to AC5's permission gate and the form's normal
    validation, a proxy may be switched in **either direction, at any time, any
    number of times**, including while it has events queued, claimed under FIFO, in
    flight under Async, awaiting a scheduled retry, or mid-replay. There is no
    cooldown, no drain-before-switch requirement, no minimum dwell time in a mode,
    no cap on the number of switches, and no one-way transition. In-flight safety is
    guaranteed independently by AC10 and mixed treatment across a switch is a normal
    outcome per AC11, so the product must not block, gate, warn against, or
    discourage a switch on the grounds of outstanding deliveries.

**Extensibility**

18. **Later enhanced capabilities extend the governed set, not the model.** Adding
    a future enhanced-only step — mapping (#8), multi-format normalisation (#9),
    change detection (#12) — must be an **addition to the set of steps the mode
    governs**, requiring no change to the mode attribute itself, no second or
    alternative gate, no per-capability sub-toggle, and no change to the toggle
    surface beyond describing the new capability per AC12. Mode remains a pure
    selector and is never widened or reinterpreted (ADR-002). *(Requirement only —
    step composition is the Principal Engineer's per the roadmap #7 build-ahead;
    see Q-07-02.)*

**Scope boundaries**

19. **No mapping.** #7 builds no reshaping, no map editor, no map selection, and no
    expected-structure capture — all #8. Enhanced mode gains mapping when #8 ships,
    by AC18, not here.
20. **No new storage or retention behaviour.** #7 adds no storage toggle separate
    from mode (PRD-05 AC14 stands), no retention control, no change to the 30-day
    window, GC, or the at-rest floor. Q-07-01(a) resolved with **no** new retention
    behaviour: a downgrade is not an erasure trigger and retention expiry remains
    the sole eraser (AC13), so PRD-05's lifecycle is carried forward unamended.
21. **No change to retry or replay semantics.** The Q-06-01 rulings stand unchanged:
    automatic retry for all proxies on the system default; configurability
    enhanced-only; exponential/fixed-interval; default 5; cap 10; span bounded
    inside the retention window. #7 changes none of these values, adds no strategy,
    and changes nothing about replay (PRD-06 AC9–AC14, AC25). AC14's dormant-policy
    rule changes no value either: a Simple proxy is governed by exactly the system
    default it would have had if no policy had ever been saved.
22. **No change to processing mode.** Async/FIFO behaviour, defaults, ordering
    guarantees, and its form control are untouched (PRD-04). #7 asserts only that
    the two axes stay independent and legible (AC15).
23. **No third mode and no sub-toggles.** The axis stays exactly two values. #7
    introduces no per-capability on/off switches within Enhanced (e.g. "storage on,
    retry config off") and no intermediate mode.
24. **No notifications, analytics, or audit surface for mode changes.** #7 emits and
    displays no history of who changed a mode when; notifications are #13,
    analytics #11.
25. **No numeric targets.** #7 asserts no throughput, latency, switch-propagation,
    or delivery-success number (V8 remains deferred — Owner, 2026-08-04).

## Out of Scope
Each points to the item that owns it.

- **Payload mapping / reshaping, map selection, expected-structure capture** — #8
  (depends on this item). Enhanced mode acquires it there, by AC18.
- **Multi-format (XML / form-encoded) ingestion** — #9.
- **Change detection** — #12.
- **Field-level obfuscation, sensitive-header policy, verification tokens** — #10.
  Mode gates none of these and #7 adds no sensitive-data behaviour.
- **Analytics / stats surfaces, including any per-mode breakdown** — #11.
- **Notifications, including any alert on mode change** — #13.
- **Test payloads** — #14.
- **Retention window changes, per-plan retention, subscription tiers, billing** —
  V5 and the vision's Out of Scope; #7 adds no entitlement (AC8).
- **Retry policy values, strategies, defaults, or caps** — settled at #6 (Q-06-01);
  #7 re-opens none of them.
- **Async/FIFO processing behaviour** — settled at #4; a separate axis (AC22).
- **Bulk or team-wide mode changes, team-level mode defaults, mode templates** —
  not in the roadmap; #7 is per-proxy only.
- **Audit trail / change history for proxy configuration** — not in the roadmap at
  any item; not built and not designed for here.

## Amendment A — AC14(b) is scoped to read surfaces (Product Manager ruling on Q-07-03, 2026-08-25)
Amends the Approved PRD; does **not** reopen it. The PRD stays **Approved**. Recorded per
`docs/standards/documentation.md` (amend in place, retain history, never rewrite ratified
content silently). Doc:
`docs/questions/prd-07-q-07-03-dormant-policy-restoration-surface.md` (**RESOLVED**,
Option A).

### The conflict
The Principal Engineer, resolving Q-07-02, found that two clauses of AC14 could not both
hold literally on the **one save that performs a Simple → Enhanced upgrade**. AC14's lead
sentence requires the preserved policy to be in force again **"with its previous values …
without the member re-entering anything"**. AC14(b), as originally written, forbade a
dormant value on **"no read surface — Show, index, form, event or delivery surface, or any
response shaped for one"**. The edit form is the only place an upgrade happens and was
named in both: withhold the values from it and the upgrade save submits "unconfigured",
destroying what the downgrade preserved — unless the server overrides the submission, in
which case the form told the member one thing and the system did another (an AC12 breach).

### The ruling — Option A
**AC14(b) binds every surface that presents a value as the policy in force. The create/edit
form is a write surface and is not one of them.** It may carry the proxy's persisted retry
values whatever the proxy's current mode, subject to four binding conditions now written
into AC14(b): nothing rendered while the form's Mode reads Simple; the preserved values
shown only once Enhanced is selected, as what **will** be in force on save; tunable in that
same save; and a Simple-mode save never changing the persisted values. Every other surface —
Show, Index, the event and delivery surfaces, and any response shaped for one of them —
is bound exactly as before.

### The reasoning, in short
1. **"Form" was the Product Manager's word, not the Owner's.** The Owner's Q-07-01(b)
   ruling reads *"The Show page and any read surface must not present them while the proxy
   is simple."* AC14(b)'s enumeration was the PM's rendering of that ruling, and it swept a
   write surface into a rule written about read surfaces. Correcting the PM's own rendering
   is the PM's to do and reverses no Owner decision.
2. **Only Option A delivers what the Owner ruled.** The Owner chose preservation so that
   *"the setting stays as reversible in effect as it is in appearance."* Restoring
   server-side while the form displays "System default" is reversible in effect but not in
   appearance, and costs the member a second save to tune.
3. **It resolves an AC12 tension without creating an AC12 breach.** A form that shows one
   configuration while the save writes another is precisely what AC12 forbids.
4. **The clause's purpose is untouched.** AC14(b) exists to stop a member believing a
   dormant value governs their proxy. Under this ruling no surface, at any moment, presents
   a value as though it applied.
5. **Cost, named and accepted:** a Simple proxy's preserved values travel in the edit
   form's data, unrendered, to a member who already holds the AC5 update permission. The
   Principal Engineer assessed the exposure as security-neutral (Q-07-02(5)).

### What changed in this PRD
| Item | Change |
|---|---|
| AC14(b) | **Scoped.** Binds read surfaces (Show, Index, event/delivery, and responses shaped for them) exactly as before. The create/edit form is excluded as a write surface, under four binding conditions written into the criterion. |
| AC12 | Closing sentence split by surface kind: a read surface displays what **is** in force; a write surface displays what **will be** in force if saved, and may never show one thing while the save does another. |
| AC14 lead sentence, AC14(a), AC14(c), AC13, AC16, AC17, AC21 | **Unchanged.** In particular the restoration promise and the "Simple always resolves the system default" rule are untouched. |
| Criteria added / removed / renumbered | **None.** |

### What this amendment does **not** do
- It does **not** narrow AC14's restoration promise. Option C in Q-07-03 — narrowing the
  promise — would have reopened the **Project Owner's** Q-07-01(b) ruling and was
  explicitly not taken.
- It does **not** change what governs behaviour. AC14(a) and ADR-018 stand: a Simple proxy
  resolves the fixed system default regardless of any persisted value, and a dormant value
  governs nothing, ever.
- It does **not** relax the read-surface rule by one word, and creates no precedent for a
  second carve-out. The edit form's data is the only exclusion.

### Consequences downstream — not edited here
- **`design-07`** — Screen 1's restore-on-upgrade behaviour is **correct as specified** and
  its Q-07-02 dependency (2) is discharged. Screen 2's dependency is discharged the other
  way: under **ADR-018 Decision 4** the Show payload must not carry dormant values, so the
  client-side gate specified there is not the enforcement point. Returned to the Designer
  as a required correction at the design gate.
- **`plan-07`** — the edit-form data shape and the upgrade write rule are unblocked.
  ADR-018's resolution gate, retained `prohibited_if:mode,simple`, and don't-write-on-Simple
  rule are unchanged, exactly as ADR-018 Decision 3 anticipated.

## Amendment B — the Show page presents Mode in its header, not a "Details card" (Product Manager, 2026-08-25)
Amends the Approved PRD; does **not** reopen it. The PRD stays **Approved**. A factual
correction to the Product Manager's own text, made on the record rather than silently, per
`docs/standards/documentation.md`.

**What was wrong.** The UX Direction bullet *"The Show page states the proxy's mode and
what it currently means"* opened with "The Mode row already exists in the Details card."
No card of that name exists on the proxy detail page — Mode is a header `Badge` beside the
proxy name (`design-01`, unchanged by `design-04`). The Designer verified this against the
shipped page and flagged it at the design gate (`design-07`, flagged call 2).

**What changed.** That one sentence, tagged **(B)** in the UX Direction. Nothing else.

**What did not change.** Every requirement the bullet carries stands verbatim: the detail
view states the mode's present-tense meaning, **sized for a detail row**, and must **not**
duplicate `design-06`'s Retry policy card — that card stays the single home for retry
values, and Mode references rather than restates it. **AC16 is untouched.**

**Consequence at the design gate.** `design-07` renders the meaning as a one-line caption
under the header rather than a dedicated card. That is the correct reading of what this
bullet requires, and it was **accepted** at the design gate (PM ruling, 2026-08-25). The
"Details card" phrasing must not be read by any downstream agent — Principal Engineer,
Task Planner, Senior Developer, or Reviewer — as requiring a new card on that page; doing
so would also breach the UX Direction's standing "no new surface" mandate.

## Open Questions
Question IDs Q-07-0x.

- **Q-07-01 (Project Owner) — Mode-switch consequences. RESOLVED 2026-08-21; no
  longer blocking.** Doc:
  `docs/questions/prd-07-q-07-01-mode-switch-consequences.md`. **(a) Retain** —
  a downgrade erases nothing; existing dispatched outputs expire on their normal
  30-day schedule via the existing pass; retention expiry stays the only eraser and
  **PRD-05 needs no amendment** → AC13. **(b) Preserved dormant and restored**
  *(Owner ruling against the PM's recommendation to discard)* — a persisted retry
  policy is kept when the proxy is saved as Simple, is inert while Simple (a Simple
  proxy always resolves the system default of 5 attempts, exponential, regardless of
  persisted values), is never presented while Simple, and applies again on a return
  to Enhanced → AC14, with the mode-gated resolution mechanism routed to Q-07-02(4).
  **(c) Unrestricted** — either direction, any time, any number of times, including
  with events queued, retrying, or in flight → AC17. `design-06` Flow F is
  unaffected: the ruling governs persistence, Flow F governs in-form, in-session
  behaviour (see AC14).
- **Q-07-02 (Principal Engineer, technical) — Mode-gated step composition,
  in-flight mode resolution, and extensibility. RESOLVED 2026-08-25 (Principal
  Engineer); all five confirmations hold, no data-model change, and the mechanism is
  **ADR-018** (Accepted, Project Owner, 2026-08-25 — partially supersedes ADR-015
  Decision 3). One clause proved self-conflicting on one path and returned as Q-07-03,
  below.** Doc:
  `docs/questions/prd-07-q-07-02-mode-step-composition.md`. Confirm at #7 technical
  design: (1) the AC6 set is gated on the single ADR-002 selector and AC18's
  extensibility holds for #8/#9/#12; (2) AC9's current-mode-at-processing rule
  holds under queued dispatch, with no stale or inconsistent mode read within one
  event; (3) AC10/AC11 switch safety across ADR-011/016 FIFO claim state, Async
  in-flight jobs, scheduled retries, in-flight replays, ADR-012 holds and the
  ADR-014 cleaned guard; (4) a simple-mode proxy always resolves the system-default
  retry policy regardless of any persisted value (AC14); (5) whether anything here
  is a data-model change carrying a `CLAUDE.md` Owner gate at plan time — the PM
  expects none, since `mode` already exists and is already settable. Step
  composition is the Principal Engineer's per the roadmap #7 build-ahead and is not
  designed here. Item (4) is now firm rather than provisional: the Q-07-01(b) ruling
  makes a persisted-but-dormant policy a real state a Simple proxy can be in, so the
  mode-gated resolution rule of AC14(a) is a requirement the technical design must
  satisfy, not a possibility to weigh.
- **Q-07-03 (Product Manager) — How a preserved retry policy reaches an upgrade save,
  given AC14(b). RESOLVED 2026-08-25 (Product Manager, as the Owner's proxy for
  requirement scope); no longer blocking.** Doc:
  `docs/questions/prd-07-q-07-03-dormant-policy-restoration-surface.md`. Raised by the
  Principal Engineer out of Q-07-02(4): AC14's restoration promise and AC14(b)'s
  no-dormant-values rule could not both hold literally on the upgrade save. **Option A**
  — AC14(b) binds **read** surfaces; the create/edit form is a **write** surface and may
  carry the preserved values, under four binding conditions now written into AC14(b).
  Folded in as **Amendment A** (AC12 closing sentence + AC14(b); no criterion added,
  removed, or renumbered). The Owner's Q-07-01(b) restoration promise is **not** narrowed;
  AC14(a) and ADR-018 are untouched.

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#7 line + build-ahead — "wires steps to
  the mode rather than re-modelling either", extensible to #8/#9/#12, "Principal
  Engineer owns step composition"; #8/#9/#10/#11/#12/#13 boundaries; V5, V8),
  `docs/product/vision.md` ("Mode toggle. Simple proxy vs. enhanced mode (mapping,
  payload storage, retry strategy)"; "Pipeline-oriented architecture"; billing out
  of scope), `docs/product/prd-01-walking-skeleton.md` (§ *Mode selector (item #1)*
  — the ungated, unspecified selector this PRD specifies; AC11),
  `docs/product/prd-04-queued-processing.md` (AC4–AC7 + UX Direction — the
  `processing_mode` axis this item must stay orthogonal to),
  `docs/product/prd-05-payload-storage-retention.md` (AC4, AC11, AC12, AC14, AC15,
  AC18, AC21, AC22 + Amendment A — the enhanced-only output store and the
  mode-independent retention contract),
  `docs/product/prd-06-retry-replay.md` (AC1, AC2, AC9, AC11, AC14, AC20, AC25 —
  enhanced-only retry configurability, mode-independent retry/replay),
  `docs/product/prd-02-role-based-collaboration.md` + Q-02-01 (update permission,
  ownership rule), `docs/questions/prd-06-q-06-01-retry-policy-scope-and-defaults.md`
  (the Owner ruling that made retry configuration enhanced-only),
  `docs/design/design-06-retry-replay.md` (Screen 5 — the flagged stale Mode help
  text and the PM copy constraint carried into AC12; Flow F — in-form, in-session
  clearing, not in conflict with AC14's persistence rule; Flow G step 2 — the
  simple-mode Retry policy card showing only the default in force, which AC14(b)
  requires be preserved),
  `docs/architecture/adr-002-simple-enhanced-mode-attribute.md` (the attribute, the
  gate, the "#7 is a state change, not a re-model" mandate),
  `docs/architecture/adr-001-ingest-delivery-pipeline-spine.md`,
  `docs/architecture/adr-011-per-proxy-fifo-dispatch-mechanism.md` /
  `adr-016-fifo-composition-under-retry-and-replay.md` (the separate axis; claim
  state AC10 must survive), `docs/standards/documentation.md`.
- **Outputs:** this PRD (incl. **Amendment A**, 2026-08-25);
  `docs/questions/prd-07-q-07-01-mode-switch-consequences.md` (**RESOLVED**, Project
  Owner, 2026-08-21 — folded into AC13/AC14/AC17 and the UX Direction);
  `docs/questions/prd-07-q-07-02-mode-step-composition.md` (**RESOLVED**, Principal
  Engineer, 2026-08-25 → **ADR-018** Accepted, Project Owner, 2026-08-25);
  `docs/questions/prd-07-q-07-03-dormant-policy-restoration-surface.md` (**RESOLVED**,
  Product Manager, 2026-08-25 — Option A, folded in as Amendment A).
- **Dependencies:** #5 (Done) and #6 (Approved through plan; in implementation) —
  #7's governed-step set (AC6) is exactly what those two items built, so #7's
  acceptance depends on #6 landing. Every decision #7 rests on is already frozen:
  PRD-06 Approved (Owner 2026-08-12), Q-06-01 resolved, ADR-015/016/017 Accepted,
  design-06 PM-approved, plan-06 PE-certified. #7 does **not** depend on #8, #9,
  #10, #11, #12 or #13 and must not pre-empt them.
- **Outstanding Questions:** **none — all three are resolved.** **Q-07-01 (Project
  Owner) — RESOLVED 2026-08-21**; AC13, AC14 and AC17 are concretely testable and the
  downgrade-disclosure copy is writable. **Q-07-02 (Principal Engineer) — RESOLVED
  2026-08-25**, mechanism recorded in ADR-018 (Accepted, Project Owner, same day); no
  data-model change, so #7 carries no further Owner gate from technical design.
  **Q-07-03 (Product Manager) — RESOLVED 2026-08-25**, folded in as Amendment A;
  `design-07`'s two flagged dependencies are both discharged by it.
- **Next Agent:** **Designer.** This PRD carries a UX Direction section (the mode
  control on the existing create/edit form, the downgrade disclosure, the Show
  page's Mode presentation, and the no-dormant-values constraint), so under the
  mechanical routing rule it must clear the UX Design gate before Technical Design —
  once the Project Owner approves this PRD. Q-07-02 then travels with the Principal
  Engineer at Technical Design.
