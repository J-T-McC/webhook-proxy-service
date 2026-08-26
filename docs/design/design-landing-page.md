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

- **As-built revision (Designer/Owner working session, 2026-08-25):** the
  illustrations were reworked substantially after this spec was approved, in a
  direct iteration loop with the Project Owner. Both are now canvas-rendered and
  share a primitives module. Illustration 1 changed structure (one shared
  junction, not two), motion (charge pulse and travelling heat band, not
  travelling dots), colour (two illustration-scoped accent tokens, since the app
  palette is greyscale and a greyscale illustration read as unfinished), type
  (tracked uppercase monospace, no indices), legend placement (in-canvas), and
  gained a drifting grid and a frame. Illustration 2 was rebuilt on canvas from
  its original SVG/CSS form. The sections below describe **what exists**; where
  an earlier decision was superseded it is marked in place rather than deleted,
  so the reasoning survives. Exact numeric values now live in the code, which is
  authoritative — an earlier revision duplicated them in prose and they drifted
  within a day.

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
  *(Superseded 2026-08-25: the Project Owner moved the legend into the canvas
  and dropped these descriptions as self-evident. The canvas now renders only
  `ASYNC` and `FIFO`; the sentences below survive only as the `sr-only`
  description. Retained here as the record of what they said.)*

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

## Illustration 1 — Fan-out: Async vs. FIFO (hero)

> **Numbers live in the code, not here.** This section states intent, structure
> and the decisions behind them. Every concrete value — easing curves, pulse
> width, bloom radius, alphas, phase durations — lives in
> `resources/js/components/welcome/FanOutIllustration.vue` and
> `resources/js/components/welcome/canvasKit.ts`, which are authoritative. An
> earlier revision of this spec duplicated those values in prose and they had
> drifted out of date within a day of the build starting.

### Concept

One `<canvas>` diagram. Two inbound **event** nodes on the left, both fanning
through a **single shared junction** to the same three **destination** nodes on
the right. The diagram plays an Async phase and then a FIFO phase, back to back,
looping indefinitely.

A single junction rather than one per event: two junctions each fanning to the
same three destinations produced six crossing lines that read as noise, and one
fan point is the truthful shape — a proxy's destination set is one set, whichever
event is passing.

### What each mode shows

Two webhooks land close together, one shortly after the other, in **both** modes.
What differs is what happens next, and that difference is the whole point:

- **Async** — both events travel concurrently. Their journeys overlap for most of
  their length, with the second offset enough to read as a separate event rather
  than a synchronised pair.
- **FIFO** — the second event holds at its ingest node until the first has fully
  settled, then departs. The *length of that hold* is the mode's claim made
  visible.

This is event-level ordering, not per-destination ordering. Each event still
fans out to every destination in both modes (ADR-011 §Impact rules
per-`(proxy, destination)` ordering out of scope; `AdvanceProxyFifoQueue`
guarantees "at most one in-flight **event** per proxy").

### Motion vocabulary

- **Charge pulse.** A bright head cooling through the second accent in its tail,
  travelling the wire like current through a conductor. Trails, not discrete
  dots — a hard circle reads as a bouncing ball.
- **Travelling heat band.** The wire is at rest except around the charge, where
  it brightens and drains off behind. Transparent outside the lit zone so the
  base wire shows through; lighting the whole segment made the pulse compete
  with its own lit path.
- **Event highlight.** An ingest node's border fades in when its event lands,
  holds for however long that event waits, then drains **left-to-right** into
  the pipe as it dispatches. Each envelope is tied to its own event's arrival and
  departure — anchoring both to the phase start made two unrelated events light
  and drain in lockstep, claiming a simultaneity neither mode has.
- **Destination arrival.** The receiving node's own border lights and fades. No
  expanding ring: that read as an explosion and fought the calm of the pulse. The
  rise matches the ingest highlight's, so both ends of a journey light
  identically; only what follows differs.
- **Grid field.** A backdrop whose elliptical edge fade drifts on out-of-phase,
  non-harmonic sines so it never resolves onto a beat. An alpha breath does most
  of the perceptual work — luminance change is far easier to notice at the edge
  of vision than a boundary moving a few pixels.

### Colour

Two illustration-scoped tokens, `--illustration-from` (violet) and
`--illustration-to` (cyan), defined per theme in `resources/css/app.css`. The app
UI palette is greyscale by design and is untouched.

The split is semantic and consistent: **violet is node state** (an event waiting
to dispatch, a destination that received); **cyan is only ever charge in
transit**. Earlier revisions used cyan for both and the two ends of a journey did
not rhyme.

### Rendering

Canvas with a `requestAnimationFrame` driver consuming two declarative timeline
schema instances, rather than hand-authored per-element animation. The schema
is the point: it is what makes the motion iterable.

- **Theme tokens are read at runtime** via `getComputedStyle` and re-read on
  theme change (`MutationObserver` on the root element's class). A canvas that
  caches its palette at init looks correct until someone toggles the theme.
  **No hex is written anywhere in the code.**
- **Emissive draws composite additively** (`lighter`) on dark, so overlapping
  pulses accumulate light. Light theme composites normally — `lighter` on a white
  field drives everything to white.
- `devicePixelRatio`-aware backing store; `ResizeObserver` drives continuous
  rescaling. The rAF loop and every observer tear down on unmount.
- **No new dependency.** Native canvas, rAF, ResizeObserver, MutationObserver.

### Grid alignment

The cell size is derived from the canvas rather than fixed, so the grid divides
it into a whole number of cells and the outermost lines land on the frame. The
count rounds to the nearest **odd** number, which places the canvas centre at a
cell midpoint — both diagrams put their junction at exact centre, and an
unphased grid left it sitting off-square. Cells land within a few percent of
square; that drift is what buys the alignment and is not perceptible.

### Type

Node and legend labels are tracked uppercase monospace drawn into the canvas.
Body copy set inside diagram boxes read as body copy; monospace reads as a
schematic. Label size is capped by what its node can actually hold, so a label
can never overflow its box at any viewport.

Labels carry no indices — three boxes reading DESTINATION is self-evident, and
the numbers were noise.

### Mode legend

Drawn in-canvas, top-left and left-aligned on desktop, centred above the diagram
on compact. Both mode names are always rendered, the active one in the accent
and the other dimmed, so a viewer landing mid-loop still sees both modes exist.

The legend was previously DOM text beneath the canvas with a sentence of
description each; the Project Owner moved it in-canvas and dropped the
descriptions as self-evident. An `sr-only` paragraph retains a description of
both modes for assistive technology, since the canvas is `aria-hidden`.

### Frame

Both illustrations sit in a rounded, bordered container. In light it gives the
diagram a panel identity the grid alone did not provide; in dark it frames
without asserting itself.

## Illustration 2 — Retry, backoff, terminal failure & replay

Same canvas approach, same vocabulary, same shared primitives
(`canvasKit.ts`). A single `DELIVERY → DESTINATION` pair rather than a fan-out.

**Sequence.** Three delivery attempts on a widening backoff, each failing; then a
terminal failure; then a manual replay that succeeds.

**Where it departs from Illustration 1, and why:**

- **Failure is `--destructive`**, so a failed attempt is legible without text
  explaining it.
- **Failure has a different attack than success.** A delivery arrives on a soft
  rise; a failure hits hard and releases slowly. It should read as an impact,
  not an arrival.
- **Terminal is held, not decaying.** Every other state fades. A terminal failure
  is a state the delivery *stays in* until someone acts on it, and the drawing
  should say so.
- **Backoff draws as a stall** — a dim charge creeps a little way down the wire
  and stops. The widening gap between attempts is the curve itself.
- **Replay travels in the ordinary colours.** It is charged like any other
  delivery; only its outcome differs, so it does not announce itself.

**Illustrative simplification, disclosed:** three attempts are shown where the
system's default limit is five. No copy asserts a count. Same precedent as the
arbitrary three destinations in Illustration 1.

## Light / Dark Treatment

Both themes carry **separate numeric values**, not one set with a token swap.
The palette is greyscale, so anything drawn from a neutral token needs checking
in both: the same alpha that gives a crisp hairline against near-black washes out
on white, and glow tuned for dark blows out on light.

The per-theme table in each component covers grid alpha, idle and hot line
treatment, pulse width, bloom, arrival and queued edge treatment, node stroke,
labels, and the junction. Two bugs came from assuming a dark-tuned value would
carry — the grid drawn in `--border` (invisible on dark) and the node stroke
sharing one alpha across themes (washed out on light).

Additive compositing is dark-only, as above.

## Responsive Behavior

**Compact mode below 520px canvas width.** Not a scale-down: the proportions
change. Node labels are a fixed character count, so a node sized as a fraction of
a phone viewport is narrower than the word it must hold. Compact widens and
flattens the nodes, pulls the columns inward, tightens label tracking, and moves
the legend clear of the first node.

The canvas takes a square aspect on phones and its wide aspect from the `sm`
breakpoint up; a 2:1 letterbox is a desktop shape.

Verified with no horizontal overflow at 390, 520 and 768 wide.

## Reduced-Motion Fallback

A requirement, not a nicety. The rAF loop never starts; a single deliberate
static frame is drawn, and the grid's drift terms hold at their neutral values so
the frame is genuinely still.

- **Illustration 1** — the FIFO-settled moment.
- **Illustration 2** — the terminal-failure moment, the single frame that says
  the most without movement.

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
| Illustration 1 (fan-out) | `<canvas>` + a `requestAnimationFrame` driver reading two timeline-schema data objects; legend drawn in-canvas, with an `sr-only` description for assistive tech | **New, page-specific composition** — no new npm dependency; native canvas/rAF/`ResizeObserver`/`MutationObserver` only |
| Illustration 2 (retry/backoff/replay) | `<canvas>`, same approach and same shared primitives as Illustration 1 | **Rebuilt on canvas** — replaced the original inline `<svg>` + CSS keyframes version; no new npm dependency |
| Shared canvas primitives | `components/welcome/canvasKit.ts` — easing curves, runtime token reading, path sampling, grid layer and drifting mask, rounded rect, glow blend | **New module** — extracted so the two diagrams cannot drift apart when either is tuned |
| Icons (optional, "How it works" step markers) | `@lucide/vue` — suggested: `Webhook`, `Split`, `ListOrdered` (or equivalent already-available names) | Reused library; **verify exact icon names exist in the installed `@lucide/vue` version** before use — not blocking, swap for a visually equivalent available icon if a name doesn't exist |

**No new npm dependency, icon library, or `ui/*` primitive is introduced.**

## Interactions

- **CTA buttons/links:** standard `Link`/`Button` navigation, no
  client-side state, identical to every other navigational button in the
  app.
- **Illustrations are non-interactive** — no click-to-replay, no hover
  scrub, no toggle control. Both are canvas-rendered with an internal
  `requestAnimationFrame` driver: Illustration 1 alternates its two timeline
  schemas on its own timer, Illustration 2 runs one attempt/backoff/replay
  timeline. That is internal animation state, not a user-facing control, and
  does not contradict the "no interactive toggle" constraint. Both loop
  continuously (motion-safe) or render one static frame (reduced-motion); this
  keeps the page's only interactive surface to its CTAs, matching a marketing
  page's actual job.
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

**Three of those five were later reversed by the Owner during the build**, in
the working session recorded in the As-built note at the top. Recorded here so
the ruling above is not read as still governing:

- **Call 1 (reuse existing tokens, no new colour)** — reversed. The app palette
  is greyscale, so the illustration rendered as white lines and white boxes and
  read as unfinished. Two illustration-scoped accent tokens were added. The
  original ruling and the "Laravel/Vercel/Stripe tier" craft bar set later in
  the session were incompatible; the craft bar won.
- **Call 2 (two simultaneously-visible mini-diagrams)** — reversed. One diagram
  now plays both modes back to back.
- **Call 3 (two illustrations rather than one)** — upheld, and Illustration 2 was
  subsequently rebuilt on canvas.
- **Call 4 (no footer, pricing, testimonials or closing CTA)** — upheld.
- **Call 5 (three destinations, arbitrary)** — upheld.

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
