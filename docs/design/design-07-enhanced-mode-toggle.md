# Design Spec: Enhanced-mode toggle

- **Status:** In Review
- **Author:** Designer
- **PRD:** `docs/product/prd-07-enhanced-mode-toggle.md` (Approved, Project Owner,
  2026-08-21, incl. the Q-07-01 ruling in AC13/AC14/AC17)
- **Approved by / date:** *Pending — Product Manager (design gate, delegated per
  `CLAUDE.md`).*
- **Backlog item:** Roadmap #7 (`docs/product/roadmap.md`)

> **Scope note.** #7 adds **no new page and no new navigation entry** (UX
> Direction — "no new surface"). It makes two existing surfaces true and
> consequential: **(1)** the existing proxy create/edit form's **Mode** field —
> brought to the form's first-class field pattern, given truthful, complete
> present-tense help text (AC12), and, on an Enhanced→Simple edit, an inline
> disclosure of exactly what changes and what does not (AC13/AC14); **(2)** the
> proxy **Show** page — a present-tense one-line statement of what the proxy's
> current mode means, without duplicating `design-06`'s Retry policy card. It
> also specifies a **required correction** to that Retry policy card's existing
> binding, which today would leak a dormant retry policy once the backend stops
> nulling it out (AC14(b) — see *Required correction* under Screen 2). It does
> **not** touch the Processing field, the Retry-policy field pair themselves,
> the Events/Replay surfaces, or any data model or resolution mechanism — all
> Principal Engineer territory, and Q-07-02 (mode-gated composition, in-flight
> resolution, extensibility) remains open and non-blocking for this gate.

## Decisions carried forward from Q-07-01 (not re-litigated here)
Owner rulings, already rendered into PRD-07; restated only so this spec's
choices read as consequences, not inventions:
- **(a) Retain.** A downgrade erases nothing. Existing dispatched outputs live
  out their normal 30-day window; retention expiry stays the only eraser
  (AC13).
- **(b) Preserved dormant and restored.** A persisted retry policy is **kept**
  on save-as-Simple, **inert** while Simple (Simple always resolves the system
  default — 5 attempts, exponential — regardless of the stored columns), and
  **in force again with its previous values** on a later return to Enhanced,
  with no re-entry (AC14). The mode-gated resolution mechanism is the
  Principal Engineer's (Q-07-02(4)); this spec assumes it will exist and
  specifies the presentation and form behavior that depend on it, flagging
  each dependency explicitly.
- **(c) Unrestricted.** Either direction, any time, any number of times,
  including with events queued, retrying, or in flight (AC17). No cooldown, no
  drain-before-switch, no one-way transition — the UI adds no gating,
  confirmation, or warning keyed to outstanding deliveries.

## Overview
A team member configuring a proxy sees the existing **Mode** field on the
create/edit form finally behave like every other field on that form: a
labelled `Select` with help text that states, in plain present tense, exactly
what Enhanced adds today — a separately stored dispatched payload and
per-proxy retry configuration — and that automatic retry, payload capture,
retention, and replay apply to every proxy regardless of Mode. Creating a
proxy is consequence-free either way. **Editing** an existing Enhanced proxy
down to Simple surfaces one additional thing, right where the choice was
made: a plain-language, non-blocking notice that nothing is deleted — stored
outputs live out their normal 30-day window, and a saved retry configuration
is kept but goes dormant, reactivating with its old values if Enhanced is
turned back on. Nothing about switching is restricted or warned against on
account of in-flight events. On the proxy's **Show** page, a one-line,
present-tense statement under the header states what the proxy's current mode
means today, pointing at — not repeating — the existing Retry policy card,
which itself gets one required correction: it must show the system default for
a Simple proxy **unconditionally**, never a value read from the proxy's
(possibly dormant) stored columns.

## Scope boundaries (confirmed, not designed here)
Restated so this spec reads as complete against every AC, not only the
UI-bearing ones:
- **AC8 — not an entitlement.** No plan, tier, subscription, quota, or
  team-level enablement gate is introduced anywhere in this spec — Enhanced
  remains a plain `Select` option, exactly as unrestricted as Simple. Nothing
  to design; its absence is the compliance.
- **AC15 — Mode/Processing independence.** Already established and unchanged:
  the Processing field's existing help text ("Independent of the Mode setting
  above...") already states the two axes are unrelated; Mode's corrected help
  text (Screen 1) makes no mention of ordering/throughput. #7 adds nothing
  here — the independence this AC requires predates this item and this spec
  preserves it verbatim.
- **AC18 — extensibility.** The Mode `Select` is untouched in shape (still
  exactly two items) and its help text is written as an enumerable list of
  "what Enhanced does" — a future item (#8/#9/#12) adding a governed capability
  is a clause added to that same list, per AC12, not a new control, a second
  gate, or a change to this spec's structure.
- **AC19–AC25 — no mapping, no new storage/retention behavior, no retry/replay
  semantic change, no processing-mode change, no third mode or sub-toggles, no
  notifications/audit surface, no numeric targets.** None of this spec's two
  additions (the downgrade disclosure, the Retry-policy-card mode gate)
  changes a stored value, a default, a cap, a window, or adds a control beyond
  the existing Mode `Select` — the Retry policy card correction (Screen 2)
  changes **which value is read**, never a retry value, default, or strategy
  itself (AC21 is explicitly preserved: "AC14's dormant-policy rule changes no
  value either").
- **AC4 — existing proxies untouched.** No UI-bearing consequence; a pure
  backend/migration guarantee this spec has no surface to affect.

## User Flows

### Flow A — Create a proxy and choose a mode (consequence-free)
*(User story: "the mode control tells me what Enhanced actually does today,
so I'm not choosing against a promise the product can't keep yet.")*
1. Team member opens **New proxy** (`design-01` Flow A).
2. Leaves **Mode** at its default, **Simple**, or selects **Enhanced** — reads
   the corrected help text (Screen 1) before deciding. Nothing is saved yet;
   no disclosure is shown at Create, in either direction — there is no
   existing proxy state to preserve or lose (UX Direction: "the same control
   serves proxy creation, where the choice is consequence-free").
3. Continues filling the rest of the form and submits (unchanged — AC1/AC2/AC3
   are already satisfied by the existing create path; #7 adds no new field
   here).

### Flow B — Upgrade an existing proxy, Simple → Enhanced
*(User story: "turn enhanced mode on for an existing proxy... without
recreating the proxy or losing its history.")*
1. Member opens **Edit** on a Simple proxy (`design-01` Flow D).
2. Changes **Mode** to **Enhanced**. No disclosure renders — the UX Direction
   states upgrading needs no equivalent treatment to the downgrade notice.
3. **If this proxy carries a dormant retry policy from an earlier Enhanced
   period,** the (now-visible) Retry policy section pre-fills with those
   previous values automatically, not blank (AC14(c) — see *Interactions*,
   Screen 1, and the flagged dependency on Q-07-02(4)). If it never had one,
   the section shows its normal unconfigured state (`design-06` Flow F).
4. Submits → same validation/success handling as any other field on this form.
   Enhanced-only steps (AC6) begin applying to this proxy's subsequent events
   (AC9); nothing about its existing destinations, ingest URL, or history
   changes (AC1).

### Flow C — Downgrade an existing proxy, Enhanced → Simple
*(User stories: "turn enhanced mode back off... stops doing the extra work";
"told what a downgrade does and does not change before it happens.")*
1. Member opens **Edit** on an Enhanced proxy.
2. Changes **Mode** to **Simple**. Immediately, directly below the Mode
   field, a **downgrade disclosure** (Screen 1) appears — three factual
   points, all required by AC13/AC14: enhanced-only steps stop for future
   events; stored dispatched outputs are kept and expire on their normal
   schedule; any saved retry configuration is kept but goes dormant and
   reactivates if Enhanced is chosen again. The Retry policy fieldset (if it
   was showing) unmounts per `design-06` Flow F step 4 — any **unsaved**
   in-session values in it are cleared to their default-sentinel state; this
   does **not** touch what is already persisted in the database (see
   *Interactions*).
3. Member reads the disclosure — no separate confirmation click, no modal, no
   checkbox is required to proceed (see *Flagged design call 1*) — and clicks
   **Save changes**.
   - **Changes mind:** switches Mode back to **Enhanced** before saving — the
     disclosure disappears; nothing was ever submitted.
4. On save: this proxy's dispatched-output store and retry configurability
   stop applying to events processed from now on (AC9); nothing stored is
   erased (AC13); the retry policy (if any) is preserved, dormant (AC14);
   destinations, ingest URL, and history are unaffected (AC1).

### Flow D — Switch mode with events queued, retrying, or in flight
*(User story: "change mode without losing the events already queued or
retrying"; AC10/AC11/AC17.)*
1. Member changes **Mode** (either direction) on a proxy that currently has
   events queued, claimed under FIFO, in flight under Async, awaiting a
   scheduled retry, or mid-replay, and saves.
2. **Nothing in the UI blocks, warns against, gates, or asks the member to
   confirm on account of outstanding deliveries** — no such control exists
   anywhere in this spec, by design (AC17). In-flight safety is a backend
   guarantee (AC10), not a UI-enforced precondition.
3. The proxy's subsequent events are processed under the pipeline the
   **current** mode composes at the time each is processed (AC9); a single
   proxy's event history may afterward show events treated under either mode,
   or one delivery attempt made under one mode and a later retry under the
   other (AC11). **No screen in this app currently displays a per-event or
   per-delivery "mode" field at all** (`design-06`'s Events list/detail
   surfaces carry no such attribute), so there is nothing to mis-flag as an
   error or inconsistency — AC11 is satisfied by the absence of anything to
   contradict it, not by new suppression logic. No design change to those
   surfaces is introduced or needed by #7.

### Flow E — View a proxy's mode and its current meaning (Show)
*(User story: "the mode control tells me what Enhanced actually does today.")*
1. From the Show page, the member sees the existing **Mode** badge in the
   header (unchanged — `design-01`/`design-04` precedent) and, directly below
   the header row, a new one-line, present-tense statement of what that mode
   means for this proxy today (Screen 2), pointing to the Retry policy card
   for the actual retry values rather than repeating them (AC16).
2. If the proxy is Simple, the Retry policy card (`design-06` Screen 1,
   corrected — see Screen 2 below) shows the fixed system default,
   **regardless of any dormant value this proxy may be holding** (AC14(b)).

## Screens & States

### Screen 1 — Create / Edit Proxy form — Mode field (extends `design-06` Screen 5)
Same location as today, in the **Details** section, between **Name** and
**Processing**:
```
Details
  Name
  Mode              (this spec — first-class field pattern + downgrade disclosure)
  Processing
Retry policy         (design-06, unchanged — renders only when Mode = Enhanced)
Response
Destinations
```

**Bring Mode to the form's first-class field pattern (UX Direction).** Today
`mode`'s `SelectTrigger` carries no `aria-describedby`/`aria-invalid` wiring
and its help/error text are not id-linked — the one field on this form still
short of the pattern every other field (`processing_mode`, `response_status`,
the retry-policy pair) already follows. Bring it into line:

```
Label "Mode" for="mode"
Select v-model="form.mode" :disabled="form.processing"
  SelectTrigger id="mode"
                aria-describedby="mode-help mode-error"
                :aria-invalid="form.errors.mode ? 'true' : undefined"
    SelectItem "simple"   → Simple
    SelectItem "enhanced" → Enhanced
p#mode-help  (corrected copy, below)
span#mode-error → InputError :message="form.errors.mode"
[downgrade disclosure — conditional, below]
```

**Corrected help text (AC12).** The currently shipped copy names only retry
configurability and omits the dispatched-output store — incomplete against
AC6/AC12's "complete set" requirement. Replacement, naming both AC6
capabilities and the mode-independent guarantees, in present tense, with no
roadmap numbers and no implication that mapping exists:

> "Enhanced mode stores the payload actually dispatched, separately from the
> payload received, and lets this proxy configure its own retry attempts and
> backoff strategy below. Automatic retry, payload capture, retention, and
> replay apply to every proxy regardless of Mode."

**Downgrade disclosure — conditional `Alert`, Enhanced → Simple only.**
Renders when, and only when, the form's **loaded** mode was Enhanced and the
**current** selection is Simple:

```ts
const isDowngrading = computed(
  () => props.initial.mode === 'enhanced' && form.mode === 'simple',
);
```

- **Never true on Create** — `Create.vue` always passes `initial.mode:
  'simple'` (confirmed in the current implementation), so a member choosing
  Enhanced then Simple in the same create session never sees it — matching
  "consequence-free" at Create.
- **Never true for a proxy that is already Simple and stays Simple** —
  re-opening Edit on an already-downgraded proxy without touching Mode again
  shows nothing; the disclosure is about the transition, not a standing state.
- **True regardless of whether a retry policy was ever configured** — the
  stored-output point (AC13) always applies to an Enhanced proxy's past
  events; the copy below is worded to hold whether or not a retry policy
  exists, so no extra prop/condition is needed to decide whether to show
  bullet 3.

```
div aria-live="polite"                      (announces appearance to AT)
  Alert (Info-styled, TeamInvitationAlert.vue precedent — first reuse for a
         non-team-invitation context)
    AlertTitle "Switching to Simple mode"
    AlertDescription
      ul
        li "Enhanced-only steps — payload storage and retry configuration —
            stop running for events processed after you save. Automatic
            retry, payload capture, retention, and replay are unaffected;
            they apply to every proxy regardless of mode."
        li "Dispatched payloads already stored for this proxy's past events
            are kept, unchanged, and expire on their normal 30-day
            schedule — the same as always. Nothing is deleted by this
            switch."
        li "Any retry configuration you've saved for this proxy is kept but
            stops applying while it's Simple — the system default (5
            attempts, exponential) governs meanwhile. It applies again, with
            the same values, if you turn Enhanced back on."
```

**States.**
- **Mode = Enhanced (create or edit):** help text as above; no disclosure; the
  Retry policy fieldset renders per `design-06` Flow F (unchanged).
- **Mode = Simple, not downgrading** (create default, or an already-Simple
  proxy on edit): help text as above; no disclosure; no fieldset.
- **Mode = Simple, downgrading** (`isDowngrading` true): disclosure renders as
  specified; Retry policy fieldset does not render (mode = Simple).
- **Toggling back to Enhanced before submit:** disclosure disappears
  immediately (reactive computed); nothing was ever sent to the server.
- **Validation error / submitting / disabled:** identical mechanics to every
  other field on this form (`form.processing`, first-invalid-field focus
  management) — #7 introduces no new validation rule on `mode` itself (AC5's
  gate is the existing update permission, enforced server-side as today).

**Interaction — dormant-value restore on upgrade (AC14(c)).** The edit form's
initial state (`props.initial.retryAttemptLimit` /
`retryBackoffStrategy`) is populated from the proxy's persisted columns
**regardless of the proxy's current mode** — including a Simple proxy's
dormant values — and `design-06`'s existing `watch(isEnhanced, …)` clearing
effect only fires on a mode change made **within the current session**, never
on mount. Consequence, with no additional client logic beyond what
`design-06` already ships: opening Edit on a Simple proxy that carries a
dormant retry policy and switching Mode to Enhanced in that same session
re-shows its previous Attempts/Backoff values automatically — satisfying
"applies again... without the member re-entering anything." `design-06`
Flow F's in-session clearing (switching Enhanced→Simple→Enhanced again within
one sitting loses in-session, unsaved values) is unchanged and not in
conflict — it governs the current session's typed values, never what was
already persisted before the page loaded (PRD-07 AC14, closing paragraph).

**⚠ Dependency on Q-07-02 (Principal Engineer, open, non-blocking for this
gate).** The restore-on-upgrade behavior above assumes: (1) the update
endpoint stops unconditionally nulling `retry_attempt_limit`/
`retry_backoff_strategy` on an Enhanced→Simple save (reversing T30, per
review-06 Minor 8 obligation (a)); and (2) the Edit page's initial prop
continues to carry those persisted, possibly-dormant values un-redacted, so
the client has something to restore. If the Principal Engineer's technical
design instead redacts the dormant value from this payload (e.g., serving it
only through a separate, explicitly mode-gated read path), this restore-on-
mount mechanism cannot work exactly as specified and must be revisited at
technical design — flagged here rather than assumed. Either way, AC14(b) —
**never presenting** a dormant value while the proxy is Simple — is
unaffected: the Retry policy fieldset is already gated on `isEnhanced` and
does not render at all while Simple, so the dormant value sitting in
(unrendered) form state is never shown to the user.

### Screen 2 — Proxy detail (Show) — Mode meaning + Retry policy card correction
**(a) One-line present-tense meaning, under the header (AC16).** The PRD's UX
Direction refers to "the Mode row... in the Details card"; this Show page has
no card literally named "Details" — Mode is a header `Badge` (`design-01`,
extended by `design-04`). Reading the Direction's intent as "wherever Mode is
shown, state what it means," this spec adds a single muted line directly
below the existing header row (name + Mode badge + Processing badge), **not**
a new card — proportionate to "sized for a detail row" and consistent with
"no new surface." *(Flagged design call 2, below.)*

```
<div class="flex items-center gap-3">          (existing header, unchanged)
  <h1>{{ proxy.name }}</h1>
  <Badge>{{ Mode }}</Badge>
  <Badge>{{ Processing }}</Badge>
</div>
<p class="text-sm text-muted-foreground">      (NEW)
  {{ modeSummary }}
</p>
```

Copy, present tense, referencing — not restating — the Retry policy card:
- **Simple:** "Simple mode — no dispatched-output storage or per-proxy retry
  configuration; automatic retry, payload capture, retention, and replay
  still apply. See Retry policy below for what governs this proxy's
  retries."
- **Enhanced:** "Enhanced mode — stores this proxy's dispatched payload
  separately from what it received, and lets you configure its retry
  attempts and backoff below."

**(b) Required correction — Retry policy card must be mode-gated at the
presentation layer (AC14(b), AC16).** Today `retryAttemptsDisplay`/
`retryBackoffDisplay` (`Show.vue`) call
`proxyRetryAttemptLimitDisplay`/`proxyRetryBackoffStrategyDisplay` directly on
`props.proxy.retry_attempt_limit`/`retry_backoff_strategy`, with no mode
branch — safe **only** as long as those columns are guaranteed `null` for
every Simple proxy (today's T30 behavior). Once a Simple proxy can genuinely
hold a **dormant** value (Q-07-01(b)/AC14), this binding would start
rendering it — a direct breach of AC14(b)/AC12 ("never a stored value that has
no effect"). Required fix, same shape as the existing computed properties,
no new component:

```ts
const retryAttemptsDisplay = computed(() =>
  props.proxy.mode === 'simple'
    ? proxyRetryAttemptLimitDisplay(null)
    : proxyRetryAttemptLimitDisplay(props.proxy.retry_attempt_limit),
);
const retryBackoffDisplay = computed(() =>
  props.proxy.mode === 'simple'
    ? proxyRetryBackoffStrategyDisplay(null)
    : proxyRetryBackoffStrategyDisplay(props.proxy.retry_backoff_strategy),
);
```

This guarantees a Simple proxy's card **always** reads `5 (default)` /
`Exponential (default)` plus the existing simple-mode note, regardless of
what its stored columns hold — closing exactly the gap review-06 Minor 8(c)
named ("add the Show-page suppression Q-07-01(b) consequence (1) requires").
No other part of `design-06`'s Screen 1 Retry policy card changes — same
placement (after Destinations), same `dl`/`dt`/`dd` shape, same simple-mode
note.

**⚠ Same Q-07-02 dependency as Screen 1.** This correction assumes the Show
payload's `proxy.retry_attempt_limit`/`retry_backoff_strategy` fields
continue to carry the actual (possibly dormant) persisted values for a Simple
proxy — the client-side gate above is the enforcement point precisely
**because** the raw value is still present in the payload. If the Principal
Engineer's technical design instead nulls or omits the field server-side for
a Simple proxy, this client-side computed becomes redundant (harmless, not
wrong) rather than load-bearing; either mechanism satisfies AC14(b), and which
one is chosen is not a design-gate blocker.

**(c) Index list — no change.** The proxies Index table's existing **Mode**
column (a bare `Badge`, no description — `design-01`/`design-04`) already
satisfies AC12 trivially: it states a fact ("Simple"/"Enhanced"), not a claim
about behavior, so there is nothing inaccurate to correct. No retry value is
shown on Index today and none is added — the UX Direction's "no new surface"
mandate and the "cover every surface" responsibility are both satisfied by
leaving it exactly as `design-04` left it.

**States (both additions):**
| Case | Mode summary line | Retry policy card |
|---|---|---|
| Simple, never configured | Simple copy above | `5 (default)` / `Exponential (default)` + simple-mode note |
| Simple, holding a dormant policy | Simple copy above (identical — no leak) | `5 (default)` / `Exponential (default)` + simple-mode note (**identical to the row above** — this is the point of the correction) |
| Enhanced, unconfigured | Enhanced copy above | `5 (default)` / `Exponential (default)` (no simple-mode note) |
| Enhanced, configured | Enhanced copy above | e.g. `8` / `Fixed interval` |

No loading/error states beyond the page-level ones already specified
(`design-01` Screen 3) — both additions render from data already on the Show
payload.

## Components
| Role | Component | Status |
|---|---|---|
| Mode field control | `Select`/`SelectTrigger`/`SelectContent`/`SelectItem`/`SelectValue` | Reused, unchanged shape — only wiring (`aria-describedby`/`aria-invalid`) is added |
| Mode field label/error | `Label`, `InputError` | Reused |
| Downgrade disclosure | `Alert`, `AlertTitle`, `AlertDescription` | Reused primitives — **first application use of `AlertTitle`**; `Alert`/`AlertDescription` already reused for the FIFO note (`design-06`) |
| Downgrade disclosure icon | `Info` (`@lucide/vue`) | Reused — same icon/styling as `TeamInvitationAlert.vue`/the FIFO note |
| Show header (Mode/Processing badges) | `Badge` `variant="secondary"` | Reused, unchanged |
| Show mode-summary line | plain `p`, `text-sm text-muted-foreground` | Reused text treatment (matches every existing card caption on this page) — no new component |
| Retry policy card | `Card`, `dl`/`dt`/`dd` | Reused, unchanged layout — only its two computed values are corrected |

**No new npm dependency, icon library, or `ui/*` primitive is introduced.**
`AlertTitle` is an already-generated primitive with no prior application use
(`Alert`/`AlertDescription` are already in use via `TeamInvitationAlert.vue`
and `design-06`'s FIFO note) — this is its first real use, not a new
addition, the same pattern `design-06` documented for `Checkbox`/`Collapsible`.

## Interactions
- **Mode select semantics** are unchanged — standard Reka UI `Select`, no
  confirmation dialog on change itself (a routine field edit, consistent with
  how Processing and Response status are already edited inline, per
  `docs/standards/design.md`'s reservation of `AlertDialog` for destructive
  actions only — this is not one).
- **The downgrade disclosure is not a gate.** It renders, but does not block,
  delay, or require an extra click before **Save changes** is enabled — no
  checkbox, no "I understand," no second confirmation step. This is a
  deliberate proportionality choice given the Owner's non-destructive ruling;
  see *Flagged design call 1*.
- **The disclosure is purely reactive to the two mode values in play** (the
  form's loaded value vs. its current selection) — it carries no server
  round-trip, no fetch, and no dependency on whether a retry policy or any
  stored output actually exists for this proxy (the copy is written to hold
  either way).
- **Retry-policy field mount/unmount** is unchanged from `design-06` Flow F:
  switching Enhanced→Simple clears in-session field values to their
  default-sentinel state as a data operation, not merely a visual toggle. The
  **only** new interaction is that the fields' **initial** (page-load) values
  now may be non-null while Simple (a dormant persisted value) without this
  spec asking for that to be cleared — because the fieldset simply doesn't
  render while Simple, nothing is exposed either way.
- **No interaction anywhere in this spec is conditioned on in-flight event
  state** (queued, claimed, retrying, mid-replay) — per AC17, deliberately.

## Accessibility
- **Mode field:** `Label for="mode"` / `SelectTrigger id="mode"` association
  (unchanged); `aria-describedby="mode-help mode-error"` and
  `:aria-invalid="form.errors.mode ? 'true' : undefined"` newly added, bringing
  it to parity with `processing_mode`/`response_status` per
  `docs/standards/design.md`'s screen-reader requirements (help/error linked,
  not just visually adjacent).
- **Downgrade disclosure:** wrapped in an `aria-live="polite"` region so its
  appearance (triggered by the member's own Mode selection, not a page
  navigation) is announced to assistive technology — the same pattern this
  app already uses for asynchronous state changes that aren't a full page
  visit (`CopyField`'s copy-result announcement). `AlertTitle` +
  `AlertDescription` give the block a programmatic heading/description
  relationship, consistent with how `Dialog`/`AlertDialog` content is already
  required to pair a title with a description.
- **Retry policy card correction:** presentation-only change, no new
  interactive element; the existing card's accessibility treatment
  (`design-06` Screen 1) is untouched.
- **Show mode-summary line:** real text content, read by assistive technology
  exactly as sighted users see it — no icon-only or color-only cue, consistent
  with the project's "colour is never the sole carrier of meaning" rule
  (already true of the Mode badge itself).
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`'s baseline; no new
  interactive pattern beyond already-vetted Reka UI primitives (`Select`,
  `Alert`).

## Responsive Behavior
- **Mode field:** unchanged `SelectTrigger` sizing (`w-full sm:w-64`, matching
  every other enum field on this form).
- **Downgrade disclosure:** the `Alert` sits full-width within the form's
  existing `max-w-2xl` column, same as every other block-level element on
  this form; its bulleted list wraps naturally at any width, no bespoke
  breakpoint handling.
- **Show mode-summary line:** a single wrapping paragraph within the existing
  `max-w-3xl` detail-page column; wraps like any other card caption on this
  page.
- **Minimum supported width:** 360px, per the standing default in
  `docs/standards/design.md` — no feature-specific override.

## Open Questions
**None blocking this spec's approval.** Two flagged, reversible judgment calls
for the Product Manager's design-gate attention (matching the `design-04`/
`design-06` precedent for flagging non-blocking calls), plus the two Q-07-02
dependencies already called out inline above (not open questions — routed
technical concerns the Principal Engineer resolves at technical design, not
gaps in this spec):

1. **The downgrade disclosure is an inline, non-dismissible `Alert`, not an
   `AlertDialog`/confirmation step.** The Owner's Q-07-01 ruling makes the
   downgrade genuinely non-destructive (nothing is deleted, nothing
   discarded), so the UX Direction's own framing — "the disclosure's weight
   should be proportionate... whether it is inline help, a confirmation step,
   or both... is the Designer's call" — is read here as calling for the
   lighter treatment: informative, not gate-keeping. If the Product Manager
   judges that a downgrade still warrants an explicit confirm-click (e.g., to
   match the deliberateness standard `design-06` applied to replay), the swap
   to a plain `Dialog` with a "Continue" button is the same shape of change
   `design-06`'s own flagged call 1 described — low-risk, no ripple to the
   rest of this spec.
2. **The Show page's present-tense meaning is a one-line caption under the
   header, not a dedicated "Mode" card.** The PRD's UX Direction refers to "the
   Mode row... in the Details card," but no card literally named "Details"
   exists on this Show page (Mode is a header badge, per `design-01`,
   unchanged by `design-04`). This spec reads that phrase as referring to the
   header area generally and sizes the addition to "a detail row" — a single
   line, not a new card — consistent with `design-04`'s ruling that a
   single-value attribute with no complex secondary state doesn't warrant a
   dedicated card (the caption here carries a sentence of context, more than a
   bare badge, but not four states' worth of content the way Response earned
   its card). If the Product Manager reads the PRD's "Details card" phrasing
   as calling for an actual dedicated card, the swap is additive and
   independently reversible.

No requirement gap was found and no technical-feasibility doubt is raised
beyond the two Q-07-02 dependencies already named inline (Screen 1's
restore-on-upgrade mechanism and Screen 2's mode-gating enforcement point),
both of which are routed to the Principal Engineer's already-open,
non-blocking Q-07-02 rather than treated as blockers here.

## Handoff
- **Inputs:** `docs/product/prd-07-enhanced-mode-toggle.md` (Approved, esp. UX
  Direction and AC1–AC25); `docs/questions/prd-07-q-07-01-mode-switch-
  consequences.md` (RESOLVED, Project Owner, 2026-08-21 — the (a)/(b)/(c)
  rulings this spec's downgrade disclosure and restore-on-upgrade behavior are
  built on); `docs/questions/prd-07-q-07-02-mode-step-composition.md` (OPEN,
  Principal Engineer, non-blocking — the two dependencies flagged inline route
  here); `docs/reviews/review-06-retry-replay.md` (Minor 8 and Ruling 2 — the
  three obligations #6 owes #7, which this spec's Screen 1/Screen 2 mechanisms
  and the flagged dependencies are written to align with);
  `docs/design/design-06-retry-replay.md` (Screen 1 Retry policy card and Flow
  F/Flow G — extended and, in one place, corrected here; Screen 5's Mode
  help-text flag, now superseded by this spec's corrected copy);
  `docs/design/design-04-queued-processing.md` (header-badge-vs-card
  precedent and its PM ruling, applied to Screen 2's flagged call 2);
  `docs/design/design-01-walking-skeleton.md` (Mode field/badge origin,
  create/edit form pattern); `docs/architecture/adr-002-simple-enhanced-mode-
  attribute.md` (the attribute and gate this item makes user-meaningful, not
  re-models); `resources/js/pages/proxies/{ProxyForm,Show,Create,Edit,Index}.vue`,
  `resources/js/types/proxies.ts`, `resources/js/data/proxyRetryBackoffStrategies.ts`,
  `resources/js/components/TeamInvitationAlert.vue`,
  `resources/js/components/ui/alert/*` (current implementation studied for
  this spec — confirmed the exact gaps this spec closes: unwired Mode
  `aria-describedby`, the incomplete Mode help text, and the unconditional
  `retryAttemptsDisplay`/`retryBackoffDisplay` bindings); `docs/standards/
  design.md`.
- **Outputs:** this design spec.
- **Dependencies:** no new npm dependency, icon, or `ui/*` primitive.
  `AlertTitle` is an already-generated, currently-unused primitive — this
  spec is its first real application use, not an addition.
- **Outstanding Questions:** None blocking. Two flagged, reversible judgment
  calls above for the Product Manager's design-gate review. Two dependencies
  on the Principal Engineer's resolution of the already-open, non-blocking
  **Q-07-02** are called out inline (Screen 1's dormant-value restore
  mechanism; Screen 2's mode-gating enforcement point) — neither gates this
  spec's approval, both must be checked against whatever Q-07-02 ultimately
  decides at technical design.
- **Next Agent:** **Product Manager**, to approve this spec against PRD-07
  (design gate, delegated per `CLAUDE.md`). On approval, hands to the
  **Principal Engineer** for technical design, which also resolves the open
  **Q-07-02** (mode-gated step composition, in-flight resolution,
  extensibility, and the two dependencies this spec flags against it).
