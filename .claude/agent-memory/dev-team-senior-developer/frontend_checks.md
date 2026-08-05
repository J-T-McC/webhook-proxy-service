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
