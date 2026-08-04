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

Inertia prop-shape contract: PHP `Http/Resources` keys stay snake_case on the client
(`ingest_url`), but `Data/` DTO shares are camelCase (`canCreateProxy`) — mirror the source.
