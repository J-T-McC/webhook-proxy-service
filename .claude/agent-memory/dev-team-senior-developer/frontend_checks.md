---
name: frontend-checks
description: Frontend verification commands (not in CLAUDE.md) for Vue/Inertia changes
metadata:
  type: project
---

Frontend DoD triad (pnpm, not npm) — CLAUDE.md only lists the PHP commands:

- `pnpm types:check` → `vue-tsc --noEmit`
- `pnpm lint:check` → `eslint .`  (autofix: `pnpm lint`)
- `pnpm format:check` → `prettier --check resources/`  (autofix: `pnpm format`, or
  `./node_modules/.bin/prettier --write <files>`)

Prettier reliably reformats hand-edited `.vue` files (wrapping multi-attribute `<Button>`
tags) — run `--write` after editing Vue templates or `format:check` fails. No JS test framework
exists; UI-task verification is inspection + the three checks green.

Prettier gotcha: it breaks `{{ expr }}` onto its own indented line, which leaks a newline +
indentation into the render. Harmless normally, but on a `whitespace-pre-wrap`/`<pre>` element
that whitespace becomes visible — bind with `v-text="expr"` (self-closing element) instead of
interpolation so no template whitespace enters the output.

Inertia prop-shape contract: PHP `Http/Resources` keys stay snake_case on the client
(`ingest_url`), but `Data/` DTO shares are camelCase (`canCreateProxy`) — mirror the source.

shadcn/reka-ui `Select` cannot hold an empty-string `SelectItem` value. For an
optional/unconfigured state, use a non-empty sentinel item (e.g. `'default'`) and map
sentinel↔`''` via a `computed` get/set wrapping the useForm string field, normalising
back to null in `form.transform` on submit. (ProxyForm response_status select.)

`resources/js/routes/**` and `resources/js/actions/**` (Wayfinder-generated) are
`.gitignore`d (`/resources/js/routes`) — never `git add` them; they regenerate from route
definitions and editing a controller (even just line numbers shifting) auto-regenerates the
file on disk, which is expected, not something to revert.

`DataOption<TValue>` (`types/data.ts`) has no `variant` field by default — when a value set
drives a `Badge` whose variant differs per value (not a single fixed variant like
`proxyResponseStatuses.ts`), extend it with `variant: NonNullable<BadgeVariants['variant']>`
(`BadgeVariants` exported from `@/components/ui/badge`), mirroring how
`ProxyResponseStatusOption` extends with `emptyBody`.

`useForm()`'s `form.errors` type is inferred strictly from the form's own declared data keys.
A backend validation error keyed on something *not* in the form payload (e.g.
`ValidationException::withMessages(['event' => ...])` when the form only posts
`destinations`) needs `(form.errors as Record<string, string>).theKey` to read — `vue-tsc`
rejects `form.errors.event` outright.

Inertia `useForm().post()`/`router.post()` cannot avoid following a server redirect — if the
controller does a PRG `to_route(...)` to a *different* page than the caller (e.g. a dialog on
an Events page whose backend redirects to the Proxy Show page on success), the browser lands
on that page after a successful submit; there is no "stay on this page, just re-fetch" opt-out
without the backend returning something other than a redirect. When a spec wants "land back
where the user was" rather than a fixed resource page, `return back();` is the fix — Inertia's
`form.post()` carries the browser's `Referer` header, so no client change is needed; assert it
in feature tests with `->from($refererUrl)->post(...)->assertRedirect($refererUrl)` (review-06
Major 1, `ProxyEventReplayController`).

`reka-ui`'s `CheckboxRoot` (and other `role="button"`-rendering reka-ui primitives) is NOT in
HTML-AAM's name-computation chain for a wrapping `<label>` with no `for`/`id` — a `<Label>`
wrapping a `<Checkbox>` with a sibling text `<span>` gives the rendered `<button
role="checkbox">` an EMPTY accessible name (axe `button-name` fails). Fix: pass `aria-label`
(or `aria-labelledby`) straight on the `<Checkbox>` — `CheckboxRoot` reads `$attrs["aria-label"]`
and forwards it verbatim onto the underlying button (verified in
`node_modules/reka-ui/dist/Checkbox/CheckboxRoot.js`); Vue's default attribute fallthrough
carries it there through the `components/ui/checkbox/Checkbox.vue` wrapper with no extra work.
No frontend a11y test harness exists in this project — verify via source read of the rendered
node_modules output, or a real browser/DOM check, not assumption (review-06 Major 3).

`eslint.config.js`'s `ignores: ['vendor', 'node_modules', ...]` entries are bare (top-level-only)
patterns, not `**/vendor/**` — a nested checkout under the repo root (e.g. a concurrent agent's git
worktree under `.claude/worktrees/*/vendor/**`) is NOT excluded and floods `pnpm lint:check` (bare
`eslint .`) with tens of thousands of unrelated errors. If `lint:check` explodes with errors rooted
outside `resources/js`/`app`, check for a stray sibling worktree/vendor copy before assuming your
diff broke something — confirm your own files are clean with a scoped `npx eslint <files>` and don't
edit the shared ignore list as a workaround (out of scope for a task diff, and other agents may be
mid-session against it).

`truncate` inside a flex/grid ancestor chain silently no-ops without `min-w-0` at
*every* level in that chain (the flex/grid item itself, and any flex/grid ancestor
wrapping it, e.g. a `<Label class="flex items-center gap-3">` that is itself a grid
item of a `<fieldset class="grid gap-3">`) — flex/grid items default to `min-width:
auto`, which floors shrinking at content width. Add `min-w-0` (and usually `flex-1`
on the truncating element so it actually claims the row) rather than assuming
`overflow-hidden`'s own `overflow != visible` exempts it; verify with a
deliberately-long content value in a live browser, not just visually-short fixtures.
`<legend>` is excluded from its `<fieldset>`'s grid/flex layout by the HTML rendering
spec (the UA builds an anonymous "fieldset content box" around every child except
`legend`, and *that* box becomes the grid/flex container) — a `gap-*` utility on the
fieldset never reaches the legend; give the legend its own explicit margin instead.
Only fix this where a `legend` is directly followed by an *interactive* row (reads as
crowded); a `legend` followed by a plain description `<p>` reads fine as-is and
doesn't need the same treatment (`ReplayDialog.vue` vs. `DestinationRows.vue`/
`ProxyForm.vue`'s Retry-policy fieldset, fix `replay-dialog-layout.md`).

`Tooltip`/`TooltipProvider`/`TooltipTrigger`/`TooltipContent` (`components/ui/tooltip`,
reka-ui-backed) composes cleanly inside a `Dialog` — `TooltipContent` renders through
its own `TooltipPortal`, same mechanism as `DialogContent`'s `DialogPortal`, so
hover-opening a tooltip doesn't fight the dialog's focus trap. Existing usage pattern
in this repo wraps each `Tooltip` (or a `v-for` group of them) in one `TooltipProvider`
(`teams/Index.vue`, `teams/Edit.vue`, `AppHeader.vue`) — no app-wide provider exists.
`TooltipContent` ships `w-fit` with no width ceiling — for content whose length is
data-driven (e.g. a URL), add an explicit `max-w-*` plus `whitespace-normal break-all`
so it wraps instead of overflowing the viewport on narrow/no-space sides.

Driving a reka-ui `Select` (e.g. `#mode`, `#retry_backoff_strategy`) via Playwright for a
manual-verification pass: it's not a native `<select>`, so `page.selectOption()` doesn't
work. Click the `SelectTrigger` by its `id` to open it, then
`page.getByRole('option', { name: <item label>, exact: true }).click()`. Read the current
value back via the trigger's rendered text (`SelectValue`'s `innerText`), not
`inputValue()`. See [[manual_verification_recipe]] for the rest of the seed/verify/cleanup
recipe — this is the Select-specific piece it doesn't cover.
