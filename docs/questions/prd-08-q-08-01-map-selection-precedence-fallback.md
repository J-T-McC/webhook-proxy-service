# Question Q-08-01: Map-selection precedence & fallback (roadmap **M1**)

- **Status:** **RESOLVED** (Project Owner, 2026-08-26) — **roadmap M1 is closed**. Folded
  into `docs/product/prd-08-payload-mapping.md` **AC13, AC14, AC15**, with **AC11**
  corrected as a consequence.
- **Raised by:** Product Manager
- **Owner (must answer):** **Project Owner** *(product decision. The roadmap raised
  M1 from the Owner's own 2026-07-30 insight and states explicitly: "must be settled
  before #8's PRD. **Not answered here.**" No vision, roadmap, PRD, ADR or prior
  ruling states these rules, and the Product Manager will not invent them.)*
- **Raised:** 2026-08-25
- **Gates:** *(when open)* **BLOCKING** for PRD-08 approval — **AC13, AC14 and AC15** of
  `docs/product/prd-08-payload-mapping.md` take their concrete behaviour from this
  answer, and the Designer cannot design the map-set management surface (which map,
  when, in what order, and what the default is) without it. AC11 ("exactly one map per
  event") and AC16 ("deterministic and attributable") hold under **every** option below
  and are not at risk. Nothing else in PRD-08 depends on this.
- **Source:** `docs/product/roadmap.md` #8 and § Open Questions **M1**: *"When a proxy
  holds multiple maps, what are the exact precedence and fallback rules for choosing one
  per event? For example: does the global/default map apply only when no conditional map
  matches, and what happens if more than one conditional map matches?"* Also R3 as
  refined 2026-07-30 (one map SELECTED per event) and `vision.md` § What It Must Do.
- **Related:** `Q-08-02` (M2 — how a condition is *expressed*; this doc is only about
  how a winner is *picked*). The two are independent: any answer here works with any
  answer there.

## Context
A proxy holds many maps because one ingest URL receives many payload shapes (Stripe:
`charge.succeeded`, `invoice.paid`, …). Per event **exactly one** map is applied
(roadmap #8; PRD-08 AC11). The roadmap fixes that a **global/default map** exists and
that conditional maps are matched by a key/value condition — but it deliberately leaves
the *rules for picking the winner* to this PRD.

Three situations have no defined behaviour today, and each is reachable by an ordinary
user with an ordinary configuration:

1. A conditional map matches **and** a default map exists.
2. **Two or more** conditional maps match the same event (e.g. one condition on
   `type` and another on `data.object.status`, both true).
3. **No** conditional map matches and the proxy has **no** default map (the natural
   state right after a member authors their first conditional map).

## Question

### (a) Precedence between conditional maps and the default map

- **Option A — the default is a pure fallback (PM recommendation).** Conditional maps
  are evaluated first; the default map is applied **only** when no conditional map is
  selected. A conditional match always beats the default. *Basis:* this is the roadmap's
  own wording — "a **global/default map** that applies when no condition matches" — and
  the simplest rule to explain in a UI ("if nothing matches, this one runs").
  *Consequence:* the default can never override a specific map, so there is no way to
  express "always use X regardless" other than deleting the conditional maps.
- **Option B — one explicit priority order over all maps, default included.** The member
  orders every map, the default among them; the first whose condition matches (the
  default matching everything) wins. *Basis:* one mental model instead of two tiers;
  makes (b) fall out for free. *Consequence:* a member can accidentally place the default
  first and silently disable every conditional map; more UI, and the "global/default"
  concept loses its distinct meaning.
- **Option C — something else the Owner names.**

### (b) More than one conditional map matches the same event

PRD-08 AC11 forbids applying both, and AC16 forbids an arbitrary winner, so a rule is
required.

- **Option A — member-controlled explicit order; first match wins (PM recommendation).**
  A proxy's conditional maps carry a visible, editable order; evaluation stops at the
  first match. *Basis:* always resolves, never surprises at 3am, never blocks a save, and
  gives the member a real tool for deliberate overlap ("this specific case first, this
  broader one after"). Deterministic by construction (AC16). *Consequence:* a new map's
  position matters, so the ordering must be visible in the UI, not hidden.
- **Option B — prevent overlap at save time.** The product rejects a map whose condition
  could match the same event as another; maps must be provably mutually exclusive.
  *Basis:* ambiguity can never occur at runtime. *Consequence:* overlap is only provable
  for trivial conditions (identical key, different values); anything richer either
  over-rejects valid configurations or gives false confidence. Blocks a save for a
  situation the member may want.
- **Option C — treat multiple matches as a selection failure at runtime.** No map is
  applied; the event follows the (c) rule. *Basis:* refuses to guess. *Consequence:* a
  configuration mistake becomes a live traffic incident rather than an authoring-time
  one — the worst timing.
- **Option D — undefined / whichever is found first.** Rejected by PRD-08 AC16; listed
  only for completeness.

### (c) No conditional map matches **and** there is no default map

- **Option A — deliver unreshaped, and record that no map was applied (PM
  recommendation).** The proxy delivers the payload exactly as it received it — the
  behaviour it had before mapping existed — and the event shows that no map was applied
  (AC16). *Basis:* mapping stays **additive**; authoring a map for one event type never
  changes what happens to every other event type; nothing is withheld from destinations
  that may well accept the raw shape; and the vision's failure-resistance stance ("handled
  gracefully rather than causing errors") points this way. #12 is the item that will
  *detect and notify* about unrecognised structures. *Consequence, named:* a destination
  that can only accept the reshaped structure receives a payload it will reject — a
  delivery failure the member sees through the existing #6 retry/terminal-failure path
  rather than a mapping failure.
- **Option B — treat as a mapping failure; do not deliver.** The event is captured,
  retained, visible and marked as unmapped-and-undelivered; nothing is sent; the member
  fixes the configuration and replays through the existing #6 path. *Basis:* never ship a
  shape a destination was not configured to receive; a destination expecting the mapped
  structure is protected. *Consequence:* a proxy that receives an unanticipated event type
  stops delivering it entirely until someone notices — and until #13 exists, nothing tells
  them. Also makes mapping non-additive: enabling it changes behaviour for events it was
  never about.
- **Option C — require a default map whenever any map exists.** Validation makes the
  situation unreachable: authoring the first conditional map obliges the member to author
  (or accept a generated pass-through) default. *Basis:* the behaviour is always explicit
  and chosen. *Consequence:* friction on the most common first action, and a
  pass-through default is just Option A written out by hand.
- **Option D — something else the Owner names.**

## PM recommendation, in one line
**(a) Option A, (b) Option A, (c) Option A** — default as pure fallback; explicit
member-controlled order with first-match-wins; unmatched events delivered unreshaped and
recorded as such. Together these keep mapping additive, keep every outcome deterministic
and explainable in the UI, and put every ambiguity in front of the member at authoring
time rather than at delivery time. The honest counterweight the Owner should weigh:
**(c) Option B is the safer answer for destinations that cannot tolerate the raw shape**,
and it is the one to choose if "never send a shape I did not configure" matters more than
"never stop delivering an event".

## Impact if unresolved
PRD-08 cannot be approved. AC13 (precedence), AC14 (multi-match) and AC15 (no match, no
default) are not concretely testable; the Designer cannot lay out the map-set management
surface, because whether an **order** is part of the model is decided here; and the
Principal Engineer cannot specify selection at Technical Design. Every other part of
PRD-08 — maps as first-class configuration, the expected incoming structure, applying the
selected map, the #5/#6/#7 interactions, extensibility, and the scope boundaries — is
unaffected by any option above.

## Answer
- **Answered By:** **Project Owner**
- **Answered:** **2026-08-26**

**(a) Precedence — Option A: the default map is a pure fallback.** Conditional maps are
evaluated first; the default applies **only** when no conditional map is selected. A
conditional match always beats the default. → **PRD-08 AC13.** A proxy may designate at
most one default map and is not required to have one.

**(b) Multiple conditional maps match — Option A: member-controlled explicit order, first
match wins.** A proxy's conditional maps carry an explicit order; evaluation stops at the
first condition that matches. **The order is part of the model and must be visible in the
UI, not hidden.** → **PRD-08 AC14.** Consequences carried into the PRD: deliberate overlap
is a *supported configuration*, nothing rejects a save on overlap grounds, and no runtime
multi-match state exists to report.

**(c) No conditional match and no default map — Option A: deliver unreshaped, and record
that no map was applied.** The proxy delivers the payload exactly as received — its
pre-#8 behaviour — and the event records that no map was applied. Mapping stays
**additive**: authoring a map for one event type never changes what happens to any other.
→ **PRD-08 AC15.**

**Consequence the Owner accepted explicitly:** a destination that can only accept the
reshaped structure will reject the unreshaped payload. That surfaces as an ordinary
**delivery** failure through the existing #6 retry-and-terminal-failure path — **not** as a
mapping failure, and nothing may report it as a mapping fault.

**Downstream:**
- **AC13, AC14, AC15** are now concretely testable; their `PENDING M1` tags are removed.
- **AC11 was corrected, not merely re-checked.** It read *"Exactly one map is applied per
  event"*; ruling (c) makes **zero** a reachable, deliberate outcome, so AC11 now reads
  **"at most one map"**. This reconciles with the roadmap's "per event exactly one map is
  chosen", which presumed a default map always exists — the Owner has now ruled it need not.
- **AC16** holds and was sharpened: determinism now follows from AC14's explicit order
  rather than being asserted, and attribution enumerates three outcomes (a named conditional
  map / the default / no map).
- **AC22** was clarified so the two are never confused: a *mapping failure* is a selected
  map that cannot be applied; the AC15 case is not a failure at all.
- The Designer can now lay out the map-set management surface — the visible evaluation
  order is a requirement, and the default map reads as a fallback outside that order.
- **Roadmap M1 is closed.** `docs/status.md` is the Orchestrator's to update.
