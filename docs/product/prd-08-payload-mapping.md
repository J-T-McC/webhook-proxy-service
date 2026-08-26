# PRD: Payload mapping / reshaping

- **Status:** **Approved (Project Owner, 2026-08-26).** M1 (`Q-08-01`) and M2
  (`Q-08-02`) were RESOLVED by the Project Owner on 2026-08-26 and are folded in below;
  every acceptance criterion is concretely testable. Approving this PRD **ratified the
  four PM-derived requirement calls D-08-1..4** (§ Open Questions) — the Owner approved
  it "as written", with those four explicitly presented. Overruling any of them remains a
  single-criterion edit, not a reopening. **Next gate: the Designer** — `## UX Direction`
  is present and the design spec must be PM-approved before Technical Design.
- **Author:** Product Manager
- **Date:** 2026-08-25 · **Revised:** 2026-08-26 — the Owner's Q-08-01 (M1) and
  Q-08-02 (M2) rulings rendered into **AC12, AC13, AC14, AC15**, into **AC11** and
  **AC16** (both re-checked against the concrete rulings, one corrected — see the AC
  preamble), and into new **AC17** (the condition model's extensibility, made
  reviewer-checkable). Scope-boundary criteria renumbered by one from AC18 onward; no
  criterion was removed and no requirement changed by the renumbering. UX Direction's
  map-set bullet rewritten now the selection model is settled.
- **Approved by / date:** Project Owner, 2026-08-26
- **Backlog item:** Roadmap #8 (`docs/product/roadmap.md`), refined 2026-07-30 by
  Project Owner insight (multiple maps per proxy, one selected per event).
- **Build-ahead status:** written while **#7 is in Implementation**, deliberately and
  on the same precedent #7 itself used while #6 was in flight. Every #7 decision this
  PRD rests on is frozen and approved — PRD-07 (Approved 2026-08-21) + Amendments A/B,
  `design-07` (PM-approved), `plan-07` (PE-certified), `tasks-07` (Planner-certified),
  **ADR-018** (Accepted, Project Owner, 2026-08-25). This PRD is written against those
  decisions, never against speculation about #7's implementation.

## Feature
A team member can **reshape an incoming JSON payload into the structure a proxy's
destinations expect**, using a no-code editor driven by the proxy's **known/expected
incoming structure**. A proxy holds **many maps**; **at most one is selected per incoming
event** — the first, in a member-defined order, whose key/value condition matches, else
the **global/default map**, else none — and the single reshaped result is delivered to
**every** destination in the same structure (R3).

## Definitions
Fixed vocabulary; every criterion below uses these words exactly.

| Term | Meaning |
|---|---|
| **Map** | A named, declarative JSON→JSON reshaping definition owned by one proxy. Input: the incoming payload's JSON representation. Output: the structure delivered to destinations. |
| **Selection condition** | The path + operator + value condition that makes a map eligible for a given event (roadmap example: `type == "CHARGE"`). Form settled by **M2** → AC12, AC17. |
| **Default map** | The map applied when no conditional map is selected — a **pure fallback**, never a competitor in the evaluation order (M1(a) → AC13). At most one per proxy; not required. Called "global/default" in the roadmap and vision. |
| **Selected map** | The map chosen for one event: the **first** conditional map in the proxy's member-defined order whose condition matches, else the default map, else **none** (M1 → AC13–AC15). |
| **Reshaped payload** | The output of applying the selected map. One per event, identical for all destinations (R3). |
| **Expected incoming structure** | The proxy's first-class, persisted record of the payload shape it expects to receive. Drives the editor's autocomplete and validation (vision); is what #12 later compares against and what #9 later feeds. **Not** a map, and not a by-product of one. |
| **Mode** | The simple/enhanced axis (ADR-002). Never the `processing_mode` (Async/FIFO) axis — the two stay orthogonal (PRD-07 § Definitions, AC15). |

## Problem
Payload reshaping is **one of the two halves of the product's core pain** (vision
§ Problem: "Fan-out" and "Payload reshaping", "roughly equal parts"). Seven items have
shipped; the second half does not exist yet. Concretely:

1. **A destination must accept the sender's shape or nothing.** Today a proxy fans out
   the payload it received, unchanged, to every destination (PRD-01, R3). The vision's
   motivating case — a company migrating SaaS tools, whose existing ingestion endpoints
   expect a structure the new provider no longer sends — is unserved.
2. **One ingest URL carries many payload shapes.** Stripe posts `charge.succeeded`,
   `invoice.paid` and others to a single URL. A single map per proxy cannot express
   this, which is why the Owner refined R3 on 2026-07-30 into *one map **selected** per
   event*. Nothing in the product performs that selection.
3. **The proxy has no notion of what it expects to receive.** The vision requires
   "a JSON editor with autocomplete driven by a known incoming structure, and validation
   of pasted/raw JSON against known structures". No such structure is captured anywhere
   today, and two later items are blocked on it: #9 must normalise XML/form-encoded input
   *into it*, #12 must detect changes *against it*.
4. **Enhanced mode advertises a capability set that is one short.** PRD-07 AC12 forbids
   even implying mapping exists, precisely because it does not. #7 built the toggle and
   `PipelineFactory` carries a reserved `#8 MapStep` seam; nothing occupies it.

## What earlier items delivered vs. what #8 adds

| Concern | Owner | State |
|---|---|---|
| Fan-out of one payload structure to all destinations | #1 (R3) | Done — the structure #8 changes is *what* is fanned out, never *to whom* |
| `mode` as the single pure selector; `PipelineFactory` composition gate | #1 (ADR-002) | Done — #8 adds a step to the enhanced branch, re-models nothing (PRD-07 AC18) |
| Raw capture, immutable and mode-independent | #3/#5 (ADR-010/014) | Done — mapping reads it, never mutates it |
| Dispatched-output store, enhanced-only, divergence-gated | #5 (ADR-013) | Done — the reshaped payload is what it stores; divergence stops being the exception |
| Retry, replay-through-current-configuration, the received-events surface | #6 (AC9–AC11, AC25) | Done — #8 adds no parallel path |
| The user-facing mode toggle and its truthful-presentation rules | #7 (AC12, AC18) | Done — #8 is the **first** capability added to the governed set under AC18 |
| Resolution-time vs. composition-time mode gating | ADR-018 | Accepted — #8 must state which side it sits on (routed: Q-08-03) |
| **Maps as first-class per-proxy configuration, and their no-code editor** | **#8** | **This PRD** |
| **Per-event map selection with a default fallback** | **#8** | **This PRD** — precedence settled by M1, condition form by M2 (both Owner-ruled 2026-08-26) |
| **The proxy's expected incoming structure, as a first-class thing** | **#8** | **This PRD** (roadmap build-ahead, explicitly) |
| XML / form-encoded ingestion normalised to the same JSON representation | #9 | Not here — #8 leaves the seam |
| Structure-change detection and its notification | #12 / #13 | Not here — #8 provides the thing compared against |
| Submitting a test payload through the pipeline | #14 | Not here — #8's editor validates, it does not send traffic |

## Goals
- A member can make a proxy deliver **the structure its destinations expect**, without
  code, scripting, or an external tool.
- A single proxy handles **many incoming payload shapes** on one ingest URL, choosing
  one map per event.
- The proxy's **expected incoming structure is a first-class, persisted thing**, so #9
  can feed it and #12 can compare against it without a second representation
  (roadmap #8 build-ahead, verbatim).
- Mapping is **additive and reversible**: a proxy with no matching map behaves exactly
  as it did before #8 shipped, and turning Enhanced off destroys no authored map.
- Reshaping never becomes a way to **lose a webhook**. Capture precedes mapping and is
  unaffected by it (vision success signal: "No mapping, processing, or code error should
  fail before capture").
- Mapping stays **inside the scope boundary**: conditional map *selection*, never
  conditional destination *routing*; no scripting, no external lookups, no workflow
  builder (vision § Explicitly Out of Scope).
- The step set added here **extends** PRD-07's governed set under AC18 — no second gate,
  no per-capability sub-toggle, no change to the `mode` attribute.

## Users
- **Team member (map author)** — defines the proxy's expected incoming structure,
  authors maps, sets the condition that selects each one, and needs to know a map is
  right before real traffic meets it.
- **Team member (operator)** — needs to see which map was applied to a given event when
  a destination complains.
- **Team** — maps are configuration on a team-owned proxy; authoring them is gated by
  the existing #2 permission model.
- **The product (system)** — selects one map per event and applies it, once, before
  delivery.
- **Upstream sender** — unaffected. The #3 decoupled response does not depend on mapping
  and is not changed here.
- **Destination endpoint** — receives the reshaped payload. Every destination of a proxy
  still receives the **same** structure for a given event (R3).

## User Stories
- As a team member, I want to reshape an incoming payload into the structure my
  destination expects, so I can point a new provider at an endpoint I cannot change.
- As a team member, I want several maps on one proxy and a rule that picks one per event,
  so a single Stripe ingest URL can serve `charge.succeeded` and `invoice.paid`
  differently.
- As a team member, I want a default map for events no condition matches, so an
  unrecognised event type still has defined behaviour.
- As a team member, I want the editor to know what my proxy receives — autocomplete on
  the incoming fields and validation against them — so I build a map without guessing
  key paths.
- As a team member, I want to know which map was applied to a given event, so a wrong
  output is a five-minute diagnosis rather than a hunt.
- As a team member, I want an unexpected extra property in an incoming payload to be
  handled gracefully rather than to error, so senders can evolve without breaking me
  (vision § Target Users).
- As a team member, I want turning Enhanced off and on again to leave my maps intact, so
  a mode change is as reversible in effect as it is in appearance (Owner rationale,
  Q-07-01(b)).
- As the product (system), I want mapping to attach to the existing mode selector and the
  existing pipeline seam, so #9 and #12 attach the same way.

## UX Direction
**#8 is a UI feature — the editor *is* the item.** The vision specifies the experience,
not merely the outcome: "a **no-code / low-code** experience … a JSON editor with
**autocomplete** driven by a known incoming structure, and **validation** of pasted/raw
JSON against known structures". This section's presence makes the **Designer gate
mandatory**: `design-08` must be written and Product-Manager-approved **before**
Technical Design. Direction only — screens, states, components, and copy are the
Designer's.

- **Primary flow: author a map for a proxy.** From a proxy the member can view, the
  member reaches a mapping surface, picks or creates a map, and defines the output
  structure by drawing on the proxy's **expected incoming structure**. Optimise for
  *"a technically inclined member builds a correct map without learning a transformation
  syntax"* — the incoming fields are offered (autocomplete), the output is validated as
  it is built, and a mistake is caught at authoring time rather than discovered as a
  destination failure hours later.
- **The expected incoming structure is visible and establishable, not implicit.** The
  member can see what the proxy believes it receives and can set or update it. It is
  presented as a property **of the proxy**, not of any one map — later items read it
  (#9 writes into it, #12 compares against it), so it must not look like a field of the
  editor. How a member establishes it (from a payload the proxy has actually received —
  those exist and are already surfaced by PRD-06 AC25 — or from a sample they supply) is
  AC10; which of those is the primary affordance is the Designer's call.
- **Managing the set: which map, when — now settled, and the order is not optional.** A
  proxy holds many maps, so the surface must make the **selection story** legible at a
  glance: which condition selects which map, which map is the default, and **the order in
  which conditional maps are evaluated**. The Owner ruled that order is part of the model
  and **must be visible wherever maps are managed — never hidden or implicit** (AC14), and
  that first match wins; the member must be able to see and change it. The default map
  reads as the **fallback**, visibly outside the ordered list, never as a competitor within
  it (AC13). A proxy with **no** default map is a normal, supported state and must not be
  presented as misconfigured (AC15) — but a member should be able to tell, from this
  surface, that unmatched events will be delivered unreshaped.
- **The condition control has three parts, not two.** Per AC12 and AC17 the condition is
  path + **operator** + value, with `equals` the only operator guaranteed at MVP. The
  operator is **shown, not implied**, even while there is only one — the Owner ruled the
  model must accept further operators without a refactor, and a two-field control that
  hides equality is exactly the refactor being ruled out. Design it so a second operator,
  and later a second condition on the same map, are additions rather than a redesign.
- **Validation, not live traffic.** The editor validates against the expected structure
  and shows the member the output shape a map produces. It does **not** submit anything
  to a real destination — sending a payload through the pipeline is **#14**, and must not
  be built here.
- **Unexpected properties read as normal, never as errors.** An incoming payload
  carrying fields the expected structure does not know about is a routine event
  (vision § Target Users, AC21) — as is an event that matches **no map at all**, which is
  delivered unreshaped by the Owner's ruling (AC15). Nothing in the experience may present
  either as a fault. Detecting and *notifying* about structural change is **#12**, not here.
- **Truthful presentation, inherited unchanged from #7.** PRD-07 AC12 binds this item:
  a **read** surface shows what is actually in force; a **write** surface shows what will
  be in force if the member saves what it shows. For a **Simple** proxy that holds
  preserved maps (AC5), no read surface may present those maps as governing its events
  (AC6) — the same rule `design-06` Flow G step 2 and `design-07` already satisfy for the
  dormant retry policy, and the same Amendment A distinction applies: an authoring
  surface is a write surface.
- **Which map ran, on the event.** When an operator looks at a received event, the
  outcome must be attributable — which conditional map ran, that the default ran, or that
  **no map** ran (AC16, three outcomes). The received-events
  surface **already exists** (`design-06`, PRD-06 AC25) — extend it; do not build a second
  event surface, and do not duplicate the payload viewer, its mask, or its reveal
  behaviour (those are settled at #6 / Q-06-02 and are #10's to change, not #8's).
- **Copy constraint, carried forward and now partially retired.** `design-06`'s and
  PRD-07 AC12's binding constraint — "no implication that payload mapping exists" — is
  retired **by this item shipping and by nothing earlier** (AC26). The other half stands
  unchanged: **no internal roadmap numbers in user-facing text**, and no claim of a
  capability that is not built (#9's XML/form-encoded input and #12's change detection in
  particular).
- **No workflow builder.** The vision excludes an iPaaS / Zapier-style canvas explicitly.
  A map is a declarative shape, not a graph of steps, and the experience must not read as
  the first screen of a workflow tool.
- **No per-destination anything.** Nothing in this experience may offer a
  per-destination map, a per-destination condition, or a choice of which destinations an
  event reaches. That is conditional routing and it is out of scope product-wide (AC29).

## Acceptance Criteria

> **All criteria are concrete and testable.** M1 and M2 are resolved (Project Owner,
> 2026-08-26); the `PENDING` tags are gone. Two consequences of rendering the rulings,
> recorded rather than made silently:
> - **AC11 is corrected, not merely re-checked.** It read *"Exactly one map is applied per
>   event"*. The Owner's Q-08-01(c) ruling makes **zero** maps a reachable, deliberate
>   outcome, so "exactly one" is now false. AC11 reads **at most one**. This reconciles
>   with the roadmap's "per event exactly one map is chosen", which presumed a default map
>   always exists — the Owner has now ruled that it need not.
> - **AC16 holds and is sharpened.** Determinism is no longer merely asserted: it now
>   follows from AC12's comparison rules plus AC14's explicit order, and attribution
>   enumerates the three reachable outcomes.
>
> Numbering shifted by one from AC18 onward to make room for AC17. Nothing downstream
> cites these numbers yet (no design, plan, tasks or review artifact exists for #8), and
> the three `Q-08-0x` docs were updated in the same change.

**Maps as first-class proxy configuration**

1. **A proxy owns a set of maps.** A proxy may hold zero, one, or many maps. Each map
   belongs to exactly one proxy, is individually identifiable by a member-supplied name,
   and can be created, edited, and deleted without affecting the proxy's ingest URL,
   destinations, events, or history.
2. **A map is declarative JSON→JSON, authored no-code.** A map takes the incoming
   payload's JSON representation and produces a JSON structure. It contains no code, no
   script, no expression evaluated against anything outside the incoming payload, and
   performs no external lookup or network call (vision § Explicitly Out of Scope). Pure
   JSON-to-JSON is the whole of the MVP (vision § What It Must Do).
3. **Map management is permission-gated, never role-gated.** Creating, editing, and
   deleting a proxy's maps and its expected incoming structure is gated by the existing
   team-scoped proxy **update** permission under the #2 model, including the Member
   ownership rule (Q-02-01) — exactly as PRD-06 AC14 gates retry configuration and PRD-07
   AC5 gates the mode. Viewing them is gated by the existing **read** permission. #8
   introduces **no new permission** and no new role.
4. **Mapping is enhanced-only, and is the first addition to PRD-07's governed set.**
   Reshaping runs for a proxy in **Enhanced** mode and never for a proxy in **Simple**
   mode; a Simple proxy delivers exactly what it delivers today. This extends the
   enumerated set at PRD-07 AC6 to three items — dispatched-output store, retry
   configurability, **mapping** — under PRD-07 AC18, and must require **no** change to the
   `mode` attribute, no second or alternative gate, no per-capability sub-toggle, and no
   new toggle surface (ADR-002; ADR-018 Decision 6).
5. **A downgrade preserves maps and the expected structure; an upgrade restores them.**
   *(**PM-derived — D-08-1**, from the Owner's Q-07-01(a)+(b) rulings and PRD-07
   AC13/AC14. Not an independent Owner decision; the Owner may overrule at approval.)*
   Saving a proxy as Simple **keeps** its maps and its expected incoming structure intact
   and unaltered, leaves them **inert** while the proxy is Simple, and puts them **in
   force again, unchanged**, if the proxy is later saved as Enhanced — with nothing
   re-authored. A downgrade deletes no map. *Basis: the Owner ruled preservation for
   persisted retry values on the stated rationale that "an accidental downgrade must not
   silently destroy tuned configuration, and the setting stays as reversible in effect as
   it is in appearance"; a hand-authored map is a strictly stronger instance of the same
   thing, and PRD-07 AC13 already forbids a downgrade being an erasure trigger.*
6. **Dormant mapping configuration is never presented as being in force.**
   *(**PM-derived — D-08-2**, mirroring PRD-07 AC12 + AC14(b) and ADR-018 Decision 4.)*
   While a proxy is Simple, no **read** surface presents its preserved maps or expected
   structure as governing its events; read surfaces show what is actually in force, which
   for a Simple proxy is unreshaped delivery. The **authoring** surface is a **write**
   surface and may show the preserved configuration — under the same conditions
   Amendment A binds: it states what **will** be in force if the proxy is Enhanced, never
   what is in force now, and a Simple-mode save never silently alters it. No mapping
   value may reach a read surface presented as effective when it is not.
7. **Maps are proxy-scoped and team-scoped.** A map is reachable only through its proxy
   and only by members of that proxy's team (R1). #8 introduces no shared map library, no
   cross-proxy reuse, no map templates, and no import/export.

**The proxy's expected incoming structure**

8. **The expected incoming structure is a first-class, persisted property of the proxy —
   not a by-product of a map.** It exists independently of how many maps the proxy holds
   (including zero), survives the deletion of any map, and is the single representation
   later items read. *(Roadmap #8 build-ahead, verbatim: "Capture the proxy's
   known/expected incoming structure as a first-class thing here — not just a
   transform".)*
9. **The expected structure drives authoring.** The editor's autocomplete offers the
   incoming fields it describes, and validation of a map — and of raw/pasted JSON — is
   performed against it (vision § Target Users, § What It Must Do). A proxy with no
   expected structure established can still be given maps; authoring is then simply
   unaided. Mapping never becomes unavailable for want of an expected structure.
10. **A member can establish and update the expected structure.** *(**PM-derived —
    D-08-3**; the roadmap requires the structure to be first-class but does not state how
    it comes to exist. The Owner may overrule at approval.)* It can be established from a
    payload the proxy has actually received (retained events already exist and are already
    surfaced — PRD-05, PRD-06 AC25) **or** from a JSON sample the member supplies, and it
    can be updated later. It is never inferred and silently overwritten by incoming
    traffic: changing what a proxy expects is a member's act. *(Detecting that live traffic
    has drifted from it is **#12**, not here.)*

**Selecting a map per event — settled by M1 and M2 (Project Owner, 2026-08-26)**

11. **At most one map is applied per event.** For any incoming event a proxy applies
    **one map or none** (AC15), and never composes, chains, or merges two.
    *(Corrected from "exactly one": the Q-08-01(c) ruling makes zero a reachable,
    deliberate outcome. The roadmap's "per event exactly one map is chosen" presumed a
    default map always exists; the Owner has ruled that it need not.)*
12. **A map is selected by a condition matching a key path against a value.**
    *(Q-08-02 / M2 — Project Owner ruling, 2026-08-26, Option A with a binding
    extensibility requirement; see AC17.)* Each conditional map carries a selection
    condition with these exact semantics:
    - **Path — dot notation into nested objects**, e.g. `type`, `data.object.status`.
      **Object keys only; no array indexing** at MVP — `items[0].sku` is not addressable.
    - **Operator — carried explicitly, as its own named part of the condition.**
      **`equals` is the only operator guaranteed at MVP.** An operator that is implied
      rather than stated does not satisfy this criterion (AC17).
    - **Comparison — exact and case-sensitive.** `"CHARGE"` does not match `"charge"`.
      Scalars compare **by type and value**: a number matches a number, a boolean a
      boolean, a string a string; `42` does not match `"42"`; a scalar never matches an
      object or an array.
    - **Absent key — never matches, and is never an error.** A condition whose key path is
      not present in the incoming payload simply does not match; the event falls through
      to AC13/AC15. This is a normal outcome and may not be reported as a fault (AC21).
    - **Shape — a condition set, and named parts.** A map carries a **condition set**,
      even while that set holds exactly one condition, and each condition carries **path,
      operator and value as separate named parts**. A bare path/value pair with equality
      implied does not satisfy this criterion (AC17). At MVP a conditional map's set holds
      **exactly one** condition.
    - A map carrying **no** condition is not a conditional map: it is either the proxy's
      default map (AC13) or it is never selected.
13. **The default map is a pure fallback.** *(Q-08-01(a) / M1 — Project Owner ruling,
    2026-08-26, Option A.)* A proxy may designate **at most one** of its maps as the
    global/default map. Conditional maps are evaluated **first** (AC14); the default is
    applied **only** when no conditional map is selected. A conditional match always beats
    the default, and the default can never pre-empt a conditional map. A proxy is **not
    required** to have a default map — AC15 governs its absence.
14. **Conditional maps are evaluated in a member-controlled explicit order; the first
    match wins.** *(Q-08-01(b) / M1 — Project Owner ruling, 2026-08-26, Option A.)* A
    proxy's conditional maps carry an explicit order that is **part of the model** — not
    an artefact of storage, creation time, or name — settable and changeable by a member
    holding the AC3 update permission, and **visible wherever the maps are managed; it may
    never be hidden or implicit** (Owner ruling, stated as a requirement). Evaluation walks
    that order and **stops at the first condition that matches**; later matching maps are
    not applied (AC11). Deliberate overlap is therefore a **supported configuration, not an
    error**: nothing rejects a save because two conditions could match the same event, and
    no runtime multi-match state exists to report.
15. **No conditional map matches and there is no default map ⇒ deliver unreshaped, and
    record that no map was applied.** *(Q-08-01(c) / M1 — Project Owner ruling,
    2026-08-26, Option A.)* The proxy delivers the payload exactly as it received it — the
    behaviour it had before #8 shipped — and the event records that **no map was applied**
    (AC16). Mapping is therefore strictly **additive**: authoring a map for one event type
    never changes what happens to any other event type, and a proxy whose maps all fail to
    match behaves identically to a proxy with no maps at all. This is **not** a mapping
    failure and must not be presented as one (AC22 governs actual failures).
    **Accepted consequence, explicitly ruled by the Owner:** a destination that can only
    accept the reshaped structure will reject the unreshaped payload. That surfaces as an
    ordinary **delivery** failure through the existing #6 retry-and-terminal-failure path
    (PRD-06), not as a mapping error, and nothing anywhere may report it as a mapping
    fault or as a data problem.
16. **Selection is deterministic and attributable.** The same incoming payload, against the
    same set of maps in the same order, always selects the same map — no dependence on
    storage-order accidents, timing, retry attempt number, or which destination is being
    delivered to. AC12's comparison rules and AC14's explicit order together make the
    outcome a property of the **configuration**, not of the run. For any received event a
    member can determine which of exactly three outcomes occurred, and which map was
    applied: **(i)** a conditional map, named; **(ii)** the default map; **(iii)** no map
    (AC15). Non-deterministic or unattributable selection is a defect.
17. **The condition model extends without a refactor — verifiably.** *(Q-08-02 / M2 —
    Project Owner ruling, 2026-08-26, stated verbatim as "one key path but with forward
    looking to adding additional conditions so its not a refactor later".)* "Additional
    conditions" is read as covering **both** axes — more **operators** on a condition, and
    eventually more than **one condition** per map — and the model must foreclose neither.
    The Reviewer checks this against the shipped model, not against intent:
    **(a)** every persisted and every emitted condition names its **operator explicitly**;
    no condition is operator-less or operator-implied anywhere it is stored, returned, or
    displayed.
    **(b)** a map's conditions are represented as a **set** at every layer — persistence,
    API, and UI — so a one-condition map has the same shape a two-condition map would;
    adding a second condition later changes **no** persisted shape and **no** emitted
    field.
    **(c)** the selection algorithm's contract is *"does this condition match this
    payload"*, with the operator selecting the comparison. Nothing in selection, storage,
    validation, or presentation assumes the operator is `equals`; nothing renders a
    condition by assuming equality rather than by rendering its operator.
    **(d)** consequently, adding `one-of`, `not-equals` or `exists` later requires **no
    migration of existing maps, no change to the selection contract, and no change to how
    a condition is presented**. A model that would require any of the three fails this
    criterion.
    **(e) Delegated latitude, with its bound — no further Owner ruling needed.** Whether
    **any** operator beyond `equals` ships in the first pass is **the implementor's call**,
    conditional on (a)–(d) making it genuinely cheap. Shipping only `equals` is fully
    acceptable. Any operator that does ship must be **completely** specified on AC12's
    terms — absent-key behaviour, case sensitivity, and type semantics — and must be
    presented in the UI; a half-shipped operator is a defect. *For the record: `not-equals`
    materially raises the likelihood of two maps matching one event, which is defined
    behaviour under AC14's first-match-wins order and needs no additional rule.*
    **(f)** Combination semantics for a multi-condition map (AND / OR, and any precedence
    between them) are **not decided at #8** and are not built here (AC30). The model must
    simply not preclude them.

**Applying the selected map**

18. **One reshaped payload per event, identical to every destination (R3).** The selected
    map is applied **once per event**, and the single result is delivered to every
    destination of that proxy in the same structure. Mapping is per-proxy, never
    per-destination (R3, PRD-01). It is not a routing decision: **which** destinations an
    event reaches is unchanged by #8 (AC29).
19. **The raw captured input is never mutated by mapping.** Mapping reads the captured
    raw payload and produces a new structure; the stored raw input remains byte-identical
    to what arrived (R2, PRD-03 AC7, ADR-010/014), so replay of the raw payload and the
    #5 lifecycle are unaffected.
20. **The stored dispatched output is the reshaped payload.** For an Enhanced proxy, what
    #5 stores as the dispatched output (PRD-05 AC12/AC14, ADR-013) is what was actually
    sent — i.e. the reshaped payload — so a member can compare received against sent. #8
    adds no second store and no new retention behaviour; the 30-day window, GC, at-rest
    protection, and the three payload states (PRD-05 AC4/AC5/AC11/AC15/AC21/AC22) are
    untouched. *(ADR-013 gates the stored body on divergence from the raw input; mapping
    makes divergence the normal case rather than the exception — a consequence flagged to
    the Principal Engineer as `Q-08-03(3)`, not a change to this criterion.)*
21. **Unexpected incoming properties never cause an error.** An incoming payload
    containing fields the expected structure does not describe, or missing fields a map
    references, is handled gracefully and never raises a user-visible error, aborts
    delivery for that reason alone, or is reported as a data fault (vision § Target Users:
    "unexpected properties are handled gracefully rather than causing errors"). What a map
    produces for a referenced-but-absent field must be defined and stable. The same holds
    for an absent **selection** key path, which simply does not match (AC12).
22. **A mapping failure never loses the event, and never silently ships the wrong shape.**
    *(**PM-derived — D-08-4**; consequence of the vision's headline success signal.)* Raw
    capture happens **before** mapping and is unaffected by any mapping outcome ("No
    mapping, processing, or code error should fail before capture"). If the selected map
    cannot be applied, the event remains captured, retained, visible and replayable; the
    failure is attributable to that event (AC16); and a partially or incorrectly reshaped
    payload is **not** delivered. Once the map is corrected, the existing replay path
    (PRD-06 AC9–AC11) re-sends through the **current** configuration.
    *A mapping failure is a **selected map that cannot be applied**. It is not the AC15
    case, where no map was selected and unreshaped delivery is the correct ruled outcome.*
23. **The upstream response is unaffected.** The #3 decoupled response — its status code,
    body, and timing — does not depend on whether a map was selected, applied, or failed
    (PRD-03; PRD-04). Mapping is invisible to the upstream sender.

**Interaction with settled items**

24. **#8 introduces no new mode gate and re-gates nothing.** Everything PRD-07 AC7
    enumerates as mode-independent stays mode-independent: raw capture; retention and GC;
    at-rest protection; automatic retry under the system default; manual replay; the
    received-events surface and its masked viewer; the decoupled response; fan-out to all
    destinations with the same structure; per-destination attempt records and domain
    events (ADR-003); Async/FIFO processing. Mapping is gated by the single ADR-002
    selector and by nothing else.
25. **Replay and retry use the proxy's configuration in force at the time they run.**
    PRD-06 AC11 already rules that replay runs through the proxy's **current**
    configuration, and ADR-018 Decision 5 already forbids snapshotting mode. It follows —
    and must be stated, not discovered — that an event replayed after a map is edited is
    reshaped by the **new** map, and that a proxy's event history may contain events
    reshaped by different versions of its configuration. Exactly as PRD-07 AC11 rules for
    mode, this is a **normal outcome, not a fault**, and nothing may report it as an error
    or inconsistency. *(Whether an in-flight **retry** of an already-dispatched delivery
    re-applies mapping or re-sends the payload already reshaped for that delivery is a
    composition question routed to the Principal Engineer — `Q-08-03(4)`.)*
26. **The mode control gains mapping, and the copy constraint retires here.** With #8
    shipped, the enhanced-mode description required by PRD-07 AC12 names mapping as
    something Enhanced does **today** — the `design-06`/PRD-07 constraint forbidding any
    implication that mapping exists is discharged by this item and by nothing earlier. The
    rest of PRD-07 AC12 stands verbatim: no internal roadmap numbers, and no claim of a
    capability that is not built — **XML/form-encoded ingestion (#9) and change detection
    (#12) in particular must not be implied**.

**Extensibility**

27. **#9 feeds the same maps and the same expected structure.** Multi-format ingestion
    must be able to normalise XML and form-encoded input into the **same JSON
    representation** this item's editor, expected structure, and maps already consume —
    adding a normalisation step ahead of mapping, with **no second mapping path, no second
    editor, and no format-specific map** (roadmap #9 build-ahead). #8 builds none of it and
    must foreclose none of it.
28. **#12 compares against this structure; #14 exercises this path.** Change detection
    compares an incoming payload against the expected incoming structure defined here —
    there is no second structure for it to compare against — and a test payload (#14) runs
    through the same selection-and-mapping path as real traffic, not a mock one (roadmap
    #12/#14 build-aheads). #8 builds neither.

**Scope boundaries**

29. **No conditional destination routing.** #8 selects **which map applies**, never
    **which destinations receive an event**. Every destination of a proxy receives the
    same reshaped payload for a given event (R3, AC18). Per-destination maps,
    per-destination conditions, destination filtering, and event-type-based routing are
    **out of scope product-wide** (vision § Explicitly Out of Scope; roadmap § Notes on
    Scope Boundaries) and are not built, designed for, or hinted at here.
30. **No scripting, expressions, external lookups, or workflow builder.** Beyond the
    condition model AC12 and AC17 define, #8 introduces no expression language, no
    user-supplied code, no function library, no lookup against anything outside the
    incoming payload, no AND/OR combination of conditions (AC17(f)), and no
    iPaaS/Zapier-style canvas.
31. **No non-JSON ingestion.** XML and form-encoded input remain unsupported at ingest
    until **#9**. #8 reshapes the JSON representation only.
32. **No change detection and no notifications.** Detecting that an incoming structure has
    drifted from the expected structure is **#12**; alerting anyone about anything is
    **#13**. #8 emits no mapping alert and displays no drift state.
33. **No sensitive-data, analytics, or test-payload behaviour.** Field-level obfuscation,
    encryption policy and verification tokens stay **#10** (the mask/reveal behaviour
    settled at Q-06-02 is unchanged); per-map or per-event analytics stay **#11**;
    submitting a payload through the pipeline stays **#14**. #8 adds no audit trail or
    version history for maps — no roadmap item claims one.
34. **No numeric targets.** #8 asserts no throughput, latency, payload-size, map-count, or
    mapping-duration number (V8 remains deferred — Owner, 2026-08-04).

## Out of Scope
Each points to the item that owns it.

- **Conditional destination routing / per-destination payloads** — out of scope
  **product-wide**, not deferred to a later item (vision; roadmap § Notes on Scope
  Boundaries). AC18 and AC29.
- **XML and form-encoded ingestion** — **#9**. #8 leaves the normalisation seam (AC27).
- **Change detection against the expected structure** — **#12**. #8 provides the thing
  compared against (AC8), nothing more.
- **Notifications of any kind, including on mapping failure** — **#13**.
- **Test payloads / submitting traffic from the editor** — **#14**. The editor validates;
  it does not send (UX Direction, AC33).
- **Field-level obfuscation, sensitive-field policy, verification tokens, encryption
  changes** — **#10**.
- **Analytics, per-map success/failure statistics** — **#11**.
- **Retention, GC, or storage-shape changes** — settled at **#5**; #8 changes none of them
  (AC20).
- **Retry/replay semantics, values, or surfaces** — settled at **#6**; #8 reuses them
  unchanged (AC25). An unreshaped payload a destination rejects under AC15 is an ordinary
  #6 delivery failure and adds nothing to #6's scope.
- **The mode toggle, its surfaces, or any mode semantics** — settled at **#7**; #8 adds
  one capability to the governed set under AC18 and touches nothing else (AC4, AC26).
- **Combining conditions (AND / OR) on a single map** — not decided and not built at #8
  (AC17(f), AC30). The model must not preclude it; nothing here specifies it.
- **Operators beyond `equals`** — *not* out of scope: whether any ship in the first pass is
  the implementor's delegated call under AC17(e), bounded by AC12's full-specification
  requirement. Listed here only so it is not mistaken for an exclusion.
- **Shared/global map libraries, templates, import/export, map version history** — no
  roadmap item claims them (AC7, AC33).

## Open Questions
Question IDs Q-08-0x. **No question blocks approval.** Both roadmap-level open items are
resolved; the one remaining question is technical and travels to Technical Design.

- **Q-08-01 (Project Owner) — Map-selection precedence & fallback. `= roadmap M1`.
  RESOLVED 2026-08-26; no longer blocking.** Doc:
  `docs/questions/prd-08-q-08-01-map-selection-precedence-fallback.md`. **(a) Option A** —
  the default map is a **pure fallback**; conditional maps evaluate first → AC13.
  **(b) Option A** — a **member-controlled explicit order** over conditional maps, **first
  match wins**, the order part of the model and **visible, never hidden** → AC14.
  **(c) Option A** — no conditional match and no default map ⇒ **delivered unreshaped**,
  with the event recording that no map was applied; mapping stays additive → AC15. The
  Owner accepted the named consequence: a destination that requires the reshaped structure
  rejects the unreshaped payload, surfacing through the existing #6 retry/terminal-failure
  path rather than as a mapping failure. **Roadmap M1 is closed by this ruling.**
- **Q-08-02 (Project Owner) — Map-selection matching syntax. `= roadmap M2`. RESOLVED
  2026-08-26; no longer blocking.** Doc:
  `docs/questions/prd-08-q-08-02-map-selection-matching-syntax.md`. **(a) Option A, with a
  binding extensibility requirement** — one key path per map at MVP, but modelled so that
  further conditions are "not a refactor later": an **explicit operator field from day
  one**, a **condition set** even at count one, and operator additions that require no
  migration and no contract change → **AC12 + AC17**. Whether any operator beyond `equals`
  ships in the first pass is **delegated to the implementor**, bounded by AC12's
  full-specification rule → AC17(e); this is stated latitude, **not** an open question.
  **(b) Option A** — dot notation, object keys only, no array indexing. **(c)** all three
  PM recommendations accepted — case-sensitive exact matching; absent key never matches and
  is never an error; typed scalar comparison. **Roadmap M2 is closed by this ruling.**
  *PM note on interpretation, flagged rather than assumed:* "additional conditions" is read
  as covering **both** more operators **and** eventually more than one condition per map,
  and AC17 is written to keep both open. If the Owner meant only one axis, AC17 narrows —
  it does not otherwise change.
- **Q-08-03 (Principal Engineer, technical) — Mapping's gate under ADR-018, the
  expected-structure model, and the mapping/storage/retry interactions. OPEN —
  non-blocking for approval; travels to Technical Design.** Doc:
  `docs/questions/prd-08-q-08-03-mapping-composition-and-expected-structure.md`. Raised
  because ADR-018 draws a line between enhanced-only **steps** (gated structurally by
  composition) and enhanced-only **configuration** (gated at resolution time), and mapping
  has both a step and per-proxy configuration — **which side it sits on is a technical
  call the Product Manager will not make**. Also carries: the expected structure's model
  and whether #8 triggers a `CLAUDE.md` data-model Owner gate at plan time (the PM
  **expects one** — unlike #7, this item plainly adds persisted entities); ADR-013's
  divergence gate under routine divergence; whether a retry re-applies mapping; and
  feasibility of AC22's failure semantics. *Unaffected by the M1/M2 rulings — items (1),
  (2), (3), (4) and (5) stand exactly as raised; item (6) is now answerable, since the
  selection rules it asked the PE to confirm determinism against are concrete.*

**PM-derived requirement calls — the only items still awaiting an Owner position.**
Stated, not invented: each is derived from an existing Owner ruling or from the vision, is
named in the criterion it drives, and is listed here so the Owner approves with them
visible rather than buried. **None has ever been put to the Owner**, which is why this PRD
remains Draft rather than Approved. Approving the PRD ratifies all four; overruling any one
of them is an edit to a single criterion, not a reopening.

| ID | Criterion | Derived from |
|---|---|---|
| **D-08-1** | AC5 — a downgrade preserves maps and the expected structure, dormant; an upgrade restores them | Owner ruling Q-07-01(a)+(b) and PRD-07 AC13/AC14 — preservation over destruction on a reversible setting |
| **D-08-2** | AC6 — dormant mapping configuration is never presented as in force; the authoring surface is a write surface | PRD-07 AC12 + AC14(b) as scoped by Amendment A; ADR-018 Decision 4 |
| **D-08-3** | AC10 — the expected structure is established by the member from a received payload or a supplied sample, never silently inferred from traffic | Vision ("validation of pasted/raw JSON against known structures"); #12 owns drift detection, so #8 must not pre-empt it |
| **D-08-4** | AC22 — a mapping failure loses nothing and never ships a partial reshape | Vision success signal ("No mapping, processing, or code error should fail before capture"); PRD-06 AC9–AC11 replay-through-current-configuration |

## Handoff
- **Inputs:** `docs/product/roadmap.md` (#8 line + build-ahead — multiple maps, one
  selected per event, global/default fallback, expected structure "as a first-class thing",
  selection-not-routing; § Notes on Scope Boundaries; **M1**, **M2**; R3 as refined
  2026-07-30), `docs/product/vision.md` (§ Problem "Payload reshaping"; § Target Users
  no-code editor with autocomplete + validation; § What It Must Do "Payload mapping /
  reshaping"; § Explicitly Out of Scope; § How We'll Know It's Succeeding),
  `docs/product/prd-01-walking-skeleton.md` (fan-out, same structure to all destinations),
  `docs/product/prd-03-decoupled-upstream-response.md` (AC7 raw capture; the response
  contract mapping must not touch),
  `docs/product/prd-05-payload-storage-retention.md` (AC4/AC5/AC11/AC12/AC14/AC15/AC21/AC22
  + Amendment A — the dispatched-output store mapping fills and the retention contract it
  must not change), `docs/product/prd-06-retry-replay.md` (AC9–AC11 replay through current
  configuration; AC25 the received-events surface #8 extends; Q-06-02 the mask/reveal
  settlement #8 must not touch), `docs/product/prd-07-enhanced-mode-toggle.md` (AC6 the
  governed set #8 extends; AC7 mode-independence; AC11 mixed treatment as a normal outcome;
  AC12 truthful presentation and the copy constraint; **AC18** extensibility; AC19 "#7
  builds no mapping"; Amendments A and B),
  `docs/questions/prd-07-q-07-01-mode-switch-consequences.md` (the preservation ruling
  D-08-1 derives from), `docs/architecture/adr-002-simple-enhanced-mode-attribute.md`
  ("#8 gates the map step likewise"; `mode` stays a pure selector),
  `docs/architecture/adr-001-ingest-delivery-pipeline-spine.md`,
  `docs/architecture/adr-018-mode-gated-resolution-of-enhanced-configuration.md`
  (Decisions 1, 4, 5 and **6** — the recipe every later enhanced capability follows;
  `#8 MapStep` named at its reserved position),
  `docs/architecture/adr-010-raw-payload-capture.md` /
  `adr-014-captured-entity-erasure-and-header-encryption.md` (raw immutability),
  `docs/architecture/adr-013-dispatched-output-store.md` (the divergence gate),
  `docs/design/design-06-retry-replay.md` (the events surface and Flow G step 2),
  `docs/design/design-07-enhanced-mode-toggle.md`, `docs/standards/documentation.md`.
- **Outputs:** this PRD;
  `docs/questions/prd-08-q-08-01-map-selection-precedence-fallback.md` (**RESOLVED**,
  Project Owner, 2026-08-26 — roadmap M1 closed; folded into AC13/AC14/AC15 and AC11);
  `docs/questions/prd-08-q-08-02-map-selection-matching-syntax.md` (**RESOLVED**,
  Project Owner, 2026-08-26 — roadmap M2 closed; folded into AC12 and AC17);
  `docs/questions/prd-08-q-08-03-mapping-composition-and-expected-structure.md`
  (**OPEN**, Principal Engineer — technical, travels to Technical Design).
- **Dependencies:** **#7 (Enhanced-mode toggle) — currently in Implementation.** #8's AC4
  extends PRD-07 AC6's governed set under PRD-07 AC18, so #8's acceptance depends on #7
  landing. Every #7 decision #8 rests on is already frozen and approved: PRD-07 Approved
  (Owner 2026-08-21) with Amendments A and B, Q-07-01/02/03 all resolved, ADR-018 Accepted
  (Owner 2026-08-25), `design-07` PM-approved, `plan-07` PE-certified, `tasks-07`
  Planner-certified. Also depends on #5 (Done — the dispatched-output store AC20 fills)
  and #6 (Done — the events surface AC16 extends, the replay path AC22 relies on, and the
  retry/terminal-failure path AC15's accepted consequence surfaces through). #8 does
  **not** depend on #9, #10, #11, #12, #13 or #14 and must not pre-empt them. If #7's
  review forces a PRD-07 touch-up, re-check AC4, AC6, AC24 and AC26 against it.
- **Outstanding Questions:** **none blocking.** **Q-08-01 (M1)** and **Q-08-02 (M2)** are
  **RESOLVED** (Project Owner, 2026-08-26) and rendered into AC11–AC17, so every criterion
  is concretely testable and the Designer has a settled selection model to design against.
  **Q-08-03 (Principal Engineer) — OPEN, non-blocking**; it travels to Technical Design
  exactly as Q-07-02 did for #7, and the PM expects it to surface a **data-model Owner
  gate** at plan time. The only items still awaiting an Owner position are the four
  PM-derived calls **D-08-1..4**, which the act of approving this PRD ratifies.
- **Next Agent:** **Designer**, once the Project Owner approves this PRD. This PRD carries
  a `## UX Direction` section — the no-code map editor with autocomplete and validation,
  the expected-structure surface, the map-set management view (now with a settled model:
  visible evaluation order, first-match-wins, the default as a fallback outside the order),
  the three-part condition control, and the applied-map attribution on the existing events
  surface — so under the mechanical routing rule the **UX Design gate is mandatory before
  Technical Design**, no exceptions. Q-08-03 then travels with the Principal Engineer at
  Technical Design.
