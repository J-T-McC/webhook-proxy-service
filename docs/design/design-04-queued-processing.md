# Design Spec: Queued processing (FIFO & Async) — processing-mode control

- **Status:** Approved
- **Author:** Designer
- **PRD:** `docs/product/prd-04-queued-processing.md` (Approved, Project Owner, 2026-08-04)
- **Approved by / date:** Product Manager, 2026-08-04 (design gate, delegated)
- **Approval note:** Reviewed strictly against PRD-04's UX Direction and
  AC4/AC5 and the house design standards (`docs/standards/design.md`,
  precedents `design-01` and `design-03-decoupled-upstream-response-show`).
  Every UI-bearing story is covered: the per-proxy processing-mode choice on
  the existing create/edit form (Flows A/B, Screen 2), settable at create and
  edit (AC4), Async pre-selected as the default (AC5). The UX Direction is
  honored in full — the help-text copy states the parallel/no-ordering vs.
  in-order/"more serialized and slower, not a free upgrade" tradeoff plainly,
  and states the choice is independent of the simple/enhanced Mode selector
  (ADR-002). Control choice (`Select`), placement (below Mode), and copy are
  Designer authority the PRD explicitly delegated; the `Select` reuse adds no
  new primitive and matches every existing enum field. All required per-screen
  states are documented (default create/edit, change, server-validation error,
  submitting/disabled; empty/loading correctly N/A for an always-populated
  required enum). Accessibility is specified per surface at WCAG 2.1 AA
  (label association, `aria-describedby` help+error linkage, keyboard
  operability, badges text-not-colour). No requirement the Owner did not state
  is introduced — the read-only surfacing decisions below stay within the
  Designer's "cover every surface a persisted attribute touches" authority and
  mirror existing precedent. Rulings on the two flagged judgment calls are
  recorded under Open Questions below.

> **Scope note.** #4's only user-facing surface is a **per-proxy processing-mode
> choice** — **Async** (default, parallel, no ordering guarantee) vs **FIFO**
> (opt-in, in-order delivery) — orthogonal to the existing simple/enhanced
> **Mode** selector (ADR-002). This spec covers where that choice lives on the
> existing proxy **create/edit form**, whether/how it is surfaced read-only on
> **Show** (mirroring, where appropriate, the #3 Response-card precedent —
> `docs/design/design-03-decoupled-upstream-response-show.md`), and — per this
> role's standing responsibility to cover every surface a data addition
> touches — the **Index** list. It does **not** specify queue mechanics, the
> transport, data model, or how an in-flight event is handled if the mode is
> changed mid-flight — all Principal Engineer territory (PRD-04 Q-04-01).

## Overview
A team member configuring a proxy sees a new **Processing** field on the
existing create/edit form, directly below the existing **Mode** field: a
two-option **Select**, defaulting to **Async**, with an opt-in **FIFO**
alternative. The field's help text states the tradeoff plainly — Async is
parallel with no ordering guarantee (the right default for most traffic);
FIFO delivers the proxy's events in the order they were received but is
necessarily more serialized and slower, not a free upgrade — and makes clear
the choice is independent of the Mode field just above it. The chosen value
is then surfaced read-only, as a second badge beside the existing Mode badge,
on both the proxies **list** (Index) and the proxy **detail** (Show) page, so
a team member can tell a proxy's processing mode at a glance without opening
Edit.

## Control decision — Select (not radio group or toggle)

**Decision:** the processing-mode control is a **kit `Select`** (Reka UI,
`components/ui/select`), styled and wired exactly like the existing **Mode**
field and the #3 **Response status** field in `ProxyForm.vue` (`Label` above,
`SelectTrigger` `w-full sm:w-64`, `SelectContent`/`SelectItem` options, help
text below, `InputError` for server validation).

**Rationale:**
- **Consistency over novelty.** Every existing enum-valued field on this form
  (Mode: Simple/Enhanced; Response status: Default/200/202/204) already uses
  `Select`. Introducing a different control family (radio group, switch/toggle)
  for this one field would fragment the form's interaction language for no
  requirement-driven reason — the PRD imposes no interaction constraint that a
  `Select` can't satisfy (AC4: "settable at create and edit time"; no
  always-visible-both-options requirement).
- **A toggle/switch is a weaker fit, not just a style mismatch.** A `Switch`
  primitive doesn't exist in this app's `components/ui/` today (checked: not
  imported anywhere in `resources/js/pages/proxies/`), so it would be a wholly
  new primitive for a binary choice the form already has an idiom for
  (`Select`). A toggle also reads as a simple on/off with no room for the
  option **labels** themselves to carry meaning (a toggle typically shows only
  its current state, pushing "what does off mean" entirely into a caption) —
  weaker than a labelled `Select` where both option names ("Async", "FIFO")
  are always legible when open.
- **A radio group was considered and rejected for this form.** A two-item
  radio group has a real advantage — both options and their descriptions can
  be visible without a click — which would foreground the "honest tradeoff"
  framing the PRD asks for even more directly. But: (a) it breaks the
  established one-`Select`-per-enum-field visual rhythm of this exact form
  (Mode, Response status), (b) it costs more vertical space on an already
  multi-section form (Details → Response → Destinations), and (c) the #3
  Response-status field already proves the "convey a nuanced tradeoff via
  **help text under a Select**" pattern works here (204's forced-empty-body
  coupling is exactly this kind of nuance, and it ships as help text, not a
  radio group). The tradeoff copy (below) carries the same information a
  radio group's per-option description would, at lower layout cost and higher
  consistency.
- **Net:** `Select` is the only control choice that adds zero new components,
  matches the form's existing pattern for every other enum field, and fully
  satisfies the PRD's "make clear the tradeoff" requirement via help text.

## User Flows

### Flow A — Create a proxy with a processing mode (extends design-01 Flow A)
1. Team member opens **New proxy** (`/proxies/create`).
2. Fills **Name**, leaves or changes **Mode** (Simple/Enhanced — unchanged from
   design-01/ADR-002).
3. New: leaves **Processing** at its default, **Async**, or opens the select
   and chooses **FIFO** — reads the help text explaining the parallel/ordered
   tradeoff before deciding.
4. Continues filling Response / Destinations as today (design-01 Flow A,
   design-03) and clicks **Create proxy**.
   - **Validation failure:** form re-renders in place; if `processing_mode`
     itself is ever in error (e.g. a stripped/unexpected value round-trips
     from a tampered request), its `InputError` renders under the field like
     every other field; realistically unreachable via normal `Select` use
     since the control never has an empty state (see Interactions).
   - **Success:** redirects to the new proxy's detail page; the **Processing**
     badge (Async or FIFO) appears beside the **Mode** badge in the header.

### Flow B — Edit an existing proxy's processing mode (extends design-01 Flow D)
1. From the list-row **Edit** action or the detail-page **Edit** button →
   Screen 2 (the same Create/Edit form), pre-filled including the proxy's
   current **Processing** value.
2. Member changes **Processing** (Async ↔ FIFO) independently of any other
   field — no confirmation dialog; this is a routine, non-destructive
   configuration change (consistent with how Mode and Response status are
   edited today — inline, no confirmation).
3. Clicks **Save changes** → same validation/success handling as Flow A step 4.
   - **Note (flagged, not designed here):** what happens to an event already
     mid-flight for this proxy when its mode changes is a queue-mechanics
     question for the Principal Engineer (PRD-04 Q-04-01) — no UI warning is
     specified pending that answer; this spec assumes none is needed for a
     routine settings save, matching the PRD's framing of the choice as a
     persisted proxy property, not a live operational toggle.

### Flow C — View a proxy's processing mode (Show, extends design-01 Flow C)
1. From the list or detail, the member sees the proxy's **Processing** badge
   (Async or FIFO) beside its **Mode** badge — no extra click needed.

### Flow D — Scan processing mode across proxies (Index, extends design-01 Flow B)
1. On the **Proxies** list, the member sees a **Processing** column (Async/FIFO
   badge) alongside the existing **Mode** column, so they can identify FIFO
   (more serialized/slower) proxies at a glance without opening each one.

## Screens & States

### Screen 2 — Create / Edit Proxy form (`/proxies/create`, `/proxies/{proxy}/edit`)
**Placement:** a new field in the existing **Details** section, directly
**below Mode and above Response status** — grouping the proxy's three core
identity/pipeline settings (Name, Mode, Processing) before the
acknowledgement-contract fields (Response status/body, #3) and the fan-out
targets (Destinations, #1). This is an *addition* to the section already
specified in `design-01-walking-skeleton.md` Screen 2 and unchanged by
`design-03`; nothing else on the form moves.

```
Details
  Name            (unchanged)
  Mode            (unchanged — Simple/Enhanced, ADR-002)
  Processing      (NEW — Async/FIFO, this spec)
Response          (unchanged — #3 status/body)
Destinations      (unchanged — #1 repeatable rows)
```

**Field spec:**
- **Label:** `Processing` (`Label for="processing_mode"`, label-above-control,
  matching the Mode/Response-status pattern).
- **Control:** `Select` (`SelectTrigger id="processing_mode" class="w-full
  sm:w-64"`), two `SelectItem`s:
  - `value="async"` → **Async** (selected by default on create — AC5).
  - `value="fifo"` → **FIFO**.
  - No third "unconfigured"/sentinel option, unlike Response status (#3):
    AC5 makes **Async itself the default**, not a separate fallback resolved
    elsewhere, so the control always holds an explicit value — a plain
    two-item `Select`, not a tri-state one.
- **Help text** (`id="processing-help"`, `text-sm text-muted-foreground`,
  below the control):
  > "Independent of the Mode setting above. Async (default) delivers this
  > proxy's events to its destinations in parallel, with no guaranteed order —
  > the right choice for most, higher-throughput traffic. FIFO delivers this
  > proxy's events in the order they were received; it trades throughput for
  > strict ordering, so FIFO is necessarily more serialized and slower than
  > Async, not a free upgrade."
- **Error:** `InputError :message="form.errors.processing_mode"` in a
  `<span id="processing-error">`, following the response-status field's fully
  wired pattern (not the older, lighter Mode field — see Accessibility).
- **`aria-describedby="processing-help processing-error"`** on the
  `SelectTrigger`, and `:aria-invalid="form.errors.processing_mode ? 'true' :
  undefined"`.

**States.**
- **Default (create):** `processing_mode` = `async` pre-selected; no other
  field's state changes because of this one (unlike Response status, choosing
  Processing has no side effect on other fields — no analogue to the 204/body
  coupling).
- **Default (edit):** pre-filled to the proxy's saved value (`async` or
  `fifo`).
- **Changing the value:** immediate, client-side only until submit — no
  confirmation dialog (non-destructive setting change, consistent with
  Mode/Response-status).
- **Validation error (server round-trip):** re-renders with the submitted
  value retained and `InputError` shown, following the shared
  `ProxyForm.vue` `onError` focus-management callback (focus moves to the
  first `[aria-invalid="true"]` field, which would be this trigger if it's
  the offending field).
- **Submitting / disabled:** `:disabled="form.processing"` like every other
  field in this form.

### Screen 3 — Proxy detail (`/proxies/{proxy}` show)
**Decision — header badge, not a new card.** Unlike #3's Response value (which
needed a dedicated **Response** card because it carries two fields —
status *and* body — across four distinct states), Processing is a single
enum value with no secondary data, exactly like the existing **Mode** badge
already in the page header. Giving it a whole card would be disproportionate
to its information content and would inconsistently duplicate the header
badge pattern already established for the same-shaped Mode field. So:

- **Placement:** a second `Badge` (`variant="secondary"`, matching Mode) in
  the existing header row, immediately after the Mode badge:
  `<div class="flex items-center gap-3"><h1>…</h1><Badge>Mode</Badge><Badge>Processing</Badge></div>`.
- **Text:** `Async` or `FIFO` — verbatim match to the form's `SelectItem`
  labels (same verbatim-consistency rule `design-03` established for the
  Response-status badge).
- No separate helper paragraph is added under the header for this badge
  (the header today carries no explanatory text for the Mode badge either);
  the tradeoff explanation lives on the form, where the choice is made.

**States.** Always populated — `processing_mode` is a required, always-set
field (no "unconfigured" state per the Control decision above), so there is
no empty/muted variant to design, unlike the Response body cell.

*(This decision — badge vs. card — is a designer judgment call within this
role's authority; flagged explicitly for the Product Manager's design-gate
review, and reversible to a card if the PM disagrees given the parallel to
#3.)*

### Screen 1 — Proxies list (`/proxies` index) — other surfaces
**Decision — add a `Processing` column**, mirroring the existing `Mode`
column exactly (same `Badge` `variant="secondary"` pattern), positioned
immediately after **Mode** and before **Ingest URL**:

```
Name | Mode | Processing | Ingest URL | Actions
```

**Rationale:** this wasn't explicitly asked for by the PRD's UX Direction
(which named only the create/edit form), but it is this role's standing
responsibility to decide how a new persisted attribute appears on *every*
surface of the resource, including "not shown here" if that's the call. Given
FIFO is a meaningfully different operational characteristic (more
serialized/slower) that a team scanning many proxies benefits from seeing
without a click — and the column costs nothing new (same `Badge`, same data
already returned per proxy) — the decision is to **show it**, matching the
`Mode` column precedent exactly. If the Product Manager judges this expands
scope beyond the PRD's UX Direction, the fallback is trivial: drop the column,
everything else in this spec is unaffected.

**States.** Same as the existing `Mode` column — no independent loading/empty
state; renders as part of each table row.

## Components

| Role in this spec | Component | Status |
|---|---|---|
| Processing field control | `Select` / `SelectTrigger` / `SelectContent` / `SelectItem` / `SelectValue` (`components/ui/select`) | Reused — already imported in `ProxyForm.vue` for Mode and Response status |
| Processing field label | `Label` (`components/ui/label`) | Reused |
| Processing field error | `InputError` (`components/InputError.vue`) | Reused |
| Show-page Processing badge | `Badge` `variant="secondary"` (`components/ui/badge`) | Reused — same as the existing Mode badge |
| Index-page Processing column | `Badge` `variant="secondary"` inside `TableCell` | Reused — same as the existing Mode column |

**No new component or dependency is introduced.**

**Recommended (not mandated) data-const treatment.** `docs/standards/coding.md`
codifies a pattern from #3's review: a value/label set reused across
components (`data/proxyResponseStatuses.ts`, typed against `DataOption`) as a
single source of truth for labels/values shown in more than one place. This
spec's Async/FIFO labels appear in three places (form select, Show badge,
Index badge) — the same shape of reuse that justified
`proxyResponseStatuses.ts`. The Designer recommends the implementing developer
follow the same pattern (e.g. `resources/js/data/proxyProcessingModes.ts`
exporting `{value: 'async', label: 'Async'}` / `{value: 'fifo', label:
'FIFO'}` plus a label-lookup helper), for the same verbatim-consistency and
no-magic-string reasons — this is a file-organization/reuse note for the
Senior Developer, not a Designer requirement gating approval; the visible
result (labels, placement) is unchanged either way.

## Interactions
- **Select semantics:** standard Reka UI `Select` — click/Enter/Space opens,
  arrow keys move selection, Enter/click commits, Esc closes without change —
  identical to the existing Mode and Response-status selects on this form.
- **No side effects on other fields.** Unlike Response status (whose `204`
  value disables/clears the body field), choosing Processing never disables,
  clears, or changes any other field on the form.
- **No confirmation on change.** Switching Async ↔ FIFO on Edit is a plain
  field edit, submitted with the rest of the form on **Save changes** — no
  destructive-confirmation dialog (this is not a delete/remove action; the
  project's `AlertDialog` convention is reserved for destructive actions per
  `docs/standards/design.md`).
- **Submit / validation:** unchanged from the rest of the form — Inertia
  `useForm()` round-trip, `form.errors.processing_mode` renders via
  `InputError`, first-invalid-field focus management already implemented in
  `ProxyForm.vue`'s `onError` callback covers this field automatically
  (it queries `[aria-invalid="true"]`).
- **Badges (Show/Index) are read-only** — no click behavior, consistent with
  the existing Mode badge in both locations.

## Accessibility
- **Label association:** `Label for="processing_mode"` ↔ `SelectTrigger
  id="processing_mode"` — programmatic association, not placeholder-only.
- **Help/error linkage:** `aria-describedby="processing-help processing-error"`
  on the trigger, pointing at the help paragraph and the (conditionally
  rendered) `InputError` span — following the more rigorous pattern already
  established by the #3 Response-status field (the existing Mode field
  predates this and lacks full `aria-describedby` wiring; this spec does not
  ask to retrofit Mode, only specifies the new field correctly per
  `docs/standards/design.md`'s binding accessibility baseline).
- **Keyboard:** fully operable via keyboard alone (Tab to the trigger,
  Enter/Space to open, arrow keys to select, Enter to commit) — inherited from
  Reka UI's `Select` per the project's accessibility baseline; no custom
  keyboard handling needed.
- **Badges (Show header, Index column):** text-only meaning (`Async`/`FIFO`),
  never colour-alone, consistent with the project's "colour is never the sole
  carrier of meaning" rule; identical to how the existing Mode badge already
  satisfies this.
- **Focus management:** on a validation error touching this field, focus
  moves to it via the form's existing first-`[aria-invalid="true"]` handler —
  no new focus-management code path is introduced.
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`'s accessibility
  baseline; no new interactive pattern is introduced beyond the already-vetted
  `Select`.

## Responsive Behavior
- **Form field:** `SelectTrigger` uses the same `w-full sm:w-64` pattern as
  Mode and Response status — full-width below `sm`, fixed width at `sm` and
  above (`docs/standards/design.md` → Responsive targets: "a field that's
  fixed-width on larger screens goes full-width below `sm`, never the
  reverse").
- **Show header badges:** the header row (`name` + badges) is a `flex`
  container; on very narrow viewports it wraps like any flex row with
  multiple inline children — no new responsive behavior beyond what the
  existing Mode badge already relies on.
- **Index column:** the `Processing` column sits inside the existing
  horizontally-scrollable `Table` container (`design-01`'s established
  pattern — tables scroll horizontally on narrow viewports rather than
  reflowing to cards); adding one more `Badge` column does not change that
  behavior.

## Open Questions
**None.** The PRD's UX Direction is unambiguous on control semantics (Async
default / FIFO opt-in / orthogonal to Mode / honest tradeoff framing), and
every layout/control/copy decision above resolves within this role's
authority using directly reusable, already-approved patterns from
`design-01` and `design-03`. Two judgment calls are flagged above for the
PM's attention during design-gate review (not blocking, both reversible with
no ripple to the rest of the spec):
1. Show-page: header badge (this spec) vs. a dedicated card (the #3
   precedent) — this spec's rationale is the information-shape difference
   (single value vs. status+body across four states).
2. Index list: adding a `Processing` column was not explicitly named in the
   PRD's UX Direction; this spec includes it under this role's "cover every
   surface" responsibility, with a trivial opt-out if the PM prefers to defer
   it.

### PM design-gate rulings (Product Manager, 2026-08-04)

**Ruling 1 — Show page: header Badge is APPROVED (not a card).** The Designer's
information-shape rationale is correct and consistent with precedent. `design-03`
gave Response a dedicated card precisely because it carries **two** fields
(status *and* body) across **four** distinct states — a card's worth of content.
`processing_mode` is a single, always-set enum with no secondary data, exactly
the shape of the existing **Mode** value, which already renders as a header
`Badge` (`design-01` Screen 3). Surfacing Processing as a second `Badge` beside
Mode is the consistent choice; a whole card would be disproportionate to its
information content and would fragment the header-badge pattern already
established for the same-shaped Mode field. Approved as specified.

**Ruling 2 — Index `Processing` column is APPROVED (keep it).** This does not
expand the PRD's requirements — it is a presentation decision within the
Designer's authority to decide how a persisted attribute appears on every
surface of the resource, and it mirrors an existing precedent rather than
inventing anything. The **Mode** column already exists on the Index table
(`design-01` Screen 1); showing the same-shaped `processing_mode` beside it,
using the identical `Badge` pattern with data already returned per proxy,
removes an arbitrary inconsistency exactly as `design-03`'s PM-approved Show
addendum did for the read-only Response fields. No new requirement is asserted
(the value is read-only, no new interaction or data), and FIFO's
"more-serialized/slower" operational characteristic is genuinely useful to scan
at a glance. Keep the column as specified. (Had this introduced a new
interaction or a requirement the Owner had not stated, it would have been
returned or raised to the Owner; it does neither.)

Both rulings match the Designer's chosen design; no change to the spec is
required for approval. The recommended `proxyProcessingModes.ts` data-const is a
non-gating file-organization note for the Senior Developer, consistent with the
`data/` + `DataOption` convention ratified during #3.

No technical-feasibility doubt is raised to the Principal Engineer — the
control is a plain `Select` bound to a form field, no different in kind from
the already-shipped Mode/Response-status fields.

## Handoff
- **Inputs:** `docs/product/prd-04-queued-processing.md` (Approved, esp. UX
  Direction, AC4–AC7); `docs/design/design-01-walking-skeleton.md` (Screen 2
  form pattern, Screen 3 header-badge pattern, Screen 1 list-table pattern);
  `docs/design/design-03-decoupled-upstream-response-show.md` (Show-page
  card-vs-badge precedent, verbatim-label-consistency convention);
  `resources/js/pages/proxies/ProxyForm.vue`, `Show.vue`, `Index.vue`,
  `Create.vue`, `Edit.vue` (current implementation studied for exact
  patterns); `resources/js/data/proxyResponseStatuses.ts`,
  `resources/js/types/data.ts` (`DataOption` convention);
  `docs/standards/design.md` (binding design/accessibility standards).
- **Outputs:** this design spec.
- **Dependencies:** no new npm dependency, icon, or `ui/*` primitive —
  `Select`, `Badge`, `Label`, `InputError`, `Table` are all already in use in
  `resources/js/pages/proxies/`.
- **Outstanding Questions:** None blocking. Two non-blocking judgment calls
  flagged above for PM review (Show badge-vs-card; Index column inclusion).
  PRD-04's own outstanding items (V8, Q-04-01) are unaffected by and do not
  gate this spec — Q-04-01 (data-model/mechanism) is the Principal Engineer's
  at technical design, after this design gate.
- **Next Agent:** Product Manager, to approve this spec against PRD-04 (design
  gate, delegated). On approval, hands to the Principal Engineer for technical
  design (PRD-04 Q-04-01 — queue mechanism, `processing_mode` data-model
  shape, and composing `::dispatch` with the #3/#3-preserved response and
  ADR-010 capture guarantees).
