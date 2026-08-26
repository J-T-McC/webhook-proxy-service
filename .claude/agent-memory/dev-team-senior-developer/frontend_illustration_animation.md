---
name: frontend-illustration-animation
description: Building responsive, motion-preference-aware inline SVG illustrations with plain CSS in this Tailwind v4 app (no animation library)
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
