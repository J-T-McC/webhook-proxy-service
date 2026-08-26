# Fix: replay-dialog-layout

- **Date:** 2026-08-26
- **Author:** Senior Developer
- **Reported by:** Project Owner

## Problem
Two layout defects in the replay confirmation dialog
(`resources/js/components/ReplayDialog.vue`), shipped as part of feature #6:

1. A destination with a long URL overflowed the dialog instead of ellipsising,
   widening the whole modal. There was also no way to see a truncated URL's
   full value.
2. The "Choose destinations" `<legend>` crowded the "Select all" checkbox row
   beneath it, with no visible breathing room between the two.

## Cause
1. `truncate` was applied to the destination `<span>` (`:167` before the fix),
   but that span is a flex item of `<Label class="flex items-center gap-3">`
   with no `min-w-0`. Flex/grid items default to `min-width: auto`, which
   floors an item's shrink at its content's intrinsic width — the span (and,
   before it, its `Label` grid-item ancestor inside `<fieldset class="grid
   gap-3">`) could never shrink below the full URL's rendered width, so
   `overflow-hidden`/`text-overflow: ellipsis` never had anything to clip.
   Confirmed live in the browser (Playwright, desktop + mobile, light + dark):
   before the fix, a long URL pushed the dialog wider than its `DialogContent`
   bounds; after adding `min-w-0` down the chain, it ellipsises correctly and
   the dialog keeps its width.
2. `<legend>` receives special rendering in all browsers: the UA constructs an
   anonymous "fieldset content box" containing every child except the
   `legend`, and that box — not the `fieldset` element itself — is what
   becomes the grid/flex container. The `legend` sits outside that box, so
   `gap-3` on the `fieldset` never applies between the legend and the first
   grid row.

## Chosen behaviour / fix
- `min-w-0` added to the per-destination `<Label>` (grid item) and to the
  destination `<span>` (flex item) so the flex/grid shrink chain is unbroken
  and `truncate` can engage. `flex-1` added to the span so it claims the
  remaining row width rather than sizing to content only.
- Hover-to-reveal: wrapped the destination `<span>` in the existing `Tooltip`
  primitive (`resources/js/components/ui/tooltip`), following this
  codebase's established `TooltipProvider > Tooltip > TooltipTrigger
  (as-child) > TooltipContent` pattern (already used in `teams/Index.vue`,
  `teams/Edit.vue`, `AppHeader.vue`). Chose the tooltip primitive over a bare
  `title` attribute because it was a clean fit — `TooltipContent` renders via
  its own `TooltipPortal`, exactly like `DialogContent`'s `DialogPortal`, so
  the two portals coexist without fighting the dialog's focus trap (confirmed
  in the browser: opening/closing the tooltip on hover does not close the
  dialog or shift focus). Gave `TooltipContent` a `max-w-xs` plus
  `whitespace-normal break-all` so a very long URL wraps within the viewport
  instead of extending off-screen (`TooltipContent`'s own `w-fit` has no
  width ceiling by default — verified pre-fix that an ~200-character test URL
  rendered its tooltip past the right edge of a 1280px and a 375px viewport;
  post-fix it wraps to multiple lines and stays fully on-screen at both
  widths).
  - No duplicate/conflicting accessible name: the tooltip trigger is a plain
    `<span>` with no ARIA role of its own; the `Checkbox`'s existing
    `:aria-label="`${destination.http_method} ${destination.url}`"` (`:173`)
    remains the sole accessible name for the row's interactive control, and
    already carries the full method + URL to assistive tech independent of
    hover. The tooltip is a supplementary, sighted-pointer-user affordance
    only.
- `<legend class="mb-1 ...">` plus `pt-1` on the `<fieldset>` to add the
  breathing room `gap-3` cannot provide across the legend boundary.
- Checked the other two `fieldset`/`legend` sites using the same `grid gap-*`
  pattern (`resources/js/components/DestinationRows.vue:71`,
  `resources/js/pages/proxies/ProxyForm.vue:247`) in the browser (desktop,
  `/proxies/create` and enhanced-mode `/proxies/create` for the Retry-policy
  fieldset). Both differ from `ReplayDialog.vue` in composition: their
  `legend` is immediately followed by a `<p class="text-sm
  text-muted-foreground">` help/description paragraph, not an interactive
  checkbox row. That legend-then-description pairing already reads as a
  normal label/description grouping with no perceptible crowding — the
  underlying "legend doesn't participate in the grid" mechanism is identical,
  but it isn't visually a defect there the way it is in `ReplayDialog.vue`,
  where the legend sits directly against a bordered checkbox control. Left
  both unchanged rather than applying the same spacing utilities
  speculatively.

## Verification
- `pnpm run build` (production build, exercised before every live check —
  review-06 M-3 requires this since a stale checked-in bundle proves
  nothing).
- Live browser verification via the `playwright` skill against a local
  `php artisan serve` instance of this worktree (Sail's containers mount the
  primary checkout on a different, unrelated branch, so verification was run
  against this branch directly, on an isolated `fix_verify_replay_dialog`
  MySQL database seeded with a proxy, a short-URL destination, and a
  ~200-character long-URL destination):
  - Desktop (1280×900) light + dark: long URL ellipsises, dialog keeps its
    width; legend has clear space above "Select all"; hovering the long URL
    reveals the full value in a wrapped, on-screen tooltip.
  - Mobile width (375×812) light: same — dialog stays contained, both
    destinations ellipsise, tooltip wraps within the narrow viewport.
  - Toggled a single checkbox and "Select all": `data-state="indeterminate"`
    confirmed on the Select-all checkbox with a partial selection; footer
    Confirm label updated correctly ("Replay to 1 destination" → "Replay to 2
    destinations").
  - Set the verification proxy to FIFO processing: the FIFO alert still
    renders above the destinations list, unaffected by the fieldset spacing
    change.
- `pnpm run lint:check`, `pnpm run types:check`, `pnpm run format:check`: all
  pass.
- `composer lint` (Pint): passed. `composer types:check` (PHPStan level 7):
  passed, 0 errors — backend untouched by this fix.
- `DB_DATABASE=fix_verify_testing php artisan test --parallel` (full backend
  suite, run directly against this worktree rather than via Sail for the same
  mount-isolation reason as above): **723 passed / 723, 2645 assertions**.
- No frontend test harness exists in this project (known deferred backlog
  item); the browser verification above is the test for this change, per
  project convention.

## Follow-ups
None.
