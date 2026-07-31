# Design Spec: Walking skeleton — proxy create / list / view / edit / delete

- **Status:** Approved
- **Author:** Designer
- **PRD:** `docs/product/prd-01-walking-skeleton.md` (Approved)
- **Approved by / date:** Project Owner — 2026-07-30

> **Revision — 2026-07-30 (all Open Questions resolved; spec Approved).** The
> three Open Questions that gated marked sections are now resolved and folded in:
> 1. **Management scope (PM, PRD-01 AC16):** edit AND delete are in item #1 scope
>    — edit proxy name and destinations (add/remove/change URL + method), delete
>    an individual destination, and delete a proxy entirely, all team-scoped, with
>    the minimum-one-destination invariant enforced (the last destination cannot
>    be removed). The previously **[DRAFT — PENDING PM]** edit/delete surfaces are
>    now specified (Flows D–F, Screen 2 as create+edit, Screen 3 actions).
> 2. **Mode selector (PM):** the Enhanced option may be selectable and may persist
>    a partial `enhanced` value — **no "coming soon"/disabled gating is required**.
>    The mode control is left at functional-minimum; Simple is the acceptance bar.
> 3. **Ingest-URL display (PE, ADR-006 "Display feasibility" addendum) + list URL
>    affordance (Owner):** confirmed feasible for **both** detail and **inline
>    list rows**; the Owner chose **INLINE**. Screen 1 now shows the full ingest
>    URL inline with the Copy control (not a count + drill-in). The Screen 3
>    **[ASSUMPTION — feasibility]** marker is removed. Honored constraints: list
>    stays paginated; the displayed value is the decrypted plaintext token; the
>    URL host comes from server config (never the request Host header); ingest
>    tokens are kept out of logging/analytics/prop capture.
>
> The "Fidelity posture (pre-MVP)" directive is unchanged: kit defaults for
> transient states, no bespoke polish; correctness, team-scoping, auth,
> validation, and accessibility remain non-negotiable.

> **First design spec in this project.** There is no existing `docs/design/`
> spec to reuse patterns from and no `docs/standards/design.md`. This spec
> therefore treats the **Laravel + Vue/Inertia starter-kit UI kit** as the de-facto
> design system: every screen slots into the starter kit's authenticated app shell
> and reuses its components rather than inventing a parallel language. Recommend the
> Owner establish `docs/standards/design.md` from this kit once item #1 is built.
>
> **Starter kit confirmed from Owner screenshots** (`docs/design/screenshots/`,
> 2026-07-30 — stock kit shots: `landing`, `login`, `register`, `dashboard`). The
> kit is the **official Laravel Vue starter kit** (Inertia + Vue 3 + Tailwind, using
> the **shadcn-vue / reka-ui** component library), **dark-mode by default**, with a
> collapsible **left-sidebar app shell**: Laravel logo, a **team switcher** dropdown,
> a "Platform" nav section of icon+label items (active item = subtly filled rounded
> row), and — pinned to the sidebar footer — external links ("Repository",
> "Documentation") plus a **user menu** (avatar + name). The content area opens with
> a top bar holding a **sidebar-collapse toggle** and a **breadcrumb/title**. Auth
> screens (login/register) use a separate centered single-column layout (label-above-
> input fields, full-width primary button) — reused as-is, not redesigned.
>
> **Important consequence:** the stock kit ships the shell + auth + component
> *primitives* but **no in-app list/detail/CRUD page, table, modal, or flash/toast
> in use**. So the proxy screens below are the **first in-app CRUD screens** and
> establish that pattern by composing the kit's shadcn-vue primitives (Table, Card,
> Dialog, Badge, Sonner toast, Button, Input, Label, Select, Breadcrumb) — not by
> mirroring an existing in-app page (there is none). Markers below: **[SK-CONFIRMED]**
> = seen in a screenshot; **[SK-PRIMITIVE]** = shipped by shadcn-vue in this kit but
> not yet used in-app, so this feature is its first use (no further screenshot
> exists to request — the component would have to be built to be shown).

## Overview
A team member opens **Proxies** from the app shell's navigation and sees a
team-scoped list of their proxies (or an empty state inviting the first one). They
create a proxy by giving it a name, choosing a **mode** (Simple by default), and
adding one or more destination rows (each a URL + an HTTP method, POST or PUT), adding
and removing rows as needed. On save they land on the proxy's detail page, where the
generated **ingest URL** is shown prominently with a one-click **Copy** control to
hand to an upstream sender, alongside the list of destinations and the mode. From the
list or the detail page they can **edit** an existing proxy (its name and its
destinations) or **delete** an individual destination or the whole proxy — all
team-scoped, and always keeping at least one destination (AC16). No delivery/attempt
data is shown anywhere at item #1 — attempt records are captured by the backend but
their visualization is roadmap #11 (see "Explicitly not designed").

## Scope confirmation (what the PRD does and does not ask the UI to do)
- **In scope (designed here):** create a proxy with name + mode + N destinations
  (AC1, AC2, AC3); list the team's proxies with each proxy's full ingest URL shown
  **inline** (AC4, AC12d); view a proxy's ingest URL and destinations and mode
  (AC4, AC12d); **edit** a proxy's name and destinations (add/remove/change URL +
  method) and **delete** an individual destination or a whole proxy (AC16), always
  retaining at least one destination (AC2/AC16b). All screens are team-scoped and
  behind auth (AC5, AC6) — enforced by the starter-kit shell + backend, not by this
  UI.
- **Explicitly not designed at item #1 (with the roadmap item that owns each):**
  - **No analytics/attempt UI.** PRD Out of Scope defers "Analytics dashboards /
    stats presentation" to **#11**. AC13–AC15 require attempt records to be
    *captured and team-scoped-queryable* — none require them to be *displayed*.
    So there is **no delivery/attempt list, count, badge, or status indicator on
    any item #1 screen.** This is stated deliberately; #11 later adds it.
  - **No mapping editor** — enhanced-mode maps are **#8**.
  - **No retry/replay controls** — **#6**.
  - **No token-rotation UI** — ADR-006 states the model supports rotation but "no
    UI is required at #1".
  - **No response-config fields** (status/body) on the form — decoupled response
    is **#3**.
  - **No login / register / team-management screens** — provided by the starter
    kit; reused, never rebuilt.
- **Previously deferred, now resolved** (`docs/questions/prd-01-design-manage-scope.md`,
  RESOLVED 2026-07-30): item #1 **does** include an edit/delete proxy surface
  (AC16), and the mode selector needs **no** "coming soon"/disabled gating. The
  sections that were marked **[DRAFT — PENDING PM]** are now specified below.

## Fidelity posture (pre-MVP) — Owner directive 2026-07-30
This product is **not released until MVP** (date irrelevant here), so **do not
spend effort beautifying transient or semi-working states**. This spec specifies
*functional* behaviour and reuses the kit's defaults for anything transient — it
deliberately does **not** ask for bespoke polish. Concretely:
- **Loading / submitting / progress:** use the kit's default Inertia progress bar
  and the default disabled/`processing` button state. **No custom skeletons,
  spinners, or transition animations.**
- **Copy feedback, empty states, flashes:** use the plain shadcn-vue primitive
  (a label swap to "Copied", a simple Card, a default Sonner toast). Functional and
  accessible is the bar — **not** polished micro-interactions.
- **Responsive:** meet the functional minimum (kit sidebar collapse; a horizontally
  scrollable table). **Do not** build stacked-card table fallbacks or other
  responsive niceties at #1.
- What is **not** negotiable even pre-MVP: the acceptance-criteria behaviour, the
  team-scoping/auth boundaries, correct validation, and basic accessibility
  (labels, focus, keyboard, screen-reader-announced copy). These are correctness,
  not polish.

Where a state below reads like detailed styling, treat it as the *intended
behaviour at MVP*, delivered with kit defaults now — not a request to hand-craft the
temporary state.

## User Flows

### Flow A — Create a proxy (primary path, AC1–AC3)
1. From the app shell, the team member clicks **Proxies** in the nav → lands on the
   Proxies list.
2. Clicks **New proxy** (top-right primary button) → Create Proxy form.
3. Enters a **Name**. Mode defaults to **Simple**; the member may leave it or pick
   **Enhanced** (selectable — see the mode-control note; Enhanced simply persists a
   mode value at #1 with no functional difference).
4. The form opens with **one empty destination row**. The member enters a
   destination **URL** and picks a **method** (POST default / PUT).
5. To fan out, clicks **Add destination** → a new empty row appends and focus moves
   to its URL field. Repeats for N destinations. Can **Remove** any row (the control
   is disabled/hidden when only one row remains, since ≥1 is required — AC2).
6. Clicks **Create proxy**.
   - **Validation failure** (missing name, empty/invalid URL in any row): the form
     re-renders in place with per-field, per-row error messages; focus moves to the
     first field in error; nothing is saved.
   - **Success:** redirect to the new proxy's **detail** page with a success flash
     ("Proxy created"). The ingest URL is shown ready to copy.
7. The member clicks **Copy** on the ingest URL and hands it to the upstream sender.

### Flow B — List the team's proxies (AC4)
1. Team member clicks **Proxies** → sees a (paginated) table of the team's proxies:
   name, mode, and each proxy's **full ingest URL shown inline** with a per-row
   **Copy** control, plus per-row **Edit** / **Delete** actions and a **View** link.
2. **Empty state** (no proxies): a centered empty-state card with a short line and a
   **Create your first proxy** button → Flow A.

### Flow C — View a proxy (AC4, AC12d)
1. From the list, the member clicks a row / **View** → proxy detail page.
2. Sees the proxy **name** and **mode**, the full **ingest URL** with a **Copy**
   control, and the list of **destinations** (URL + method), plus **Edit** and
   **Delete** actions.
3. Copies the ingest URL as needed.

### Flow D — Edit a proxy (AC16a, AC16b)
1. From the list-row **Edit** action or the detail-page **Edit** button → the
   Create/Edit form (Screen 2), **pre-filled** with the proxy's current name, mode,
   and destination rows.
2. The member changes the **name**, changes the **mode**, edits any destination's
   **URL** or **method**, **adds** destination rows, or **removes** rows.
   - **Remove** is disabled/hidden when only one row remains, so an edit can never
     leave the proxy with zero destinations (AC16b / AC2). Deleting the only
     remaining destination is therefore not possible from the form.
3. Clicks **Save changes**.
   - **Validation failure:** the form re-renders in place with per-field/per-row
     errors and focus on the first field in error; nothing is saved.
   - **Success:** redirect to the proxy's detail page with a success flash
     ("Changes saved"). The ingest URL is unchanged (edit does not rotate the token).

### Flow E — Delete a single destination (AC16c, AC16b)
1. A destination can be removed either **from the edit form** (Flow D — removing its
   row and saving) or, for a quick single removal, via a **Remove** control on the
   detail page's destinations list.
2. If the detail-page per-destination Remove is used and it is **not** the last
   destination: a confirmation dialog ("Remove this destination?") → on confirm the
   destination is removed and the detail page re-renders with a success flash.
3. If it **is** the last remaining destination: the per-destination Remove control is
   **disabled** with a hint ("A proxy must keep at least one destination"), so the
   minimum-one-destination invariant (AC16b) is enforced in the UI as well as the
   backend.

### Flow F — Delete a proxy (AC16d)
1. From the list-row **Delete** action or the detail-page **Delete** button → a
   **destructive confirmation dialog** (shadcn-vue AlertDialog) naming the proxy
   ("Delete '{name}'? Its ingest URL will stop accepting webhooks and all its
   destinations are removed. This cannot be undone.").
2. **Confirm:** the proxy (and its destinations) is deleted; redirect to the Proxies
   list with a success flash ("Proxy deleted"). **Cancel:** dialog closes, nothing
   changes.

(Failure path shared by B–F: acting on a proxy that is not the current team's, or
does not exist, yields the starter kit's standard 403/404 page — enforced by the
backend team scope, AC5/AC6/AC16e; not a bespoke screen. Edit/delete controls only
ever act on proxies the authenticated member's team owns.)

## Screens & States

### Screen 1 — Proxies list (`/proxies` index)
**Layout.** Renders inside the confirmed starter-kit sidebar shell **[SK-CONFIRMED:
collapsible left sidebar + team switcher + user menu; content top bar with
collapse toggle + breadcrumb]**. A new **Proxies** nav item is added under the
sidebar's **"Platform"** section, styled exactly like the existing "Dashboard"
item (icon + label, active-highlight when selected); a webhook/link/share-style
icon is used. The content top-bar breadcrumb reads "Proxies". Page body:
- **Page header row:** page title "Proxies" (left); **New proxy** primary button
  (right) — kit Button, matching the auth screens' primary button style
  **[SK-CONFIRMED style; first in-app header use]**.
- **Data table** (shadcn-vue Table **[SK-PRIMITIVE — first in-app use]**) with columns:
  1. **Name** (links to detail).
  2. **Mode** — a badge ("Simple" or "Enhanced"). Both values can appear, since a
     proxy may persist an `enhanced` mode at #1 (see the mode-control note).
  3. **Ingest URL** — the **full** ingest URL shown **inline** (Owner-chosen,
     PE-confirmed feasible — ADR-006 "Display feasibility" addendum), in a read-only
     truncating/monospace cell, with an adjacent **Copy** button (icon + "Copy").
     The value is the decrypted plaintext token URL built from server config (see the
     Ingest-URL display constraints note). Each row's caution is conveyed once at the
     table level (see states) since every URL here is a bearer secret.
  4. **Actions** — **View** link → detail; **Edit** → the pre-filled Create/Edit
     form (Flow D); **Delete** → destructive confirmation dialog (Flow F).

**Ingest-URL display constraints (honored per ADR-006 addendum — not design
decisions).** The list **must stay paginated** (bounds the number of tokens
decrypted and rendered per page). The displayed value is the **decrypted plaintext
`ingest_token`** built as `https://<ingest-host>/ingest/{token}`, where the host
comes from **server-side config, never the request `Host` header**. Because every
inline URL is a live bearer secret, these tokens/URLs must be **kept out of any
request/response logging, APM/analytics prop capture, and any client-side event
tracking** — a backend/PE responsibility flagged here so the inline affordance does
not leak. This resolves former Open Question #3 and the Screen 3 feasibility marker.

**States.**
- **Default (≥1 proxy):** the paginated table as above. Because each row exposes a
  live ingest URL (bearer secret), the same secrecy caution applied on detail is
  carried here — surfaced once for the table (a short line near the Ingest URL column,
  e.g. "These ingest URLs are secrets — anyone with one can post webhooks.") rather
  than repeated per row, keeping the per-URL secrecy caution applied to the inline
  affordance (fidelity posture: functional, not decorated).
- **Empty (no proxies):** table replaced by a centered empty-state built from a
  shadcn-vue Card **[SK-PRIMITIVE]** — short heading ("No proxies yet"), one line
  ("Create a proxy to get an ingest URL and start fanning out webhooks."), and a
  **Create your first proxy** primary button. (The stock dashboard shows empty
  hatch-pattern placeholder cards; this feature defines the real empty-state copy.)
- **Loading:** Inertia page visits show the kit's global top progress bar
  **[SK-PRIMITIVE — Inertia default]**; no bespoke skeleton required at #1.
- **Error:** page-level load errors fall back to the kit's standard error page; a
  failed action surfaces via the kit's error flash (see Interactions).

### Screen 2 — Create / Edit Proxy form (`/proxies/create`, `/proxies/{proxy}/edit`)
**Layout.** Single-column form inside the app shell, constrained to a readable
column width, wrapped in a shadcn-vue Card **[SK-PRIMITIVE]**. **The same form
serves create and edit** (AC16a) — in edit mode it is **pre-filled** with the
proxy's current name, mode, and destination rows, the primary button reads **Save
changes** (vs **Create proxy**), and the breadcrumb reads "Proxies / {name} / Edit"
(vs "Proxies / New proxy"). Editing does **not** change the ingest URL. Fields
follow the kit's confirmed auth-form pattern **[SK-CONFIRMED on login/register]**:
**Label above a full-width Input**, placeholder text, and an `InputError` slot
beneath. Body sections:

1. **Details**
   - **Name** — kit Input + Label (label above), required, placeholder e.g.
     "Stripe → billing services". Help text: "A name to recognise this proxy." 
   - **Mode** — a two-option control (kit Select or segmented/radio group) with
     **Simple** and **Enhanced**, **Simple selected by default**. Per the PM
     resolution, the Enhanced option is **selectable** and persists an `enhanced`
     value; **no "coming soon"/disabled gating is required or applied** (pre-MVP
     partial states are permitted). Left at functional-minimum — no bespoke polish.
     A neutral one-line help note may indicate Enhanced-mode behaviours (mapping,
     storage, retries) are not yet functional, but Enhanced remains choosable. The
     Simple path is the item-#1 acceptance bar; the selector adds no Simple-path
     requirement. In **edit** mode the control is pre-set to the proxy's saved mode
     and can be changed.

2. **Destinations** (repeatable rows; min 1 — AC2)
   - Section label "Destinations" + one-line help: "The webhook is delivered to
     every destination below."
   - Each **row** is a horizontal group: **URL** (kit Input, `type=url`, required,
     grows to fill), **Method** (kit Select: POST default / PUT — AC3), and a
     **Remove** icon-button.
     - The **Remove** control is **disabled (or hidden)** while exactly one row
       remains, so a proxy can never be created with zero destinations.
   - **Add destination** — kit secondary/outline button below the rows; appends an
     empty row and moves focus to its URL input.

3. **Form actions:** primary button — **Create proxy** in create mode, **Save
   changes** in edit mode (matching the auth screens' primary button **[SK-CONFIRMED
   style]**); **Cancel** secondary/ghost button or link back to the list (edit mode)
   or detail (from detail-page edit).

**States.**
- **Default (create):** Name empty, mode = Simple, exactly one empty destination row,
  Remove disabled on that row.
- **Default (edit):** Name, mode, and destination rows pre-filled from the existing
  proxy; Remove disabled/hidden when only one row remains (AC16b — an edit cannot
  remove the last destination).
- **Adding/removing rows:** rows append/remove instantly; no confirmation during
  creation (nothing is persisted yet). List reflows; focus management per
  Interactions.
- **Validation error (server round-trip via Inertia):** the page re-renders with the
  submitted values retained and errors shown inline:
  - Name error under the Name field.
  - Per-row URL errors under the offending row's URL field via `InputError`, keyed
    `destinations.{i}.url` from Inertia's `errors` bag **[SK-PRIMITIVE: the kit's
    auth forms already render field errors this way; array-keyed errors are the
    first in-app use — Principal Engineer confirms the exact error-bag keys]**.
  - A short summary/error flash (Sonner toast) may also appear at the top.
  - Focus moves to the first field in error.
- **Submitting:** primary button shows a busy/disabled state and inputs are disabled
  for the duration (Inertia form `processing` state **[SK-PRIMITIVE]**); prevents
  double-submit.
- **Success:** redirect to Screen 3 (detail) with a success flash — "Proxy created"
  (create) or "Changes saved" (edit).
- **Unexpected server error:** kit error flash ("Something went wrong. Please try
  again."); form values retained.

### Screen 3 — Proxy detail (`/proxies/{proxy}` show)
**Layout.** App-shell page. Content top-bar breadcrumb: "Proxies / {name}". Header:
proxy **name** + a **Mode** badge. Body as stacked shadcn-vue Cards **[SK-PRIMITIVE]**
— ordered so later items can add sibling cards/tabs without disturbing #1:

1. **Ingest URL card** (the AC12d payoff)
   - Label "Ingest URL".
   - The **full URL** (`https://<ingest-host>/ingest/{token}`) in a read-only,
     monospace field / code block, horizontally scrollable if long.
   - A **Copy** button (icon + "Copy") adjacent.
   - A short caution line: "Anyone with this URL can post webhooks to this proxy.
     Keep it secret." (Reflects ADR-006's "secrecy of the URL is the sole
     authenticator" at #1.)
   - The full URL is rendered **server-side** by concatenating a configured ingest
     host with the proxy's **decrypted plaintext token** — confirmed feasible by
     ADR-006's "Display feasibility" addendum (the token is stored in an `encrypted`
     column precisely so it can be displayed; AC12d). The host comes from **server
     config, never the request `Host` header**; the token is kept out of any
     logging/analytics/prop capture (same constraint as Screen 1). *(This resolves
     the former **[ASSUMPTION — feasibility]** marker and Open Question #3.)*

2. **Destinations card**
   - A list/table of destinations: **URL** and a **Method** badge (POST/PUT). Shows
     "every configured destination" (AC4).
   - Each destination row carries a **Remove** control for quick single-destination
     deletion (AC16c). Confirming removes that destination and re-renders the card
     with a success flash. The Remove control is **disabled** on the **last remaining
     destination** with a hint ("A proxy must keep at least one destination"),
     enforcing the minimum-one-destination invariant (AC16b) in the UI. (Bulk/multi
     destination edits, URL/method changes, and adding destinations happen on the
     Edit form — Flow D — to reuse the repeatable-row composite.)

3. **Actions** (AC16, team-scoped)
   - **Edit** button → the Screen 2 form pre-filled (Flow D), for changing name,
     mode, and destinations (add/remove/change URL + method).
   - **Delete** button → a destructive confirmation dialog (shadcn-vue AlertDialog
     **[SK-PRIMITIVE]**) naming the proxy (Flow F); on confirm the proxy and its
     destinations are deleted and the user returns to the list with a success flash.

**States.**
- **Default:** all cards populated (a proxy always has ≥1 destination by AC2/AC16b,
  so the destinations card is never empty; the last destination's Remove is disabled).
- **Copy success/failure:** see Interactions (copy affordance).
- **Confirming a delete (destination or proxy):** the AlertDialog is open with
  Cancel / Confirm; the confirm button uses the kit's destructive variant and enters
  a busy/disabled state during the round-trip (kit default; no bespoke polish).
- **Delete success:** destination removal re-renders the detail page with a flash;
  proxy deletion redirects to the list with "Proxy deleted".
- **Loading / not-found / cross-team:** kit global progress bar on visit; a proxy
  not belonging to the team resolves to the kit's standard 403/404 page (backend
  team scope, AC5/AC6/AC16e).

### Later-item attach points (so #1 screens don't preclude them)
- **#11 analytics** — detail gains a "Deliveries"/"Analytics" tab or sibling card
  reading the already-captured attempt records; the stacked-card/tab layout leaves
  room. No attempt UI now.
- **#8 mapping** — enhanced-mode proxies gain a "Maps" tab/section on detail; the
  mode control already exists on the form.
- **#6 retry/replay** — actions attach to attempt rows surfaced by #11.
- **#3 decoupled response** — the create/edit form gains a "Response" section
  (status/body); the form's section layout accommodates it.
- **#7 enhanced toggle** — mode is already editable on the create/edit form; #7
  makes the Enhanced selection functionally meaningful (mapping/storage/retry) rather
  than a persisted-but-inert value.
- **#2 roles** — the New/Edit/Delete/Remove controls (now present at #1) become
  permission-gated; layout unchanged.

## Components
All reused from the confirmed starter kit (official Laravel Vue kit, shadcn-vue /
reka-ui on Tailwind, dark-mode default). Status markers: **[SK-CONFIRMED]** seen in
a screenshot; **[SK-PRIMITIVE]** shipped by shadcn-vue in this kit but first used
in-app by this feature.

| Role in this spec | Starter-kit component | Status |
|---|---|---|
| Sidebar app shell + collapse toggle + breadcrumb | AppSidebar / sidebar layout | SK-CONFIRMED |
| Team switcher / user menu | DropdownMenu in sidebar header/footer | SK-CONFIRMED |
| Sidebar nav item (add "Proxies") | NavMain item (icon + label, active state) | SK-CONFIRMED |
| Primary / secondary / ghost buttons | Button (variants) | SK-CONFIRMED (auth) |
| Text + URL inputs, labels, field errors, help text | Input, Label, InputError | SK-CONFIRMED (auth) |
| Method + mode dropdowns | Select | SK-PRIMITIVE |
| Proxies list table | Table | SK-PRIMITIVE |
| Cards / form section wrappers | Card | SK-PRIMITIVE |
| Mode & method badges | Badge | SK-PRIMITIVE |
| Success/error flash | Sonner (toast) | SK-PRIMITIVE |
| Destructive confirmation (delete proxy / destination) | Dialog / AlertDialog | SK-PRIMITIVE |
| Page load indicator | Inertia global progress bar | SK-PRIMITIVE |

**New composites this feature introduces (the stock kit ships no equivalent):**
- **Repeatable destination-row group** (URL + method + remove, plus an add-row
  button). The stock kit has no dynamic repeatable-row pattern, so this is a **new
  composite** built from existing Input / Select / Button primitives — not a new
  design language. It becomes the reusable pattern for future N-row inputs.
- **Copy-to-clipboard field** (read-only value + Copy button + copied feedback). The
  stock kit has no copy control, so this is a **small new component** on Button +
  Clipboard API. Reused for the ingest URL on both list and detail.

## Interactions
- **Add destination row:** appends an empty row; focus moves to the new URL input;
  the list of rows is a labelled group so screen readers announce membership.
- **Remove destination row:** removes that row immediately (no modal — unsaved data);
  Remove is disabled/hidden when one row remains (guarantees ≥1). Focus moves to the
  previous row's URL input (or the Add button if the removed row was first).
- **Method / mode selects:** standard select semantics; method default POST; mode
  default Simple. Enhanced is **selectable** (not disabled/gated) and persists an
  `enhanced` value; it has no functional difference at #1 (PM resolution).
- **Field validation feedback:** server-authoritative via Inertia; errors render
  inline per field/row on submit; the first errored field receives focus. (Optional
  lightweight client hints — required name, non-empty URL — may mirror server rules
  but the server is the source of truth; no separate client validation contract is
  asserted.)
- **Submit:** **Enter** in a text field submits the form (kit default); primary
  button enters a busy/disabled state; inputs disabled until the round-trip
  resolves; no double-submit.
- **Copy ingest URL affordance (explicit AC deliverable):**
  - Clicking **Copy** writes the full ingest URL to the clipboard (Clipboard API).
  - **Success:** a plain label swap to "Copied" (no animation) plus an
    `aria-live="polite"` announcement "Ingest URL copied to clipboard." (Per the
    fidelity posture — functional feedback, not a polished micro-interaction.)
  - **Failure** (clipboard blocked): the URL field is already selectable read-only
    text, so the user can select-and-copy manually. No bespoke fallback UI needed.
  - The same copy control appears on each list row and on detail; identical
    behaviour. On the list each row copies its **own** proxy's full ingest URL.
- **Delete a proxy / a destination (destructive):** the Delete control opens a
  shadcn-vue **AlertDialog** naming the target and stating the consequence; the
  action fires only on explicit **Confirm** (Cancel is the default focus); the
  confirm button uses the destructive variant and disables during the round-trip
  (no double-submit). On a **destination** delete, the control is disabled when it is
  the last remaining destination (AC16b) — the invariant is enforced in the UI, not
  only server-side. Success surfaces a Sonner flash.
- **Flash messages:** success and error flashes use the kit's Sonner toast
  **[SK-PRIMITIVE]**, driven off Inertia shared flash props, so notifications match
  the kit; this spec invents no bespoke notification style.

## Accessibility
- Every input has a programmatically associated **Label**; help text and error text
  are associated via `aria-describedby`.
- **Destination rows** are wrapped in a `fieldset`/group with a legend/label
  "Destinations"; each **Remove** button has an accessible name that identifies its
  row (e.g. "Remove destination 2"); the **Add destination** button is reachable in
  the tab order immediately after the last row.
- **Focus management:** on add-row, focus the new URL field; on remove-row, focus a
  sensible neighbour; on validation error, focus the first field in error.
- **Copy control:** a real `button` with a discernible name ("Copy ingest URL");
  copy result announced via an `aria-live` polite region (not colour/icon alone).
- **Delete / Remove controls:** each has a discernible accessible name identifying
  its target (e.g. "Delete proxy {name}", "Remove destination 2"); the confirmation
  **AlertDialog** traps focus, defaults focus to the non-destructive **Cancel**, is
  dismissible via Esc, and announces its title/description (shadcn-vue AlertDialog
  handles this). A disabled last-destination Remove exposes its reason via
  `aria-describedby` (the "must keep at least one destination" hint), not colour
  alone.
- **Badges** (mode/method) are not the sole carrier of meaning — the adjacent text
  label conveys the same information; colour is decorative.
- Targets inherit the kit's contrast and focus-ring tokens (shadcn-vue / Tailwind,
  **dark-mode default** with the kit's light/appearance toggle if present); this
  spec sets no colour values, deferring to the kit's accessible defaults, and both
  the mode/method badges and copy-feedback must read in dark and light.
- Keyboard: the entire create flow (fill fields, add/remove rows, submit) and the
  copy control are operable without a pointer.

## Responsive Behavior
The PRD scopes no specific form factor; this is a developer-facing tool used
primarily on desktop, so **desktop-first**, degrading gracefully:
- **Proxies list table:** the stock kit has no in-app table to inherit behaviour
  from, so this spec sets it: the shadcn-vue Table sits in a horizontally
  scrollable container on narrow viewports (simplest accessible default); a
  stacked-card fallback is optional, not required at #1.
- **Sidebar shell:** collapses via the confirmed collapse toggle on narrow
  viewports **[SK-CONFIRMED]** — inherited, not redesigned.
- **Create form:** single-column; destination rows that are horizontal on wide
  screens **wrap** on narrow screens (URL full-width above a row of Method + Remove).
- **Detail cards:** stack vertically; the ingest-URL field scrolls horizontally
  within its card rather than overflowing the layout.
- All inherits the app shell's responsive nav (collapsing sidebar/hamburger) from
  the kit — not redesigned here.

## Open Questions
**None — all resolved (2026-07-30).** Recorded here with their resolutions for
downstream traceability:

1. ~~**[PM — requirement scope]** Item #1 management surface + mode-selector
   behaviour~~ — **RESOLVED** by the Project Owner via
   `docs/questions/prd-01-design-manage-scope.md` and PRD-01 AC16. Edit AND delete
   are in scope (folded into Flows D–F, Screen 2 create+edit, Screen 3 actions,
   destination Remove). The mode selector needs **no** "coming soon"/disabled gating;
   Enhanced is selectable and may persist a partial value.
2. ~~**[Owner — UX preference]** Full ingest URL / destinations inline in the list
   vs. count + drill-in~~ — **RESOLVED** by the Project Owner: **INLINE**. Screen 1
   now shows the full ingest URL inline with the Copy control and the per-URL secrecy
   caution.
3. ~~**[Principal Engineer — feasibility]** Server-side display of the full ingest
   URL on list/detail~~ — **RESOLVED** by the Principal Engineer in ADR-006's
   "Display feasibility for the UI" addendum: feasible for **both** detail and inline
   list rows. Constraints folded into Screens 1 and 3 (paginated list; decrypted
   plaintext token; host from server config, not the request Host header; tokens kept
   out of logging/analytics/prop capture). The Screen 3 **[ASSUMPTION — feasibility]**
   marker is removed.

### Screenshots — RESOLVED (2026-07-30)
The Owner supplied the stock-kit screenshots in `docs/design/screenshots/`
(`landing`, `login`, `register`, `dashboard`). These grounded the app shell,
auth-form field pattern, primary-button style, team switcher, user menu, and
dark-mode default (see the confirmation note at the top and the **[SK-CONFIRMED]**
markers). No further screenshots are outstanding: the stock kit ships **no** in-app
table, detail, modal, flash/toast, repeatable-row, or copy control to photograph —
those are built new by this feature from shadcn-vue primitives (**[SK-PRIMITIVE]**),
so there is nothing left to request.

## Handoff
- **Inputs:** Approved `docs/product/prd-01-walking-skeleton.md`;
  `docs/plans/foundational-architecture-plan.md` (Accepted);
  `docs/architecture/adr-006-ingest-url-generation-security.md`;
  `docs/product/vision.md`, `docs/product/roadmap.md`.
- **Outputs:** this design spec; `docs/questions/prd-01-design-manage-scope.md`.
- **Dependencies:** the official Laravel Vue (Inertia) starter kit — shadcn-vue /
  reka-ui components, Tailwind, dark-mode default, sidebar app shell — confirmed via
  `docs/design/screenshots/`; auth/register/teams reused, not designed here.
- **Outstanding Questions:** **None.** All three prior Open Questions are resolved
  (PM management-scope + mode via `docs/questions/prd-01-design-manage-scope.md` /
  PRD-01 AC16; Owner list-URL affordance = inline; PE ingest-URL display feasibility
  via ADR-006 "Display feasibility" addendum). Screenshot asks are **RESOLVED** (kit
  confirmed). No section remains in draft.
- **Next Agent:** Principal Engineer (item #1 per-PRD implementation plan). This spec
  is **Approved** (Project Owner, 2026-07-30) and handed off. Two ingest-URL display
  constraints are flagged for the PE/plan to pin down implementationally: the exact
  server-side ingest-host **config key** (ADR-006 suggests `config('ingest.url')` /
  `INGEST_URL`, defaulting to `config('app.url')`) and keeping ingest tokens out of
  logging/APM/analytics/prop capture — both are backend responsibilities, not UI.
