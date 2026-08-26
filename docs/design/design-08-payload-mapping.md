# Design Spec: Payload mapping / reshaping

- **Status:** **Approved — with nine required corrections** (design gate, delegated
  per `CLAUDE.md`). The corrections change **no structural design decision** and
  reopen **no** flagged call; they close copy, coverage and record-accuracy gaps.
  They do **not** require re-approval — land them and hand on. **Where this note
  and the spec body conflict, this note governs until the Designer lands the
  corrections** (the precedent set at the `design-06` and `design-07` gates).
  **Corrections landed: Designer, 2026-08-26** — all nine required corrections
  (C1–C9) and all three non-blocking notes are reflected in the spec body below.
  See Screen 1, Screen 3, Screen 4, Flow B, Flow C, Flow D, Flow E, Screen 7,
  Components, Open Questions, and Handoff for what changed.
- **Author:** Designer
- **PRD:** `docs/product/prd-08-payload-mapping.md` (Approved, Project Owner,
  2026-08-26 — 34 ACs; approval ratified D-08-1..4)
- **Approved by / date:** **Product Manager, 2026-08-26.** Verified against PRD-08's
  34 acceptance criteria: every UI-bearing story is covered and the UX Direction is
  honoured. The **settled selection model is rendered faithfully** — *at most* one map
  per event with **zero deliberate and reachable** (Screen 3's empty state, the
  no-default state, and Flow G's "No map applied" all exist and all read as normal);
  a **member-controlled explicit order** with position numbers, an `ol`, reorder
  controls and the first-match-wins sentence, i.e. **visible, never implicit** (AC14);
  the **default map set apart below a rule as a fallback**, at most one, never
  required, never a row in the ordered list (AC13); **no save-time overlap rejection
  and no runtime multi-match state** anywhere in the spec (AC14); and the AC15
  outcome phrased "as a fact, never a warning icon or error color". **AC17 is
  satisfied**: the condition control is genuinely three-part — Path, an explicit
  **Operator** `Select`, and Value with a companion Type select carrying AC12's typed
  comparison — so a second operator is a new `SelectItem` and a second condition is a
  second row, neither a redesign (subject to **C4**, which removes the one place the
  spec still *renders* equality by assumption). **Four attribution states** are all
  expressible and "No map applied" (muted, explicitly never a warning colour or icon)
  is visually and lexically distinct from "Mapping failed" (`destructive`-toned, the
  word "failed" in the copy) — the conflation that would have been a defect does not
  occur. **Mode and `processing_mode` are never conflated**: no copy or layout in this
  spec mentions Async/FIFO or ordering at all. **Nothing implies conditional
  destination routing** — no control anywhere offers a destination choice, a
  per-destination condition, or a per-destination map (AC29/AC18). Scope boundaries
  AC19/AC23/AC27/AC28/AC30–AC34 are met by construction, and the two out-of-scope
  temptations the spec names by their absence — a dispatched-output viewer and a
  test-send affordance — are correctly absent.

  **All eight flagged design calls are ruled on below. Seven are accepted as
  designed; one (call 5) is accepted with its record corrected. None is sent back
  for redesign, and no dependency question travels to the Project Owner.**

  **(1) The mapping surface is reachable and fully editable in both Modes —
  ACCEPTED, with three binding conditions.** This is the **faithful extension of the
  retry-policy precedent in principle, and a deliberate, correct departure from it in
  mechanism** — and the distinction matters, so it is recorded rather than blurred.
  The principle the retry precedent establishes is the one PRD-08 AC6 / D-08-2
  restates verbatim: a **read** surface shows what is in force; the **authoring**
  surface is a **write** surface and may show preserved configuration as what *will*
  be in force. That principle is honoured here. The *mechanism* differs, necessarily:
  Amendment A condition (ii) — "renders no retry-policy value at all while the form's
  Mode reads Simple" — is keyed to a **pending Mode value living on the same form**.
  The Mapping page carries no Mode control and participates in no mode-setting save,
  so condition (ii) has no term to bind to and cannot be transposed mechanically.
  Transposing its *effect* instead would also break a second thing the retry
  precedent quietly guarantees: a member can see preserved retry values **without
  saving anything**, by flipping the form's Mode to Enhanced in-session. Hiding the
  Mapping surface while Simple has no such escape hatch — it would make preserved
  maps (AC5/D-08-1) genuinely unreachable until the member commits a mode change,
  which is the opposite of "as reversible in effect as in appearance". And there is
  an asymmetry with no analogue: retry has a **default in force** to display instead
  of the dormant value; mapping has none. The truthful answer for a Simple proxy is
  "nothing is reshaped", which is exactly what the banner, the Show caption, and
  every event's Mapping row already say. Finally, AC3 gates authoring on the update
  permission and draws **no** mode line, AC4 gates *reshaping* rather than authoring,
  and ADR-018 Decision 6 forbids a per-capability gate — mode-gating the surface
  would add a gate no criterion asks for. **Binding conditions, all but one already
  met:** *(i)* the Simple-mode `Alert` is **non-dismissible**, rendered for as long as
  the proxy is Simple, never collapsed behind a "learn more", and sits **above both
  cards** so it cannot be passed unseen (the same two conditions I bound `design-07`'s
  downgrade disclosure with); *(ii)* no copy on this page may state or imply that a
  map governs events **now** while the proxy is Simple — see **C3**, the one place
  the spec still does; *(iii)* nothing on this page may be reachable or editable
  without the AC3 permission gate — see **C6**.

  **(2) Conditional-vs-Default chosen on the map editor, with a plain-`Alert`
  replace disclosure — ACCEPTED.** Map identity (what selects it) belongs with the
  condition that expresses it; splitting "make default" into a list action would put
  half of a map's selection story on a different surface. The plain-`Alert`-not-
  `AlertDialog` treatment is the correct application of `docs/standards/design.md` and
  of the `design-07` flagged-call-1 ruling: nothing is deleted, only re-pointed, and a
  confirm step would manufacture gravity the action does not have. One consequence
  the spec did not follow through — where the **demoted** default goes — is **C7**.

  **(3) Up/down reorder buttons with immediate persistence, not drag-and-drop —
  ACCEPTED.** No drag-and-drop library exists in this app, and adding one is an Owner
  dependency gate under `CLAUDE.md` that AC14 does not require: AC14 demands the order
  be **visible and changeable**, not that it be draggable. Buttons are keyboard-
  operable without extra work, which drag-and-drop is not. Immediate persistence
  follows the single-purpose-action precedent Reveal set at `design-06`, and Flow C's
  snap-back-on-failure means a failed reorder leaves the model untouched. The named
  cost — several clicks to move a map several positions — is proportionate at the map
  counts this feature implies (roughly one per incoming event shape).

  **(4) "Choose a received event" primary, "paste a sample" secondary —
  ACCEPTED.** PRD-08 UX Direction delegated this explicitly ("which of those is the
  primary affordance is the Designer's call"), and the reasoning is sound: a real
  captured event is less error-prone for a no-code author than hand-typed JSON, and
  PRD-06 AC25 already surfaces those events. The **disabled-with-reason** treatment
  when no retained events exist is the right call over hiding — it explains rather
  than silently steering, and it keeps a brand-new proxy's path obvious.

  **(5) A dual-mode output editor (row-based Builder + Raw JSON toggle) rather than
  one free-form JSON editor with live inline autocomplete — ACCEPTED as designed. No
  dependency question is escalated to the Project Owner, and none should be.** Taking
  the harder half first: the spec reports a contradiction inside PRD-08's `## UX
  Direction`, which is my own text. **There is no contradiction, and the record must
  not carry the claim that there is** (**C9**). The vision's sentence is two clauses,
  not one: *"a JSON editor with **autocomplete** driven by a known incoming structure,
  **and validation of pasted/raw JSON** against known structures"*. PRD-08 AC9 renders
  those as two separable requirements — autocomplete over the incoming fields, and
  validation of a map *and* of raw/pasted JSON — and neither the PRD nor the vision
  anywhere requires them to inhabit a single control, nor uses the words "code
  editor", "inline", or "cursor-aware". The Designer's split maps onto those two
  clauses more exactly than the unified reading does: the **Builder** is the
  autocomplete-driven authoring path (per-field, via `PathAutocomplete`), and **Raw
  JSON** is the paste-and-validate path. It also serves the PRD's stated optimisation
  target better, not worse: AC2 requires the map be **authored no-code**, and the goal
  is "without code, scripting, or an external tool" — a free-form code editor over
  hand-typed JSON is the *more* code-like of the two, not the less. So the split is
  the faithful reading of the vision's intent, and I record that reading as binding
  here so the Principal Engineer does not re-litigate it at Technical Design.
  Escalating a CodeMirror/Monaco dependency to the Owner would be asking the Owner to
  gate a dependency **no acceptance criterion requires**, on the strength of a
  contradiction that does not exist — I will not manufacture an Owner gate to cover a
  design call that is mine to make. If a code editor is later judged worthwhile for
  its own reasons (#9's richer payload shapes is the plausible one), that is a fresh
  Owner dependency gate raised then, on that item's merits; the spec is right that
  this two-mode structure and the `{{path}}` convention are additive to replace rather
  than a redesign. The named cost — no live suggestion popup while typing *inside*
  the Raw JSON textarea — is accepted: AC9's autocomplete requirement is discharged by
  the Builder and by `PathAutocomplete`, and AC9's validation requirement is
  discharged in both modes.

  **(6) `{{path}}` is illustrative UX, not a binding persistence format —
  ACCEPTED, and correctly routed.** Persistence shape is Technical Design's, not the
  Designer's and not mine; folding the note into the already-open Q-08-03 rather than
  opening a new question doc is the right handling. The binding UX contract the spec
  states is the right one and I ratify it as the design gate's output: Builder and Raw
  JSON must describe the **same** map, Raw JSON must carry **some** literal-vs-
  reference distinction, and the editor must validate it **before** Save. Whether
  `{{path}}` survives as the wire format, the stored format, or only as what the
  textarea shows is the Principal Engineer's call.

  **(7) Attribution on event detail only, no Events-list column — ACCEPTED.** This
  is the same minimalism I upheld at `design-06`'s flagged call 5 (no Index shortcut),
  and AC16's requirement is that a member **can determine** the outcome for a received
  event — one click, exactly as Reveal and Replay already are — not that it be
  scannable in bulk. Per-map/per-event aggregate views are #11's, and a list badge
  stays additive later if it proves painful.

  **(8) No dispatched-output / reshaped-payload viewer — ACCEPTED, with one
  consequence named rather than left implicit.** `design-06` named #8 by number as the
  natural place to reconsider surfacing it, so it was right to close the question here
  rather than let it drift. It closes **shut**: PRD-08's UX Direction forbids
  duplicating the payload viewer, its mask, or its reveal behaviour and rules those
  #10's to change; no criterion asks for a second viewer; and mask/reveal policy over
  a *reshaped* payload would drag #10's sensitive-field decisions into #8. The
  consequence, stated so nobody discovers it later: **AC20's clause "so a member can
  compare received against sent" is discharged as a property of what is *stored*, not
  as a shipped UI affordance.** After #8 the reshaped payload is what #5 stores and it
  is routinely divergent, but no surface displays it. That is the correct outcome
  against the criteria as written; if the Project Owner wants the comparison
  surfaced, that is a **new requirement** and a PRD edit, not a gap in this spec.
  Recorded for the Owner's awareness; it blocks nothing and needs no ruling now.

  **Nine required corrections, returned to the Designer.** None changes a structural
  decision or a flagged call; all are landable without a further round trip.
  **(C1) The Mode field's help text is missing — the largest gap in the spec.**
  Screen 1 updates the two **Show** mode-summary strings from `design-07` Screen 2(a)
  and stops there, while the Handoff claims the spec extends "Mode field/Show-caption
  copy". The create/edit form's `#mode-help` text (`design-07` Screen 1) is the
  primary place mode is described, and it currently names **two** capabilities. AC4
  extends PRD-07 AC6's governed set to **three**, and AC26 discharges the constraint
  that kept mapping out of that copy — so PRD-07 AC12's "names the AC6 capabilities"
  is no longer satisfied by the shipped string. Specify its replacement in Screen 1
  alongside the Show captions, under the constraints that still bind: present tense,
  names all three Enhanced capabilities, restates the mode-independent guarantees, **no
  internal roadmap numbers**, and **no implication of #9's XML/form-encoded ingestion
  or #12's change detection** (AC26). Final wording is yours; illustrative only —
  *"Enhanced mode stores the payload actually dispatched, separately from the payload
  received, lets this proxy configure its own retry attempts and backoff strategy
  below, and lets you reshape incoming payloads into the structure your destinations
  expect. Automatic retry, payload capture, retention, and replay apply to every proxy
  regardless of Mode."* Fix the Handoff line at the same time so it describes what the
  spec actually does.
  **(C2) The Simple Show caption must not point a member at Mapping for "what
  actually governs this proxy".** Screen 1's Simple string ends *"See Retry policy
  below and Mapping above for what actually governs this proxy."* For a Simple proxy
  **no map governs anything** — this directs a **read** surface at dormant
  configuration as though it were in force, which is precisely what AC6/D-08-2, PRD-07
  AC12 and AC14(b) forbid. The first half of the sentence is fine because a Simple
  proxy really is governed by the default retry policy; there is no mapping analogue.
  Restore `design-07`'s "See Retry policy below for what governs this proxy's
  retries." and, if mapping is mentioned at all in the Simple caption, state only that
  saved maps are kept and do not run while the proxy is Simple. The **Enhanced** string
  is correct as drafted and needs no change.
  **(C3) The Maps card's present-tense evaluation copy must not assert evaluation
  while the proxy is Simple** (binding condition (ii) of flagged call 1). Screen 3's
  *"Conditional maps are evaluated top to bottom — the first match wins"* and the
  default map's *"Runs only when nothing above matches"* are false for a Simple proxy
  and sit on the same page as the maps they describe. The banner scopes the page but
  does not make those sentences true. Render them conditionally on the proxy's mode —
  the future/conditional form while Simple ("would be evaluated…", "will run when this
  proxy is Enhanced…"), present tense while Enhanced. This is the same
  read-vs-write-surface discipline C1 of the `design-07` gate applied to Screen 2(b).
  **(C4) Screen 3 must render the condition's operator, never the literal word
  "equals".** The Screen 3 schematic writes the row as `{path} equals {value}` with
  `equals` hardcoded, which is exactly the failure mode AC17(c) names — *"nothing
  renders a condition by assuming equality rather than by rendering its operator"*.
  Restate it as `{path} {operator} {value}`, sourced from the condition. The three-part
  editor control (Screen 5) is already compliant; this is the one rendering that is
  not, and it is the single thing standing between this spec and full AC17 compliance.
  **(C5) Screen 4 — drop the pre-announcement in the array-indexing error.** *"array
  indexing isn't available yet"* pre-announces a capability that is not built and that
  no roadmap item promises; AC26 carries forward PRD-07 AC12's prohibition on claiming
  or **pre-announcing** unbuilt capability. One word: state that only object keys are
  supported, without "yet". AC12(b)'s substance is otherwise correctly rendered.
  **(C6) Every map create/edit/delete/reorder control must carry the AC3 permission
  gate, stated.** Flow A step 2 gates the expected-structure **Update** button ("same
  formula as Show's Edit/Delete — AC3"), but Flow B, Flow C, Flow E and Screen 3 show
  **Add a map**, **Edit**, **Delete** and the reorder buttons unconditionally. AC3
  gates all of them on the team-scoped proxy **update** permission including the #2
  Member ownership rule, with **viewing** on the read permission. Say so once,
  explicitly, covering the Maps card actions, the map editor's Save/Delete, and the
  reorder controls — a read-permission member reaching `/proxies/{proxy}/maps` must see
  the maps and no mutating affordance.
  **(C7) Specify what happens to a map on either side of a Conditional⇄Default
  change.** Flow D step 8 tells the member the outgoing default "keeps its output
  structure but stops being the fallback" and then abandons it. Per AC12's last bullet
  a map with no condition is **either** the default **or** never selected — so the
  demoted map lands in a state the spec never renders. Two things must be stated:
  *(a)* where a demoted default appears on the Maps card and how it reads — it may
  **not** appear in the ordered conditional list, because everything in that list is
  presented as evaluated (AC14), so either it is shown in a clearly-labelled
  never-selected grouping or the flow requires it to be given a condition; and *(b)*
  what becomes of a **conditional** map's condition and order position when it is saved
  as the Default (kept and dormant, or cleared) and what the member is told. Either
  resolution is yours; leaving the state unrendered is not, because an invisible map
  that never runs is the kind of silent configuration AC14's visibility ruling exists
  to prevent.
  **(C8) Name the Mapping row's rendering for an event carrying no recorded mapping
  outcome.** Screen 7 defines four states, each presuming a recorded outcome; every
  event processed **before #8 ships** has none, and those events do not disappear.
  "No map applied — delivered unreshaped" is truthful for them (nothing was applied and
  they were delivered as received) and is my preferred resolution; a neutral `—` is
  acceptable if Technical Design finds the outcome genuinely unknowable. What is not
  acceptable is leaving implementation to guess, or a fifth state that reads as a
  fault. State it in Screen 7's States.
  **(C9) Restate flagged call 5 so the record does not assert that PRD-08 contradicts
  itself.** Per the ruling above, the UX Direction's autocomplete clause and its
  pasted/raw-JSON validation clause are two separable requirements, both discharged by
  the dual-mode design; the perceived contradiction came from reading "a JSON editor"
  as necessarily one code-editor control. Keep the feasibility reasoning, the named
  cost, and the note to Q-08-03 exactly as they are — they are the valuable part.
  Replace only the framing that the PRD asks for something unbuildable. **PRD-08 needs
  no amendment; no criterion or wording is wrong**, and this note is the record of the
  reading.

  **Three non-blocking notes, Designer's discretion.** *(i)* Flow G's failure copy —
  "the event was captured but nothing was delivered by this attempt" — asserts a
  delivery outcome **Q-08-03(5)** has not settled (AC22's feasibility is still with the
  Principal Engineer). Prefer wording guaranteed by AC22 itself (captured, retained,
  replayable, and no incorrectly-reshaped payload delivered) over a claim about
  attempts, and re-check the string once Q-08-03 resolves. *(ii)* Sourcing the
  **Operator** and **Type** option sets from a `data/` const following this app's
  existing `DataOption` convention (as `proxyRetryBackoffStrategies.ts` does) would
  make AC17(e)'s "adding an operator is cheap" true in the UI layer by construction
  rather than by intention — worth a line in Components. *(iii)* Screen 5 requires the
  map name to be unique within the proxy; AC1 requires only that a map be individually
  identifiable by a member-supplied name, so uniqueness is your addition. It is a good
  one — attribution (AC16) names maps to operators — but say it is a design decision
  rather than implying AC1 mandates it.

  **No requirement gap was found beyond C1, and no PRD-08 criterion needs amending.**
  Q-08-03 remains open, non-blocking and correctly routed; nothing in this spec depends
  on its resolution, and the two implementation-level notes it adds to that thread
  (calls 5 and 6) belong there rather than in a new question doc.

> **Scope note.** #8 is the map editor, the expected-incoming-structure
> surface, the map-set management view, and applied-map attribution on the
> existing events surface (PRD-08 UX Direction). It is **map selection**,
> never destination routing (AC29): one reshaped payload still goes to every
> destination (R3, AC18). It does not touch the Response, Destinations, or
> Ingest URL cards, retry policy, replay, FIFO, or any #5/#6/#7 surface beyond
> the two small copy updates AC26 requires (Screen 6). It builds no dispatched-
> output content viewer (AC20; UX Direction forbids duplicating the #6
> payload viewer), no test-payload/send affordance (#14), no XML/form-encoded
> input (#9), and no drift/change-detection state (#12).

## Decisions carried forward from Q-08-01/Q-08-02 (not re-litigated here)
Owner rulings (2026-08-26), already rendered into PRD-08; restated so this
spec's layout reads as a consequence, not an invention:
- **Default is a pure fallback** (AC13). Conditional maps evaluate first; the
  default applies only when none match. At most one default per proxy, never
  required.
- **Conditional maps carry a member-controlled explicit order; first match
  wins** (AC14). The order **must be visible wherever maps are managed** —
  never hidden. Deliberate overlap is supported; nothing blocks a save on it.
- **No match, no default ⇒ deliver unreshaped, record no map applied** (AC15).
  Additive by construction; not a fault.
- **Condition = path + operator + value, three named parts** (AC12/AC17).
  `equals` is the only MVP operator, but it is **shown**, never implied — a
  two-field control is non-compliant. Path is dot-notation, object keys only,
  case-sensitive, typed-scalar comparison, absent key never matches.
- **A map's conditions are a set, even at count one** (AC17b) — the UI must
  not assume a maximum of one forever, but AC17(f)/AC30 mean **no UI is built
  here for a second condition or for AND/OR**; that is explicitly undecided.

## Overview
From a proxy's **Show** page, a member reaches a new **Mapping** surface
(mirrors the existing **Events** entry point) covering two proxy-level things:
the proxy's **expected incoming structure** (a flattened field list, established
from a received event or a supplied sample, used everywhere below for
autocomplete and validation) and its **map set** — an explicitly ordered list
of conditional maps (first match wins, order always visible, reorderable with
up/down controls) plus, set apart as a fallback rather than a competitor, at
most one **default map**. Each map opens a dedicated editor: a **Selection**
choice (conditional, with its three-part path/operator/value condition, or
default), a plain-text **name**, and an **output structure** built either as a
row-per-field **Builder** (reusing this app's existing repeatable-row pattern)
or, for anyone who prefers it, as **Raw JSON** with a `{{path}}` token standing
in for "take this value from the incoming payload" — validated for syntax and
soft-warned (never blocked) on a path the expected structure doesn't recognise.
A read-only **Preview** renders the shape the map produces, using the sample
that established the expected structure, entirely client-side — nothing is
ever sent to a real destination from this editor (AC33, #14 stays #14). On the
existing event detail page (`design-06` Screen 3), a single new **Mapping**
row states, truthfully and for exactly one of four states, what happened to
that event: a named conditional map, the default map, no map (normal,
unreshaped), or a mapping failure (a genuine fault). While a proxy is Simple,
the Mapping surface stays reachable and editable — authoring is not
mode-gated — but carries a persistent, non-alarming notice that nothing there
currently governs delivery (AC6/D-08-2), exactly mirroring what every event's
Mapping row already shows on its own for a Simple proxy: **no map applied**.

## Scope boundaries (confirmed, not designed here)
- **AC2/AC30 — no code, no expressions, no external lookups, no AND/OR.** The
  output Builder offers exactly two value sources per field — a fixed literal
  or a reference to one incoming field — and Raw JSON's only non-literal
  syntax is the `{{path}}` token. Nothing else is offered anywhere in this
  spec: no function, no lookup, no conditional-inside-a-value, no combinator
  control. This is the compliance; nothing further to design.
- **AC7 — proxy- and team-scoped, no library.** The Mapping surface is nested
  under one proxy's route (`/proxies/{proxy}/maps`), exactly like Events. No
  cross-proxy map list, template gallery, or import/export control exists
  anywhere in this spec.
- **AC17(f)/AC30 — no second condition, no AND/OR, built here.** The condition
  UI shows exactly one path/operator/value row per conditional map and no
  "add condition" affordance — deliberate, not an oversight (see Screen 3).
- **AC19/AC23 — raw capture and the upstream response are untouched.** No
  control in this spec touches the Response card, the ingest acknowledgement,
  or the raw-payload capture path; nothing to design.
- **AC20 — no dispatched-output viewer.** #8 adds **no** second payload
  viewer. The `design-06` payload mask/reveal on the **received** (raw)
  payload is untouched and not duplicated for the **reshaped** payload
  anywhere in this spec — an explicit decision, not a gap (see *Flagged design
  call 8*).
- **AC29 — no destination routing, anywhere.** No control in the map editor,
  the map-set view, or the attribution row offers a destination choice, a
  per-destination condition, or a per-destination anything.
- **AC33/AC34 — no audit trail, version history, analytics, or numeric
  target.** No control here logs "who changed this map and when" beyond the
  ordinary `updated_at` a member would expect of any record; no throughput,
  latency, or count is asserted.

## User Flows

### Flow A — Establish or update the proxy's expected incoming structure
*(User story: "the editor knows what my proxy receives... so I build a map
without guessing key paths.")*
1. From a proxy's Show page, member clicks **Mapping** (new header action,
   next to **Events** — reachable in either Mode, read-permission gated like
   Events; no Create-time equivalent, exactly like Events — a brand-new proxy
   has no traffic yet to shape).
2. On the **Mapping** page, the **Expected incoming structure** card shows
   either its current flattened field list (path + inferred type,
   e.g. `type` — string, `data.object.amount` — number) with "Established
   from {a received event on {date} | a sample you supplied on {date}}", or,
   unestablished, "No expected structure set yet — autocomplete and
   validation below will be unaided until you set one," plus an
   **Update**/**Set structure** button (gated by the update permission,
   same formula as Show's Edit/Delete — AC3).
3. Clicking **Update** opens a `Dialog` (Screen 2) with two ways in — pick a
   received event, or paste a sample — never both required, never inferred
   from live traffic (AC10/D-08-3).
4. On save, the flattened field list refreshes; **no existing map is
   altered** — a path a map's condition or output references that is no
   longer in the new list is not retroactively flagged (that is #12's job,
   not #8's — AC10 parenthetical, AC32).

### Flow B — View the map set (the selection story, at a glance)
*(User stories: "several maps and a rule that picks one per event"; "a
default map for events no condition matches"; "turning Enhanced off and on
leaves my maps intact.")*
1. On the same **Mapping** page, below the structure card, the **Maps** card
   shows, in order, every **conditional** map — position number, name, its
   condition rendered as one line (`type` **equals** `"CHARGE"`, its operator
   always rendered, never assumed — AC17(c), see Screen 3's **C4**), reorder
   controls, **Edit**/**Delete** — then, visually set apart (not one more row
   in the ordered list — AC13), the **default map**: its name if one is
   designated, or "No default map set — an event matching nothing above is
   delivered unreshaped" if not (AC15, phrased as a fact, never a warning
   icon or error color); then, only when one or more exist, a further
   **"Not currently selected"** grouping for a demoted default that has not
   yet been given a condition (**C7**, see Flow D step 8) — visually distinct
   from both the ordered list and the default, so a map that never runs is
   never mistaken for one that does. **(C6) Permission gating, stated once
   for the whole card:** viewing this page requires only the proxy's **read**
   permission, exactly like Events (Screen 1). **Add a map**, every row's
   **Edit**/**Delete**, and the reorder controls (Flow C) are all gated by
   the proxy's **update** permission, including the #2 Member ownership
   rule — the same AC3 formula already stated for the expected-structure
   **Update** button (Flow A step 2). A read-permission member sees every
   map and the order in full; they see no **Add a map**, **Edit**,
   **Delete**, or reorder control anywhere on this page or in the map
   editor (Flow D/E).
2. **Empty (no maps at all):** a single card reads "No maps yet — every
   event is delivered exactly as received," plus **Add a map** (update-
   permission gated, per step 1's **C6** note). Not an error state; the
   pre-#8 behavior, stated plainly (AC15's own wording).
3. **If the proxy is Simple:** a **non-dismissible** `Alert` (Info-styled,
   same precedent as `design-07`'s downgrade disclosure and the FIFO note)
   sits above both cards, rendered for as long as the proxy is Simple, never
   collapsed behind a "learn more": "This proxy is Simple. Its maps are kept
   but not applied to events right now — switch to Enhanced to have them
   run." Nothing below this line is disabled by **Mode** — every map remains
   fully viewable, and editable subject only to the AC3 update-permission
   gate that already applies regardless of Mode (**C6**; AC3 draws no mode
   line; see *Flagged design call 1*). This is the same truthful-presentation
   guarantee AC6/D-08-2 states, applied to a surface-level page banner rather
   than a per-field suppression — and it is this banner, together with **C3**
   and **C6**, that discharges the three binding conditions flagged call 1
   was accepted under.
4. Clicking a conditional map or the default map opens its editor (Flow D).

### Flow C — Reorder conditional maps
*(User story: "a rule that picks one per event" — AC14's visible, changeable
order.)*
1. On the **Maps** card, each conditional row carries an **up** and a
   **down** icon button (disabled at the top/bottom of the list
   respectively), both gated by the AC3 update permission (**C6** — a
   read-permission member sees the order but no reorder control).
2. Clicking one **immediately** persists the new order (no separate "Save
   order" step — a single-purpose action, like Reveal in `design-06`) and the
   row animates to its new position; the position numbers update in place.
3. **Request-level failure:** the row snaps back to its prior position and
   an inline error appears above the list ("Couldn't reorder — try again");
   nothing about the underlying map is changed.

### Flow D — Create or edit a conditional map
*(User stories: "reshape an incoming payload into the structure my
destination expects"; "the editor... autocomplete... validation... a mistake
is caught at authoring time.")*
1. From the Maps card (**Add a map**, or an existing row's **Edit**), the
   member reaches the map editor (Screen 3), gated by the AC3 update
   permission (**C6** — reaching this route without it is not offered as a
   control; Flow B step 1).
2. **Selection:** two radio options, **Conditional** (default choice for a
   new map) or **Default**. Conditional reveals the three-part condition
   fields (path/operator/value); Default hides them entirely (AC12's last
   bullet — a map with no condition is never a conditional map). **(C7,
   reverse transition)** Switching an **existing conditional map's**
   Selection to Default discards its condition on Save — Default carries no
   condition to keep, so there is nothing to hold dormant — and gives up its
   position in the ordered list, exactly as any other map newly designated
   Default does. This is stated inline, next to the radio, the moment
   Default is chosen on a map that currently has a condition: "Saving as
   Default clears this map's condition and removes it from the ordered
   list." Choosing Conditional again afterward starts from a blank condition,
   the same as any new conditional map.
3. **Condition** (Conditional only): **Path** — a text field with an
   autocomplete suggestion list drawn from the expected structure (Screen 4's
   `PathAutocomplete`, degrades to plain free text if no structure is set);
   **Operator** — a `Select` with one item, **Equals**, shown explicitly
   (never implied — AC17a), its options sourced from a `data/` const
   following this app's `DataOption` convention (see Components); **Value** —
   a text field plus a compact **Type** select (String/Number/Boolean,
   defaulting String, same `DataOption` convention) so `42`, `"42"`, and
   `true` are distinguishable per AC12(c)'s typed comparison.
4. **Name:** a plain text field, required. Unique-within-the-proxy is a
   **design decision of this spec, not an AC1 mandate** — AC1 requires only
   that a map be individually identifiable by a member-supplied name; this
   spec adds the uniqueness constraint because AC16's attribution names maps
   to operators, and an ambiguous name would undermine that (server
   validated like any other field).
5. **Output structure:** Builder mode by default (Screen 5) — repeatable
   rows, each an output field path plus a value source (**From incoming
   field**, autocomplete, or **Fixed value**, literal + type); **Add output
   field** appends a row (`DestinationRows.vue` pattern, reused verbatim in
   shape). **Raw JSON** toggle switches to a textarea seeded with the
   Builder's current JSON (using `{{path}}` tokens for incoming-field
   references); switching back to Builder re-parses it when it decomposes
   cleanly into flat path→literal/token rows, otherwise the member stays in
   Raw JSON with an explanatory note (Screen 5 States).
6. **Preview** (read-only, Screen 6): renders the output JSON the map would
   produce, computed entirely client-side against the sample that established
   the expected structure (if any) — never a network call, never a real send
   (AC33). No sample available: "Set an expected structure with a real
   sample to preview real values here."
7. **Validation** (Screen 5 States): a syntax error in Raw JSON, a duplicate
   output field path, or a missing required condition field blocks **Save**
   with an inline error, exactly like every other form on this app
   (`InputError`, first-invalid-field focus). A path unknown to the expected
   structure is a **soft, non-blocking** note only ("not in your expected
   structure — check for typos"), never an error (AC9/AC21's "unaided, never
   unavailable" spirit).
8. **Setting Default when another map already is:** an inline `Alert` states
   plainly what Save will do: "{other map}" is currently the default; saving
   this map as the default replaces it — {other} keeps its output structure
   but stops being the fallback." No confirmation click beyond Save itself
   (same non-destructive-disclosure precedent as `design-07`'s downgrade
   `Alert` — nothing here is deleted, only re-pointed). **(C7, forward
   transition)** The `Alert` names where `{other}` lands: "and appears in a
   **Not currently selected** group on the Maps card — give it a condition
   to make it conditional again, or delete it — until then it does not run."
   `{other}` keeps its output structure and its name; it loses only its
   default designation, exactly as it never had a condition to lose. It is
   never shown as a row in the ordered conditional list (AC14 — everything
   in that list is presented as evaluated) and never silently disappears
   (Screen 3's "Not currently selected" grouping, Flow B step 1).
9. **Save** → success toast, returns to the Maps card with the new/edited row
   in place (new conditional maps append to the end of the order — AC14
   never implies a default insertion point). Save is gated by the AC3 update
   permission throughout, same as reaching this editor (**C6**, step 1).

### Flow E — Delete a map
1. From the Maps card row or the editor, **Delete** — gated by the AC3
   update permission like every other mutating control on this surface
   (**C6**) — opens the standard destructive `AlertDialog`
   (`docs/standards/design.md` — this **is** destructive: the map and its
   condition/output are gone, unlike a mode switch). Title names the map;
   description states plainly what is lost (its condition, if conditional;
   its default designation, if default) and that other maps and the
   expected structure are unaffected (AC1/AC8).
2. Confirm removes it; the order of the remaining conditional maps closes up
   with no gap (positions renumber); if it was the default, "No default map
   set" reappears (Flow B step 1); a map in the "Not currently selected"
   grouping (**C7**) simply disappears, same as any other deleted map.

### Flow F — Establish the expected structure inline (Dialog detail)
*(Continues Flow A step 3 — Screen 2.)*
1. **Choose a received event:** a scrollable list of the proxy's recent
   **retained** events (reusing `formatTimestamp`/`formatByteSize`, same
   descriptors as `design-06` Screen 2's list rows) — a cleaned or
   never-captured event isn't offered (nothing to derive a structure from).
   **No retained events exist:** the option is present but disabled with a
   one-line reason ("No retained events yet") — not hidden, so the member
   understands why, and isn't pushed toward the other option silently.
2. **Paste a sample:** a plain JSON textarea (same styling tokens as the
   Payload card's masked block / Raw JSON mode) — validated for JSON syntax
   before the flattened field list can be derived.
3. Either way, before confirming, the Dialog shows the **derived flattened
   field list** (path + inferred type) so the member can check it before it
   becomes the autocomplete/validation source — never applied silently.
4. **Confirm** replaces the proxy's expected structure (Flow A step 4);
   **Cancel** discards, nothing changes.

### Flow G — See which map ran on a received event
*(User story: "know which map was applied to a given event, so a wrong
output is a five-minute diagnosis.")*
1. On an event's detail page (`design-06` Screen 3, extended — Screen 7), the
   existing **Details** card gains one row: **Mapping**. Exactly one of four
   states renders, truthfully, never as a guess:
   - **A named conditional map:** the map's name, linked to its editor (Flow
     D) — e.g. `Charge mapping` (link).
   - **The default map:** `Default map`, linked the same way, or plain text
     if the default has since been deleted (a map link can 404 for a
     since-deleted map; plain text is the safe fallback — the event's
     history is a historical record, AC25).
   - **No map applied:** muted text, "No map applied — delivered
     unreshaped." (AC15's own wording; **never** a warning color/icon — this
     is the normal, additive outcome, exactly as a Simple proxy's every event
     reads). **(C8)** This same rendering also covers an event with **no
     recorded mapping outcome at all** — every event processed before #8
     shipped — since it is equally truthful for them: nothing was applied,
     delivered as received (Screen 7 States).
   - **Mapping failed:** a `destructive`-toned inline note naming the
     selected map, "{map name} — mapping failed" linked to the map's editor
     (AC22 — this **is** a genuine fault, unlike the case above, and reads as
     one), paired with AC22's own guarantee rather than a claim about
     delivery attempts: "captured, retained, and replayable; nothing
     incorrectly reshaped was delivered." (Non-blocking note (i): the
     earlier wording — "the event was captured but nothing was delivered by
     this attempt" — asserted a delivery outcome Q-08-03(5) has not settled;
     this phrasing commits only to what AC22 itself guarantees, and should be
     re-checked once Q-08-03 resolves.) The member fixes the map, then uses
     the existing **Replay** button (`design-06` Flow D) to re-send through
     the corrected configuration (AC25/PRD-06 AC9–AC11) — no new action is
     added here.
2. A proxy replayed or retried across a map edit may show different Mapping
   rows on different delivery groups of the **same** event's history (AC25) —
   this is normal, exactly as PRD-07 AC11 rules for Mode; nothing here flags
   it as inconsistent.
3. **Events list (`design-06` Screen 2):** unchanged — no Mapping column
   added (see *Flagged design call 7*); attribution lives on the detail page.

## Screens & States

### Screen 1 — Proxy detail (Show) — new header action
One addition, alongside the existing `Events` button (`design-07`'s header
row: `Events | Edit | Delete`, unchanged in shape):
```
Events | Mapping | Edit | Delete
```
```vue
<Button variant="outline" as-child>
  <Link :href="proxyMapRoutes.index({ current_team: teamSlug, proxy: proxy.id })">
    Mapping
  </Link>
</Button>
```
No separate permission gate beyond page view (mirrors `Events` exactly — AC3
gates viewing by the existing proxy read permission). Route names here are
illustrative (`proxyMapRoutes`), final routing is Technical Design's.

**Show page copy, superseded by this item (AC26 retires the copy
constraint)** — the mode-summary caption `design-07` added (Screen 2(a) of
that spec) now names mapping, since it is no longer unbuilt:
- **Simple:** "Simple mode — no dispatched-output storage, per-proxy retry
  configuration, or payload mapping; automatic retry, payload capture,
  retention, and replay still apply. See Retry policy below for what governs
  this proxy's retries. Saved maps are kept and do not run while this proxy
  is Simple." **(C2)** No map governs a Simple proxy, so this caption must
  not point a member at Mapping for "what actually governs this proxy" the
  way it points at Retry policy — retry has a default in force to show
  instead; mapping does not, so its half of the sentence states only that
  saved maps are preserved and dormant.
- **Enhanced:** "Enhanced mode — stores this proxy's dispatched payload
  separately from what it received, lets you configure its retry attempts
  and backoff below, and lets you reshape incoming payloads via Mapping
  above." Correct as drafted; unchanged.

This supersedes only those two strings in `design-07` Screen 2(a); nothing
else about that screen changes.

**(C1) The create/edit form's `#mode-help` copy (`design-07` Screen 1) is
also superseded by this item.** `design-07`'s corrected copy named only the
two AC6 capabilities that existed before #8; AC4 extends the governed set to
three and AC26 discharges the constraint that kept mapping out of this
string, so the two-capability version no longer satisfies PRD-07 AC12's
"names the AC6 capabilities" once #8 ships. Replacement, present tense,
naming all three Enhanced capabilities plus the mode-independent guarantees,
with no internal roadmap numbers and no implication of #9's XML/form-encoded
ingestion or #12's change detection (AC26):

> "Enhanced mode stores the payload actually dispatched, separately from the
> payload received, lets this proxy configure its own retry attempts and
> backoff strategy below, and lets you reshape incoming payloads into the
> structure your destinations expect. Automatic retry, payload capture,
> retention, and replay apply to every proxy regardless of Mode."

This supersedes only the `#mode-help` string set by `design-07`'s Screen 1
correction; the field's markup, id-linking (`aria-describedby="mode-help
mode-error"`), and every other part of that screen are unchanged.

### Screen 2 — Update expected incoming structure (Dialog)
Non-destructive `Dialog` (create/edit precedent, `docs/standards/design.md`),
triggered from the Expected incoming structure card's **Update**/**Set
structure** button:
```
Dialog
  DialogTitle "Update expected incoming structure"
  DialogDescription "Used for autocomplete and validation while you build
    maps. Updating it doesn't change any existing map."
  fieldset "How should we learn this?"
    ( ) Choose a received event
        [scrollable list: "Received {timestamp} — {size}", radio per row]
        (disabled state: "No retained events yet" — see Flow F step 1)
    ( ) Paste a sample
        textarea, placeholder: '{"type": "charge.succeeded", ...}'
  [derived field list preview — path + type, once a source is chosen/valid]
  DialogFooter: Cancel | Save
```
**States:**
- **Default (opened), nothing chosen:** Save disabled.
- **Event chosen / sample valid JSON:** field list preview populates; Save
  enabled.
- **Sample invalid JSON:** inline error under the textarea ("Not valid
  JSON"); Save disabled; no field list shown.
- **Sample valid JSON but not an object at its root** (e.g., a bare array or
  scalar): inline error ("The sample must be a JSON object at its top
  level"); Save disabled.
- **Submitting/Success/Request-failure:** identical mechanics to every other
  `Dialog` form in this app (disable-during-request, toast on success, inline
  error + re-enable on failure).

### Screen 3 — Mapping page (`/proxies/{proxy}/maps`)
```
Proxies > {Proxy name} > Mapping                              (breadcrumb)
Mapping for "{Proxy name}"                                    h1

[Alert — Simple-mode notice, conditional, Flow B step 3]

Card "Expected incoming structure"
  (established) dl: path/type rows + "Established from {source} on {date}"
  (unestablished) p "No expected structure set yet..."
  Button "Update" / "Set structure"                            → Screen 2

Card "Maps"
  p {Enhanced: "Conditional maps are evaluated top to bottom — the first
      match wins." | Simple: "Conditional maps would be evaluated top to
      bottom — the first match wins — once this proxy is Enhanced."}
  ol (each li = one conditional map)
    "#{n}"  {map name}   {path} {operator} {value}   [↑][↓][Edit][Delete]
      (all four actions update-permission gated — C6, see Flow B step 1)
  Button "Add a map" (update-permission gated — C6)
  hr
  h3 "Default map"
  p {Enhanced: "Runs only when nothing above matches." | Simple: "Would run
      only when nothing above matches, once this proxy is Enhanced."}
  (set) {map name}  [Edit][Delete] (update-permission gated — C6)
  (unset) "No default map set — an event matching nothing above is
    delivered unreshaped."  Button "Add a default map" (update-permission
    gated — C6)
  hr [only rendered when a demoted-default map exists — C7]
  h3 "Not currently selected"
  p "This map has no condition and isn't the default, so it never runs —
    give it a condition or delete it."
  {map name}  [Edit][Delete] (update-permission gated — C6)
```
**(C3)** Screen 3's evaluation copy renders in the present tense only while
the proxy is Enhanced — the tense that is genuinely true right now — and in
the conditional/future form while Simple, exactly like the Simple-mode
banner above it (Flow B step 3); the banner scopes the page but does not by
itself make the present-tense sentences true, so the sentences themselves
must change. **(C4)** The condition line renders `{operator}` sourced from
the condition, never the literal word `equals` hardcoded — AC17(c)'s "never
by assuming equality" applies to this rendering exactly as it does to the
three-part editor control (Screen 5), which was already compliant.
**States:** empty (Flow B step 2); Simple-mode banner (Flow B step 3), with
the Maps card's own copy in its conditional/future tense per **C3** above;
loaded with N conditional maps + a default; loaded with conditional maps and
no default; loaded with only a default and no conditional maps; loaded with
one or more demoted-default maps in the "Not currently selected" grouping
(**C7**, Flow D step 8/Flow B step 1) — all valid, none presented as
misconfigured (AC13/AC15). No loading/error states beyond the page-level
Inertia convention already used by Events.

### Screen 4 — `PathAutocomplete` (shared control)
A single-line text `Input` plus a filtered suggestion list — **new, hand-
written composition**, same convention as `PayloadViewer.vue`
(`design-06`): built from existing tokens/primitives, no new `ui/*`
primitive, no new dependency.
```
Label (contextual — "Path" on a condition, "Output field" on a Builder row,
       "Value" when sourcing from an incoming field)
Input (text) + a positioned <ul role="listbox"> of matching paths, filtered
  as the member types, from the expected structure's flattened field list
Keyboard: ArrowDown/ArrowUp move `aria-activedescendant`; Enter selects the
  highlighted item; Escape closes the list without changing the input.
```
**States:**
- **No expected structure set:** no list ever renders; a small helper line
  under the field reads "No expected structure set — typing is unaided.
  Set one on this page." (links to the Expected incoming structure card).
- **Typed text matches nothing:** list simply doesn't open; free text is
  always accepted (this is a suggestion aid, never a closed set — a
  condition or output reference is not required to match a known path,
  consistent with AC9's "never becomes unavailable" and AC21's tolerance of
  drift).
- **Array-indexed path typed** (e.g. `items[0].sku`): inline error on blur,
  "Only object keys are supported — array indexing isn't available" (AC12(b)).
  **(C5)** No "yet" — that word pre-announces a capability that isn't built
  and that no roadmap item promises (AC26 carries forward PRD-07 AC12's
  prohibition on claiming or pre-announcing unbuilt capability).

### Screen 5 — Map editor (`/proxies/{proxy}/maps/create` and `/edit`)
```
< Back to Mapping
{New map | Edit "{map name}"}                                   h1

Card "Selection"
  fieldset "How is this map selected?"
    ( ) Conditional — runs when its condition matches
    ( ) Default — runs when nothing above matches
  [if Conditional] Condition
    Path      [PathAutocomplete]
    Operator  [Select: Equals]
    Value     [Input] + [Select: String/Number/Boolean]
  [if switching an existing Conditional map to Default] inline note
    (Flow D step 2, C7): "Saving as Default clears this map's condition
    and removes it from the ordered list."
  [if Default, another map is already default] Alert (Flow D step 8, C7 —
    names where the replaced map lands)

Card "Name"
  Input (required; unique within the proxy — a design decision, not an
    AC1 mandate, see Flow D step 4)

Card "Output structure"
  [Builder | Raw JSON]                     (segmented Button pair)
  --- Builder ---
    rows: Output field [PathAutocomplete-as-plain-text] |
          (From incoming field [PathAutocomplete]) or (Fixed value [Input]+[Type])
          [Remove]
    Button "Add output field"
  --- Raw JSON ---
    textarea (font-mono, same block styling as the Payload viewer)
    [inline validation — see States]

Card "Preview"                                                  (Screen 6)

Actions: [Save map] [Cancel] ([Delete] — edit only, opens Flow E's AlertDialog)
```
**States:**
- **New map:** Selection defaults to Conditional, condition fields blank,
  Builder mode with one empty row.
- **Editing:** fields populated from the map's persisted values; whichever
  of Builder/Raw JSON the underlying output cleanly decomposes into is shown
  first (defaults to Builder when ambiguous — i.e., an empty or single-row
  output always opens in Builder).
- **Raw JSON — syntax error:** inline error under the textarea, Save
  disabled; error text is whatever the validator reports (no promised
  line/column precision — an implementation-fidelity detail, not a UX
  requirement here).
- **Raw JSON — valid but a `{{path}}` token references a path outside the
  expected structure:** non-blocking note above the textarea, one line per
  unknown path, Save remains enabled.
- **Builder — duplicate output field path across two rows:** inline error on
  the second row, Save disabled.
- **Raw JSON → Builder toggle, output too complex to decompose** (nested nesting
  Raw JSON can express but Builder's flat-row model cannot, or a string value
  mixing literal text and a token): toggle to Builder is disabled with a
  tooltip/inline note, "Too complex for Builder view — continue here."
  Nothing is lost; Raw JSON stays the editable source.
- **Validation error / submitting / disabled:** identical mechanics to every
  other form on this app (`InputError`, first-invalid-field focus,
  `:disabled="form.processing"`).

### Screen 6 — Preview (read-only, inside the map editor)
```
Card "Preview"
  p "What this map produces, using {the sample that set your expected
     structure | no sample yet}."
  (has a sample) <pre> rendered output JSON, computed client-side from the
    current Builder/Raw JSON state against that sample — updates as the
    member edits, no request sent anywhere.
  (no sample) p "Set an expected structure with a real sample (Screen 2) to
    preview real values here. Meanwhile, incoming-field references show as
    their path, e.g. {{ data.object.id }}."
```
No `aria-live` region on this panel — it updates on every keystroke, and a
live announcement at that frequency would be noise rather than help; sighted
feedback is the intended channel (see *Accessibility*).

### Screen 7 — Event detail — Mapping row (extends `design-06` Screen 3)
One new row in the existing **Details** card, after **Content type**:
```
dl
  dt "Received" / dd ...
  dt "Size" / dd ...
  dt "Content type" / dd ...
  dt "Mapping" / dd {one of the four states — Flow G step 1}
```
**States:** the four listed in Flow G step 1, exactly — plus **(C8)** one
named rendering for an event with **no recorded mapping outcome at all**:
every event processed before #8 shipped has none, and those events do not
disappear from history. This case renders as the **same** "No map applied —
delivered unreshaped" text and treatment as the ordinary no-match outcome
(muted, never a warning colour or icon) — it is truthful for them too:
nothing was applied, and they were delivered as received. No fifth state is
introduced, and nothing here reads as a fault. No loading/error state beyond
the page-level convention — this renders from data already on the event
payload, same as every other Details row.

## Components
| Role | Component | Status |
|---|---|---|
| Mapping entry point | `Button` `variant="outline"` `as-child` + `Link` | Reused (Show header actions, `Events` pattern) |
| Structure/Maps page cards | `Card` | Reused |
| Simple-mode notice | `Alert`, `AlertDescription`, `Info` icon | Reused — `design-07`/`design-06` FIFO-note precedent |
| Update-structure dialog | `Dialog`, `DialogHeader/Title/Description/Footer/Close` | Reused (non-destructive dialog pattern) |
| Received-event picker (Screen 2) | native `input type="radio"` + `Label`, list styled like `design-06` Screen 2 rows | Reused native-form-element pattern (`DestinationRows.vue` precedent) — no new primitive |
| Sample/Raw-JSON textarea | plain `<textarea>`, `Payload`-block tokens (`border-input`, `font-mono`, `dark:bg-input/30`) | Reused styling tokens on a native element — no new `ui/*` primitive, mirrors `PayloadViewer.vue`'s hand-styled block |
| Selection radio (Conditional/Default) | native `input type="radio"` + `Label`, `fieldset`/`legend` | Reused native-form-element pattern — no `RadioGroup` primitive exists; not introduced |
| Operator field | `Select`/`SelectTrigger`/`SelectContent`/`SelectItem`/`SelectValue` | Reused, one `SelectItem` at MVP (`Equals`) |
| Value-type field | `Select` (String/Number/Boolean) | Reused |
| Operator/Type option sets | `data/proxyMapConditionOperators.ts` / `data/proxyMapValueTypes.ts` (illustrative names) | **Non-blocking note (ii):** sourced from a `data/` const following this app's `DataOption` convention, exactly as `proxyRetryBackoffStrategies.ts` does — makes AC17(e)'s "adding an operator is cheap" true in the UI layer by construction, not merely by intention. Final file naming is implementation's. |
| Path/output autocomplete | **New:** `PathAutocomplete.vue` — `Input` + a plain positioned `<ul role="listbox">` | New hand-written composition, no new dependency (`PayloadViewer.vue` precedent) |
| Output row repeater | `Button` (add/remove), `Input` | Reused — `DestinationRows.vue` pattern, reused in shape |
| Builder/Raw JSON toggle | two `Button`s, segmented (`variant="default"`/`"outline"` by active state) | Reused, no new primitive |
| Reorder controls | `Button` `variant="ghost"` `size="icon"`, `ArrowUp`/`ArrowDown` (`@lucide/vue`) | Reused library, new icon pair |
| Map delete confirmation | `AlertDialog` family | Reused (destructive-action standard) |
| Mapping row (event detail) | plain `dt`/`dd`, `Link` for named/default map, `destructive`-toned text for failure | Reused `dl` pattern (`design-03`/`design-06`) |
| Feedback | Sonner toast (`flash.toast`), `InputError` | Reused channels |

**No new npm dependency and no new generated `ui/*` primitive is
introduced.** Every new piece of UI (`PathAutocomplete.vue`, the Builder row
group, the Preview panel) is a hand-written composition built from existing
tokens and primitives — the same convention `PayloadViewer.vue` and
`DestinationRows.vue` already established. Two new `@lucide/vue` icons
(`ArrowUp`, `ArrowDown`), same library already in use.

## Interactions
- **Reorder is immediate, not staged** — no "Save order" step; each
  up/down click is its own request (Flow C), consistent with Reveal's
  single-purpose-action precedent.
- **The Simple-mode banner is purely presentational** — it renders from
  `proxy.mode` and gates nothing **by Mode** (every action on the page stays
  as enabled or disabled as it would be on an Enhanced proxy), exactly as
  `design-07`'s Flow D established for mode-independent UI: no control
  anywhere is conditioned on Mode except what it truthfully describes. The
  **one** gate any action on this page is conditioned on is AC3's update
  permission (**C6**), which applies identically regardless of Mode.
- **PathAutocomplete never blocks free text** — it is a suggestion aid over
  an open field, not a closed-set picker; the underlying `Input`'s value is
  the source of truth, not a selection index.
- **Builder ⇄ Raw JSON is not always reversible** — Raw JSON is the durable
  representation; Builder is a decomposition view available only while the
  output stays flat (one literal-or-token value per path). This is stated
  up front in the toggle's disabled-state tooltip, never a silent data loss.
- **The Preview panel has no interactive controls** — pure output of the
  current form state, recomputed on every relevant change, never persisted
  or sent anywhere.
- **Setting Default when one exists is a plain-Alert disclosure, not a
  confirm dialog** — same reasoning `design-07`'s downgrade disclosure used:
  nothing is deleted, only re-pointed; a second click would manufacture
  gravity the action doesn't have.
- **A Conditional⇄Default change is symmetric in what it discards, never
  silent** (**C7**): becoming Default clears a condition and gives up an
  order position (nothing to keep dormant — Default carries no condition);
  being replaced as Default gives up only the default designation and lands
  in the visible "Not currently selected" grouping, never in the ordered
  list and never invisible.
- **Deleting a map is the one destructive action in this spec** and is the
  only place an `AlertDialog` appears (Flow E) — everything else here is
  create/update, using the plain-`Dialog`/inline-form conventions.
- **The Maps card's evaluation copy is mode-conditioned, the Simple-mode
  banner is not** (**C3**): the banner's own text never changes, but the
  present-tense sentences describing evaluation order switch to a
  future/conditional form while the proxy is Simple, so no sentence on the
  page asserts a map governs delivery when none does.

## Accessibility
- **PathAutocomplete:** `role="combobox"` semantics on the input
  (`aria-expanded`, `aria-controls` pointing at the `listbox` id,
  `aria-activedescendant` tracking the highlighted suggestion); the listbox
  itself `role="listbox"`, each suggestion `role="option"`. Full keyboard
  operation (Arrow keys, Enter, Escape) per the WAI-ARIA combobox pattern —
  no suggestion is reachable by pointer alone.
- **Selection radios / Builder value-source choice:** native
  `<input type="radio">` grouped in a `fieldset`/`legend`, each with a
  programmatically associated `Label` — screen readers announce the group
  name and the checked option natively, no custom ARIA needed.
- **Reorder buttons:** `aria-label="Move {map name} up"` / `"down"` — never
  a bare icon (`docs/standards/design.md`'s icon-only-control rule); a
  disabled boundary button (top/bottom of list) is `:disabled`, not merely
  visually dimmed, so it's excluded from the tab order's actionable set
  while still announced as disabled.
- **Raw JSON textarea:** a real `<textarea>` with an associated `Label`
  ("Output structure (raw JSON)"); its validation error is linked via
  `aria-describedby` exactly like every other field on this app.
- **Preview panel:** static, read-only text content — no `aria-live` (see
  Screen 6's rationale); a heading (`h3 "Preview"`) gives it a landmark for
  screen-reader navigation.
- **Mapping row (event detail):** real text content per state, never
  color-only — the "mapping failed" state pairs `text-destructive` with the
  word "failed" in the copy itself, consistent with this app's "colour is
  never the sole carrier of meaning" rule.
- **Map delete `AlertDialog`:** identical accessibility treatment to every
  existing destructive dialog in this app (title/description pairing, focus
  trap, focus return) — nothing new to specify.
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`'s baseline. The
  combobox pattern is this spec's one genuinely new interactive pattern;
  every other control reuses an already-vetted primitive or native element.

## Responsive Behavior
- **Mapping page cards** stack vertically like every other Show-adjacent
  page; the Maps card's ordered list wraps its row content on narrow
  viewports (position/name/condition/actions reflow to multiple lines,
  not a horizontal scroll) — this list is short by nature (one row per
  map), unlike the Events table, so no scroll container is needed.
- **Map editor:** single-column, `max-w-2xl`, matching `ProxyForm.vue`'s
  form-width convention; Builder rows use the same
  `sm:grid-cols-[1fr_auto_auto]`-style layout as `DestinationRows.vue`,
  full-width below `sm`.
- **PathAutocomplete's suggestion list:** anchored to its input, capped
  height with internal scroll (`max-h-48 overflow-y-auto`) so it never grows
  taller than the viewport on a short screen.
- **Preview panel:** wraps (`whitespace-pre-wrap break-words`), same
  precedent as the Payload/Response blocks — content, not a single-line
  bearer secret.
- **Minimum supported width:** 360px, per the standing default in
  `docs/standards/design.md` — no feature-specific override.

## Open Questions
**None blocking this spec's approval.** Eight flagged, reversible design
calls below for the Product Manager's design-gate attention (matching the
`design-06`/`design-07` precedent), plus one already-open, non-blocking
question this spec adds a note to rather than reopening:

1. **The Mapping surface is reachable and fully editable in both proxy
   Modes**, not gated to Enhanced-only the way `design-06`'s Retry-policy
   fieldset is. *Basis:* nothing in AC3 ties authoring to Mode, and D-08-1's
   premise (maps authored, preserved, later reactivated) is best served by
   letting a member build/edit maps ahead of switching, not by forcing a
   round-trip through Enhanced first. *Consequence, and the alternative:* a
   Simple-mode member can create/edit/reorder maps that do nothing yet — the
   page-level `Alert` (Flow B step 3) is the entire truthful-presentation
   mechanism, versus hiding the whole surface (mirroring the Retry-policy
   precedent) and forcing Enhanced-first authoring. Both satisfy AC6/D-08-2;
   this spec picked the more usable one.
2. **A map's Conditional-vs-Default type is chosen on the map editor itself**
   (a radio at the top), with a plain-`Alert` disclosure if saving as Default
   will replace an existing one — rather than assigning "default" as a
   separate action from the Maps list. *Basis:* keeps map identity (what
   selects it) authored in one place, alongside its condition fields.
   *Alternative:* a "Make default" row-action on the Maps card, leaving the
   editor to only ever show condition fields. Independently reversible.
3. **Reordering is up/down buttons, immediate-persist, not drag-and-drop.**
   *Basis:* no drag-and-drop library exists in this app today, and buttons
   are unambiguously keyboard-accessible without extra work. *Consequence:*
   reordering a map several positions takes several clicks rather than one
   drag; acceptable given map counts are expected to be small (one per
   incoming event shape).
4. **Establishing the expected structure defaults to "choose a received
   event," with "paste a sample" as the secondary option** — the PRD's UX
   Direction explicitly left this choice to the Designer. *Basis:* a real,
   already-captured event is less error-prone for a no-code author than
   hand-typing JSON, and PRD-06 AC25 already surfaces those events elsewhere.
   *Consequence:* a brand-new proxy with no traffic yet must use the sample
   path first — reflected in Screen 2's disabled-with-reason state, not
   hidden.
5. **The output structure editor is dual-mode: a row-based Builder (default)
   plus a Raw JSON toggle using a `{{path}}` placeholder token**, rather than
   a single free-form JSON text editor with cursor-position-aware inline
   autocomplete. **(C9)** PRD-08's `## UX Direction` vision sentence is
   **two separable clauses, not one**: a JSON editor with autocomplete
   driven by a known incoming structure, *and* validation of pasted/raw JSON
   against known structures — AC9 already renders those as two separable
   requirements, and nothing in the PRD or the vision requires a single
   control, or uses the words "code editor", "inline", or "cursor-aware".
   This spec's split maps onto those two clauses more exactly than a unified
   reading would: the **Builder** is the autocomplete-driven authoring path,
   **Raw JSON** is the paste-and-validate path. It also serves AC2's
   authored-no-code requirement better, not worse — a free-form code editor
   over hand-typed JSON is the *more* code-like of the two options, not the
   less. **PRD-08 needs no amendment; no criterion or wording is wrong** —
   there is no contradiction in the PRD to resolve, and this record does not
   claim one. *Feasibility angle, kept:* true inline autocomplete inside an
   arbitrary multi-line JSON text area would additionally require a
   code-editor component (e.g. CodeMirror/Monaco) that is not a dependency of
   this app today — a technical undertaking, not a styling choice, and not
   the basis for this call, only a further reason it is the right one. The
   dual-mode design delivers both of the vision's clauses using only
   existing primitives and a single-line suggestion control (Screen 4), at
   the cost of the Raw JSON textarea itself only offering a static reference
   token rather than a live suggestion popup while typing inside it. *This
   is also noted to the Principal Engineer, folded into the already-open
   Q-08-03 rather than a new question doc* — if a code-editor dependency is
   later judged worthwhile (e.g. for #9's richer payload shapes), that is a
   fresh Owner dependency gate raised then, on that item's own merits; this
   design's two-mode structure and the `{{path}}` token convention are
   additive to replace, not a redesign.
6. **The `{{path}}` placeholder token is this spec's illustrative proposal
   for expressing "take this value from the incoming payload" inside Raw
   JSON — the concrete syntax, escaping, and whether it is also the map's
   *persisted* representation (versus a structured schema the UI merely
   renders this way) is Technical Design's call**, folded into Q-08-03's
   existing "expected structure's model" thread rather than a new question.
   The binding UX contract is only: Builder and Raw JSON must describe the
   same map, some literal-vs-reference distinction must exist in Raw JSON
   text, and the editor must validate it before Save.
7. **Applied-map attribution is a single Details-card row on the event
   detail page; no column is added to the Events list.** *Basis:* mirrors
   `design-06`'s own "no Index shortcut" minimalism precedent (flagged call
   5 there) — the list stays scannable, and the detail page is one click
   away exactly as replay/reveal already are. *Consequence, reversible:* an
   operator scanning many events for a mapping problem must open each one;
   if that proves painful in practice, a list badge is additive later.
8. **No dispatched-output (reshaped payload) viewer is added anywhere in
   this spec**, closing the question `design-06` deliberately left open for
   #8 ("the natural place to reconsider surfacing it"). *Basis:* PRD-08's UX
   Direction explicitly forbids duplicating the payload viewer/mask/reveal,
   and AC20 states #8 adds no second store — there is no AC asking for a
   second viewer either. Recorded here as a considered decision, not an
   oversight, since `design-06` named #8 by number as the item that might
   revisit it.

No requirement gap was found. **Q-08-03** (Principal Engineer, technical —
mapping's ADR-018 gate, the expected-structure's persisted model, ADR-013's
divergence gate under routine divergence, retry/replay re-application, AC22
failure-semantics feasibility) remains **open and non-blocking**, exactly as
the PRD states; this spec's flagged calls 5 and 6 above add two
implementation-level notes to that same open thread rather than opening a
new one. No design in this spec assumes either resolution of Q-08-03 — the
Mapping row's four states (Flow G) render from whatever the event's own
recorded outcome turns out to be, and the map editor's Save action is
generic to whatever persistence shape Technical Design settles on.

## Handoff
- **Inputs:** `docs/product/prd-08-payload-mapping.md` (Approved, esp. UX
  Direction and AC1–AC34); `docs/questions/prd-08-q-08-01-map-selection-
  precedence-fallback.md` (RESOLVED, Project Owner, 2026-08-26 — AC13/14/15);
  `docs/questions/prd-08-q-08-02-map-selection-matching-syntax.md` (RESOLVED,
  Project Owner, 2026-08-26 — AC12/AC17); `docs/questions/prd-08-q-08-03-
  mapping-composition-and-expected-structure.md` (OPEN, Principal Engineer,
  non-blocking — carries forward, plus this spec's flagged-call 5/6 notes);
  `docs/design/design-06-retry-replay.md` (Screen 2/3 event-surface pattern
  extended here, not duplicated; the payload-viewer non-duplication this
  spec confirms); `docs/design/design-07-enhanced-mode-toggle.md` (Mode
  field's `#mode-help` copy **and** Show-caption copy this spec extends per
  AC26 — both corrected under **C1**/**C2**; the truthful-presentation/
  write-surface precedent D-08-2 mirrors);
  `docs/architecture/adr-018-mode-gated-resolution-of-enhanced-
  configuration.md` (Decision 6 — `#8 MapStep`'s reserved position);
  `resources/js/pages/proxies/{ProxyForm,Show,Create,Edit}.vue`,
  `resources/js/pages/proxies/events/Show.vue`,
  `resources/js/components/{DestinationRows,PayloadViewer}.vue`,
  `resources/js/types/proxies.ts`, `resources/js/data/{proxyRetryBackoffStrategies,
  proxyDeliveryStates,proxyPayloadStates}.ts` (current implementation and
  existing patterns studied for this spec — confirmed no `Textarea`,
  `RadioGroup`, `Tabs`, `Popover`, or `Command` primitive exists today, and no
  code-editor dependency is installed, informing flagged calls 5/6);
  `docs/standards/design.md`, `docs/standards/documentation.md`.
- **Outputs:** this design spec.
- **Dependencies:** none. No new npm dependency and no new generated `ui/*`
  primitive — every new control is a hand-written composition from existing
  tokens/primitives, or a native form element styled to match, following the
  `PayloadViewer.vue`/`DestinationRows.vue` precedent. Two new `@lucide/vue`
  icons (`ArrowUp`, `ArrowDown`).
- **Outstanding Questions:** None blocking. Eight flagged, reversible design
  calls above, **all ruled on by the Product Manager at the design gate**
  (2026-08-26) — seven accepted as designed, call 5 accepted with its record
  corrected (**C9**); none sent back for redesign. **Q-08-03** (Principal
  Engineer) remains open and non-blocking, exactly as PRD-08 states, carrying
  two additional implementation-level notes from flagged calls 5 and 6 (the
  code-editor/autocomplete-fidelity trade-off and the `{{path}}` token's
  status as illustrative rather than binding).
- **Next Agent:** **Principal Engineer**, for technical design — the
  Product Manager has approved this spec against PRD-08 with nine required
  corrections (design gate, delegated per `CLAUDE.md`), and all nine (C1–C9)
  plus the three non-blocking notes are landed above; no re-approval is
  required. Technical Design also resolves the already-open **Q-08-03**.
