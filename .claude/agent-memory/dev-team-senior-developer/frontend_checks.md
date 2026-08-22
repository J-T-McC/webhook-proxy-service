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
without the backend returning something other than a redirect. Established app-wide PRG
convention (every mutating controller redirects to its resource's Show page on success) can
conflict with a UI spec's "no full navigation" expectation — check the actual controller
response before assuming a dialog can stay open on success.
