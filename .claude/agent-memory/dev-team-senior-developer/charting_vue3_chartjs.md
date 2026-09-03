---
name: charting-vue3-chartjs
description: Gotchas found implementing item #11's TrendChart.vue with chart.js + @j-t-mcc/vue3-chartjs (T25-T27) — two upstream defects, both fixed upstream and no longer worked around, plus the exports-map/types-resolution diagnosis worth keeping
metadata:
  type: project
---

**Both defects below were fixed upstream on 2026-09-02 and released as `@j-t-mcc/vue3-chartjs`
3.0.0** (the Owner's own package). This project is on `^3.0.0` from the npm registry and carries
**no** workaround for either. In 3.0.0 every prop is watched, so `TrendChart.vue` assigns its
`chartData` ref and the chart follows by itself — no component ref, no `update()` call, no
teardown of its own, and no local type shim. Note that `data` is watched **deeply**, so a very
large series pays for the walk on every change; `:auto-update="false"` removes the watchers and
restores the v2 drive-it-yourself behaviour. The history below is kept because the diagnoses
generalise to other packages, not because either defect is still live here.

`@j-t-mcc/vue3-chartjs` 2.1.0 (the Owner's own package, adopted item #11 T25 despite defeating
`chart.js` tree-shaking — see `docs/plans/plan-11-analytics.md` § Owner ruling on T25's check-2
finding) has a real bug, not just the known tree-shaking cost: its exposed `update()` method does
not apply new `data`/`options` prop values. Reading `dist/vue3-chartjs.es.js`: `setup()` captures
`props: { ...f }` — a one-time shallow spread of the props object taken when the component instance
is created — and both `update()` and the initial `render()` always read from that frozen snapshot,
never from the component's live reactive `props`. Binding `:data="someRef"` and later mutating
`someRef` then calling the exposed `update()` silently no-ops (confirmed empirically: toggled the
app's real dark/light theme via `useAppearance()`'s `updateAppearance()`, watched a chart's canvas
pixel colour never change even though the `borderColor` values in the bound `data` prop had changed
and `update()` was called).

**Fixed upstream:** the snapshot spread became `props: f`, a direct reference to the live reactive
props object, and 3.0.0 then went further and watched every prop, so replacing or mutating `data`
updates the chart with nothing to call. `onBeforeUnmount(() => destroy())` arrived in the same
work. Verified in the browser against `AnalyticsDemoSeeder` data: replacing the bound `data`
object's dataset colour repainted the canvas with no `update()` call anywhere. The old workaround —
writing straight to the exposed `chartJSState.chart` — is no longer in `TrendChart.vue`, but it
remains the correct escape hatch for any wrapper that freezes its props, because
`chartJSState.chart` is the real, live Chart.js `Chart` instance.

**Package types are unreachable through the package specifier under `moduleResolution: "bundler"`**
(this project's `tsconfig.json`): the package's `package.json` has `"types":
"./dist/index.d.ts"` (correct, present, accurate) but its `"exports"` map declares only
`import`/`require` conditions, no `types` condition — and once `exports` exists, both Node's and
TypeScript's resolvers ignore the legacy top-level `types`/`main` fields for that specifier entirely,
so `import Vue3ChartJs from '@j-t-mcc/vue3-chartjs'` type-checks as `any` (TS7016) even though a
correct `.d.ts` sits right there on disk. This is an upstream packaging omission, not a missing or
wrong declaration. **Fixed upstream** by adding `"types": "./dist/index.d.ts"` as the first
condition in the `exports` map (order matters — conditions match top-down), and by re-pointing the
generated declarations at chart.js's public entry instead of the private `chart.js/dist/types`
subpath, which `skipLibCheck: true` had been hiding. The local shim
`resources/js/types/vue3-chartjs.d.ts` has been deleted.

When the fix has to be local rather than upstream, the workaround is a local ambient module shim
mirroring just the surface actually used, following the existing `vue-shims.d.ts` `declare module
'*.vue'` pattern already in this repo. Don't try to re-export from the real `dist/index.d.ts` via a
package-specifier subpath (e.g. `'@j-t-mcc/vue3-chartjs/dist/index.d.ts'`) — that path isn't in the
`exports` map either and fails resolution the same way; a relative filesystem path into
`node_modules` works but is fragile across `pnpm`'s store layout, so a small hand-written shim is the
more robust fix. If a future dependency shows the same TS7016 shape (types field present, `exports`
map present, no `types` condition), this is the diagnosis to check first, not a genuinely missing
declaration.

Chart.js line datasets: a `null` data point (not `0`) with `spanGaps` left at its default `false`
renders a genuine visual break in the line — the correct way to plot "no data that day" (e.g. a
zero-traffic day, `UnitFigure.rate === null`) without a false 0%/100%-failure-looking dip and
without silently bridging across the gap. Verified against `AnalyticsDemoSeeder`'s deliberate
zero-traffic day.

See also [[manual_verification_recipe]] for the technique used to actually trigger `resolvedAppearance`
reactively during a headless verification pass (a raw `.dark` class toggle isn't enough).
