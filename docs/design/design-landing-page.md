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
- No new npm dependency was introduced (CSS + inline SVG only).

## Overview

A visitor lands on a single, static marketing page: a header (nav that
branches on auth state, unchanged from today), a hero with a one-line
promise and two illustrated panels showing a webhook fanning out to three
destinations — once dispatched in parallel (**Async**), once dispatched
strictly in order (**FIFO**) — a three-step "How it works" section in the
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
      Illustration 1 — Async / FIFO fan-out (two panels)

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
- Illustration captions (real text under each panel, not SVG-only):
  - Panel A: **"Async — every destination receives it at once."**
  - Panel B: **"FIFO — one event at a time per proxy, processed in the order
    received."** *(Corrected 2026-08-25 — was "destinations receive it
    strictly in order, one at a time," which misstated the guarantee as
    per-destination. See the Correction note above and the Factual Audit
    section.)*

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

> **Corrected 2026-08-25** — see the header Correction note and the Factual
> Audit section at the end of this spec. The FIFO panel below replaces an
> earlier version that animated the ordering guarantee on the
> **destination** axis (one dot visiting Destination 1, then 2, then 3).
> That is not what per-proxy FIFO guarantees. It is **event**-level:
> at most one inbound event in flight per proxy at a time, regardless of how
> many destinations that event fans out to. The Async panel is unchanged in
> substance (parallel fan-out per event was always correct); it is extended
> here only to also show multiple events overlapping, so the two panels
> remain a true, matched contrast on the same axis.

**What it must be honest to:** one inbound webhook, fanned out to N
configured destinations; Async dispatches in parallel **and admits more
than one event in flight per proxy at once** (no cross-event serialization);
FIFO admits **at most one event in flight per proxy at a time** — a second
event waits until the first fully settles before its own processing even
begins. This is an **event-level** ordering/exclusivity guarantee, not a
per-destination one:
- `docs/architecture/adr-011-per-proxy-fifo-dispatch-mechanism.md:38` —
  "Per-proxy FIFO is **event-level** ordered."
- Same ADR, line 130 — per-`(proxy, destination)` ordering is explicitly
  **out of scope**, not built.
- `app/Actions/AdvanceProxyFifoQueue.php`'s docblock — the advancer
  "guarantees at most one in-flight **event** per proxy."
- The app's own Processing field help text (`ProxyForm.vue`) already frames
  it this way: "FIFO delivers this proxy's events in the order they were
  received."

Destinations *do* happen to run one after another inside a single FIFO
event (`app/Actions/DeliverStep.php:71` calls `DeliverToDestination::run()`
inline, in a loop) — but that is a side effect of inline delivery, not the
contract being sold, and this illustration does not assert it. Async's
per-destination parallel fan-out (`DeliverStep.php:63`,
`DeliverToDestination::dispatch(...)`) is unaffected and remains exactly as
before.

**Structure — one panel, reused twice (Panel A "Async", Panel B "FIFO"):**
- An **Ingest** node on the left holding up to three discrete inbound
  **event** markers (small dots, unlabeled or numbered `1`/`2`/`3` —
  deliberately generic events arriving at this one proxy; not destinations).
- A pipe (rounded stroke) running right to a small circular **Proxy**
  junction node.
- Three pipes fanning out from the junction to three **Destination** nodes
  stacked vertically on the right (small rounded-rects, unlabeled or
  numbered `1`/`2`/`3` — deliberately generic, no invented company/service
  names; unchanged from the prior version — every event, in both modes,
  still fans out to all of the proxy's configured destinations, and that
  part was never the error).
- A traveling dot (`r≈6`) per in-flight **event** represents that event's
  journey from Ingest through the junction to its destination fan-out.

**Panel A — Async — motion:**
- Loop length: **3.6s**, `linear`, infinite; **three events, staggered by
  1.2s (20% of the loop) each**, so they visibly overlap in flight — the
  true Async guarantee (no per-proxy serialization across events).
- **Event 1** occupies 0%–45% of the loop: 0%–15% Ingest → Proxy junction;
  15%–35% junction → all three destinations simultaneously (parallel
  fan-out, unchanged from the prior version); 35% each destination's brief
  highlight flash (100–150ms) reads as "delivered," then returns to resting
  style; 35%–45% dots fade.
- **Event 2** occupies 20%–65% of the loop — identical shape, offset by
  +20% — so it departs Ingest while Event 1 is still mid-flight. This
  overlap is the point of the panel.
- **Event 3** occupies 40%–85% of the loop — offset by +40%. Between 40%
  and 45% all three events are simultaneously in some stage of flight, the
  clearest overlap beat in the loop.
- 85%–100%: hold on an empty pipe, then reset.

**Panel B — FIFO — motion:**
- Loop length: **5.4s** (longer — genuinely serialized, event-by-event
  processing takes longer, and the illustration should not compress that
  away).
- **Event 1** occupies 0%–30% of the loop: 0%–10% Ingest → Proxy junction;
  10%–25% junction → all three destinations (same simultaneous-flash
  treatment as Panel A — this illustration does not assert *how* the three
  destination sends are ordered relative to each other, only that the whole
  event settles before the next one starts); 25% each destination's brief
  highlight flash; 25%–30% dots fade/settle.
- **From 0% onward**, Events 2 and 3 render as **static, muted dots queued
  at the Ingest node** (not traveling) — the visual answer to "ordered means
  waiting," now anchored to a **queued event**, not a queued destination.
- **Event 2** only begins traveling at **30%** (the moment Event 1 fully
  settles) and occupies 30%–60% of the loop, identical shape to Event 1.
  Event 3 remains queued and muted throughout.
- **Event 3** only begins traveling at **60%** (the moment Event 2 settles)
  and occupies 60%–90% of the loop.
- 90%–100%: hold, then reset.

**Both panels sit side-by-side on `lg:` and up** (see Responsive Behavior),
each with its caption directly beneath it, always visible (not tied to
animation state) — the caption is what carries the meaning for
reduced-motion users, per Accessibility below.

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
Designer call). Mapping, chosen to mirror the vocabulary design-06 already
established for delivery-state badges (Delivered → non-destructive,
Terminally failed → `destructive`):

| Element | Token / utility | Notes |
|---|---|---|
| Pipes (stroke) | `stroke-border` (or `text-border` + `stroke-current`) | Same neutral in both themes via the token |
| Ingest / Proxy / Destination node fill | `fill-card`, `stroke-border` | Matches existing `Card` surface treatment |
| Node label text | `fill-foreground` / `text-foreground` | |
| In-flight / delivered dot | `fill-primary` | One color for "moving" and "succeeded" — there is no dedicated success-green token in this app (flagged, Open Questions #1) |
| Failure / terminal dot & border | `fill-destructive` / `stroke-destructive` | Matches the `Terminally failed` badge's `destructive` variant precedent |
| Waiting / queued (FIFO queue dot, backoff wait-bar) | `fill-muted-foreground` / `bg-muted` | Matches `Retrying` badge's muted/outline precedent |
| Destination "delivered" flash | `bg-primary/10` transient background | Same transient-highlight idiom already used for focus/active states elsewhere in the app |
| Captions and step copy | `text-foreground` (headings), `text-muted-foreground` (body) | Standard pairing used throughout the app |

Because every value is a token reference (`var(--color-*)` via Tailwind
utilities), both illustrations automatically repaint correctly under `.dark`
with no illustration-specific dark-mode branch needed — exactly like every
other token-driven component in this app.

**Page chrome (header, hero, sections):** identical token usage to every
other page — `bg-background text-foreground`, `Button` component variants
for CTAs (`default` for primary, `outline` for secondary, matching the
`Button` variants already in the design system), no page-specific palette.

## Responsive Behavior

Per `docs/standards/design.md`'s **desktop-first, degrading gracefully**
stance (this is a developer-tool product, not a consumer app) and its 360px
practical-minimum default:

- **`lg` (1024px) and up:** Illustration 1's two panels sit side-by-side in
  a two-column grid; each panel's three destination nodes stack vertically
  at full size. Illustration 2 renders as a single wide horizontal pipe with
  the wait-bars and labels beside it.
- **`md`–`lg` (768–1023px):** Illustration 1's panels stack **vertically**
  (Async above FIFO) instead of side-by-side, each still full width and
  still showing 3 destinations. Illustration 2 unchanged (still fits
  horizontally at this width).
- **`sm`–`md` (640–767px):** Same vertical panel stacking as above; node
  labels shrink to `text-xs`; illustration `viewBox` scale reduces
  proportionally (SVGs are already scale-free via `viewBox` +
  `preserveAspectRatio="xMidYMid meet"`, so this is a container-width change,
  not a redraw).
- **Below `sm` (< 640px), down to the 360px floor:** Both illustrations
  switch to a **vertical pipe layout** — Ingest/Proxy node on top, the three
  (Illustration 1) destination nodes in a row beneath it, pipes drawn
  vertically instead of horizontally fanning right. Illustration 2's
  wait-bar labels drop from inline to stacked beneath the pipe. Captions and
  the Reliability section's 4-step list already stack vertically by default
  (single-column text), so no change is needed there.
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

**Illustration 1 (fan-out vs. one-event-at-a-time) — reduced-motion state:**
- Panel A (Async) renders frozen mid-overlap: **two event dots visible at
  once, at different stages** — one already resting at all three
  destinations (delivered, all three destination nodes in their
  brief-highlight resting style), a second still on the Ingest → junction
  pipe — reading as "more than one event can be in flight at once."
- Panel B (FIFO) renders frozen at a settled moment: **one event dot resting
  at all three destinations** (delivered), and the remaining queued events
  rendered as **static, muted dots at the Ingest node**, each carrying a
  small order badge (`2`, `3`) — communicating "one event at a time, the
  rest wait their turn" without motion.
- The existing captions (already real text, always visible) carry the "more
  than one at once" vs. "one event at a time, in order" distinction
  regardless.

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

**Implementation approach (not prescribing the mechanism, only the
requirement):** both illustrations' CSS animations must be wrapped so that
`@media (prefers-reduced-motion: reduce)` swaps to the static markup/state
above — e.g. a `motion-safe:` / `motion-reduce:` Tailwind variant pair (this
app's existing `starting:opacity-0` + `motion-safe:starting:translate-y-6`
pattern in the current `Welcome.vue` is the direct precedent for this exact
technique) or an equivalent `@media` block in a scoped `<style>`. Either is
acceptable; the requirement is the **visible result**, specified above.

## Accessibility

- **Both illustrations are decorative and marked `aria-hidden="true"`** on
  their outer `<svg>` — every fact they convey (fan-out, Async-vs-FIFO,
  retry/backoff/terminal/replay) is also present as real, always-visible DOM
  text (the captions under Illustration 1; the Section D 4-step list for
  Illustration 2) per the project's binding "colour/motion is never the sole
  carrier of meaning" rule, extended here to motion generally. A
  screen-reader user gets the full substance from prose, never from
  unnarrated path elements.
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
| Illustrations | inline `<svg>` + CSS `@keyframes` / `motion-safe:`/`motion-reduce:` variants | **New, page-specific composition** — no new npm dependency, no new `ui/*` primitive; built entirely from existing token utilities |
| Icons (optional, "How it works" step markers) | `@lucide/vue` — suggested: `Webhook`, `Split`, `ListOrdered` (or equivalent already-available names) | Reused library; **verify exact icon names exist in the installed `@lucide/vue` version** before use — not blocking, swap for a visually equivalent available icon if a name doesn't exist |

**No new npm dependency, icon library, or `ui/*` primitive is introduced.**

## Interactions

- **CTA buttons/links:** standard `Link`/`Button` navigation, no
  client-side state, identical to every other navigational button in the
  app.
- **Illustrations are non-interactive** — no click-to-replay, no hover
  scrub, no toggle control. They loop continuously (motion-safe) or render
  statically (reduced-motion); this keeps the page's only interactive
  surface to its two/three CTAs, matching a marketing page's actual job.
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
2. **Async and FIFO are shown as two independent, simultaneously-visible
   mini-diagrams**, not one diagram with an interactive toggle or a
   crossfading caption. Chosen for implementation simplicity (no
   animation-state synchronization or JS needed) and because seeing both at
   once arguably makes the contrast clearer without requiring an
   interaction. An interactive toggle is a materially different (more
   complex, JS-driven) build and is called out now rather than discovered
   during implementation.
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
- **Dependencies:** **no new npm dependency.** CSS + inline SVG only, per
  the Owner's constraint. If a Senior Developer finds the specified motion
  genuinely requires a library (e.g., for `offset-path` cross-browser
  gaps), that is a new-dependency ask requiring Owner approval per
  `CLAUDE.md` — not to be assumed or added silently.
  A few new `@lucide/vue` icon names are suggested (see Components); confirm
  availability, non-blocking.
- **Outstanding Questions:** five flagged, reversible Designer judgment
  calls above, all for the **Project Owner** (no PM gate on this
  small-change flow). None blocks a first implementation pass.
- **Next Agent:** **Senior Developer** — implements this page directly
  (small-change flow; no Principal Engineer technical design phase), then
  to **Reviewer** per the standard small-change path.

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

**Not reopened / no new Owner flag required:** the corrected FIFO panel stays
within every constraint the Owner already accepted — still exactly two
panels, both simultaneously visible, no interactive toggle, existing tokens
only, no new npm dependency, three destinations per event (the already-
accepted illustrative count). Only the *animation concept* for Panel B
changed (event-axis instead of destination-axis), not the panel count,
interaction model, token usage, or dependency footprint, so none of the five
originally-ruled judgment calls under Open Questions is reopened by this
correction.

**Unverifiable claims:** none found. Every claim above traces to a named ADR,
a named code path, or `docs/status.md`'s shipped-feature record.
