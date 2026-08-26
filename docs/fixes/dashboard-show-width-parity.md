# Fix: dashboard-show-width-parity

- **Date:** 2026-08-26
- **Author:** Senior Developer
- **Reported by:** Project Owner

## Problem
The Proxy Show page (`resources/js/pages/proxies/Show.vue`) now carries the
full item #11 analytics surface (headline figures, trend chart, retry/replay
tiles, latency, destinations table) but was still laid out at the narrow
width it had when it was a plain detail page. Next to the Dashboard, which
carries comparable content full bleed, it read as cramped rather than as a
sibling page.

## Chosen behaviour
Widened Show and reined in Dashboard so both meet at the same width, and gave
the shared proxy form a middle-ground width of its own:

- `resources/js/pages/Dashboard.vue` — the populated-dashboard wrapper (the
  `v-else` branch) went from unconstrained full bleed
  (`flex h-full flex-1 flex-col gap-6 p-4`) to centred and capped at
  `max-w-6xl` (72rem): `mx-auto flex h-full w-full max-w-6xl flex-1 flex-col
  gap-6 p-4`. The separate "no proxies at all" empty-state branch (a centred
  `Card` at `max-w-md`) was left untouched — it is deliberately narrow and
  structurally unrelated to this wrapper.
- `resources/js/pages/proxies/Show.vue` — `max-w-3xl` (48rem) raised to
  `max-w-6xl` (72rem), matching the Dashboard exactly.
- `resources/js/pages/proxies/ProxyForm.vue` — the `<form>` went from
  `max-w-2xl` (42rem) to `max-w-3xl` (48rem). Deliberately *not* `max-w-6xl`:
  this is a form, and long single-column input rows get harder to scan as
  they widen, so it only needed to stop looking cramped next to its own Show
  page rather than match it exactly. `ProxyForm.vue` backs both `Edit.vue`
  and `Create.vue`, so this one change covers both.

## Verification
- `pnpm run format:check` — passed; Prettier's Tailwind plugin accepted the
  class ordering on all three edits with no reordering needed.
- `pnpm run lint:check`, `pnpm run types:check` — both passed.
- `pnpm run build` (production build, `public/hot` absent throughout —
  review-07 Finding 8's standing trap) then live browser verification via the
  `playwright` skill against Sail (`http://localhost`, this worktree is the
  primary checkout so Sail's mount is the right target), seeded with
  `php artisan db:seed --class=AnalyticsDemoSeeder`:
  - Dashboard and Show, desktop (1440px) and mobile (375px), light and dark
    (dark driven via `localStorage.appearance` before navigation, so
    `initializeTheme()` applies it for real on load rather than toggling the
    `dark` class directly): no horizontal overflow at any combination
    (`document.documentElement.scrollWidth` measured equal to
    `clientWidth` in every case).
  - Confirmed Dashboard's and Show's content wrapper measure identically at
    1152px on a 1440px viewport (both `max-w-6xl`), and the Edit form's
    wrapper measures 768px (`max-w-3xl`) — visibly narrower than the two
    siblings, as intended.
  - Show's `TrendChart` at the new width: canvas width matches its container
    exactly at both viewports (1070px on desktop, scaled down on mobile),
    height unchanged (256px) — the chart resizes to its container rather
    than stretching or clipping, in both themes.
  - Edit and Create forms: single-column layout still reads well at the new
    768px width, no cramping.
  - "No proxies yet" empty state (a freshly created, zero-proxy team):
    unchanged — still the centred `max-w-md` card, unaffected since it is a
    separate template branch this fix never touched.
- `composer lint`, `composer types:check`: both passed (backend untouched by
  this fix).
- `./vendor/bin/sail test --parallel`: **844 passed / 844**, 3564 assertions.
  No test exercises page-wrapper width; this run confirms the change is
  inert to the suite.

## Follow-ups
`resources/js/pages/proxies/Index.vue` and the events list page are both
still full bleed and will now look inconsistent next to Dashboard/Show.
Left unchanged — the Owner named Dashboard, Show, and the proxy form
specifically; widening the rest is a separate call.
