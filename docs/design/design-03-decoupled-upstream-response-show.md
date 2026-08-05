# Design Addendum: Decoupled upstream response — Show/detail page presentation

- **Status:** Approved
- **Author:** Designer
- **PRD:** `docs/product/prd-03-decoupled-upstream-response.md` (Approved)
- **Approved by / date:** Product Manager, 2026-08-04 (design gate, delegated)
- **Approval note:** Review-driven Show-page presentation addendum to Feature #3.
  Read-only detail-page rendering only; does not touch the Owner-approved
  create/edit form. Verified consistent with PRD-03 AC3 (unconfigured →
  `Default (202 Accepted)`), AC4 (closed status set `{200, 202, 204}`), and AC12
  (`204` ⇒ empty body, no body block rendered). Status badge labels and the
  `(empty)` cue confirmed verbatim against `ProxyForm.vue` SelectItem labels
  (lines 157/159/160/161) and the `response_body` placeholder (line 185).

> **Scope note.** This is a **narrow addendum**, not a full feature spec. Feature
> #3's create/edit form (`resources/js/pages/proxies/ProxyForm.vue`) is **already
> built and Owner-approved** (PR #4, 2026-08-04 review) — it is not touched or
> redesigned here. The gap this addendum closes: the read-only proxy **Show**
> page (`resources/js/pages/proxies/Show.vue`) currently renders **no**
> response-config fields at all, even though `response_status` /
> `response_body` are already present on the `ProxyDetail` payload
> (`resources/js/types/proxies.ts`) and only `Edit.vue`/`ProxyForm.vue` consume
> them today. This addendum specifies the one new **Response** card on Show and
> its states. It reuses `design-01-walking-skeleton.md`'s established Show-page
> card pattern (Ingest URL card, Destinations card) and follows up on that
> spec's own "later-item attach point" note, which anticipated #3 touching only
> the form — the Show-page gap was not foreseen there and is what this addendum
> adds.

## Decision — show it, read-only, as a new card

The response configuration **is shown** on the Show page. Rationale: the field
is already editable (Edit.vue) and returned in the Show payload; a team member
configuring a proxy's acknowledgement contract needs to verify what is
*currently* in effect without opening Edit. Withholding it from Show would be
an inconsistent, arbitrary gap now that the form exists.

**Placement:** a new **Response** `Card`, inserted **between** the existing
**Ingest URL** card and the **Destinations** card. Rationale for the order:
Ingest URL (where webhooks arrive) → Response (what is immediately sent back to
the sender at that same ingest point) → Destinations (where the payload is
separately fanned out downstream). Response is conceptually paired with the
ingest/acknowledgement moment, not the downstream fan-out, so it reads better
before Destinations.

## Screens & States

### Screen 3 — Proxy detail (`/proxies/{proxy}` show) — Response card (new)

**Layout** (matches the existing card idiom in `Show.vue`: `Card class="gap-3
p-6"`, `h2.text-sm.font-medium` heading, `p.text-sm.text-muted-foreground`
helper line — see the Ingest URL card for the exact pattern being followed):

```
Card
  h2 "Response"                                     (text-sm font-medium)
  p  "Returned to the sender immediately when the    (text-sm text-muted-foreground)
      webhook is received — independent of whether
      delivery to your destinations succeeds."
  dl (label/value pairs, see below)
    dt "Status"  / dd <Badge>…</Badge>
    dt "Body"    / dd <body value, see states>
```

Use a plain semantic `<dl>`/`<dt>`/`<dd>` for the two label/value rows (a
definition list is the correct semantic for read-only key→value data and needs
no `Label`/`for` association since nothing is editable here — this is a
**first use** of `dl` in the app; no new visual component is introduced, only
plain HTML elements styled with existing utility classes). Each row: `dt` is
`text-sm text-muted-foreground` (label), `dd` holds the value. Rows stack on
narrow viewports (`flex-col sm:flex-row sm:items-baseline sm:gap-2` on a
wrapping row container), consistent with the app's desktop-first/degrade-
gracefully responsive stance (`docs/standards/design.md` → Responsive targets).

**Status value — a `Badge`** (`variant="secondary"`, matching the existing Mode
badge in `Show.vue`'s header — not `outline`, which this page reserves for the
destination-method badge). Badge text is copied **verbatim** from the
`ProxyForm.vue` `SelectItem` labels so the same status reads identically on
both the edit form and the detail page:

| `response_status` | Badge text |
|---|---|
| `null` (unconfigured) | `Default (202 Accepted)` |
| `200` | `200 OK` |
| `202` | `202 Accepted` |
| `204` | `204 No Content` |

**Body value — four states, one shared rule:** render an actual bordered
monospace box **only** when there is real body content to show. In every case
where there is **no** body to display (unconfigured, `204`, or an explicit
empty/null body on `200`/`202`), render a single line of plain muted text —
**never an empty bordered box** (a bordered box with nothing in it reads as
broken/loading, not "intentionally empty").

1. **Unconfigured** (`response_status === null`):
   `dd` = `No custom body configured — the default response has no body.`
   (`text-sm text-muted-foreground italic`)
2. **204 (No Content)** (`response_status === 204`; AC12 guarantees the body is
   always empty in this case, so there is nothing else to check):
   `dd` = `No content (204)`
   (`text-sm text-muted-foreground italic`)
3. **200/202 with an empty body** (`response_status` is `200` or `202` **and**
   `response_body` is `null` or `''`):
   `dd` = `(empty)`
   (`text-sm text-muted-foreground italic` — this exact string mirrors the
   `placeholder="(empty)"` already used on the `response_body` `Input` in
   `ProxyForm.vue`, so the same "nothing here" cue reads consistently between
   form and detail.)
4. **200/202 with a non-empty body** (`response_status` is `200` or `202`
   **and** `response_body` is a non-empty string): render the raw value in a
   read-only block styled with the same visual tokens as the app's other
   read-only field surfaces (`CopyField`'s input, form `Input`s) — border,
   `font-mono text-sm`, `dark:bg-input/30` — but as a `<div>`/`<pre>`, not an
   `<input>`, since body content may be multi-line (e.g. a JSON challenge-echo
   payload) and should **wrap**, not truncate or force a single line:
   `class="rounded-md border border-input bg-transparent px-3 py-2 font-mono
   text-sm whitespace-pre-wrap break-words dark:bg-input/30 max-h-48
   overflow-y-auto"`. The `max-h-48 overflow-y-auto` cap is a **new,
   Proposed-default** guard (no PRD length limit exists — body constraints
   were explicitly deferred to the Principal Engineer per AC4) so an unusually
   long configured body cannot blow out the detail page's layout; flag this to
   the Senior Developer as a small, low-risk default, not a hard requirement.
   No Copy button is added here — unlike the ingest URL, the response body is
   not a secret, and the PRD does not ask for a copy affordance on it.

**States summary (all four covered, matching the task's ask):**

| Case | Status badge | Body cell |
|---|---|---|
| Unconfigured | `Default (202 Accepted)` | *(italic)* No custom body configured — the default response has no body. |
| 204 | `204 No Content` | *(italic)* No content (204) |
| 200/202, empty body | `200 OK` / `202 Accepted` | *(italic)* (empty) |
| 200/202, configured body | `200 OK` / `202 Accepted` | bordered monospace block showing the body verbatim |

No loading/error states beyond the page-level ones already specified in
`design-01-walking-skeleton.md` Screen 3 (Inertia global progress bar on
visit; team-scoped 403/404 fallback) — this card has no independent
fetch/mutation of its own, it only renders fields already present on the
existing Show payload.

## Components

| Role | Component | Status |
|---|---|---|
| Card wrapper | `Card` (`components/ui/card`) | Reused (existing Show.vue pattern) |
| Section heading | `h2.text-sm.font-medium` | Reused (existing Show.vue pattern) |
| Helper text | `p.text-sm.text-muted-foreground` | Reused (existing Show.vue pattern) |
| Status value | `Badge` `variant="secondary"` (`components/ui/badge`) | Reused (existing Mode-badge pattern) |
| Label/value rows | plain `dl`/`dt`/`dd` | **First use in this app** — plain semantic HTML, no new component file |
| Non-empty body block | `div`/`pre`, styled with existing `Input`/`CopyField` visual tokens (border, `font-mono`, `dark:bg-input/30`) | **New small pattern** (multi-line, wrapping variant of the existing single-line read-only field look) — not a new component library addition, just a styled block reusing existing tokens |

No new dependency, icon, or third-party component is introduced.

## Interactions

None beyond passive rendering — this card has no buttons, dialogs, or
mutations. It re-renders whenever the Show page re-renders (e.g. after an
Edit-form save redirects back to Show), always reflecting the current
`response_status`/`response_body` off the same `ProxyDetail` prop already
passed to the page.

## Accessibility

- The `dl`/`dt`/`dd` structure is announced by screen readers as label→value
  pairs without needing `aria-label`s or `for`/`id` associations (nothing here
  is an input).
- The status `Badge` is not the sole carrier of meaning — it always contains
  the full text (`"200 OK"`, `"204 No Content"`, etc.), never colour/icon
  alone, consistent with `docs/standards/design.md`'s "Colour is never the
  sole carrier of meaning" rule.
- The italic "no body" strings (`No content (204)`, `(empty)`, the
  unconfigured line) are real text content, not a placeholder-only or
  colour-only cue, so they are read by assistive tech exactly as sighted users
  see them.
- The non-empty body block is plain readable text (not `readonly` input
  styling with a forced single line), so it is fully selectable and reachable
  by screen readers without any special affordance.
- Meets WCAG 2.1 AA per `docs/standards/design.md`'s accessibility baseline;
  no new interactive control is introduced that needs separate keyboard
  handling.

## Responsive Behavior

- Follows the existing Show-page card stacking (`design-01`: "Detail cards
  stack vertically"). The new Response card sits inline in that same vertical
  stack — no layout change to the surrounding cards.
- The label/value rows go `flex-col` (label above value) below `sm` and
  `sm:flex-row` (label beside value) at `sm` and above, matching the project's
  general "fixed-width control goes full-width below `sm`" convention
  (`docs/standards/design.md` → Responsive targets).
- The non-empty body block wraps (`whitespace-pre-wrap break-words`) rather
  than requiring horizontal scroll, since response bodies are short,
  non-secret text/JSON, unlike the ingest URL (which intentionally scrolls
  horizontally as a single-line bearer secret).

## Open Questions

None. This addendum only fills a rendering gap on an already-approved,
already-implemented data shape (`ProxyDetail.response_status` /
`.response_body` already ship in the Show Inertia payload); no requirement or
technical-feasibility question is raised.

## Handoff

- **Inputs:** `docs/product/prd-03-decoupled-upstream-response.md` (Approved,
  esp. AC3/AC4/AC12), `docs/design/design-01-walking-skeleton.md` (Show-page
  card pattern being extended), `resources/js/pages/proxies/Show.vue` (current
  file, unchanged elsewhere), `resources/js/pages/proxies/ProxyForm.vue` (label
  copy reused verbatim for consistency), `resources/js/types/proxies.ts`
  (`ProxyDetail.response_status` / `.response_body`, already typed),
  `docs/standards/design.md`.
- **Outputs:** this addendum.
- **Dependencies:** none new — `Card`, `Badge` already imported in `Show.vue`;
  no new npm dependency, icon, or `ui/*` primitive is required.
- **Outstanding Questions:** None.
- **Next Agent:** Product Manager, to approve this addendum against PRD-03
  (design gate), then Senior Developer to implement (small, additive change to
  `Show.vue` only — no backend change, the fields are already exposed).
