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
