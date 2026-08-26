---
name: manual-verification-recipe
description: How to run a required manual-verification section (no frontend test harness in this project) — seed via sail tinker, check via headless Playwright with precise selectors
metadata:
  type: user
---

This project has no frontend test harness (deferred backlog item); tasks whose acceptance criteria
depend on rendered UI carry an explicit **manual verification** section instead. The repeatable
recipe:

1. **Seed the exact scenario via `./vendor/bin/sail tinker --execute '...'`** — build the real
   Eloquent rows the page reads (not fixtures the test suite already covers), using the project's
   factories/`createQuietly()` plus `forceFill(...)->save()` for fields a factory won't let you set
   directly (e.g. backdating `created_at`). Print back the ids/slugs you'll need for the URL as JSON
   at the end of the script.
   - To prove an ordering/derivation change actually reads the field you changed (not a coincidental
     proxy like insertion order or `id`), deliberately invert the two: give the row that should sort
     first a *higher* id but the *newer* value of the field under test. If the old (wrong) mechanism
     and the new (correct) one would disagree on the order, only the new one passes.
2. **Log in and view the page via the `playwright` skill**, headless, using a real login flow
   (`User::factory()->create()`'s default password is `password`) — never fake the session.
3. **Select precisely, not by broad text/class matching.** A generic `body.textContent` or
   class-substring selector will match unrelated UI (buttons, menu items) that happen to share the
   target's words — e.g. a "Replay" action button on the same page as "Replay — {time}" group labels.
   Read the actual Vue template first and select the exact tag the label lives in (e.g.
   `h3.text-sm.font-medium.text-muted-foreground`), or scope to a specific ancestor container.
4. **Clean up afterward** — `forceDelete()` the seeded rows (children before parents, FK order) and
   the throwaway user/team via another `sail tinker --execute` call, so the shared local dev database
   isn't left polluted for the next agent/session. `Team` has no `users()` relation — detach via
   `DB::table('team_members')->where('team_id', $team->id)->delete()`, not `$team->users()->detach()`.
   `Destination::$http_method` (`App\Enums\HttpMethod`) only has `Post`/`Put` cases — seeding a
   `GET` destination throws a `ValueError` mid-script; since a tinker script has no wrapping
   transaction, everything created *before* the failing line stays committed, so re-running the
   fixed script after a mid-script error can leave a stray half-seeded row behind — check for and
   clean up leftovers from the failed attempt too, not just the successful re-run's own rows.
   Toggling dark mode for a screenshot via `page.evaluate(() => document.documentElement.classList
   .add('dark'))` needs a short `page.waitForTimeout(300-500)` before the screenshot — capturing
   immediately can catch a CSS transition mid-fade, rendering badges/table rows as washed-out gray
   instead of their real dark-theme colours (a false-looking contrast defect that isn't real).
5. **To prove a client-side submit-normalisation actually changes the wire payload** (not just
   in-memory form state), attach a Playwright `page.on('request', ...)` listener before the click and
   read `req.postDataJSON()` for the real `POST`/`PUT` — this is the only way to see what left the
   browser versus what the DB ends up holding; pair it with a `page.on('response', ...)` listener for
   the status code (a normalisation bug shows up as an unexpected 422, a persistence bug as a wrong
   status-200 body afterward).

Record the concrete seed shape, the exact selector/assertion, and the observed output verbatim in
the task's completion notes — "manually verified" with no steps is not verification.

**Standing trap (review-07 Finding 8): a fresh-build claim can actually be served from the Vite
dev server, not the build you just ran.** If `public/hot` exists on disk and a `pnpm run dev`
process is still running, Laravel's Vite helper serves assets from the dev server regardless of
what `pnpm run build` just wrote to `public/build` — a "verified against a fresh build" claim in
that state is false, and this exact trap once hid a production-only bug (`withAlpha`, PR #12).
Before trusting any live/manual verification pass: `ls public/hot` (should not exist) and confirm
no dev server is running; if `public/hot` is present, remove it or stop the dev process before
running `pnpm run build` and re-checking.

See also [[frontend_checks]] for the scoped-eslint / stale-worktree gotcha that often comes up in the
same tasks that require this recipe, and [[charting_vue3_chartjs]] for a chart-specific dependency bug
found via the technique below.

**`useAppearance()`'s `resolvedAppearance` does NOT react to a raw `document.documentElement.classList
.add('dark')`/`.toggle('dark')`** — that only flips the CSS `.dark` scope (fine for a static
before/after screenshot of two separately-loaded page states), but the composable's `appearance` ref
and its `resolvedAppearance` computed are untouched by it, so anything watching `resolvedAppearance`
(e.g. a chart re-resolving its colours on theme change) will NOT fire. It also does not react to a
live OS/system theme change while `appearance === 'system'` — `prefersDark()` is read as a plain
non-reactive function call inside the computed, not tracked. The only thing that actually mutates the
reactive ref is calling the composable's own `updateAppearance(value)`, which in the real app is only
reachable from `AppearanceTabs.vue` on the Settings > Appearance page — a different Inertia page than
most components that would want to react to it, so there's no on-page toggle to click during a
manual-verification pass for, say, a chart on the Dashboard.

**To actually exercise that reactive path in a headless verification session without navigating
away:** dynamically `import()` the exact built chunk URL that already contains the composable (e.g.
`await import('/build/assets/useAppearance-<hash>.js')` — find the hash via `grep useAppearance
public/build/assets/*.js` or the manifest) from inside `page.evaluate`. ES module instances are
cached per absolute URL by the browser, so this returns the SAME module — and therefore the SAME
module-scoped ref — the already-mounted page's own components are subscribed to; calling
`mod.n().updateAppearance('dark')` (minified export name — read the chunk to confirm, it changes
per build) then genuinely triggers every real watcher in the live page, exactly as a user's own click
on the Settings page would if it were on the same page.

**Simpler alternative when the verification plan already does a fresh `page.goto()` per
viewport/theme combo (no same-page live toggle needed):** `page.evaluate(() =>
localStorage.setItem('appearance', 'dark'))` before the `goto()`. `app.ts` calls
`initializeTheme()` on boot, which reads `localStorage.getItem('appearance')` and calls
`updateTheme()` for real; `useAppearance()`'s own `onMounted` hook also reads the same key and sets
the reactive `appearance` ref from it. Because both run as part of real page-load initialisation
(not a post-mount patch), every component watching `resolvedAppearance` — chart colour tokens
included — reacts correctly with zero extra chunk-hunting. Confirmed working for
`TrendChart`/`chartTokens` (feat/item-11-analytics width-parity fix, 2026-08-26): dark-theme
screenshots showed correct chart line/grid colours immediately, no washed-out mid-transition frame.

**Sail's exposed HTTP port is 80 (`docker ps`), not whatever `APP_URL` in `.env` says** (this
project's `.env` has `APP_URL=http://localhost:8000`, stale/unused for local browsing) — check
`docker ps` or curl `http://localhost` first rather than trusting `APP_URL` for the Playwright
`TARGET_URL`.

**Verifying analytics/statistics work: call `App\Services\DeliveryStatistics` directly via
`sail artisan tinker`** (`app(\App\Services\DeliveryStatistics::class)->unitFiguresForTeam($teamId,
$window)` etc.) rather than screenshotting the Dashboard — it's the same service the pages read
from, gives exact numbers to quote in a report, and needs no Playwright session at all. `AnalyticsWindow`'s
cases are `TwentyFourHours`/`SevenDays`/`ThirtyDays`, not `Day`/`Week`/`Month`.

**`WebhookEvent::factory()->create()` benchmarks ~1300 rows/sec locally via sail** (plain Eloquent,
no observers) — seeding tens of thousands of fixture rows for a realistic-volume demo seeder is
cheap; don't under-seed out of an unverified performance worry.

**When a page has more than one `<table>`, a bare `page.locator('table thead th').first()` or
`table tbody tr` grabs across every table on the page, not just the one under test** — e.g. Proxy
Show has a Trend table above a Destinations table, so a naive row count silently included both.
Scope to the specific table via its nearest ancestor that also contains the identifying marker
(e.g. `page.locator('figure[aria-label]').locator('xpath=ancestor::div[.//table][1]').locator('table')`
to reach the table that shares a card with a known `<figure aria-label>`), and cross-check the
combined-vs-scoped counts against the expected per-table numbers when in doubt (found via T32's
verification: a "26 rows" combined count on a 24-point trend table + 2-row destinations table
still confirms 24 once you know to subtract).

**Seeder-loop gotcha: a per-bucket timestamp computed once outside a per-row loop silently clusters
every row in that bucket onto the same instant.** Found in `AnalyticsDemoSeeder`'s original
`seedProxyDeliveries()` — `dayTimestamp($offset)` was called once per day but reused for every
delivery that day, invisible at `perDay: 2` but would have put e.g. 60 deliveries on the exact same
minute once volume was raised, defeating any per-hour spread. Check for this pattern whenever a
seeder loop generates N rows per time-bucket — the per-row timestamp call must be *inside* the
inner loop.

**A `Collapsible`-wrapped fallback table (e.g. a chart's "View as table") is not in the DOM at all
until its trigger is clicked** — `page.locator('table')` finds zero matches for it pre-click, not
a hidden-but-present element. `await page.getByRole('button', { name: 'View as table' }).click()`
first, then query.

**This app's Events-list pagination is `<Button @click="link.url && router.get(link.url)">`, not
`<a href>`** — an `a[rel="next"]`-style selector matches nothing, and a `has-text('Next')` locator
re-queried each loop iteration can silently keep "succeeding" past the real last page if the
disabled-state check races the Inertia re-render. Prefer reading the actual `total`/`last_page` by
walking `?page=N` directly (or checking row count hits 0) over trusting a click-loop's own guard.

**To reproduce a "no records/proxies at all" empty state without seeding fixtures:**
`User::factory()->create()` already auto-provisions a personal team via its `afterCreating` hook
(same mechanism `AnalyticsDemoSeeder::makeTeam()` uses) — grab `$user->currentTeam`, rename it, log
in as that user, and the account has zero of everything by construction. `forceDelete()` the team
and user afterward per the existing cleanup step above.
