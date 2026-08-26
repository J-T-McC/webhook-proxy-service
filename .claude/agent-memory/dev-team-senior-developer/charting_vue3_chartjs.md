---
name: charting-vue3-chartjs
description: Gotchas found implementing item #11's TrendChart.vue with chart.js + @j-t-mcc/vue3-chartjs (T25-T27) — the wrapper's broken update(), and the exports-map/types-resolution shim pattern for badly-packaged deps
metadata:
  type: project
---

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

**Fix:** bypass the wrapper's own `update()`/`data` prop entirely for post-mount changes. The
wrapper's `expose()` also hands out `chartJSState.chart` — the real, live Chart.js `Chart` instance.
Write straight to that: `const chart = chartRef.value?.chartJSState.chart; chart.data = newData;
chart.update();`. This is the actual Chart.js API the wrapper is a thin layer over and is unaffected
by the snapshot bug. Still "wrapping the approved package" in every way that matters — construction
happens in the wrapper's own `onMounted`, destruction via its exposed `destroy()`, the template still
renders `<Vue3ChartJs>` — only the update path talks to the instance the wrapper already exposes for
exactly this purpose. See `resources/js/components/TrendChart.vue`.

**Package types are unreachable through the package specifier under `moduleResolution: "bundler"`**
(this project's `tsconfig.json`): the package's `package.json` has `"types":
"./dist/index.d.ts"` (correct, present, accurate) but its `"exports"` map declares only
`import`/`require` conditions, no `types` condition — and once `exports` exists, both Node's and
TypeScript's resolvers ignore the legacy top-level `types`/`main` fields for that specifier entirely,
so `import Vue3ChartJs from '@j-t-mcc/vue3-chartjs'` type-checks as `any` (TS7016) even though a
correct `.d.ts` sits right there on disk. This is an upstream packaging omission, not a missing or
wrong declaration. Fix: a local ambient module shim mirroring just the surface actually used —
`resources/js/types/vue3-chartjs.d.ts`, following the existing `vue-shims.d.ts` `declare module
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
