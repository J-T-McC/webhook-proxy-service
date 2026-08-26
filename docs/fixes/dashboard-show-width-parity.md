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

## Addendum: 2026-08-26 — proxies and events list pages

The Owner asked, as a deliberate follow-on rather than a correction of an
oversight, for the two list pages named in the Follow-ups note above to be
brought into the same width so all four content pages are consistent:

- `resources/js/pages/proxies/Index.vue` — the page wrapper went from
  unconstrained full bleed (`flex h-full flex-1 flex-col gap-6 p-4`) to
  centred and capped at `max-w-6xl` (72rem): `mx-auto flex h-full w-full
  max-w-6xl flex-1 flex-col gap-6 p-4`. Same before/after values as
  Dashboard's populated-state wrapper.
- `resources/js/pages/proxies/events/Index.vue` — identical change to the
  same wrapper class. Nothing else in either file — column widths, table
  layout, and the events list's pagination were left untouched; the events
  table's existing `overflow-x-auto` container (`Table.vue`) is what keeps it
  scrolling within its own bounds rather than pushing the page sideways now
  that it has less room.

### Verification

- `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types:check`:
  all passed.
- `composer lint`, `composer types:check`: both passed (backend untouched).
- `./vendor/bin/sail test --parallel`: **844 passed / 844**, 3564 assertions.
- `pnpm run build` (production build, `public/hot` absent throughout — the
  marker was absent before the run and confirmed absent again after) then
  live browser verification via the `playwright` skill against Sail
  (`http://localhost`), seeded with
  `php artisan db:seed --class=AnalyticsDemoSeeder`:
  - Desktop (1440px) and mobile (375px), light and dark, across Dashboard,
    the proxies list, the events list, the events list's filtered-empty
    state (`?date=2020-01-01`, no matches), and a second proxy's
    unfiltered-empty state ("Quiet Integration", zero traffic): no
    horizontal overflow at any combination
    (`document.documentElement.scrollWidth` measured equal to
    `clientWidth` in every case).
  - Measured the content wrapper (`div.max-w-6xl`) at 1152px on the 1440px
    desktop viewport identically on all four pages — Dashboard, Show, the
    proxies list, and the events list.
  - Events list at 375px: table content is visibly wider than the
    viewport (columns run past the visible edge in the screenshot) but the
    page itself does not scroll sideways — the table's own
    `overflow-x-auto` container absorbs it, confirmed both by the
    screenshot and by the scrollWidth/clientWidth equality above.
  - Events list filter chips (window + outcome, two chips) and pagination
    at 375px: chips wrap and stack cleanly above the table, pagination
    controls remain usable below it — no crowding or overflow.
  - Both empty states (filtered and unfiltered) render sensibly at the new
    width in both themes: centred `max-w-md` card, unaffected by the outer
    wrapper's width change since it's a separate, narrower element inside
    it.
