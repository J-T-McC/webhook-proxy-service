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
  - Panel B: **"FIFO — destinations receive it strictly in order, one at a
    time."**

**Section C — "How it works" (`h2`):** **"How it works"**
1. (`h3`) **"Ingest"** — *"Create a proxy and get a unique ingest URL. Point
   any webhook sender at it — no changes needed on their end."*
2. (`h3`) **"Fan out"** — *"Every request that arrives is delivered to all
   of that proxy's destinations, in the same structure, however many you've
   configured."*
3. (`h3`) **"Choose your processing"** — *"Async dispatches to every
   destination in parallel, for the highest throughput. FIFO delivers
   strictly in the order events were received, when your destinations need
   that guarantee."*

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

**What it must be honest to:** one inbound webhook, fanned out to N
configured destinations; Async dispatches in parallel; FIFO dispatches
strictly one at a time, ordered, with a visible "waiting" beat — the
"ordered-means-waiting trade-off" already described in the Processing
field's help text (`ProxyForm.vue`).

**Structure — one panel, reused twice (Panel A "Async", Panel B "FIFO"):**
- An **Ingest** node (small rounded-rect, label "Webhook") on the left.
- A pipe (rounded stroke) running right to a small circular **Proxy**
  junction node.
- Three pipes fanning out from the junction to three **Destination** nodes
  stacked vertically on the right (small rounded-rects, unlabeled or
  numbered `1`/`2`/`3` — deliberately generic, no invented company/service
  names).
- A traveling dot (`r≈6`) represents an in-flight event.

**Panel A — Async — motion:**
- Loop length: **2.4s**, `linear`/`ease-in-out`, infinite.
- 0%–35%: dot travels Ingest → Proxy junction.
- 35%: dot "splits" into three dots (or: the single dot's arrival at the
  junction immediately spawns three, each already positioned at the
  junction, opacity 0→1).
- 35%–85%: all three dots travel simultaneously, each along its own pipe, to
  its destination — same easing, same duration, so they visibly arrive
  together.
- 85%: each destination node's border/background briefly highlights (a
  100–150ms flash) to read as "delivered," then returns to its resting
  style.
- 85%–100%: hold, then reset (dots fade out at the junction, ready to
  restart at 0%).

**Panel B — FIFO — motion:**
- Loop length: **4.2s** (longer — sequential dispatch genuinely takes
  longer, and the illustration should not compress that away).
- 0%–20%: dot travels Ingest → Proxy junction.
- 20%–45%: dot travels junction → Destination 1 only. On arrival, that
  node's brief highlight flash plays (same as Panel A).
- Simultaneously, from 20% onward, a second **static, muted** dot appears
  sitting at the junction (a "queued" indicator, not traveling) to make the
  wait visible — this is the visual answer to "ordered means waiting."
- 45%–70%: the queued dot now travels junction → Destination 2, arrives,
  flashes. A new muted "queued" dot appears at the junction for the item
  still waiting for pipe 3.
- 70%–95%: that dot travels junction → Destination 3, arrives, flashes.
- 95%–100%: hold, then reset.

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

**Illustration 1 (fan-out) — reduced-motion state:**
- Both panels render **frozen at their "delivered" moment**: Panel A shows
  all three dots already resting at their destinations, all three
  destination nodes in their brief-highlight resting style simultaneously —
  reading as "all three, at once."
- Panel B shows the three destination nodes each carrying a small static
  order badge (`1`, `2`, `3`) on their pipes instead of a queued dot,
  communicating sequence without motion.
- The existing captions (already real text, always visible) carry the
  "at once" vs. "in order, one at a time" distinction regardless.

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
