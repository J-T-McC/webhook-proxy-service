---
name: frontend-illustration-animation
description: Tailwind v4/Vue-scoped-CSS gotchas hit building landing-page animated illustrations — transferable to any inline-SVG/canvas or motion-preference-aware frontend work
metadata:
  type: project
---

Landing-page illustration work (`resources/js/components/welcome/*.vue`) surfaced these
general Tailwind v4 / Vue gotchas, kept in case future canvas/SVG or chart work repeats them:

- **Tailwind v4 (^4.3.3) supports stacked breakpoint+feature variants** (`max-sm:motion-safe:block`,
  `sm:motion-safe:block`, nested media queries compile correctly). Tailwind's Vite plugin
  content-scans the whole project tree regardless of the JS import graph, so a not-yet-imported
  `.vue` file's classes still compile — verify by grepping compiled `public/build/assets/*.css`
  after `pnpm run build`, don't assume you need imports wired first.
- **Vue 3 `<style scoped>` renames `@keyframes` (appends a `-<hash>` suffix) and rewrites matching
  `animation:`/`animation-name:` declarations WITHIN that same `<style>` block — but does NOT touch
  an inline `style="..."` attribute in the `<template>`.** Never reference a scoped keyframes name
  from a template inline `style` binding, it won't resolve. Apply animations via a class defined in
  the same `<style scoped>` block; reserve inline `style` on template elements for CSS custom-
  property values (`--dx-d: ...`), which aren't renamed and templates can set freely.
- **Reduced-motion / theme fallback should be a real distinct state, not just "animation: none"**
  — this project's design standard wants an actual static scene for `motion-reduce`. Toggle via
  Tailwind's `motion-reduce:`/`motion-safe:` variants (or a `MutationObserver` on
  `document.documentElement`'s `class` attribute for canvas work, matching how
  `useAppearance()`/`initializeTheme()` toggles the `dark` class) rather than a JS `matchMedia`
  branch — keeps orientation/theme/motion as independent, composable toggles.
- **Verify pulse/trail/gradient rendering by cropping a zoomed screenshot** (Playwright
  `deviceScaleFactor: 3` + `page.screenshot({ clip })` on the element's bounding box), not just a
  full-page screenshot — full-page shots at normal zoom hide banding/beading artifacts that are
  obvious once cropped and magnified. No JS test harness exists for this class of work
  (`docs/standards/design.md`); a `pnpm run build` + Playwright pass (screenshot desktop/360px,
  light/dark via `colorScheme`, `reducedMotion: 'reduce'`/`'no-preference'` context options) is the
  verification path.

See [[frontend_checks]] for the pnpm gate triad and other Vue/Inertia gotchas, and
[[manual_verification_recipe]] for the fresh-build-vs-dev-server trap that applies to any of this
verification work.
