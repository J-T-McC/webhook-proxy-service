# Design Spec: Retry & replay

- **Status:** **Approved** (design gate, delegated per `CLAUDE.md`)
- **Author:** Designer
- **PRD:** `docs/product/prd-06-retry-replay.md` (Approved, Project Owner,
  2026-08-12)
- **Approved by / date:** **Product Manager, 2026-08-12.** Verified against
  PRD-06: every UI-bearing story covered; UX Direction honored (masked
  viewer + reveal per AC25; deliberate replay flow; enhanced-only retry
  config per AC1/AC2; three payload states distinct per AC15–AC17; replay
  both modes, R4 target selection, permission model per AC9/AC10/AC14).
  **All five flagged judgment calls accepted as designed** (PM rulings,
  2026-08-12): (1) plain `Dialog` for replay — correct, the binding standard
  reserves `AlertDialog` for destructive actions and deliberateness-via-content
  matches the UX Direction; (2) client-side aggregate delivery badge with
  worst-state-wins precedence — presentation only, AC23 intact; (3)
  vocabulary-complete "Not captured" badge — required by AC16, correct to
  fail safe; (4) received/raw payload only, no dispatched-output content —
  the correct reading of AC22/AC25 and the Owner's Q-06-02a ruling ("full
  raw payload"); (5) no Index-page Events shortcut — consistent with
  precedent, additive later. **Mode-field help-text correction endorsed**,
  with one PM constraint on the final copy at implementation: user-facing
  text must not carry internal roadmap numbers ("#8", "#5") and must not
  imply payload mapping already exists ("unlocks payload mapping" overstates
  until #8 ships) — accurate today: Enhanced mode enables per-proxy retry
  configuration; automatic retry itself applies to every proxy; mapping is
  not yet available. Final wording is the Designer's/implementer's within
  that constraint. Reveal-mechanism note recorded on Q-06-03 for the
  Principal Engineer.

> **Scope note.** #6 is the first user-facing surface over stored events
> (Q-05-01's deferred viewer lands here) plus retry-policy configuration. This
> spec covers three surfaces named in the PRD's UX Direction: **(1)** a
> per-proxy received-events list with non-content descriptors, payload state,
> and delivery state, plus a masked payload viewer with an explicit whole-
> payload reveal (AC25); **(2)** the manual **replay** flow (target selection,
> confirmation, feedback, traceability); **(3)** an **enhanced-mode-only**
> retry-policy configuration form (attempt limit + backoff strategy) on the
> existing proxy create/edit form, plus how retry state and terminal failure
> surface in delivery history. It does **not** specify retry scheduling,
> FIFO/dead-letter mechanics, the data model, or how replay composes with
> ADR-011 dispatch-by-reference — all Principal Engineer territory, gated by
> the open, non-blocking **Q-06-03**. Field-level obfuscation, sensitive-header
> policy, and the dispatched-output content are explicitly **not** rendered by
> this surface (PRD-06 AC22 — "the only payload-content exposure #6 adds is
> AC25's masked-by-default viewer... nothing beyond it").

## Overview
A team member opens a proxy and finds a new **Events** entry point next to
Edit/Delete. It leads to a paginated list of the proxy's received events —
received time, size, content type, a **payload state** badge (Retained /
Expired / Not captured) and an aggregate **delivery state** badge (Delivered /
Retrying / Failed). Opening an event shows its descriptors, its stored raw
payload — hidden behind a whole-payload mask by default, with a single
"view password"-style **Reveal** toggle that exposes the full raw payload on
explicit request — and its delivery history, grouped into the **original
delivery** and any **replays**, each showing per-destination status, attempt
counts, and (once exhausted) an explicit **terminally failed** state. From
either the list or the detail page, a **Replay** button opens a confirmation
dialog: the user picks specific destinations or all of the proxy's current
ones, reads a plain statement that this sends real traffic again, and
confirms — nothing is pre-selected, so replaying is always a deliberate,
multi-step action, never a one-click accident. A cleaned (expired) event shows
the same descriptors and delivery history but its payload block reads
"payload expired on schedule" with no reveal control, and its Replay
affordance is absent, never an error. Separately, on the existing proxy
create/edit form, an **enhanced-mode-only** "Retry policy" section lets a team
member set an attempt limit (1–10) and a backoff strategy (Exponential /
Fixed interval); simple-mode proxies show no such fields at all — the system
default (5 attempts, exponential) governs silently — and the form's FIFO
help text is extended to state the ordered-means-waiting trade-off a
retrying head imposes.

## Decisions carried forward from Q-06-01/Q-06-02 (not re-litigated here)
These are Owner rulings, already rendered into the PRD; restated only so this
spec's choices read as consequences, not inventions:
- Retry: **every proxy** gets automatic retry; only its **configurability**
  (attempt limit, strategy) is enhanced-mode only (AC1/AC2).
- Replay: available in **both** proxy modes, permission-gated to **all three
  roles**, no Member ownership limit (AC9/AC14).
- The payload viewer renders content **behind a whole-payload mask by
  default**, one **reveal** action, all-or-nothing, gated only by the existing
  proxy **read** permission (AC25).

## User Flows

### Flow A — View a proxy's received events (list)
*(User story: "see a proxy's received events and re-send a retained one".)*
1. From a proxy's **Show** page, the member clicks **Events**.
2. The **Events** list loads: one row per received event, newest first —
   received time, size, content type, **Payload** badge, **Delivery** badge.
3. **Empty:** no events yet → empty-state card, no error.
4. Member clicks a row (or its **View** action) → **Event Detail** (Flow B).

### Flow B — View an event's detail (descriptors, payload state, delivery state, terminal failure)
*(User stories: "retries stop after a limit and the delivery is clearly
marked terminal"; "a permanently failing event is set aside... after retries
are exhausted".)*
1. From the Events list or a direct link, the member opens an event.
2. Sees: descriptors (received time, size, content type), a **Payload state**
   badge, the masked **Payload** card (Flow C), and a **Delivery** card
   grouped into **Original delivery** and any **Replay — {time}** batches,
   each row showing a destination, its status badge (**Delivered** /
   **Retrying** / **Terminally failed**), and `Attempt N of {limit}`.
3. If a destination is **Terminally failed**, its row states retries are
   exhausted and it is available for replay (AC4, AC10) — no special action
   beyond the page-level **Replay** button (Flow D); the row is simply the
   place this fact is visible.
4. If the proxy is **FIFO** and this event's head is currently **Retrying**,
   an info banner states the line is held for its backoff (PRD-04 UX
   Direction; PRD-06 AC6).

### Flow C — Reveal a retained event's payload
*(AC25 — "view password"-style whole-payload reveal.)*
1. On a **retained** event's detail page, the **Payload** card shows a masked
   placeholder (never the real content) and a **Reveal payload** button.
2. Member clicks **Reveal payload** → the full raw payload renders verbatim
   in a bordered monospace block; the button becomes **Hide payload**.
3. Clicking **Hide payload** re-masks it. Navigating away and back always
   returns to **masked** — reveal state is never remembered across page
   loads (AC25: "content is never rendered unmasked without the user's
   explicit action").
4. No separate permission check: anyone who can view this page (existing
   proxy **read** permission) can reveal (AC14/AC25 — no distinct reveal
   permission).

### Flow D — Replay an event to specific or all destinations
*(User story: "re-send a retained one... to one, several, or all of its
destinations".)*
1. From an Events-list row or an event's detail page, the member clicks
   **Replay** (only rendered if the payload is **retained** and the user
   holds the replay permission — all three roles, no ownership limit).
2. The **Replay** dialog opens: a checklist of the proxy's **current**
   destinations (AC10), a **Select all** control, none pre-checked, and a
   plain-language statement that replay sends real traffic to whatever is
   checked. **Confirm** is disabled until at least one destination is
   checked.
3. Member checks destinations (or **Select all**) and clicks **Replay to N
   destination(s)**.
   - **Success:** dialog closes; a toast confirms ("Replay dispatched to N
     destination(s)"); the page's Delivery card gains a new **Replay —
     {time}** group with fresh per-destination attempt rows (AC12 — visibly
     distinguishable and traceable to this same event, no navigation away).
   - **Failure (request-level, e.g. permission revoked mid-session, network
     error):** dialog stays open, an inline error renders above the footer,
     **Confirm** re-enables; no partial dispatch is implied.
4. On a **FIFO** proxy, the dialog carries the same info note as Flow B step
   4's spirit, phrased for the action about to happen: "this proxy is FIFO —
   your replay joins the back of the line and is delivered in received
   order" (AC11 — ordered at the time of replay, not re-inserted historically).

### Flow E — Encounter a cleaned (expired) event
*(User story: "a replay-ineligible event says why — expired vs never
captured — so a missing payload reads as lifecycle, not data loss".)*
1. On the Events list, a cleaned event's **Payload** badge reads **Expired**;
   there is no Replay button in that row — a muted **Expired** label sits
   where Replay would be for a retained row (AC15 — absent, not a disabled
   error state).
2. Opening the event: descriptors and the full Delivery history still render
   normally (PRD-05 AC9/AC10 — a cleaned event's history survives). The
   Payload card shows a single muted line: "Payload expired on {date} —
   retained for your team's 30-day window. Nothing left to view." No Reveal
   control renders at all (AC25 — "a cleaned event has no content to
   reveal").
3. The page-level **Replay** button is not rendered for a cleaned event
   (mirrors the list); nothing on this page ever presents expiry as an
   error, 500, or broken state.

### Flow F — Configure a proxy's retry policy (enhanced mode only)
*(User story: "configure my proxy's retry behavior so retry pressure matches
what my destinations tolerate".)*
1. Member opens **New proxy** or **Edit proxy** (existing form,
   `docs/design/design-01-walking-skeleton.md` Screen 2 /
   `docs/design/design-04-queued-processing.md` Screen 2).
2. If **Mode = Enhanced**, a new **Retry policy** section is visible below
   **Processing**: **Attempts** (number, 1–10, blank = system default 5) and
   **Backoff strategy** (Exponential [default] / Fixed interval).
3. If **Mode = Simple**, this section does not render at all — no fields, no
   placeholder, matching "simple-mode proxies expose no retry configuration"
   (UX Direction) verbatim.
4. Member switches **Mode** from Enhanced → Simple: any values already typed
   into the (now-hidden) retry fields are cleared to their default-sentinel
   state, mirroring the existing 204→empty-body clearing precedent in this
   same form (`ProxyForm.vue`) — there is no way to submit an orphaned retry
   value for a simple-mode proxy.
5. Member switches back **Simple → Enhanced**: the section reappears at its
   default (unconfigured) state — prior in-session values are not restored,
   consistent with step 4 clearing them.
6. Submits → same validation/success handling as the existing form (server
   errors render via `InputError` per field; success redirects to Show).

### Flow G — View a proxy's effective retry policy (Show, read-only)
*(Extends design-04 Screen 3; "cover every surface a persisted attribute
touches".)*
1. On a proxy's **Show** page, a new **Retry policy** card shows the two
   values in effect — **Attempts** and **Backoff** — with a **(default)**
   annotation whenever nothing is explicitly configured, exactly like the
   existing Response-status default annotation pattern.
2. For a **simple-mode** proxy the card always shows the fixed system default
   (5, Exponential) plus a one-line note that per-proxy configurability is an
   Enhanced-mode capability — never a blank/empty card, since the default
   always applies (AC1).

## Screens & States

### Screen 1 — Proxy detail (`/proxies/{proxy}` show) — additions
Two additions to the existing page (`resources/js/pages/proxies/Show.vue`),
neither touching the existing Ingest URL / Response / Destinations cards.

**(a) Header action — `Events` button.** Inserted as the **first** button in
the existing header actions row, before **Edit**:
`Events | Edit | Delete` (Edit/Delete keep their existing permission gates;
`Events` has no separate gate beyond already being able to view this page —
the received-events surface is a read path gated by the existing proxy read
permission, PRD-05 AC16 / PRD-06 AC14).
```vue
<Button variant="outline" as-child>
  <Link :href="proxyEventRoutes.index({ current_team: teamSlug, proxy: proxy.id })">
    Events
  </Link>
</Button>
```

**(b) New `Retry policy` card**, placed **after** the existing `Destinations`
card (retry governs what happens when delivering to the destinations just
listed, so it reads best immediately after them):
```
Card
  h2 "Retry policy"                                  (text-sm font-medium)
  p  "Governs automatic re-attempts to your           (text-sm text-muted-foreground)
      destinations after a failed delivery."
  dl
    dt "Attempts" / dd <value>
    dt "Backoff"   / dd <value>
  p (simple-mode only) "Simple-mode proxies use the fixed system default.
      Configuring attempts and backoff is an Enhanced-mode capability."
```
Uses the same `dl`/`dt`/`dd` pattern `design-03` introduced for the Response
card — a **second** use in this app, not a new pattern.

**States (both additions):**
| Case | Attempts | Backoff |
|---|---|---|
| Enhanced, unconfigured | `5 (default)` | `Exponential (default)` |
| Enhanced, configured | e.g. `8` | e.g. `Fixed interval` |
| Simple (always) | `5 (default)` | `Exponential (default)` + the simple-mode note |

No loading/error states beyond the page-level ones `design-01` already
specifies (Inertia progress bar; team-scoped 403/404) — this card renders
fields already on the Show payload, no independent fetch.

### Screen 2 — Received events list (`/proxies/{proxy}/events`)
A new page, structurally modeled on the existing proxies **Index**
(`resources/js/pages/proxies/Index.vue`): page heading with a back-context
breadcrumb to the proxy, a `Table`, pagination reusing the existing
`Paginated<T>` link-row pattern verbatim.

```
Proxies > {Proxy name} > Events                          (breadcrumb)
Events for "{Proxy name}"                                 h1

Table
  Received | Size | Content type | Payload | Delivery | Actions
  {rows, newest first}
Pagination (existing pattern)
```

**Row content:**
- **Received** — absolute timestamp (e.g. `Aug 12, 2026, 3:41 PM`); no
  relative-time invention, matching this app's plain-timestamp convention
  elsewhere (no existing relative-time component to reuse).
- **Size** — human-readable byte count (e.g. `2.1 KB`).
- **Content type** — the captured content type verbatim (e.g.
  `application/json`), or `—` if absent.
- **Payload** badge — **Retained** (`variant="secondary"`) / **Expired**
  (`variant="outline"`) / **Not captured** (`variant="outline"`) per PRD-05
  AC21's three states (AC16). See the "Never captured" note below.
- **Delivery** badge — an **aggregate** across the event's destinations,
  computed client-side from already-returned per-destination state (a
  presentation decision, not a new requirement): **Delivered**
  (`secondary`, all destinations succeeded), **Retrying** (`outline`, at
  least one mid-retry, none terminal), **Failed** (`destructive`, at least
  one terminally failed).
- **Actions** — `View` (always, → Screen 3) and `Replay` (only when Payload
  = Retained **and** `permissions.canReplayProxy`); a cleaned row shows a
  muted `Expired` label in the Replay slot instead of a button (Flow E).

**"Never captured" — vocabulary-complete, not expected in practice.** Every
row on this list corresponds to an event whose raw payload was captured
unconditionally at ingest (#3 AC7); in normal operation every row's Payload
badge is **Retained** or **Expired**. The **Not captured** value is included
in the badge vocabulary solely so the component satisfies AC16's
"distinguishable" requirement for PRD-05 AC21's third state, and to fail
safely (rather than mis-render as "Expired") if the descriptor is ever
genuinely absent. Flagged under Open Questions below — not a blocking
ambiguity, just an explicit "why this exists" note for review.

**States:**
- **Empty** (no events yet): a centered `Card`, mirroring the proxies
  Index empty state exactly — heading `No events yet`, helper text `Events
  appear here once this proxy's ingest URL receives a webhook.`, and a link
  back to the proxy's Ingest URL card on Show (`View ingest URL`).
- **Loaded, has rows:** table as above.
- **Loading/navigating:** Inertia's global progress bar only (no bespoke
  spinner — this app has no client-side async fetch after mount).
- **Error (team-scoped 403/404):** the existing page-level fallback
  (`design-01` Screen 3 convention) — unauthorized or cross-team access is
  never reachable via the UI, and renders the app's standard error page if
  forced.
- **FIFO head-of-line note:** if `proxy.processing_mode === 'fifo'` **and**
  any event is currently in a **Retrying** state as the line's head, an
  `Alert` banner (reusing the exact `TeamInvitationAlert.vue` info-styling
  precedent — `Info` icon, blue-tinted) renders above the table: "This
  proxy is FIFO. An event is currently retrying and holding the line — newer
  events wait until it succeeds or is set aside after its retry limit."
  This is a proxy-level fact (not per-row), shown once.

### Screen 3 — Event detail (`/proxies/{proxy}/events/{event}`)
```
< Back to events                                          (link)
Received {timestamp}                    [Payload badge]   h1 + badge
                                          [Replay button]  (header action)

Card "Details"
  dl: Received / Size / Content type

Card "Payload"                                             (Flow C / E)
  [masked block + Reveal button]  — or —
  [cleaned message, no control]   — or —
  [not-captured message, no control]

Card "Delivery"
  FIFO note (Alert, same as Screen 2, event-scoped instance)  — conditional
  "Original delivery"
    per-destination row: method badge, URL, status badge, "Attempt N of L",
    last attempt time; Collapsible "Show N attempts" → per-attempt list
    (time, outcome, HTTP status/error summary) — collapsed by default
  "Replay — {time}"  (one group per replay, newest first; only if any exist)
    same per-destination row shape
```

**Details card.** Same `dl`/`dt`/`dd` pattern as Screen 1's Retry policy
card and `design-03`'s Response card — a third use, not a new pattern.

**Payload card — the three states (AC15/AC16/AC25):**
1. **Retained:** masked block by default —
   `rounded-md border border-input bg-transparent px-3 py-2 font-mono
   text-sm dark:bg-input/30` (same tokens as the Response-body block in
   `design-03`) containing a fixed placeholder line, e.g. `•••••• hidden —
   click Reveal to view` (never the real bytes, blurred or otherwise — see
   the implementation note under Open Questions), plus a **Reveal payload**
   button (`Eye` icon, `@lucide/vue`). Clicking it swaps the block's content
   for the real payload (`whitespace-pre-wrap break-words`, `max-h-96
   overflow-y-auto` — taller than the Response-body cap since raw payloads
   run larger) and the button becomes **Hide payload** (`EyeOff` icon).
2. **Cleaned:** a single muted italic line (Flow E step 2's copy) — no
   button, matching the Response card's "no content" precedent exactly
   (never an empty bordered box).
3. **Not captured** (vocabulary-complete, see Screen 2 note): `No payload
   was captured for this event.` — same muted-italic treatment, no button.

**Delivery card — status badges, per destination row:**
| State | Badge | Text alongside |
|---|---|---|
| Delivered | `secondary` "Delivered" | last successful attempt time |
| Retrying | `outline` "Retrying" | `Attempt N of L` + "waiting for its next attempt" |
| Terminally failed | `destructive` "Terminally failed" | `Attempt L of L — retries exhausted` |

Each destination row also shows its method (`Badge variant="outline"`, same
as the Destinations card) and URL, matching the Show page's existing
Destinations row layout.

**Replay grouping (AC12 traceability).** Attempts are grouped into
**Original delivery** (the live dispatch at ingest) and one **Replay —
{time}** group per manual replay, newest first. This is the presentation
answer to AC12's "distinguishable as replays and traceable to the original
event" — traceability is structural (nested under this one event's page);
distinguishability is the grouping itself. No separate "replayed by {user}"
attribution is added — not asked for by any AC, and would be new scope.

**States:** empty Delivery card is not reachable (every event has at least
an Original-delivery attempt by construction); no separate loading state
(same page-level Inertia convention as Screen 2).

### Screen 4 — Replay confirmation dialog
Triggered from a **Replay** button on Screen 2 (a row) or Screen 3 (header).

```
Dialog
  DialogHeader
    DialogTitle "Replay this event?"
    DialogDescription "Sends this event's stored payload to the
      destinations you choose below, as a new delivery. Your destinations
      receive real traffic again — this is not a preview."
  [FIFO note — Alert, conditional, see Flow D step 4]
  fieldset "Destinations"
    legend "Choose destinations" (sr-only if visually redundant with a
      visible heading; kept visible here — first use of Checkbox for a
      multi-select group)
    Checkbox "Select all"                     (tri-state: checked / indeterminate / unchecked)
    Checkbox × N, one per current destination — label = "{METHOD} {url}"
  DialogFooter
    DialogClose as-child → Button variant="ghost" "Cancel"
    Button "Replay to {N} destination{s}"      (disabled: N === 0 or submitting)
  [inline error region — request-level failure only]
```

**States:**
- **Default (opened):** none checked, **Confirm** disabled, count reads
  `Replay to 0 destinations` in a muted/disabled style.
- **Selecting:** checking/unchecking rows or **Select all** updates the
  Confirm label's count live (`Replay to 3 destinations`); **Select all**
  reflects `indeterminate` when some-but-not-all are checked.
- **Submitting:** Confirm shows the `Spinner` (per the design-standards
  "extend Spinner to all submit buttons" recommendation) and disables;
  Cancel also disables to prevent a mid-flight dialog dismissal race.
- **Success:** dialog closes; Sonner toast (`flash.toast`, existing channel)
  — "Replay dispatched to {N} destination{s}."; the underlying page (list or
  detail) reflects the new Replay group/attempts on next render (Inertia
  visit or partial reload — implementation's choice, not specified here).
- **Request-level failure:** dialog stays open; an inline error region above
  the footer renders the server's message (or a generic fallback); Confirm
  re-enables with the same selection retained (nothing is cleared on
  failure, so the user doesn't have to re-pick destinations).
- **Cancel / Esc / outside click:** closes with no request sent (standard
  `Dialog` behavior); re-opening resets to the default (nothing-checked)
  state — selections are not remembered between opens, consistent with
  "optimize for deliberate exposure/action" running through this whole
  feature.

### Screen 5 — Create / Edit Proxy form — Retry policy section (extends design-04 Screen 2)
**Placement:** a new subsection directly **below Processing** and **above
Response**, inside the existing `Details`-adjacent grouping design-04
established — extending that same "core pipeline settings before the
acknowledgement contract" order:
```
Details
  Name
  Mode
  Processing
Retry policy      (NEW — this spec; renders only when Mode = Enhanced)
  Attempts
  Backoff strategy
Response
Destinations
```

**Field spec:**
- **Section heading:** `Retry policy` (`h2` or `legend`, matching this
  form's existing section-boundary convention for `Destinations`), with a
  standing help line above the fields: "Applies to automatic re-attempts
  after a failed delivery to a destination. Available on Enhanced-mode
  proxies; Simple-mode proxies use the fixed system default (5 attempts,
  exponential backoff)."
- **Attempts** (`id="retry_attempt_limit"`): `Input type="number" min="1"
  max="10"`, blank = system default, `w-full sm:w-32` (narrower than the
  `sm:w-64` selects — a short numeric field). Help text: "Leave blank to
  use the default (5). Maximum 10."
- **Backoff strategy** (`id="retry_backoff_strategy"`): `Select`, same
  sentinel-plus-options idiom as the existing `response_status` field
  (`STATUS_DEFAULT` pattern) — a `RETRY_STRATEGY_DEFAULT = 'default'`
  sentinel item reading **Default (Exponential)**, plus `SelectItem`s
  **Exponential** and **Fixed interval**. Help text: "Exponential increases
  the wait between attempts each time; fixed interval waits the same amount
  every time. Either way, retries are always bounded well inside your
  team's 30-day payload retention window."
- **Errors:** `InputError` under each field, `aria-describedby` linking help
  + error, matching the `processing_mode`/`response_status` fully-wired
  pattern (not the legacy `mode` field).

**States:**
- **Mode = Enhanced:** section renders; fields default to blank/sentinel on
  create, or the proxy's saved values on edit.
- **Mode = Simple:** section does not render (no fields, no placeholder
  text in their place — a clean omission, matching how the UX Direction
  frames it: "expose no retry configuration").
- **Switching Enhanced → Simple:** any in-progress values in the two fields
  are reset to their default-sentinel state (blank / `'default'`) the
  moment the section unmounts, so nothing stale can be submitted if the
  fields were ever re-shown before submit (Flow F step 4).
- **Switching Simple → Enhanced:** section reappears at the default
  (unconfigured) state — see Flow F step 5.
- **Validation error / submitting / disabled:** identical mechanics to
  every other field on this form (`form.processing`, first-invalid-field
  focus management already implemented in `ProxyForm.vue`'s `onError`).

**Existing Mode help-text update (flagged, small).** The current `mode`
field help text reads: *"Enhanced-mode behaviours (mapping, storage,
retries) are not yet functional; Simple delivers the webhook to every
destination."* This is now **partially inaccurate** — retry is functional
in both modes as of #6, and only per-proxy retry **configurability** is
enhanced-gated. Recommended replacement: *"Enhanced mode unlocks payload
mapping (#8), payload storage (already active, #5), and per-proxy retry
configuration below. Automatic retry itself applies to every proxy
regardless of Mode."* This is a copy correction on an already-approved
field this spec's own addition makes stale — flagged for the Product
Manager's design-gate attention, not a new field or requirement.

## Components
| Role | Component | Status |
|---|---|---|
| Events entry point | `Button` `variant="outline"` `as-child` + `Link` | Reused (Show header actions) |
| Retry policy card (Show) | `Card`, `dl`/`dt`/`dd` | Reused — `dl` pattern from `design-03` |
| Events list page shell | `Card` (empty state), `Table`/`TableHeader`/`TableBody`/`TableRow`/`TableCell`/`TableHead` | Reused (proxies Index pattern) |
| Pagination | existing `Paginated<T>` link-row pattern | Reused verbatim |
| Payload / delivery-state badges | `Badge` (`secondary`/`outline`/`destructive`) | Reused — existing variant set, no new variant |
| FIFO head-of-line note | `Alert`, `AlertDescription`, `Info` icon | Reused — exact `TeamInvitationAlert.vue` styling precedent |
| Payload masked/revealed block | styled `div` (border/`font-mono`/`dark:bg-input/30`, same tokens as the Response-body block) + `Button` + `Eye`/`EyeOff` icons | **New small composition** (`PayloadViewer`-shaped), built entirely from existing tokens/primitives — no new `ui/*` primitive |
| Reveal toggle icons | `Eye`, `EyeOff` (`@lucide/vue`) | Reused library, first use of these two icons |
| Attempt history expand | `Collapsible`, `CollapsibleTrigger`, `CollapsibleContent` | Reused primitive — **first application use** in this app (already generated, unused) |
| Replay dialog shell | `Dialog`, `DialogHeader`, `DialogTitle`, `DialogDescription`, `DialogFooter`, `DialogClose` | Reused (non-destructive-dialog pattern) |
| Destination checklist | `Checkbox` + `Label` | Reused primitive — **first application use** (already generated, unused) |
| Submit / cancel buttons | `Button`, `Spinner` | Reused |
| Success/error feedback | Sonner toast (`flash.toast`), inline `InputError`-style error region | Reused channel |
| Retry-policy form fields | `Input` (Attempts), `Select`/`SelectTrigger`/`SelectContent`/`SelectItem`/`SelectValue` (Backoff), `Label`, `InputError` | Reused — identical idiom to `response_status`'s sentinel-plus-options pattern |

**No new npm dependency, icon library, or `ui/*` primitive is introduced.**
`Collapsible` and `Checkbox` are already-generated primitives with no prior
application usage in `resources/js/pages/proxies/` — this is their first
real use, not a new addition.

**Recommended data-const treatment (non-gating, mirrors design-04's note).**
Payload-state and delivery-state label/badge-variant pairs, and the
`RETRY_STRATEGY_DEFAULT` sentinel + strategy options, are each used in more
than one place (list badge, detail badge, dialog copy) — the same shape of
reuse that justified `proxyProcessingModes.ts` / `proxyResponseStatuses.ts`.
Recommend `resources/js/data/proxyPayloadStates.ts`,
`proxyDeliveryStates.ts`, and `proxyRetryBackoffStrategies.ts` following the
same `DataOption`-typed const pattern. File-organization note for the Senior
Developer, not a Designer requirement gating approval.

## Interactions
- **Reveal toggle:** a plain button, not a checkbox/switch — `aria-pressed`
  reflects state (`false` masked / `true` revealed); clicking toggles
  content and label/icon together; no confirmation needed (this is an
  exposure-of-already-authorized-content action, not a destructive one).
- **Replay button visibility** is derived **client-side** from
  `permissions.canReplayProxy` (new, all-roles-true, no `is_creator` check —
  unlike update/delete) **and** the event's payload state — per the
  Affordance Visibility standard, this governs display only; the server
  remains the authoritative gate.
- **Replay dialog is not an `AlertDialog`.** See the flagged judgment call
  under Open Questions — the plain `Dialog` is used, with deliberateness
  achieved through **content** (explicit warning sentence, nothing
  pre-checked, a count-bearing Confirm label) rather than destructive-red
  styling, since replay alters no record and deletes nothing.
- **Select all / individual checkboxes:** standard checkbox-group semantics;
  `Select all` toggles every destination checkbox and reflects
  `indeterminate` when the set is partial — no bespoke keyboard handling
  beyond Reka UI's `Checkbox` defaults.
- **No inline editing anywhere on Screens 2–4** — this is a read-plus-action
  surface, not an editable one; the only mutations are **Reveal** (local,
  no server write) and **Replay** (the one dispatch-triggering action).
- **Attempt-history `Collapsible`:** collapsed by default on every
  destination row; expanding one row does not affect others (independent
  state per row).
- **Retry-policy fields mount/unmount with Mode**, not merely
  show/hide-via-CSS — clearing on Enhanced→Simple (Flow F step 4) is a data
  operation (`form.retry_attempt_limit = ''`, etc.), not just a visual
  toggle, so a hidden field can never carry a stale value into submit.

## Accessibility
- **Reveal button:** `aria-pressed` state as above; an `aria-live="polite"`
  `sr-only` announcement region (mirroring `CopyField.vue`'s established
  pattern) announces "Payload revealed" / "Payload hidden" on toggle, so a
  screen-reader user gets the same state change a sighted user sees in the
  button label/icon.
- **Payload-state and delivery-state badges** are never color-only — every
  badge always carries its full text (`Retained`, `Terminally failed`,
  etc.), consistent with the project's binding "colour is never the sole
  carrier of meaning" rule (already true of every existing badge in this
  app).
- **Cleaned/not-captured payload messaging** is real text content (not a
  placeholder or icon-only cue), read by assistive tech exactly as sighted
  users see it — same precedent as `design-03`'s "(empty)"/"No content"
  strings.
- **Replay dialog:** `DialogTitle` + `DialogDescription` present (Reka UI
  requires this for a properly announced dialog); the destination
  `fieldset`/`legend` groups the checklist for screen readers; each
  `Checkbox` has a programmatically associated `Label` naming its
  destination (`{METHOD} {url}`) — never a bare icon or ambiguous target,
  per the existing "icon-only or ambiguous-target control" rule. The live
  selection count feeding the Confirm button's label is itself the
  accessible name update (no separate `aria-live` region needed since the
  button's own accessible name changes on each interaction).
- **Retry-policy fields:** `Label for=` association, `aria-describedby`
  linking help + error, identical wiring to `processing_mode`/
  `response_status` (this spec's stated pattern, not a new one).
- **Focus management:** on the Replay dialog opening, Reka UI's `Dialog`
  traps focus and returns it to the trigger (`Events`-row or header
  `Replay` button) on close — relied on, not reimplemented. On a
  server-side validation error touching a retry-policy field, the existing
  `ProxyForm.vue` first-`[aria-invalid="true"]` focus handler covers it
  automatically.
- **Collapsible attempt history:** Reka UI's `Collapsible` provides
  `aria-expanded` on its trigger by default — relied on, not reimplemented.
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`'s baseline; no new
  interactive pattern is introduced that isn't already a vetted Reka UI
  primitive.

## Responsive Behavior
- **Events list table:** same horizontally-scrollable container as the
  proxies Index table, no stacked-card fallback (`docs/standards/design.md`
  → Responsive targets, "Tables have no responsive stacking variant" —
  unchanged default).
- **Event detail cards:** stack vertically, same as every existing Show-page
  card stack; the Payload block wraps (`whitespace-pre-wrap break-words`)
  rather than forcing horizontal scroll, consistent with the Response-body
  precedent (payload content, unlike the ingest URL, is not a
  single-line bearer secret).
- **Replay dialog:** Reka UI's `Dialog` default responsive sizing (caps
  width on wide viewports, near-full-width on narrow ones) — no bespoke
  breakpoint handling; the destination checklist scrolls internally
  (`max-h-*, overflow-y-auto`) if a proxy has many destinations, so the
  dialog itself never grows taller than the viewport.
- **Retry policy form fields:** `Attempts` input `w-full sm:w-32`, `Backoff
  strategy` select `w-full sm:w-64` — full-width below `sm`, fixed above,
  the same "never the reverse" convention every other field on this form
  already follows.
- **Minimum supported width:** 360px, per the standing Proposed default in
  `docs/standards/design.md` — no feature-specific override.

## Open Questions
None blocking. The PRD's UX Direction and AC25/AC9/AC10/AC14 are unambiguous
on what must be built; every choice below is a Designer judgment call within
that direction, flagged for the Product Manager's design-gate attention
(each independently reversible, matching the `design-04` precedent for
flagging non-blocking calls):

1. **Replay confirmation uses `Dialog`, not `AlertDialog`.** The binding
   standard (`docs/standards/design.md`) reserves `AlertDialog` for
   destructive actions (delete/remove); replay deletes or alters nothing —
   it creates new delivery attempts. Deliberateness is instead built from
   content (explicit consequence sentence, nothing pre-checked, a
   count-bearing Confirm label) per the PRD's "optimize for deliberate
   action, not one-click accidents." If the Product Manager judges replay's
   real-world irreversibility (traffic already sent cannot be recalled)
   warrants the heavier `AlertDialog` treatment anyway, that is a
   same-shaped, low-risk swap.
2. **Delivery-state badge is an aggregate across destinations** on the list
   (Screen 2) — a client-side rollup (Delivered/Retrying/Failed) of
   per-destination states the detail page shows individually. This is
   presentation, not a new data requirement (AC23 still holds — no
   stats/dashboard is added, only a rollup of state the surface already
   needs). Flagged since the exact precedence rule (terminal-failure beats
   retrying beats delivered) is a judgment call, not PRD-stated.
3. **"Not captured" payload-state badge is vocabulary-complete but not
   expected in practice** on this list (see Screen 2's note) — included so
   the component satisfies AC16's three-state distinguishability
   requirement even though every row's raw payload is captured
   unconditionally at ingest (#3 AC7). If the Product Manager or Principal
   Engineer identifies a live case for it (e.g., a partially-ingested event
   record), the badge already exists to represent it; no design change
   needed either way.
4. **No dispatched-output content is shown anywhere in this spec.** #5's
   enhanced-mode dispatched-output store exists but AC22 states the *only*
   payload-content exposure #6 adds is the single AC25 viewer over the
   received/raw payload — confirmed as the reading this spec builds on, not
   an oversight. A future item (#8, once mapping makes dispatched content
   differ from raw) is the natural place to reconsider surfacing it.
5. **No Index-page (proxies list) shortcut to a proxy's Events** — reachable
   only via Show → `Events` (one extra hop). Consistent with how the #3
   Response card also did not get an Index column (design-03 addendum
   covered Show only). If frequent-use patterns argue for a direct
   Index-row shortcut later, it is additive and does not disturb anything
   in this spec.

**One implementation-level note for the Principal Engineer (not a UX
ambiguity — folded into the existing, open, non-blocking Q-06-03 rather
than a new question doc, since it is a technical "how", not a requirement
gap):** whether the masked payload's real content is (a) included in the
page's initial payload and merely CSS/JS-hidden until Reveal, or (b) fetched
fresh only on the Reveal click. The UX requirement (mask by default,
explicit reveal, all-or-nothing) is satisfied either way; (b) is the
stronger defense-in-depth choice (content never sits in the DOM/props
unless explicitly requested) and is the Designer's recommendation, but the
mechanism is the Principal Engineer's call at technical design.

## Handoff
- **Inputs:** `docs/product/prd-06-retry-replay.md` (Approved, esp. UX
  Direction and AC1–AC2, AC4, AC6, AC9–AC18, AC25);
  `docs/questions/prd-06-q-06-01-retry-policy-scope-and-defaults.md`
  (RESOLVED — mode gating, config shape, defaults, caps);
  `docs/questions/prd-06-q-06-02-replay-surface-modes-permission.md`
  (RESOLVED — masked-viewer content, both-modes replay, all-roles
  permission); `docs/questions/prd-06-q-06-03-retry-replay-composition.md`
  (OPEN, Principal Engineer, non-blocking — carries forward untouched, plus
  this spec's reveal-mechanism note above); `docs/product/prd-05-payload-
  storage-retention.md` (AC9/AC10/AC16/AC21 — the three-state model and
  survival-of-history guarantee this spec presents);
  `docs/design/design-01-walking-skeleton.md` (Show-page card pattern,
  Index-table pattern, empty-state pattern); `docs/design/design-03-
  decoupled-upstream-response-show.md` (`dl`/`dt`/`dd` card pattern,
  verbatim-label-consistency, "no empty bordered box" rule);
  `docs/design/design-04-queued-processing.md` (create/edit form section
  ordering and precedent-flagging conventions this spec follows);
  `resources/js/pages/proxies/{Show,Index,ProxyForm}.vue`,
  `resources/js/components/{CopyField,DestinationRows,TeamInvitationAlert}.vue`,
  `resources/js/components/ui/{checkbox,collapsible,dialog,alert}/*` (current
  implementation and unused-but-available primitives studied for this spec);
  `resources/js/types/proxies.ts` (`ProxyDetail`/`ProxyPermissions` shapes
  extended by this spec); `docs/standards/design.md`.
- **Outputs:** this design spec.
- **Dependencies:** no new npm dependency, icon library, or `ui/*`
  primitive. `Checkbox` and `Collapsible` are already-generated,
  currently-unused primitives — this spec is their first real application
  use, not an addition. Two new lucide icons (`Eye`, `EyeOff`), same
  `@lucide/vue` library already in use.
- **Outstanding Questions:** None blocking this spec's approval. Five
  flagged, reversible judgment calls above for the Product Manager's
  design-gate review; one implementation-level reveal-mechanism note folded
  into the Principal Engineer's already-open, non-blocking **Q-06-03**. The
  Mode-field help-text copy correction (Screen 5) is a small, flagged
  update to already-shipped copy, not a new requirement.
- **Next Agent:** **Product Manager**, to approve this spec against PRD-06
  (design gate, delegated per `CLAUDE.md`). On approval, hands to the
  **Principal Engineer** for technical design, which also resolves the
  open **Q-06-03** (retry/replay composition with FIFO, retention holds,
  and record shape) already gating that phase per the PRD.
