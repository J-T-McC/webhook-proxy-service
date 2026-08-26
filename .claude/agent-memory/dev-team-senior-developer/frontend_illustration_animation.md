---
name: frontend-illustration-animation
description: Building responsive, motion-preference-aware illustrations (inline SVG+CSS, and canvas+rAF) in this Tailwind v4 app with no animation library
metadata:
  type: project
---

Pattern used for the landing-page fan-out/reliability illustrations
(`resources/js/components/welcome/*.vue`), reusable for any future
CSS-only animated inline-SVG work:

- **Tailwind v4 (^4.3.3) supports stacked breakpoint+feature variants**,
  including `max-*` breakpoint variants (`max-sm:motion-safe:block`
  compiles to `@media not all and (width>=40rem){ @media
  (prefers-reduced-motion:no-preference){ ... } }`, nested correctly) and
  plain stacked variants (`sm:motion-safe:block`). Verified by grepping
  the actual compiled `public/build/assets/*.css` after `pnpm run build` —
  Tailwind's Vite plugin content-scans the whole project tree regardless of
  the JS import graph, so a not-yet-imported `.vue` file's classes still
  compile; don't assume you need to wire up imports first to test class
  output.
- **Two-orientation, two-motion-state illustrations**: render one `<svg>`
  per orientation (e.g. horizontal `hidden sm:block`, vertical `sm:hidden`
  or `max-sm:motion-safe:block` equivalents), each containing two `<g>`
  children toggled by `motion-reduce:hidden` / `hidden motion-reduce:block`
  — i.e. orientation switch and motion-preference swap are two independent,
  composable Tailwind variant toggles, not a JS `matchMedia` branch. Keeps
  the reduced-motion fallback a real distinct static scene (per this
  project's design-standard requirement), not just "animation: none".
- **Share one `@keyframes` block across orientations** via CSS custom
  properties: set `--dx-*`/`--dy-*` translate deltas as inline `style` on
  the orientation's `<svg>` root (they inherit down to descendant
  elements), and write the keyframes once using `transform:
  translate(var(--dx-x), var(--dy-x))`. Only the static node markup
  (rect/line/circle coordinates) needs duplicating per orientation; the
  timing/logic in the keyframes stays single-sourced and orientation-
  agnostic.
- Animate SVG `fill`/`stroke` color directly in `@keyframes` using the
  same CSS var Tailwind's `fill-*`/`stroke-*` utilities resolve to (e.g.
  `fill: var(--color-primary)`, `fill: var(--color-muted-foreground)`) —
  keeps a color-changing dot theme-token-correct in both light/dark
  without inventing a new token.
- No JS test harness exists for this (`docs/standards/design.md`); verified
  via `pnpm run build` + a Playwright script (screenshot desktop/360px,
  light/dark via `colorScheme`, and `reducedMotion: 'reduce'`/`'no-preference'`
  context options) rather than guessing — `context.emulateMedia` /
  `browser.newContext({ reducedMotion, colorScheme })` covers all four axes
  without touching OS settings.

**Staggering N identical-shaped events in one CSS loop** (used for the
landing page's Illustration 1 correction, event-axis Async/FIFO): give every
instance the SAME `@keyframes` + same `animation-duration` (the full loop
length), and vary only `animation-delay` per instance (`0s`, `20%-of-loop`,
`40%-of-loop`, ...) via a small set of fixed delay classes — a positive
delay on an `infinite` animation just shifts that instance's phase forever,
which is exactly "N staggered, identical, overlapping journeys." Give the
element a plain (non-keyframe) resting `opacity: 0` so it's invisible during
its own initial pre-delay window instead of popping in at a wrong resting
style.

**One element flashing multiple times per loop at different moments**
(e.g. a destination lighting up once per arriving event): comma-separate the
*same* `@keyframes` name multiple times in one `animation` shorthand and
give `animation-delay` a matching comma-separated list — CSS applies each
listed animation/delay pair independently to the same element/properties,
so `animation: pulse 3.6s linear infinite, pulse 3.6s linear infinite; animation-delay: 0s, 1.2s;`
produces two flashes per loop from one keyframes definition, no JS.

**Vue 3 `<style scoped>` renames `@keyframes` (appends a `-<hash>` suffix)
and rewrites matching `animation:`/`animation-name:` declarations _within
that same `<style>` block_ — but does NOT touch an inline `style="..."`
attribute written in the `<template>`.** Verified by grepping the compiled
`public/build/assets/*.css` for a keyframes name after a first build.
Consequence: never reference a scoped keyframes name from a template inline
`style` binding (it won't resolve to the hashed name) — apply animations via
a CSS class defined in the same `<style scoped>` block instead, and reserve
inline `style` on template elements for CSS custom-property values only
(`--dx-d: ...`), which templates can set freely since custom properties
aren't renamed.

**Process note:** a Designer-corrected spec landing mid-task (factual error
caught by the Project Owner) is a normal "resume and patch" flow, not a
redesign — re-read only the spec's changed sections (header Correction
note + the rewritten illustration/copy sections + the audit section) rather
than the whole document, patch just the named files, and re-run the full
verification pass (`pnpm run build` before any live check, all four
light/dark × desktop/360px × motion axes, full gate suite) before
re-committing with a `fix(landing):`-style header.

## Canvas + rAF illustrations (no animation library)

Used for the landing page's fan-out illustration rebuild (charge-pulse
current effect, `FanOutIllustration.vue`) — a from-scratch canvas
replacement of the SVG/CSS approach above, reusable for any future
data-driven canvas animation in this app:

- **CSS `cubic-bezier()` has no canvas/JS equivalent** — implement a small
  Newton's-method solver (`cubicBezierEasing(p1x,p1y,p2x,p2y) => (x)=>y`,
  ~20 lines) rather than reaching for a library; three named curves easily
  fit in one component file and this is the standard browser-internal
  algorithm, not bespoke math.
- **A pulse/streak drawn as discrete alpha-stepped mini-segments reads as a
  row of beads, not a continuous glow** — verified visually via a 3x-DPR
  cropped Playwright screenshot zoomed on one path. Fix: build ONE
  `ctx.createLinearGradient(head, tail)` with color stops following the
  easing-derived alpha ramp, then stroke the whole sampled polyline in a
  single `ctx.stroke()` call with that gradient as `strokeStyle`, applying
  `shadowBlur`/`shadowColor` (bloom) to that one call rather than per
  mini-segment. This is the single highest-leverage fix for making a canvas
  "current pulse" look premium instead of "shooting balls" — always zoom in
  and check for banding/beading before considering a pulse/trail effect
  done, even after the rendering "looks right" at a glance in a normal
  screenshot.
- **Arc-length lookup table for a fixed-px tail on a curved path:** sample
  a `PathSpec` (line or quadratic Bézier) at N uniform-`t` points, storing
  cumulative Euclidean length per point; then interpolate length→point by
  binary search. Needed because the spec gives tail length in real px
  (e.g. 64px) but Bézier `t` isn't uniform-speed — required to walk
  backward from the head by a physical distance rather than a parameter
  fraction. Rebuild the table whenever geometry recomputes (resize), since
  endpoints move with continuous scaling.
- **Theme-reactive canvas via MutationObserver, not the theme composable:**
  `new MutationObserver(() => rebuild()).observe(document.documentElement,
  { attributes: true, attributeFilter: ['class'] })` catches the `dark`
  class toggle this app's `useAppearance()`/`initializeTheme()` already
  perform on `documentElement`, decoupled from that composable's internals.
  Re-read all `getComputedStyle(document.documentElement)` tokens and
  rebuild any pre-baked layer (e.g. a cached grid bitmap) on every
  callback — verified via Playwright: load light, `page.evaluate(() =>
  document.documentElement.classList.add('dark'))`, screenshot ~300ms
  later, confirm full repaint (not just next frame's tokens silently
  wrong).
- **DPR + grid layer as a separate offscreen canvas:** build the static
  backdrop (grid + radial edge mask via `globalCompositeOperation =
  'destination-in'` with a radial gradient) once per resize/theme-change
  into its own `HTMLCanvasElement` sized in *device* px (`width*dpr`), then
  every frame just `ctx.drawImage(layer, 0, 0, logicalW, logicalH)` under
  the main context's `setTransform(dpr,0,0,dpr,0,0)` — cheap per-frame
  blit, correct 1:1 scaling because the drawImage destination size is
  specified in the already-dpr-transformed logical space.
- **Geometry as canvas-fraction positions/sizes vs. a separate px-reference
  scale factor:** when a spec gives node position/size as `%` of canvas
  width/height (continuous by construction) but gives stroke width /
  bloom / pulse dimensions as literal px "at a 560px reference width,"
  keep these as two separate multipliers — don't conflate them. The
  reference-width numbers need `* clamp(0.55, containerWidth/560, 1)`;
  the percentage-based geometry needs no extra scaling at all.
- **Verify pulse/trail rendering by cropping a zoomed screenshot** (Playwright
  `deviceScaleFactor: 3` + `page.screenshot({ clip })` on the canvas's
  bounding box), not just a full-page screenshot — full-page shots at
  normal zoom hide beading/banding artifacts that are obvious once cropped
  and magnified.
