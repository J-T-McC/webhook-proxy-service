---
name: codebase-gotchas
description: Non-obvious authorization/serialization traps to check when reviewing proxy work
metadata:
  type: reference
---

- **`ProxyController::store()` has no `$this->authorize('create', …)` call** — it relies on
  `EnsureTeamMembership` middleware + the fact that every TeamRole holds `CreateProxy`. The
  `create()` form action DOES authorize. No denial vector today, but it deviates from the
  "controller calls authorize" standard and from `StoreProxyRequest`'s own docblock. Pre-existing
  from item #1. Re-check if a future role is added that omits `CreateProxy`.
- **`ProxyResource` `can:{update,delete}` flags call the policy per row** (`$user->can(...)` →
  `hasTeamPermission($proxy->team, …)`). The index query does not `with('team')` and `teamRole`
  is not memoized, so the index page is an N+1 (bounded by page size 15). Watch for this pattern
  spreading to other resources.
- **`createQuietly()` suppresses model `creating` hooks** — so `HasCreator`/`BelongsToCurrentTeam`
  auto-assignment does NOT fire in factory-built records; tests set `created_by`/`team_id`
  explicitly. The auto-assign hooks are covered separately by real `new Model()->save()` tests.
- **`max:` on a string rule counts multibyte CHARACTERS, not bytes** — so a validation rule
  built from a `*_max_bytes` config (e.g. `response_body` → `max:config('ingest.response_body_max_bytes')`)
  lets a UTF-8 value exceed the intended byte cap by up to ~4×. Flag as a Minor whenever a byte-named
  cap feeds a string `max:` rule; a byte-exact check needs a custom rule.
- **`WithoutOverlapping` job middleware defaults to no TTL** (`expiresAfter = 0`) — on an ungraceful
  worker crash (SIGKILL/OOM) the Redis lock leaks forever. In the FIFO advancer (`AdvanceProxyFifoQueue`)
  this permanently stalls a proxy's line: the sweeper reaps the DB claim but its re-dispatched advancer
  is gated by the same leaked lock. Whenever `WithoutOverlapping` guards a self-dispatching/scheduled
  job, require an explicit `->expireAfter(...)` (align to the claim lease). Raised as Major in review-04.
- **`(int) env('SOME_KEY', $default)` in a config file has NO lower bound** — a blank line
  (`KEY=`) or a non-numeric value casts to `0`, silently replacing the default (verified in this
  repo: both blank and non-numeric resolve to `0`). Wherever a config int drives a destructive or
  looping operation, check for a clamp/reject at the resolution point. Two concrete shapes in
  `config/retention.php`: a `days` value of 0 makes a retention cutoff equal `now()` (mass
  irreversible erasure), and a batch-size of 0 makes Laravel emit a literal `LIMIT 0` (0 rows),
  which hangs any `do { … } while (count($ids) === $batchSize)` loop forever. Raised as Major in
  review-05; the house remedy adopted there is the pattern to expect elsewhere — a fail-loud
  `RuntimeException` naming key + value at the *single* seam that reads the key, plus threading
  the validated value in as a parameter so the loop body is structurally unreachable rather than
  guarded from inside. When checking such a guard is total, grep the key repo-wide (must have one
  read site) and confirm no callee re-reads config.
- **A guard test whose failure mode is an infinite loop HANGS instead of failing.** Tests that
  prove a loop is never entered (`DB::listen` query-count === 0) are genuine, but if the guard
  regresses the suite spins forever rather than reporting red. Worth a Nit whenever you see one.
- **Laravel releases the `withoutOverlapping()` scheduler mutex in a `finally`**
  (`Event::runCommandInForeground()`), and `Schedule::command()` runs in a child process — so a
  scheduled command that throws does NOT leave a stuck lock blocking later runs. Check this before
  objecting to a "fail loudly" posture in a scheduled job.
- **`DB::listen()` is a legitimate fault-injection / race seam and is NOT tautological.**
  `Connection::run()` dispatches `QueryExecuted` synchronously *after* the statement executes but
  *before* the caller returns, so a listener can (a) throw to fail a specific statement mid-transaction
  and prove real rollback, or (b) mutate state in the exact window between a `SELECT` and a following
  `UPDATE` to prove a compare-and-set. Match on `$query->sql`. Caveat to record: under the suite's
  `FasterRefreshDatabase` the inner transaction is a savepoint, so it proves `ROLLBACK TO SAVEPOINT`.
- **`ApplyTeamScope` registers `TeamScope` on only three models** (`Proxy`, `Destination`,
  `DeliveryAttempt`) and only for the duration of a team-scoped request — it is NOT a global model
  scope. `WebhookEvent`/`DispatchedPayload` are never scoped, so worker-path Eloquent queries on
  them need no `withoutGlobalScope()`; a factory that adds one is defensive, not required.
- **`Actions::registerCommands()` (laravel-actions) registers only classes declaring a
  `commandSignature` property** — adding it to `routes/console.php` to make `Schedule::command()`
  resolve does not accidentally expose other `AsAction` classes as Artisan commands. Verify with
  `artisan list` when it first appears.
- **`ORDER BY id LIMIT n` on a GC/batch selection adds `Using filesort`** even when the intended
  composite index is chosen — MySQL materialises and sorts the whole candidate set before the LIMIT.
  Ordering by the index's trailing column instead keeps the same plan without the filesort. Verify
  optimizer claims with a populated scratch table + `ANALYZE`; an EXPLAIN on an empty table picks
  `PRIMARY` and is worthless evidence.
- **A partial config-sanity guard is the recurring shape of this repo's config bug.** The
  house remedy (fail-loud `RuntimeException` naming key + value at the single read seam) tends
  to get applied to the keys a task's Description enumerates and skipped on the rest, with a
  docblock explicitly calling the remainder "engineering constants… read plainly". Whenever a
  resolver class implements it, enumerate EVERY `config('<ns>.*')` int it reads and check each
  one — the unguarded ones are where the zero-value collapse lives (found in `RetryPolicy`:
  a blank `*_max_delay_seconds` makes `min($delay, 0)` zero out every backoff). Probe with
  `artisan tinker --execute="config([...]); …"` rather than arguing it theoretically.
  **Enumerate by config NAMESPACE, not by class** — grep `(int) config(` across `app/` in one
  pass. Scoping the sweep to the resolver class misses sibling keys read elsewhere (missed
  `retry.sweep_grace_seconds` in `SweepDueRetries` this way at review-06).
- **Severity test for an unguarded config int: does zero COLLAPSE an invariant, or merely
  remove a margin?** Collapse (backoff → 0, cutoff → `now()`, `LIMIT 0` loop) breaches an AC or
  destroys data ⇒ **Major**. Losing an anti-race grace period whose race is already arbitrated
  by a unique key, with the residual cost already accepted in the plan's Risk list ⇒ **Minor**.
  Say which invariant survives, or the grading looks inconsistent with the neighbouring Major.
- **`reka-ui` `CheckboxRoot` renders `<button role="checkbox">`** (`as` defaults to `"button"`),
  so wrapping it in `<Label>` gives it NO accessible name — HTML-AAM's name chain for `button`
  is aria-labelledby → aria-label → subtree → title, and the indicator icon is the whole
  subtree. Same trap applies to any Reka primitive rendering a button with a role. Require an
  explicit `aria-label`/`aria-labelledby`; `aria-label` on `<Checkbox>` DOES reach the button
  (CheckboxRoot reads `$attrs["aria-label"]`; the wrapper SFC is single-root with no
  `inheritAttrs: false`) — confirmed live, name computes correctly. `Login.vue`'s "Remember me"
  still has the un-named-checkbox bug (pre-existing, unfixed as of 2026-08-22). a11y is the one
  class of frontend defect the automated gates cannot catch — everything else is covered by
  `vue-tsc` + server-side Inertia prop assertions.
- **Never accept a source trace as proof of a computed accessible name — do the live check.**
  An accessible name is computed by the browser from HTML-AAM's chain; the whole reason such
  findings arise is that markup which reads correctly to a competent reviewer computes to
  empty. Accepting a trace re-runs the reasoning that produced the defect. Recipe that works
  here: `pnpm run build` FIRST (checked-in `public/build` is routinely months stale and a live
  check against it proves nothing — grep the bundle for your new string to confirm freshness;
  `/public/build` is gitignored so rebuilding is safe), then Playwright headless →
  `locator.ariaSnapshot()` plus `getByRole('checkbox', { name, exact: true })` counts and an
  `{ name: '', exact: true }` count to prove zero unnamed controls. `page.accessibility.snapshot()`
  is REMOVED in the installed Playwright — use `ariaSnapshot()`.
- **Logging into the local app for a live check.** Forging a Laravel session cookie does not
  work (tried: encrypter + `CookieValuePrefix` + DB session row → still bounced to `/login`).
  What works: temporarily swap user 1's password, drive the real login form, then restore the
  saved hash. Trap: `User::casts()` has `'password' => 'hashed'`, so `Hash::make()` +
  `forceFill` **double-hashes** and login fails silently — assign the PLAIN string. Also
  `waitForURL('**/dashboard')`, not `waitForLoadState('networkidle')`, which races an Inertia
  redirect and reads the stale URL. Dev DB is `laravel` on sail; `QUEUE_CONNECTION=redis` with
  no worker container running, so exercising a write flow enqueues without sending real
  outbound HTTP. Revert everything after (rows, queue keys `laravel-database-queues:*`, password).
- **A design spec can be internally non-univocal** — check the Flows section against the
  Screens section before ruling a Flow/plan clash a genuine spec conflict. design-06's Flow D
  said "no navigation away" while its own Screen 4 delegated the mechanism to the implementer;
  the real defect was the redirect *destination*, not the redirect. Resolve inside the spec's
  own text where possible rather than escalating an amendment. Sharper form of the same trap:
  when a task AC says a flow "branches exactly as <other flow> does", check the SCREENS
  section actually supplies member-facing copy for BOTH branches. A Flow's ordinary branch is
  routinely behavioural prose ("the previous secret is demoted, not discarded") while only the
  amended branch got quoted copy — so an implementer can satisfy the Screen and still fail the
  task AC, and nobody can close it without the Designer writing wording.
- **A Post/Redirect/Get target must be the page that owns the affordance** (`back()` or the
  originating route), never a parent page — `to_route('proxies.show')` from an events-page
  action strands the user away from the state they just changed.
- **Authorization idiom:** every proxy/team decision is a Policy gating on `TeamPermission` via
  `$user->hasTeamPermission($team, …)`; a role literal (`role === Member`) in a policy/controller
  is a standards violation (permission-based, never role-based). Ownership is a second axis modeled
  as `-any` bundle permissions, not a role check.
- **A conditional-key `array_merge($data, $cond ? [...] : [])` does NOT omit a key already
  present in `$data`.** Whenever a controller claims to "omit column X from the write unless
  Y", check whether the base array is the validated request data — if X is fillable and
  validation lets `null` through, `Model::make($data)` assigns it and the INSERT/UPDATE writes
  it anyway. Only an explicit array literal (or `Arr::except`) actually omits. Found in
  `ProxyController::store()` at review-07: the comment states the omission rule, the code
  implements it only in `update()`. Grade the comment, not just the behaviour — a create is
  harmless, an update would not be.
- **`ProxyForm.vue`'s `watch(isEnhanced, …)` is now SYMMETRIC** (fixed 2026-08-26, plan-07
  Revision A ruling 4(b)): Enhanced→Simple still clears both retry fields, and Simple→Enhanced
  re-seeds them unconditionally from `props.initial` (the immutable mount seed), so an in-session
  round trip no longer destroys a persisted policy. Deliberate consequences to know before
  "improving" it: in-session *typed* values still do not survive a round trip (the mount seed
  wins), and the re-seed must never write a default literal — an unconfigured proxy must
  round-trip to unconfigured or it silently becomes a configured one that stops following the
  system default. Generalise: whenever a form seeds initial state from persistence AND a watcher
  clears fields on a toggle, walk the toggle-BACK path and check what a save then writes.
- **A proxy update/create REQUIRES `destinations` (`required|array|min:1`)** — a factory-built
  proxy has none, so every form save in a live check is silently rejected. And an Inertia
  validation redirect is **also `303`**, so "303 and the values are still there" is *vacuous*
  evidence. Attach a destination, and prove the save landed by also editing `name` and checking
  the row's `name`/`updated_at` plus a redirect to the Show page. Cost me a wrong pass at the #7
  re-review before I caught it.
- **`public/hot` exists and a Vite dev server has run for days on 5174** — so Laravel's `@vite`
  serves **dev-server modules**, not `public/build`. `pnpm run build` before a live check
  therefore proves nothing about what the browser executes (the modules come from
  `http://[::1]:5174/resources/js/...`). Either stop the dev server, or log the requested module
  URLs and additionally grep the freshly built chunk for the new logic — do both and say which
  one an assertion rests on.
- **Reka `SelectTrigger` DOES receive `aria-describedby`/`aria-invalid` from the template and
  computes its accessible name from `<Label for=…>` — verified live at review-07**
  (`role="combobox"`, name matches via `getByRole('combobox', { name })`). This is the
  opposite of the `CheckboxRoot` trap above; do not assume every Reka primitive drops attrs.
  House pattern for a form field here: `id` + `aria-describedby="<x>-help <x>-error"` +
  `:aria-invalid`, a `p#<x>-help`, and an `InputError` wrapped in `span#<x>-error` (the span
  is legitimately empty when there is no error).
- **Cleaner live-check login than swapping user 1's password:** seed a throwaway user with
  `User::factory()->create()` then `forceFill(['password' => '<plain>'])->save()` (the
  `hashed` cast hashes it once — never `Hash::make()`), drive the real `/login` form, and
  `forceDelete()` the user, its team, its `team_members` row and any seeded proxies/destinations
  afterwards. Leaves user 1 untouched, so a concurrent agent's session is not disturbed.
- **`router.reload()` FORCES `preserveState: true`/`preserveScroll: true`** — `doReload()` spreads
  the caller's options *first* and then overrides both (`@inertiajs/core/dist/index.js`), so a
  background `router.reload({ only: ['x'] })` can never remount the component and in-session-only
  state (a one-time revealed secret, a dialog sub-state) always survives it. The flip side: `reload`
  is fire-and-forget with `async: true` and callers routinely pass no `onError`, so a failed refresh
  leaves the prop **stale for the life of the page** with nothing said. Whenever a disclosure or a
  branch condition reads a prop refreshed only this way, walk the failed-refresh path — a full
  Inertia POST/redirect surface self-heals, a partial-reload surface does not.
- **`SecretStore::statusFor()` already filters the previous secret on `expires_at > now()`**, so
  `security.*.overlap_expires_at` is null-or-live by construction. A client-side truthiness branch
  on it is sound and needs no expiry comparison — do not raise "compares a timestamp by truthiness"
  against these surfaces. What it *is* is a mount-time snapshot: a second tab, or a failed partial
  reload, can leave an overlap-running proxy rendering its no-overlap state.
- **A write-only secret field's "absent means leave unchanged" idiom silently swallows the
  non-secret fields sitting in the same block.** `ProxyController::destinationCredentialAttributes()`
  returns `[]` — a total no-op — whenever `credential_secret === ''`, which correctly preserves the
  stored secret but also discards an edited `credential_header_name`, even though the form renders
  that input editable in exactly that state (design-10 Screen 3, "visible + editable always").
  Raised Major at review-10. **Generalise: wherever a form groups a write-only secret with an
  ordinary editable sibling, walk the "changed the sibling, did not touch the secret" path.** The
  existing test almost certainly resubmits the *same* sibling value and so proves nothing —
  `CredentialValidationTest` did exactly that (lines 79 and 96, `X-Api-Key` → `X-Api-Key`).
- **`OutboundHeaders::build()` resolves case-insensitive collisions between the added set and the
  FORWARDED set, but not WITHIN the added set.** Credential is assigned first, signing headers are
  spread over it, so a credential header named `webhookproxy-signature` emits two headers of that
  name and one named `WebhookProxy-Signature` silently loses the credential. R9's duplicate-header
  hazard, half-discharged. Minor at review-10 (needs deliberate misconfiguration to reach).
- **`JsonResource`'s `removeMissingValues()` `array_values()`s any nested array whose keys are ALL
  numeric**, recursively, silently turning an id-keyed map into an unkeyed list. `#[PreserveKeys]`
  on the resource class is the fix and is load-bearing wherever a resource returns a
  `Record<id, …>` map (`ProxySecurityResource::destinations`). A unit-level `toArray()` assertion
  looks correct and hides this — assert against a real `->response()->getContent()`.
- **`router.delete()` defaults `preserveState: true`** (`@inertiajs/core/dist/index.js:3068` spreads
  `{ preserveState: true, ...options, method: 'delete' }`), as do `post`/`put`/`patch` — the plain
  `visit()` default of `false` does NOT apply to them. So component-local refs survive a delete, and
  a dialog mounted unconditionally (no `v-if`) keeps in-session state across close/reopen. Check the
  adapter source before ruling that in-session state is lost — the Vue3 adapter remounts by changing
  `key.value = Date.now()` only when `preserveState` is false.
- **`STRIPPED_HEADERS` is now exactly ten, transport-scoped only** (ADR-026 Decision A): `host`,
  `content-length`, and the eight RFC 7230 §6.1 hop-by-hop fields. `authorization`, `cookie` and the
  five provider-signature names are deliberately FORWARDED. `proxy-authorization` stays on
  hop-by-hop grounds alone — do not read its presence beside `authorization`'s absence as an
  inconsistency. Count against the RFC, not against a completion note.
- **`docs/standards/design.md`'s typography passage is STALE and will give you the wrong answer on a
  heading finding.** It states card headings are `text-sm font-medium` and cites `Show.vue` as the
  evidence; the shipped `Show.vue` heads all seven of its cards `<h2 class="text-base font-semibold">`.
  The passage is marked "Proposed default", not ratified, so it cannot ground a standards violation
  either way — cite the design spec, and check the code before citing the standard. Same section's
  spacing rules (`space-y-6` stacked-section, `p-6` card padding, 360px minimum) ARE accurate.
- **`legend` styling is where a "cards + fieldsets" IA restructure quietly fails.** When a design says
  a single-`fieldset` card's `legend` "carries the heading weight" instead of an `h2`, check the class:
  in this repo every `legend` is `text-sm font-medium` (`DestinationRows.vue`, `ReplayDialog.vue`,
  `ProxyForm.vue` ×3), which is also what *subordinate* legends nested under a card's own `h2` use. An
  implementer reads "the legend is the heading" and changes nothing, so cards headed by a legend render
  visually subordinate to cards headed by an `h2`. Raised Major at review-17. The half living in a
  component the plan pins to a zero diff is a Designer conflict, not an implementation defect — route
  it there.
- **Reka `TooltipTrigger` sets `aria-describedby` to the content's id automatically while open**
  (`node_modules/reka-ui/dist/Tooltip/TooltipTrigger.js`), so a tooltip needs no manual id-wiring —
  but note the description lands on the TRIGGER, not on the field beside it. The correct in-repo
  pattern is `TooltipTrigger as-child` wrapping a real `Button` with its own `aria-label`
  (`teams/Edit.vue`, `ProxyForm.vue`); `ReplayDialog.vue` wraps a bare non-focusable `span` and also
  sets a bespoke `max-w-xs` on `TooltipContent` — design-17 note N1 names it as the anti-pattern.
  **Do NOT raise "each tooltip has its own `TooltipProvider`, so delay grouping is lost"** — I raised
  it at review-17 and the rationale was wrong. The local wrapper
  `components/ui/tooltip/TooltipProvider.vue` sets `withDefaults(..., { delayDuration: 0 })`, so every
  tooltip already opens instantly and there is no delay for a shared provider to skip. Hoisting one
  provider is a fine simplification but changes nothing observable; say so rather than claiming a
  timing benefit.
- **`TooltipContent` is `w-fit` with NO `max-width`, so any tooltip carrying a SENTENCE is clipped
  off-screen at the 360px minimum width — and the clipped part is unreachable.** Measured at
  review-17 on a 360px viewport: the four `ProxyForm.vue` tooltips render 431 / 469 / 744 / 892px
  wide, all at `left: 0`, losing 16–60% of their text, while `documentElement.scrollWidth` stays
  360 — no horizontal scroll exists to reveal the remainder. Cause: `w-fit` resolves to max-content
  and `text-balance` only acts once wrapping happens; Reka's collision handling repositions but
  cannot shrink. `ReplayDialog.vue`'s `max-w-xs` is the only guard in the codebase and it exists for
  exactly this reason. **The fix can never be in `TooltipContent.vue`** — `components/ui/*` is
  generated and must never be hand-edited (`coding.md` → Project structure) — so it has to be a
  call-site `class`, which is what design-17's note N1 forbade. Whenever a design routes explanatory
  prose into a tooltip, measure the width at 360px before approving.
