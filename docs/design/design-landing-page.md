# Design Spec: Product Landing Page

- **Status:** **Approved** (Project Owner, 2026-08-25)
- **Author:** Designer
- **Governing artifact:** This spec. No PRD exists — the Project Owner ruled
  this a **small change** (Designer + Senior Developer only, its own branch
  and PR; see `CLAUDE.md` "small work is flat, no gates"). Nothing below
  invents a product requirement beyond what the Owner's brief and the
  existing `docs/product/vision.md` / `docs/product/roadmap.md` already
  state.
- **Approved by / date:** **Project Owner, 2026-08-25.** This small-change flow
  has no Product Manager design gate. **All five flagged calls under Open
  Questions were put to the Owner and ACCEPTED as specified** — see the
  ruling recorded there. Implementation may proceed against this spec as
  written.
- **Correction (Designer, 2026-08-25, same day as approval — factual error
  caught by the Project Owner, not a re-approval):** Illustration 1's FIFO
  panel was factually wrong. It animated FIFO's ordering guarantee on the
  **destination** axis (a single dot visiting Destination 1, then 2, then
  3, with a queued dot waiting for a destination's turn). Per-proxy FIFO is
  **event**-level ordered, not per-destination — ADR-011 §Impact explicitly
  rules per-`(proxy, destination)` ordering out of scope, and
  `AdvanceProxyFifoQueue`'s docblock states the guarantee is "at most one
  in-flight **event** per proxy." **Fixed:** the FIFO panel now animates
  events queuing and processing one at a time per proxy, while each event
  still fans out to all configured destinations exactly as before; the two
  affected Copy strings (the Panel B caption, and "How it works" step 3's
  body) are rewritten to match. The Async panel's substance was already
  correct (parallel fan-out per event) and is unchanged; it now also shows
  multiple events overlapping, so the two panels stay a true, matched
  contrast on the same (event) axis. Status remains **Approved** and the
  Owner's approval above is unchanged — this is a correction under that
  approval, not a re-approval request. Full verification trail: see
  **Factual Audit (2026-08-25)** at the end of this spec.
- **Structure revision (Designer, 2026-08-25, later the same day — several
  Owner-directed changes of their own earlier ruling in one session, not
  factual corrections):** the Project Owner revised Illustration 1's
  presentation, spec form, rendering technology, craft bar, and travelling
  effect, each refining the last. This supersedes **Open Questions call 2**
  below (two independent, simultaneously-visible mini-diagrams). Net result:
  Illustration 1 is now **one canvas-drawn diagram** showing two inbound
  events on the left fanning out to the same three shared destinations,
  playing **Async then FIFO back to back in one continuous loop, forever**,
  with both mode names labeled in a DOM legend alongside it, specified as a
  **declarative timeline schema** (two data instances, `async` and `fifo`,
  driven by one small `requestAnimationFrame` loop, not hand-authored
  per-element keyframes), and rendered as a **charge pulse** (current
  through a conductor, not a ball) at a craft bar matching Laravel's own
  marketing site and the Vercel/Stripe/Linear/PlanetScale tier. Per the
  Owner's explicit direction, total loop length and whether a viewer sees
  both phases are **not** design constraints here — the illustration is
  decorative bells-and-whistles; the bar is that the motion looks good.
  None of this is a correction of an error — every superseded version was
  sound and Owner-accepted at the time; the Owner changed their mind about
  presentation, spec form, technology, and craft target. Illustration 1, its
  Copy legend, Light/Dark Treatment, Responsive Behavior, Reduced-Motion
  Fallback, Components, and Accessibility wording are rewritten below to
  match; Illustration 2 is untouched. Status remains **Approved**; this is a
  revision under the existing approval, per the same small-change flow as
  the correction above. See **Redesign Notes (2026-08-25)** near the end of
  this spec for the compact record of the sequence and key choices.

## Purpose & Audience

This page is the public, unauthenticated root of the app (`/`, route name
`home`, component `resources/js/pages/Welcome.vue`). It replaces the
untouched Laravel starter-kit boilerplate currently rendered there. Its job
is narrow: tell a visitor what this product does — in the product's own
vocabulary, not invented marketing terms — well enough to register or log
in, and **show, not just claim**, the core mechanic the Owner asked for: one
webhook received, fanned out to multiple destinations, reliably.

**Audience:**
- **Anonymous visitors** — the primary audience of a landing page: someone
  deciding whether to register. They have seen nothing else of the app, so
  every claim here must be true of what's shipped today. No mapping (#8), no
  analytics/dashboard (#11), no notifications (#13) — none of that is built,
  so none of it is promised.
- **Authenticated users who land on `/`** — a bookmark, a shared link, or the
  bare domain typed in. They should not be stuck reading a sales pitch; the
  header's existing behavior (Dashboard link instead of Log in/Register)
  already handles this and is preserved unchanged.

**Explicitly not this page's job:** a pricing page (`vision.md` rules out
billing for the MVP), a docs/help center, or a full marketing site with
testimonials or case studies. None of that is asked for, and none of it is
added here.

**Reviewer checklist** (what "done" means, in place of a PRD's acceptance
criteria):
- External `rsms.me` font links are gone; the page renders in the app's
  existing `font-sans` (Instrument Sans, self-hosted via Laravel's `@fonts`
  directive in `resources/views/app.blade.php` — already loaded globally, no
  per-page font-loading needed).
- The header's auth-branching (Dashboard link vs. Log in/Register, the
  team-slug-aware dashboard URL) behaves identically to today's
  implementation.
- The illustrations are honest to the product facts named in the Owner's
  brief: single-ingest fan-out, the Async-vs-FIFO contrast, bounded-backoff
  retry, terminal failure, and manual replay — nothing else is depicted.
- Both illustrations work in light and dark mode using only existing design
  tokens (`docs/standards/design.md`'s color-token table) — no new hex
  values, no new token added without Owner approval.
- `prefers-reduced-motion: reduce` yields the specified **static** fallback,
  not a silently-frozen mid-animation frame.
- The page is usable down to 360px viewport width; nothing overflows or is
  clipped.
- No new npm dependency was introduced (Illustration 1: native `<canvas>` +
  `requestAnimationFrame`; Illustration 2: CSS + inline SVG, unchanged).

## Overview

A visitor lands on a single, static marketing page: a header (nav that
branches on auth state, unchanged from today), a hero with a one-line
promise and a single animated diagram showing two webhook events fanning out
to the same three destinations — first dispatched in parallel (**Async**),
then, in the same continuous loop, dispatched strictly one at a time
(**FIFO**) — with both mode names labeled alongside the diagram at all
times, a three-step "How it works" section in the
product's own words, and a "Reliability" section pairing a short explanation
of automatic retry/backoff/terminal-failure/replay with a matching animated
diagram. There is no data fetch, no form, and no authenticated content on
this page; every state below is a **presentation** state (motion-preference,
color-scheme, viewport width), not a loading/error/empty state in the usual
sense.

## Page Structure

```
<Head title="Webhook Proxy Service" />          (no external font links)

<div class="bg-background text-foreground">     (page root, full-bleed)

  <header>                                       Section A — Nav
    <nav> Dashboard | Log in + Register </nav>   (unchanged auth branch)

  <main>
    <section> Hero </section>                    Section B
      h1 + subhead + CTA buttons
      Illustration 1 — Async / FIFO fan-out (single diagram, two phases)

    <section> How it works </section>            Section C
      3 steps, icon + heading + body each

    <section> Reliability </section>             Section D
      heading + intro paragraph + 4-step list
      Illustration 2 — retry / backoff / terminal / replay
  </main>
</div>
```

No footer is added (see Open Questions #4 — flagged, not assumed).

## User Flows

*(A marketing page has one flow per entry state, not per user story — there
is no PRD to enumerate stories from.)*

### Flow 1 — Anonymous visitor arrives at `/`
1. Page renders fully server-rendered via Inertia (no client fetch); header
   shows **Log in** / **Register**.
2. Visitor reads the hero, watches (or sees the static fallback of) the
   fan-out illustration, scrolls through "How it works" and "Reliability".
3. Visitor clicks **Register** → existing registration flow (unchanged,
   out of this spec's scope) or **Log in** → existing login flow.

### Flow 2 — Authenticated user arrives at `/`
1. Same page renders; header shows **Dashboard** instead, linking to
   `dashboard(currentTeam.slug)` exactly as today.
2. Everything else on the page (hero, illustrations, copy) is identical —
   there is no authenticated-only variant of the marketing content itself,
   matching the current Welcome.vue's behavior (only the header link
   changes).

## Copy

Actual words, not descriptions — every string below is final unless the
Owner rules otherwise on a flagged item.

**Head title:** `Webhook Proxy Service`

**Header nav (unchanged wording from today):** `Dashboard` / `Log in` /
`Register`.

**Hero:**
- Eyebrow (small label above headline, optional but recommended):
  `Webhook Proxy Service`
- Headline (`h1`): **"Ingest once. Deliver everywhere."**
- Subhead: *"One webhook in, fanned out to every destination you configure —
  automatically retried on failure, and replayable on demand, with full
  visibility into every delivery attempt."*
- Primary CTA: **"Register"** (→ `register()`) — shown to anonymous visitors
  only.
- Secondary CTA: **"Log in"** (→ `login()`) — shown to anonymous visitors
  only.
- Authenticated variant: a single **"Go to dashboard"** button (→
  `dashboardUrl`) replaces both CTAs.
- Illustration legend (real text alongside the diagram, not SVG-only — see
  Illustration 1 for the emphasis treatment; both lines render always, in
  this order, regardless of which phase is currently animating):
  - **"Async — every destination receives it at once."**
  - **"FIFO — one event at a time per proxy, processed in the order
    received."** *(Corrected 2026-08-25 — was "destinations receive it
    strictly in order, one at a time," which misstated the guarantee as
    per-destination. See the Correction note above and the Factual Audit
    section. Retained verbatim through the 2026-08-25 structure revision —
    the words did not change, only their layout from a per-panel caption to
    a shared legend line.)*

**Section C — "How it works" (`h2`):** **"How it works"**
1. (`h3`) **"Ingest"** — *"Create a proxy and get a unique ingest URL. Point
   any webhook sender at it — no changes needed on their end."*
2. (`h3`) **"Fan out"** — *"Every request that arrives is delivered to all
   of that proxy's destinations, in the same structure, however many you've
   configured."*
3. (`h3`) **"Choose your processing"** — *"Async dispatches to every
   destination in parallel, for the highest throughput. FIFO processes one
   event at a time per proxy, in the order it was received, before starting
   the next — the right choice when strict ordering matters more than
   throughput."* *(Corrected 2026-08-25 — the prior wording, "FIFO delivers
   strictly in the order events were received," was true but left the
   per-event, one-at-a-time exclusivity ambiguous when read against the
   preceding "dispatches to every destination in parallel" sentence; this
   version states the event-level guarantee explicitly. See the Factual
   Audit section.)*

**Section D — "Reliability" (`h2`):** **"Nothing gets lost, even when a
destination is down."**
- Intro paragraph: *"Every delivery to every destination is tracked on its
  own. When one fails, it's retried automatically on a bounded backoff —
  waiting a little longer each time — instead of hammering a struggling
  endpoint or giving up immediately."*
- 4-step list (mirrors the illustration; also the reduced-motion fallback
  content):
  1. **"Delivery attempted"** — *"Sent to the destination the moment it's
     due."*
  2. **"Retried automatically"** — *"A failure is retried after a short
     wait, then a longer one, up to a set limit."*
  3. **"Marked terminally failed"** — *"Once retries are exhausted, the
     delivery is marked clearly — never hidden or silently dropped."*
  4. **"Replayed on demand"** — *"Any delivery, including a terminally
     failed one, can be sent again manually to some or all of the proxy's
     destinations."*

No other copy (no pricing, no testimonials, no footer links) is introduced —
see Open Questions #4.

## Illustration 1 — Fan-out: Async vs. FIFO (Hero)

> **Redesigned 2026-08-25** (two further Owner-directed revisions the same
> day, after the factual correction above — both changes of presentation,
> not corrections of fact): first to a single diagram alternating Async and
> FIFO in one continuous loop instead of two simultaneously-visible panels;
> then to a canvas-drawn, fully numeric, high-craft treatment matching the
> Owner's named reference tier (Laravel's own marketing site; the
> Vercel/Stripe/Linear/PlanetScale tier — restrained, dark-leaning, high-craft
> motion). This supersedes the two-panel SVG version previously specified
> here and **Open Questions call 2** below. See **Redesign Notes
> (2026-08-25)** near the end of this spec for the compact rationale record.

**What it must be honest to (unchanged from the corrected version above):**
one inbound webhook, fanned out to the same three configured destinations
regardless of mode or event; **Async** admits more than one event in flight
per proxy at once (no cross-event serialization); **FIFO** admits at most
one event in flight per proxy at a time — the second event does not begin
until the first has fully settled. This is an **event-level**
ordering/exclusivity guarantee, not a per-destination one — same sources as
the Factual Audit above (`adr-011-per-proxy-fifo-dispatch-mechanism.md:38`
and `:130`, `AdvanceProxyFifoQueue`'s docblock, `DeliverStep.php:63` vs.
`:71`). Nothing about this redesign touches the claim — only how it is
rendered.

### Concept

**One canvas-drawn diagram** — no SVG, no per-element CSS keyframes. Two
inbound **event** nodes stacked on the left, each with its own small
**junction** point immediately to its right, each junction fanning out to
the **same three destination** nodes on the right (six branch paths total,
three from each junction, converging in pairs on three shared destination
nodes). This confirms the brief's reading: a proxy's destination set is
fixed, so both events, in both modes, always fan to the identical three
destinations. Two independent junctions — rather than one shared junction
both events pass through — is a deliberate geometry choice, not a shortcut:
a shared junction would put both events' travelling pulses through the same
point during Async's overlap, reading as a collision rather than two
independent deliveries. Flagged for Owner confirmation, non-blocking (see
Open Questions).

No text is drawn inside the canvas — no node numbers, no "Proxy" label.
Every fact a viewer needs (which mode is playing, what each means) lives in
the DOM legend beside the diagram (see Labels, below); the canvas draws
shapes and motion only. This is also the accessibility-correct split: canvas
text loses font consistency, can't be selected or translated, and is
invisible to assistive tech, so it never carries meaning here.

**Event identity stays legible by track, not by hue.** The token palette is
deliberately monochrome (`--primary` is a grayscale token in both themes,
per `docs/standards/design.md` — there is no second brand hue to spend on
"Event 1 vs. Event 2" without adding a token, which is an Owner-level
design-system change this spec does not make). Event 1 always travels the
top track (its own ingest node, junction, and branches at y ≈ 30%); Event 2
always travels the bottom track (y ≈ 70%). Combined with their timing offset
(Async: 500ms apart; FIFO: never simultaneous at all), the two events are
never ambiguous about which is which, even though both render in the same
color.

The diagram plays **Async, then FIFO, back to back, forever** — a single
`requestAnimationFrame` driver alternates between two data-shaped timeline
schemas (below). Per the Owner's explicit direction, there is no user
control and no requirement that a viewer see both phases; this illustration
is decorative, and its total loop length is a purely aesthetic call, not a
comprehension one.

### Rendering architecture

- **Single `<canvas>` element**, `aria-hidden="true"`, no `tabindex`, sized
  by its container (a `div` with `aspect-[2/1]`, capped at the page's
  existing `max-w-6xl` content column — **Proposed default, no prior
  precedent**, flagged the same way the container-width call already
  accepted elsewhere in this spec was).
- **Device-pixel-ratio scaling:** on mount and on resize, read
  `canvas.clientWidth`/`clientHeight` (logical px) and
  `window.devicePixelRatio`; size the backing store to `clientWidth * dpr` /
  `clientHeight * dpr`, then `ctx.setTransform(dpr, 0, 0, dpr, 0, 0)` so
  every draw call after that is written in logical px. This is what keeps
  hairlines and the pulse's bloom crisp on retina instead of blurred.
- **Resize:** a `ResizeObserver` on the canvas's container re-runs the DPR
  setup and recomputes node/path positions — specified as **fractions of
  canvas width/height**, not fixed px (see Geometry) — so the diagram scales
  continuously rather than snapping between breakpoint layouts.
- **Theme tokens, read at runtime, never hardcoded:** at init, and again
  every time `document.documentElement`'s `class` attribute changes (a
  `MutationObserver` on `documentElement` — cheap and decoupled from
  `useAppearance()`'s internals; reading that composable's own reactive
  state directly is equally acceptable if simpler for the implementer — the
  requirement is *re-read on every theme change*, not just at mount, since
  this app's `HandleAppearance` middleware and `useAppearance()` support a
  live light/dark/system toggle), call
  `getComputedStyle(document.documentElement)` and read the literal values
  of `--card`, `--border`, `--primary`, `--muted-foreground` — the only four
  tokens this illustration needs (no `--destructive`; that is Illustration
  2's vocabulary, untouched). These come back as the authored `hsl(H S% L%)`
  strings from `app.css` and are used directly as canvas
  `fillStyle`/`strokeStyle` values — **no hex is ever written in code.** A
  small `withAlpha(hslString, alpha)` helper regex-extracts `H S% L%` and
  reformats as `hsl(H S% L% / alpha)` (CSS Color 4 syntax, which
  `fillStyle`/`strokeStyle` accept) — this is the only place a numeric alpha
  is combined with a token, and it is always an opacity of an existing
  color, never a new one.
- **No new npm dependency.** Native `<canvas>` 2D context,
  `requestAnimationFrame`, `ResizeObserver`, and `MutationObserver` are all
  browser built-ins already usable in this codebase.

### Geometry (fractions of the canvas's logical width/height)

| Element | Position (x%, y%) | Shape |
|---|---|---|
| Event 1 (ingest) | 8%, 30% | rounded rect, ~13% × 14% of canvas height |
| Event 2 (ingest) | 8%, 70% | same shape |
| Junction 1 | 42%, 30% | filled circle, r = 1.2% of canvas height, resting alpha 0.4 (`--muted-foreground`) — a static anchor, always drawn, not only during motion |
| Junction 2 | 42%, 70% | same |
| Destination 1 | 88%, 20% | rounded rect, same size as event nodes |
| Destination 2 | 88%, 50% | same |
| Destination 3 | 88%, 80% | same |

Paths: Event *n* → Junction *n* is a straight line; Junction *n* → each
Destination is a quadratic Bézier curve (control point pulled ~15% toward
the vertical center between junction and destination — a soft outward curve
rather than a sharp elbow). Canvas has no `offset-path` equivalent, so the
charge pulse's head position is computed frame-by-frame from the Bézier's
parametric formula, `B(t) = (1−t)²P0 + 2(1−t)tC + t²P1` — plain math, no
library (and this also resolves the `offset-path` cross-browser feasibility
flag the prior SVG version carried in Dependencies below — it no longer
applies).

### Timeline schema

A small, reusable shape — `async` and `fifo` are two **data instances** of
it, not two hand-authored animations:

```
Schema {
  id: 'async' | 'fifo'
  label: 'Async' | 'FIFO'
  duration: number            // ms, total phase length (incl. trailing rest)
  entries: TimelineEntry[]
}

TimelineEntry {
  event: 1 | 2
  kind: 'travel' | 'arrivalRing' | 'queued'
  segment?: 'ingest-junction' | 'junction-dest1' | 'junction-dest2' | 'junction-dest3'
  start: number               // ms, relative to this schema's own t = 0
  end: number                 // ms
  easing: 'inout' | 'out' | 'decay'   // resolved via the three named curves below
}
```

Three named easings, used everywhere in this illustration, nowhere else
invented:
- **`inout`** = `cubic-bezier(0.65, 0, 0.35, 1)` — every `travel` entry
  (the charge pulse's position along its path). Departs easing in, arrives
  easing out, in one curve — the "departures ease in, arrivals ease out"
  instinct expressed as a single standard curve rather than two spliced
  ones.
- **`out`** = `cubic-bezier(0.22, 1, 0.36, 1)` — every `arrivalRing`, and the
  attack (brighten-in) half of the derived line envelope below.
- **`decay`** = `cubic-bezier(0.32, 0, 0.67, 0)` — the release half of the
  derived line envelope, and the pulse's own tail alpha ramp (see the
  rendering section below). An ease-in shape used for *fading out*: alpha
  lingers near its peak before dropping away, which is what makes the
  settle read as current dissipating rather than a value snapping back —
  "a real decay curve, not an instant reset."

**Derived rule (not separately tabled):** every `travel` entry implies its
own segment briefly "heats" — the connection line's alpha rises from idle
toward hot over the first **150ms** the pulse occupies that segment (`out`
easing: brightens quickly), holds near-hot while the pulse travels it, then
releases back to idle over **320ms** (`decay` easing: lingers, then falls
away) once the pulse — and its arrival ring, if any — has passed. This is
the "a connection line that brightens as data passes over it and settles
back" detail, implemented as one small procedural rule per segment rather
than as extra schema entries.

**`EVENT_JOURNEY`** — the shape every event plays, in both modes, offset
only by a start time (ms, relative to the event's own start = 0):

| # | kind | segment | start | end | easing |
|---|---|---|---|---|---|
| 1 | travel | ingest→junction | 0 | 420 | inout |
| 2 | travel | junction→dest1 | 420 | 1070 | inout |
| 3 | travel | junction→dest2 | 490 | 1170 | inout |
| 4 | travel | junction→dest3 | 565 | 1270 | inout |
| 5 | arrivalRing | dest1 | 1070 | 1330 | out |
| 6 | arrivalRing | dest2 | 1170 | 1430 | out |
| 7 | arrivalRing | dest3 | 1270 | 1530 | out |

The three branches deliberately do **not** start or last identically — start
offsets of +70ms/+145ms and travel durations ~5%/~8% longer for branches 2
and 3 — so the fan-out never reads as three things popping on the same
frame; it reads as an uneven, organic ripple. Combined with the charge-pulse
rendering below (no discrete ball ever appears at all), this is the direct
fix for "shooting white balls."

`EVENT_SETTLE` = **1600ms** (rounded up from the true last-effect-finish
time of ~1570ms, a small breathing margin).

**Schema `async`** (`duration: 2500`):
- Event 1: `EVENT_JOURNEY` at offset **0**.
- Event 2: `EVENT_JOURNEY` at offset **+500ms** — "slightly later," per the
  brief, and deliberately *not* half of `EVENT_SETTLE`: 500ms is enough for
  Event 1 to have already begun fanning out before Event 2 even departs, so
  the two read as a graceful handoff rather than two starting guns fired
  together. Event 2 fully settles at 500 + 1600 = 2100ms.
- No `queued` entries — Async never shows a waiting state; that concept
  belongs to FIFO only, and showing it here would misstate Async's actual
  guarantee (no cross-event serialization at all).
- 2100–2500ms: rest beat, idle geometry only.

**Schema `fifo`** (`duration: 3600`):
- Event 1: `EVENT_JOURNEY` at offset **0**, settles at 1600ms.
- Event 2, `kind: 'queued'`, `start: 0`, `end: 1600` — rendered the entire
  time as a **static** muted dot at its own ingest node (no motion, no
  breathing/pulsing — restraint; Event 1's motion already carries the eye).
- Event 2: `EVENT_JOURNEY` at offset **+1600ms** — exactly when Event 1
  settles, a precise zero-gap handoff. **This exactness is deliberate and
  not staggered for "realism" the way Async's offset is** — the zero gap
  *is* the truthful claim (the second event does not begin until the first
  has fully settled), so it is the one place in this diagram that stays
  exact rather than organic. Event 2 settles at 3200ms.
- 3200–3600ms: rest beat.

**Total loop:** 2500 + 3600 = **6100ms (6.1s)**, looping indefinitely. Per
the Owner's explicit direction, this is tuned only for how the motion feels
— not against any "will a visitor see both phases" concern.

### Charge pulse, line, grid, and node rendering — concrete values

The travelling element is **current through a conductor, not a ball in
flight**: a bright core segment with a soft bloom, trailing a long eased
falloff, moving along the path; the connection line itself sits dim at
rest, brightens as the pulse passes over it, and settles back on a real
decay curve. No discrete filled circle ever travels through empty space —
this single effect (chosen over an electric-arc/jitter treatment, a plain
comet, and a particle stream, each considered and set aside as noisier or
cheaper-looking at this craft tier) is the entire fix for "shooting white
balls," and it is now the core visual of the page.

All px values below are given at the diagram's **560px reference width**
(see Responsive Behavior) and scale with the same clamp factor as the rest
of the diagram — so the pulse stays proportionate to the diagram at any
size. The grid does not scale this way (see its own row).

| Property | Dark theme | Light theme |
|---|---|---|
| Grid cell size | 32px (fixed; re-tiles on resize, does not scale with the diagram or the pulse) | 32px |
| Grid line color | `--border` | `--border` |
| Grid line alpha (unmasked) | 0.08 | 0.05 |
| Grid radial fade mask | centered on the diagram's vertical midline, radius ≈ 65% of the shorter canvas dimension; alpha stops 1 → 0.6 → 0 at 0% / 60% / 100% radius, applied via `destination-in` composite | same shape, same stops |
| Grid motion | **static — no drift, no pulse** | static |
| Idle connection line width | 1.5px | 1.5px |
| Idle connection line alpha | 0.15 | 0.25 |
| Peak ("hot") line width, under the pulse | 2.5px | 2.5px |
| Peak ("hot") line alpha, under the pulse | 0.55 | 0.65 |
| Line attack (idle → peak) | 150ms, `out` easing | same |
| Line release (peak → idle) | 320ms, `decay` easing (lingers near peak, then falls away — not an instant reset) | same |
| Pulse core width (the bright segment at the head) | 3px | 3px |
| Pulse core alpha | 1.0, `--primary` | 1.0, `--primary` |
| Pulse falloff (tail) length behind the head | 64px | 64px |
| Pulse tail alpha ramp | eased, `1 − out(t)` for `t` = 0 (head) → 1 (64px back) — fast initial drop, long faint trailing glow, not a linear ramp | same shape |
| Pulse bloom (`shadowBlur` / `shadowColor`, on the core only) | 14px blur, `--primary` @ alpha 0.4 | 5px blur, `--primary` @ alpha 0.18 |
| Arrival ring Δradius | node radius → node radius + 16px | same |
| Arrival ring alpha | 0.55 → 0, `out` easing | 0.4 → 0, `out` easing |
| Arrival ring duration | 260ms | 260ms |
| Arrival ring stroke width | 1.5px | 1.5px |
| Destination "lit" fill wash | `--primary` tint @ alpha 0.08, same 260ms envelope as the ring | same |
| Junction resting dot | `--muted-foreground` @ alpha 0.4, r = 1.2% canvas height, always drawn | same |
| Queued dot (FIFO, idle event) | `--muted-foreground` @ alpha 0.5, static, no animation — the "idle event low in the opacity ladder" state | same |
| Node fill / stroke | `--card` / `--border` @ 1px | same |

**Per-branch stagger (unchanged from Timeline schema above, restated here
because it is part of what keeps the pulse from looking mechanical):**
branch start offsets +70ms / +145ms and travel durations ~5% / ~8% longer
for branches 2 and 3 — the three pulses departing a junction do not arrive
on the same frame.

**Active vs. idle intensity:** the event currently in flight always renders
at the full values above. There is no dimmed "secondary" treatment for a
second event that is genuinely, concurrently in flight during Async — both
render at full strength, distinguished by track position and timing (see
Event identity, above), because both are equally real deliveries. The
*only* dim/idle treatment is the FIFO queued dot — the event that has
**not started** gets the low, static, muted-foreground treatment, never the
pulse's palette. This asymmetry is deliberate: it is what keeps the FIFO
phase from reading as "a dimmer Async" rather than as a genuinely different
guarantee.

**Grid motion, decided:** static in both themes. Per "restraint in element
count" — the travelling pulses are the only motion in this scene; animating
the backdrop too would compete with them rather than support them. This is
a considered choice, not an omission.

**Why dark and light get different bloom/line values, not one set with a
token swap:** a soft white-hot bloom (14px blur, 0.4 alpha) is what makes
the dark-theme diagram read as the named reference tier — `--primary`
resolves to near-white there, so the bloom is a true bright-on-near-black
halo. The same blur/alpha on light theme, where `--primary` resolves to
near-black, would read as a muddy gray smear rather than a glow — so light
theme trades bloom intensity for **line and ring contrast** instead (idle
line 0.25 vs. dark's 0.15, hot line 0.65 vs. dark's 0.55, ring peak 0.4 vs.
dark's 0.55 — lower on light so an expanding near-black ring doesn't read
as a harsh dark halo on white). The "premium" cue on light comes from
crisper, more-contrasted lines rather than a halo; the grid is likewise a
shade fainter on light (0.05 vs. 0.08) because a hairline at equal token
alpha reads visually heavier against white than against near-black. These
are deliberately different numbers per theme, not the same values reused.

**Restraint accounting (why this reads calmer than the prior version):** the
prior SVG version's peak was **3 events × 3 destinations = up to 9**
simultaneous, opaque, solid dots. This version: at most **2 events** ever
(never 3), each producing a soft current-like pulse rather than a solid
ball, and Async's 500ms offset means the two events' fan-out windows only
partially overlap rather than starting in lockstep. There is no literal cap
forcing "only one pulse at a time" — an event genuinely dispatches to all
three destinations at roughly the same time, and drawing that as a single
branch would misstate the fan-out — but every other lever (event count,
opacity, current-not-ball, monochrome single accent, organic stagger, no
canvas text, no "Proxy" label, static junction/queued dots, no grid motion)
is pulled toward fewer, softer, less-synchronized things on screen than
before.

### Labels (DOM, not canvas)

Two lines, always both rendered, positioned as a small stacked legend above
the diagram, horizontally centered:
- **"Async — every destination receives it at once."**
- **"FIFO — one event at a time per proxy, processed in the order
  received."**

(Same copy as the Copy section above — unchanged text; only its layout
changed, from a per-panel caption to a shared legend.)

- **Active line** (whichever schema is currently playing): `text-foreground
  font-medium`, `opacity: 1`.
- **Inactive line:** `text-muted-foreground`, `opacity: 0.5`.
- The swap is a CSS `transition: opacity 240ms cubic-bezier(0.22, 1, 0.36,
  1), color 240ms` triggered the instant the driver switches `activeSchema`
  — timed to land inside the 400ms rest beat at the end of each phase, so
  the label never flips mid-motion.
- Both lines' full text is always present in the DOM regardless of phase —
  a viewer who only ever sees one phase animate still reads both mode names
  and their one-line descriptions.

### Reduced-motion fallback

Simple, per the Owner's direction — a single static frame that looks
deliberate, not a second explanatory design:

- The `requestAnimationFrame` loop never starts; the canvas draws **one
  frame, once**, on mount (and again on resize or theme change).
- **The frame drawn:** the **FIFO-settled** moment — Event 1 at rest,
  delivered to all three destinations (idle-alpha connection lines, no
  rings, no trails), and Event 2 shown as the static muted queued dot at its
  own ingest node. This single frame is the most legible still composition
  available: "one delivered, one waiting" reads immediately without motion,
  whereas Async's defining fact ("more than one in flight *at once*") is
  inherently about timing and does not freeze into a meaningful still.
- Grid: drawn static, no drift, same per-theme alpha/mask values as above
  (unchanged from the motion-safe state, since the grid is already static
  there).
- Legend: both lines render at **equal, full weight**
  (`text-foreground`, `opacity: 1`, no dimming) — there is no "currently
  playing" concept to indicate when nothing is playing.
- `prefers-reduced-motion: reduce` is checked once via
  `window.matchMedia('(prefers-reduced-motion: reduce)').matches` at init,
  plus its `change` listener (in case the OS setting flips live) — this
  gates which of the two code paths (rAF loop vs. one static draw) runs at
  all, not merely pausing an already-running loop.

## Illustration 2 — Retry, backoff, terminal failure & replay

**What it must be honest to:** delivery failure is retried automatically on
a *bounded* backoff (each wait longer than the last, but not unbounded);
after the limit, the delivery reaches a terminal failed state — a real,
visible state, not an error/crash; any delivery, including a terminally
failed one, can be replayed manually.

**Structure:** a single pipe from a small **Ingest/Origin** node to one
**Destination** node (this illustration is about one delivery's lifecycle,
not fan-out, so one destination is enough — fan-out is already Illustration
1's job).

**Motion — one 10-second loop, `linear`, infinite** (timeline below is
percent-of-loop, i.e. 1% = 0.1s):

| Time | State | Visual |
|---|---|---|
| 0%–10% | Attempt 1 departs | Dot (in-flight color) travels origin → destination |
| 10% | Attempt 1 fails | Dot recolors to the failure color, small recoil/shake, fades. Destination border briefly flashes the failure color, then returns to its resting (neutral) border |
| 10%–20% | Backoff wait 1 (short) | A small horizontal "wait" bar fills over ~1s next to the destination; muted-foreground label "retrying…" |
| 20%–30% | Attempt 2 departs | Dot travels again |
| 30% | Attempt 2 fails | Same failure flash as above |
| 30%–50% | Backoff wait 2 (longer) | Wait bar fills over ~2s — **visibly longer** than wait 1, so the growing backoff reads at a glance |
| 50%–60% | Attempt 3 departs | Dot travels again |
| 60% | Attempt 3 fails | Same failure flash |
| 60%–80% | **Terminal** | Destination border switches to a **dashed** failure-color border; label "Terminally failed — retries exhausted" fades in and holds. No further automatic attempt is drawn — the automatic sequence is visibly over |
| 80%–83% | Replay initiated | A small curved "replay" arrow/icon animates in near the origin (fade or short slide), representing the manual action |
| 83%–93% | Replay dispatch | A fresh dot (in-flight color) travels origin → destination, exactly like an ordinary attempt |
| 93% | Replay succeeds | Destination border returns to solid neutral, a brief in-flight-color flash reads as "delivered," label switches to "Delivered" |
| 93%–100% | Hold | Delivered state holds, then the whole sequence resets to 0% |

The 4-step copy list in Section D (Copy, above) is the same information in
static prose, always visible beside/below the illustration regardless of
animation or motion-preference state.

## Light / Dark Treatment

Both illustrations use **only existing semantic tokens** — no new hex value
and no new token pair is introduced by this spec (adding one would be an
Owner-level design-system change per `docs/standards/design.md`, not a
Designer call). The two illustrations reach that goal through two different
mechanisms, because Illustration 1 is canvas-drawn and Illustration 2 is
SVG:

**Illustration 1 (canvas):** tokens are read at runtime via
`getComputedStyle`, not written as Tailwind utility classes — see
Illustration 1's own **Rendering architecture** and **Charge pulse, line,
grid, and node rendering** subsections above for the exact mechanism and the
full numeric, per-theme value table (grid, line, pulse, ring, node). Nothing
here duplicates that table; this section only confirms the same
no-new-token rule applies.

**Illustration 2 (SVG, unchanged):** Tailwind utility classes mapped to
tokens, mirroring the vocabulary design-06 already established for
delivery-state badges (Delivered → non-destructive, Terminally failed →
`destructive`):

| Element | Token / utility | Notes |
|---|---|---|
| Pipe (stroke) | `stroke-border` (or `text-border` + `stroke-current`) | Same neutral in both themes via the token |
| Origin / Destination node fill | `fill-card`, `stroke-border` | Matches existing `Card` surface treatment |
| Node label text | `fill-foreground` / `text-foreground` | |
| In-flight / delivered dot | `fill-primary` | One color for "moving" and "succeeded" — there is no dedicated success-green token in this app (flagged, Open Questions #1) |
| Failure / terminal dot & border | `fill-destructive` / `stroke-destructive` | Matches the `Terminally failed` badge's `destructive` variant precedent |
| Backoff wait-bar | `fill-muted-foreground` / `bg-muted` | Matches `Retrying` badge's muted/outline precedent |
| Destination "delivered" flash | `bg-primary/10` transient background | Same transient-highlight idiom already used for focus/active states elsewhere in the app |
| Captions and step copy | `text-foreground` (headings), `text-muted-foreground` (body) | Standard pairing used throughout the app |

Because every Illustration 2 value is a token reference (`var(--color-*)`
via Tailwind utilities), it automatically repaints correctly under `.dark`
with no illustration-specific dark-mode branch needed — exactly like every
other token-driven component in this app. Illustration 1 achieves the same
result deliberately (re-reading tokens on every theme change, per its own
Rendering architecture subsection) rather than automatically, since canvas
has no CSS cascade to inherit from.

**Page chrome (header, hero, sections):** identical token usage to every
other page — `bg-background text-foreground`, `Button` component variants
for CTAs (`default` for primary, `outline` for secondary, matching the
`Button` variants already in the design system), no page-specific palette.

## Responsive Behavior

Per `docs/standards/design.md`'s **desktop-first, degrading gracefully**
stance (this is a developer-tool product, not a consumer app) and its 360px
practical-minimum default:

**Illustration 1 (canvas) — continuous scaling, no breakpoint layouts:**
unlike a breakpoint-swapped SVG, the canvas redraws from fractional
coordinates every time its container resizes (see its Rendering
architecture subsection), so it does not need — and does not use —
per-breakpoint layout variants or an orientation switch. Concretely:
- A single **560px reference width** is the 1:1 design size for every
  diagram element's geometry and for the charge pulse's px values (core
  width, bloom radius, tail length, etc.).
- At any container width, a `scale = clamp(0.55, containerWidth / 560, 1)`
  factor is applied to node size, stroke widths, pulse core/bloom/tail
  values, and gap sizes uniformly, so proportions stay consistent from full
  desktop width down to the 360px floor (at 360px, container ≈ 340px after
  padding, `scale ≈ 0.61`).
- The **diagram's orientation never changes** — two ingest nodes on the
  left, three destinations on the right, at every width — it only shrinks.
  This is a deliberate departure from a reflow-to-vertical pattern: reference
  sites at the named craft tier (Vercel/Stripe/Linear/PlanetScale, Laravel's
  own marketing site) keep one diagram orientation and scale it, rather than
  re-flowing to a different layout on narrow viewports, and a canvas makes
  continuous scaling essentially free where an SVG breakpoint swap was not.
- The **grid does not scale with this factor** — its 32px cell size stays
  fixed in logical px at every width, so it re-tiles (more/fewer visible
  cells) rather than stretching; this keeps the backdrop's texture visually
  constant regardless of how large the diagram itself is drawn.
- The **DOM legend labels are never scaled down** with the diagram — they
  stay at their normal readable size (see Labels) at every width, since
  legibility there matters more than matching the diagram's shrink.
- Device-pixel-ratio scaling (see Rendering architecture) applies at every
  width, so the diagram stays crisp on retina from full desktop down to the
  360px floor, not just at one reference size.

**Illustration 2 (SVG, unchanged):**
- **`lg` (1024px) and up:** renders as a single wide horizontal pipe with
  the wait-bars and labels beside it.
- **`md`–`lg` (768–1023px):** unchanged (still fits horizontally at this
  width).
- **`sm`–`md` (640–767px):** illustration `viewBox` scale reduces
  proportionally (SVGs are already scale-free via `viewBox` +
  `preserveAspectRatio="xMidYMid meet"`, so this is a container-width change,
  not a redraw).
- **Below `sm` (< 640px), down to the 360px floor:** switches to a
  **vertical pipe layout** — Origin node on top, Destination node beneath,
  pipe drawn vertically instead of horizontally. Wait-bar labels drop from
  inline to stacked beneath the pipe. The Reliability section's 4-step list
  already stacks vertically by default (single-column text), so no change
  is needed there.
- **CTAs and text sections:** single-column, centered, width-capped
  (`max-w-6xl mx-auto px-6` container — this app has no prior landing-page
  precedent for a content width, so `max-w-6xl` is a **Proposed default, no
  prior precedent**, flagged alongside the standards doc's own convention
  for such calls), consistent with the existing "full-width below `sm`,
  fixed above" rule already used elsewhere in this app.
- No horizontal scroll is introduced anywhere on this page at any width.

## Reduced-Motion Fallback

Respecting `prefers-reduced-motion: reduce` is not optional per
`docs/standards/design.md`'s accessibility baseline; both illustrations
specify an explicit **static** replacement, not merely "animation off":

**Illustration 1 (fan-out, Async vs. FIFO) — reduced-motion state:** a
single static canvas frame — the FIFO-settled moment (Event 1 delivered to
all three destinations at rest, Event 2 shown as the static muted queued
dot) — plus both legend lines at equal, full weight. Full spec, including
exactly why this frame and not a frozen Async moment, lives in Illustration
1's own **Reduced-motion fallback** subsection above; not repeated here.

**Illustration 2 (retry/backoff/replay) — reduced-motion state:**
- Renders as a **static horizontal (or, on narrow viewports, vertical)
  stepper**: five fixed nodes — "Attempt 1 (failed)" → "Attempt 2 (failed)"
  → "Terminally failed" → "Replay" → "Delivered" — connected by static
  arrows, each labeled, all visible at once. No wait-bars fill; the
  bounded-backoff idea is carried by the adjacent Section D prose ("waiting
  a little longer each time"), not by a growing bar.
- This mirrors, and is reinforced by, the always-visible 4-step copy list
  in Section D — a reduced-motion user loses nothing that a motion user
  gets, since the substance was never motion-only.

**Implementation approach — two different mechanisms, one per illustration,
because they use different rendering technology:**
- **Illustration 1 (canvas):** a JS `window.matchMedia('(prefers-reduced-motion:
  reduce)')` check (plus its `change` listener) gates whether the
  `requestAnimationFrame` loop ever starts at all, per its own subsection
  above — not a CSS media query, since there are no CSS animations to
  suppress.
- **Illustration 2 (SVG, unchanged):** CSS animations wrapped so that
  `@media (prefers-reduced-motion: reduce)` swaps to the static markup/state
  above — e.g. a `motion-safe:` / `motion-reduce:` Tailwind variant pair
  (this app's existing `starting:opacity-0` +
  `motion-safe:starting:translate-y-6` pattern in the current `Welcome.vue`
  is the direct precedent) or an equivalent `@media` block in a scoped
  `<style>`.

Either mechanism is an implementation detail; the requirement in both cases
is the **visible result**, specified above and in Illustration 1's own
subsection.

## Accessibility

- **Both illustrations are decorative and marked `aria-hidden="true"`** on
  their outer element (`<canvas>` for Illustration 1, `<svg>` for
  Illustration 2), with no `tabindex` on either — every fact they convey
  (fan-out, Async-vs-FIFO, retry/backoff/terminal/replay) is also present as
  real, always-visible DOM text (the two-line legend beside Illustration 1;
  the Section D 4-step list for Illustration 2) per the project's binding
  "colour/motion is never the sole carrier of meaning" rule, extended here
  to motion generally. A screen-reader user gets the full substance from
  prose, never from an unnarrated canvas or unnarrated path elements.
- **Heading hierarchy:** hero headline is the page's single `h1`; "How it
  works" and "Reliability, …" are `h2`; the three "How it works" items and
  the Reliability section's four steps use consistent, real heading/label
  markup (`h3` or a styled `dt`-equivalent — implementer's choice of
  semantic element, but never a bare styled `div` masquerading as a
  heading).
- **CTA buttons/links:** reuse the existing `Button` component and its
  `focus-visible:ring-ring/50` styling — no bespoke focus treatment. Labels
  are the visible text itself ("Register", "Log in", "Go to dashboard") —
  no icon-only or ambiguous-target controls on this page.
- **Color contrast:** all text/background pairs are existing token pairs
  already verified elsewhere in the app (`text-foreground` on
  `bg-background`, `text-muted-foreground` for body copy) — nothing new to
  re-verify.
- **`prefers-reduced-motion`:** see the dedicated section above; this is a
  stated requirement, not left implied.
- Meets **WCAG 2.1 AA** per `docs/standards/design.md`'s baseline; no new
  interactive control is introduced (this page has zero form fields — its
  only interactive elements are the existing `Link`/`Button` CTAs).

## Components

| Role | Component | Status |
|---|---|---|
| Page header nav | Hand-rolled `<header>`/`<nav>` (unchanged logic from today's `Welcome.vue`) | Reused — logic only restyled onto token classes |
| CTA buttons | `Button` (`components/ui/button`), `variant="default"` / `variant="outline"`, `as-child` + `Link` | Reused — same pattern as every other CTA in the app (e.g. proxies Index "New proxy") |
| Section containers | plain `section`/`div` with `max-w-6xl mx-auto px-6` | **New layout convention for this page** — flagged as Proposed default (no prior landing-page precedent) |
| Illustration 1 (fan-out) | `<canvas>` (2D context) + a small `requestAnimationFrame` driver reading two timeline-schema data objects, plus a DOM legend (two `<p>` lines) | **New, page-specific composition** — no new npm dependency; native canvas/rAF/`ResizeObserver`/`MutationObserver` only |
| Illustration 2 (retry/backoff/replay) | inline `<svg>` + CSS `@keyframes` / `motion-safe:`/`motion-reduce:` variants | **Unchanged** — new, page-specific composition; no new npm dependency, no new `ui/*` primitive |
| Icons (optional, "How it works" step markers) | `@lucide/vue` — suggested: `Webhook`, `Split`, `ListOrdered` (or equivalent already-available names) | Reused library; **verify exact icon names exist in the installed `@lucide/vue` version** before use — not blocking, swap for a visually equivalent available icon if a name doesn't exist |

**No new npm dependency, icon library, or `ui/*` primitive is introduced.**

## Interactions

- **CTA buttons/links:** standard `Link`/`Button` navigation, no
  client-side state, identical to every other navigational button in the
  app.
- **Illustrations are non-interactive** — no click-to-replay, no hover
  scrub, no toggle control. Illustration 1's internal `requestAnimationFrame`
  driver alternates its two timeline schemas on its own timer; this is
  internal animation state, not a user-facing control, and does not
  contradict the "no interactive toggle" constraint. Both illustrations
  loop continuously (motion-safe) or render statically (reduced-motion);
  this keeps the page's only interactive surface to its two/three CTAs,
  matching a marketing page's actual job.
- **No inline editing, no forms, no toasts** — this page performs no
  mutation and has no server state beyond `auth.user` / `currentTeam`
  (already-shared Inertia props), consistent with the existing
  implementation.

## Open Questions

**RULED — Project Owner, 2026-08-25: all five calls ACCEPTED as specified.**
Calls 1–4 were put to the Owner explicitly and each was kept as the Designer
specified; call 5 was noted as a visual-balance choice with no product claim
attached and left as-is. Nothing below is outstanding — the entries are
retained as the record of what was decided and why.

Original framing, for the record — every choice below is a Designer
judgment call, flagged for the **Project Owner's** explicit accept/reject
(there is no PM design gate on this small-change flow):

1. **Illustration color semantics reuse the existing badge vocabulary
   (`primary` = in-flight/delivered, `destructive` = failed/terminal,
   `muted-foreground` = waiting/queued) rather than introducing a dedicated
   "success green."** This app has no green/success token today — adding
   one would itself be a design-system change requiring Owner approval per
   `docs/standards/design.md`, which this spec does not do. If the Owner
   wants a true green "delivered" state for this illustration, that's a new
   token pair to approve first, not a Designer-level swap.
2. **SUPERSEDED, 2026-08-25 (Owner directive, not a correction) — kept
   verbatim for the record.** Original text: "Async and FIFO are shown as
   two independent, simultaneously-visible mini-diagrams, not one diagram
   with an interactive toggle or a crossfading caption. Chosen for
   implementation simplicity (no animation-state synchronization or JS
   needed) and because seeing both at once arguably makes the contrast
   clearer without requiring an interaction. An interactive toggle is a
   materially different (more complex, JS-driven) build and is called out
   now rather than discovered during implementation." The Owner
   subsequently asked for the opposite: one diagram, Async and FIFO playing
   back to back forever, driven internally by a small JS timeline (not a
   user-facing toggle — see Interactions). See the header's Structure
   revision note and **Redesign Notes (2026-08-25)** below.
3. **Two illustrations are specified (fan-out contrast, and
   retry/backoff/terminal/replay), not one.** The Owner's brief named both
   the fan-out motion and, separately, retry/backoff/terminal/replay as
   product facts to draw from; this spec treats both as in scope for the
   page rather than trimming to only the fan-out illustration. Flagged in
   case the Owner intended a single, smaller illustration for this
   "small change."
4. **No footer, pricing section, testimonials, or repeated closing CTA is
   added.** The page ends after the Reliability section. This matches
   `vision.md`'s explicit no-billing-in-MVP stance and avoids inventing
   content no brief asked for, but is called out in case the Owner expects
   a closing CTA band (e.g., a repeated "Register" prompt at the page's
   end).
5. **Both illustrations show exactly 3 destinations**, an arbitrary,
   illustrative number — the product supports "one or more" destinations
   per proxy with no stated typical count. Purely a Designer visual-balance
   choice; changing it to 2 or 4 has no spec impact and needs no re-approval
   if the Owner would rather it matched some other number.
6. **New, 2026-08-25 — Illustration 1's two events each get their own
   junction point** (six branch paths total, converging in pairs on the
   same three shared destination nodes) **rather than one shared junction
   both events pass through.** This is the reading the brief invited the
   Designer to confirm or correct. Chosen because a single shared junction
   would put both events' charge pulses through the same point during
   Async's overlap, reading as a collision rather than two independent,
   concurrent deliveries. If the Owner pictured one shared junction, that is
   a straightforward geometry change (destinations and the fan-out concept
   are unaffected) — flagged, not blocking.

## Handoff

- **Inputs:** the Owner's task brief (this page's sole governing
  requirement — no PRD); `docs/product/vision.md` (product vocabulary:
  "ingest and fan out," "retry / replay," "no lost data / no missed
  webhooks"); `docs/product/roadmap.md` (item #4's Async/FIFO framing, item
  #6's retry/replay framing — used only for accurate *today-shipped*
  vocabulary, not to imply unshipped scope); `docs/standards/design.md`
  (binding token/color/accessibility/responsive rules);
  `resources/js/pages/Welcome.vue` (current implementation — header
  auth-branching logic preserved verbatim, external font links removed);
  `resources/js/pages/Dashboard.vue`, `resources/js/pages/proxies/{Index,
  Show,ProxyForm}.vue` (established conventions: `Button`/`Card` usage,
  token classes, the `motion-safe:`/`starting:` transition idiom already
  present in `Welcome.vue` itself); `resources/css/app.css` (token
  definitions, confirms no new tokens needed); `resources/views/app.blade.php`
  (`@fonts` directive — confirms Instrument Sans is already self-hosted
  app-wide, so no page-level font loading is ever needed);
  `docs/design/design-06-retry-replay.md` (badge-state color vocabulary this
  spec's illustration mirrors: Delivered / Retrying / Terminally failed).
- **Outputs:** this design spec.
- **Dependencies:** **no new npm dependency.** Illustration 1 is native
  `<canvas>` 2D context + `requestAnimationFrame` + `ResizeObserver` +
  `MutationObserver` — all browser built-ins; Illustration 2 remains CSS +
  inline SVG, unchanged. Per the Owner's explicit direction, canvas/rAF was
  chosen specifically because it makes the requested motion quality (bloom,
  trails, a masked grid) cheaper than SVG or CSS would, while still adding
  zero dependencies. If a Senior Developer finds any part genuinely requires
  a library, that is a new-dependency ask requiring Owner approval per
  `CLAUDE.md` — not to be assumed or added silently.
  A few new `@lucide/vue` icon names are suggested (see Components); confirm
  availability, non-blocking.
- **Outstanding Questions:** six flagged, reversible Designer judgment calls
  above (five original, plus a new one on Illustration 1's two-junction
  geometry), all for the **Project Owner** (no PM gate on this small-change
  flow). None blocks a first implementation pass.
- **Next Agent:** **Senior Developer** — implements this page directly
  (small-change flow; no Principal Engineer technical design phase), then
  to **Reviewer** per the standard small-change path.

## Redesign Notes (2026-08-25)

Compact record of the Illustration 1 redesign, for traceability — not a
re-litigation of history; the sections above are the current, coherent spec.
The Owner revised direction several times in one session; each step
narrowed toward the final brief below, and only the final brief is what the
sections above specify.

**Sequence of Owner direction, each superseding the last:**
1. One diagram instead of two simultaneous panels; Async then FIFO back to
   back, forever; both mode names labeled alongside; two ingest events on
   the left sharing three destinations; fewer, softer moving elements.
2. Specify the motion as a **declarative timeline schema** (data, not
   hand-authored prose keyframes) — two instances, `async` and `fifo` —
   with a small driver alternating between them. Drop all "will a visitor
   see both phases" hedging: length is now purely aesthetic.
3. Use **`<canvas>`** rather than SVG/CSS, with a DOM label layer, a grid
   backdrop, DPR scaling, and runtime token reads (with re-read on theme
   change) — all specified explicitly rather than left for the builder to
   discover.
4. Craft bar raised explicitly to the **Laravel marketing site /
   Vercel/Stripe/Linear/PlanetScale tier** — every visual property specified
   as a literal number (stroke widths, alpha ladders, `cubic-bezier` curves,
   bloom radii, grid alpha/mask), separately for light and dark.
5. Replace the travelling element with a **charge pulse** (current through a
   conductor: bright core, soft bloom, eased falloff tail, connection line
   brightening then decaying), chosen over an electric-arc/jitter, plain
   comet, or particle-stream treatment — each considered and set aside as
   noisier or less premium at this tier — and specified with the same
   numeric rigor.

**What stayed true throughout, unaffected by any of the above:** the FIFO
event-level semantics from the Factual Audit below (one event in flight per
proxy, each fanning out to all destinations); no new design token; no new
npm dependency; no interactive toggle; light and dark both supported;
responsive to 360px; Illustration 2 untouched.

**Why canvas doesn't compromise anything structural:** the two-junction
geometry, the Async/FIFO timeline numbers, the event-identity-by-track
approach, and the reduced-motion static frame are all identical in *concept*
to what a schema-driven SVG/CSS version would have specified — canvas only
changed *how* they're drawn (runtime token reads instead of Tailwind
classes, a Bézier-sampling function instead of `offset-path`, a rAF loop
instead of CSS `@keyframes`). Nothing in "still holds" was traded away for
craft.

## Factual Audit (2026-08-25)

Triggered by the Project Owner catching a factual error in Illustration 1's
FIFO panel (see the header Correction note). Every product-facing claim in
this spec was re-checked against the named ADRs and the actual code, not
re-derived from memory. Recorded here so the Senior Developer and any future
reviewer have a source for each claim, and so this class of error — asserting
a guarantee on the wrong axis — doesn't recur unnoticed.

**Corrected:**
1. **Illustration 1, Panel B (FIFO).** Was: ordering guaranteed on the
   *destination* axis (dot visits Destination 1 → 2 → 3 in order). Actually:
   ordering/exclusivity is guaranteed on the *event* axis — at most one
   inbound event in flight per proxy at a time. Source:
   `docs/architecture/adr-011-per-proxy-fifo-dispatch-mechanism.md:38` ("Per-proxy
   FIFO is event-level ordered") and `:130` (per-`(proxy, destination)`
   ordering explicitly out of scope); `app/Actions/AdvanceProxyFifoQueue.php`
   docblock ("at most one in-flight event per proxy"); corroborated by
   `app/Actions/DeliverStep.php:63` (Async dispatches per destination in
   parallel, `::dispatch`) vs. `:71` (FIFO runs each destination inline,
   `::run`, inside the same event) and `ProxyForm.vue`'s Processing help text
   ("FIFO delivers this proxy's events in the order they were received").
   Fixed in the Illustration 1 section above.
2. **Hero caption, Panel B.** Was: "FIFO — destinations receive it strictly
   in order, one at a time." Rewritten to state the event-level guarantee.
   Same sources as above.
3. **"How it works" step 3 body.** Was technically defensible ("FIFO
   delivers strictly in the order events were received") but ambiguous
   against the destination-parallel Async sentence immediately before it.
   Rewritten to state one-event-at-a-time explicitly, unambiguously. Same
   sources as above.

**Confirmed correct, with source (no change needed):**
- **Headline** ("Ingest once. Deliver everywhere.") and the general framing
  of ingest → fan-out — `docs/product/vision.md` ("Ingest and fan out"),
  `docs/product/roadmap.md` item #1.
- **Subhead clause "fanned out to every destination you configure"** —
  matches the ADR-001 pipeline spine and `DeliverStep`'s fan-out over the
  dispatch's `deliveries` rows (one per live destination).
- **Subhead clause "automatically retried on failure"** —
  `docs/architecture/adr-015-delivery-retry-mechanism.md` Decision 5
  (CAS status transition + delayed `RetryDelivery` job + `SweepDueRetries`
  sweeper); `app/Services/RetryPolicy.php` implements the same backoff
  formula the ADR specifies.
- **Subhead clause "replayable on demand"** —
  `docs/architecture/adr-017-replay-dispatch-and-payload-read-surface.md`
  Decision 1 (manual replay dispatch); `POST
  /proxies/{proxy}/events/{event}/replay` exists in `routes/web.php`.
- **Subhead clause "full visibility into every delivery attempt"** —
  verified this is scoped to per-event/per-destination attempt visibility
  (not the separate, unbuilt #11 analytics/dashboard aggregate stats).
  Feature #6 (retry & replay) is **Done** and merged to `main` per
  `docs/status.md` (PR #8, `e1c2894`, 2026-08-25), and ships exactly this:
  `GET /proxies/{proxy}/events`, `.../events/{event}`, and
  `.../events/{event}/payload` (ADR-017 Decision 5) plus
  `resources/js/pages/proxies/events/{Index,Show}.vue`. The claim is true of
  what is shipped today, and is not conflated with the unbuilt #11 dashboard
  (correctly excluded elsewhere in this spec's Purpose & Audience section).
- **"How it works" step 1 (Ingest)** — unique per-proxy ingest URL, no
  sender-side changes — matches roadmap item #1 and ADR-006 (ingest-URL
  generation & security).
- **"How it works" step 2 (Fan out)** — "delivered to all of that proxy's
  destinations, in the same structure" — matches roadmap R3 (all
  destinations receive the same structure) and `DeliverStep`'s fan-out over
  every live destination.
- **"How it works" step 3 (Async half)** — "dispatches to every destination
  in parallel, for the highest throughput" — matches `DeliverStep.php:63`
  (`DeliverToDestination::dispatch(...)` per destination, queued). Unaffected
  by the FIFO error; not implicated, per the Owner's brief.
- **Section D headline and intro paragraph** ("Nothing gets lost, even when
  a destination is down"; "every delivery... tracked on its own... retried
  automatically on a bounded backoff... instead of hammering... or giving up
  immediately") — matches ADR-015 Decision 1 (`deliveries`, one row per
  dispatch × destination) and Decision 4 (bounded, growing backoff:
  exponential default 60s/300s/1500s/~2h/6h-flat, worst case ≈32.6h; fixed
  interval alternative). Confirmed against the live formula in
  `app/Services/RetryPolicy.php` (`min(base * multiplier^(N-2), cap)`),
  which matches the ADR verbatim.
- **Section D 4-step list, step 1** ("Delivery attempted... the moment it's
  due") — matches `DeliverStep`'s immediate attempt-1 dispatch at pipeline
  entry.
- **Section D 4-step list, step 2** ("Retried automatically... a short wait,
  then a longer one, up to a set limit") — matches ADR-015 Decision 3 (policy:
  attempt limit 1–10, default 5) and Decision 4 (growing backoff). Confirmed:
  the schedule genuinely grows (1m → 5m → 25m → ~2h → 6h-flat for the
  exponential default).
- **Section D 4-step list, step 3** ("Marked terminally failed... never
  hidden or silently dropped") — matches ADR-015 Decision 1: "`failed` IS
  the AC4 terminal state — a stored fact, never a derivation," and the
  shipped "Terminally failed" badge (`resources/js/data/proxyDeliveryStates.ts`).
- **Section D 4-step list, step 4** ("Replayed on demand... to some or all
  of the proxy's destinations") — matches roadmap R4 and ADR-017 Decision 1
  (destination-subset selection at replay).
- **Illustration 2's sequence** (attempt → fail → backoff → fail → backoff
  (longer) → fail → terminal → manual replay → delivered) — the *mechanism*
  is confirmed: bounded, growing backoff (ADR-015 Decision 4, `RetryPolicy`);
  an explicit, stored terminal state, never inferred (ADR-015 Decision 1);
  manual replay as a new dispatch through the same pipeline, capable of
  succeeding (ADR-017 Decision 1). One illustrative simplification is worth
  recording: the panel shows **3** failed attempts before terminal, while the
  system default attempt limit is **5**
  (`config('retry.default_attempt_limit')` = 5, `config/retry.php`). No copy
  in this spec asserts an exact attempt count — the 4-step list says only "up
  to a set limit" — so this is the same kind of arbitrary, illustrative count
  as Illustration 1's three destinations (Open Questions #5, already
  Owner-accepted as non-binding). Not corrected as a factual error for that
  reason, but flagged here for visibility.
- **"No mapping (#8), no analytics/dashboard (#11), no notifications (#13)"
  exclusion** (Purpose & Audience) — confirmed against `docs/status.md`:
  items #8, #11, #13 are all still **Backlog**, not started.

**Not reopened / no new Owner flag required (as of the correction itself):**
the corrected FIFO panel stayed within every constraint the Owner had
accepted at that point — still exactly two panels, both simultaneously
visible, no interactive toggle, existing tokens only, no new npm dependency,
three destinations per event (the already-accepted illustrative count).
Only the *animation concept* for Panel B changed (event-axis instead of
destination-axis), not the panel count, interaction model, token usage, or
dependency footprint, so none of the five originally-ruled judgment calls
under Open Questions was reopened by this correction. **Superseded later the
same day:** the Owner subsequently changed the panel count, interaction
model (internal timeline instead of two static panels), and rendering
technology anyway, via the separate Structure revision recorded in the
header and **Redesign Notes (2026-08-25)** above — a distinct, later,
non-factual decision, not a reopening of this factual audit. The
event-level FIFO substance this audit verified is unchanged by that later
revision.

**Unverifiable claims:** none found. Every claim above traces to a named ADR,
a named code path, or `docs/status.md`'s shipped-feature record.
