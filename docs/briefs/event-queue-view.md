# Brief: team-wide event queue view

## What

A team-wide, read-only index of every captured `WebhookEvent` across every
proxy the team owns, newest first, so a member can see what is backlogged
(captured but not yet dispatched) rather than dispatched. New nav item
("Event queue"), same 5-second poll-with-toggle the proxy events list has.

## Decisions

- **Stored `webhook_events.status` column** (`pending`/`dispatched`, enum,
  default `pending`), cast to a new `App\Enums\WebhookEventStatus`. Written
  in exactly one place: `ProcessIngestedWebhook::handle()`, inside the
  existing `$dispatchUuid === $ingestId` block (the block that already
  identifies "this is the original dispatch, not a replay") — set to
  `Dispatched` right after the block's original `deliveries` rows are
  created. Never mass-assignable (not in `#[Fillable]`), written only through
  the query builder, mirroring `payload_cleaned_at`'s convention.
- **Every status transition traced:**
  - Async original dispatch: reaches the write above -> `dispatched`.
  - FIFO original dispatch (via `AdvanceProxyFifoQueue::claimNext()`'s claim
    then `ProcessIngestedWebhook::run()`): same block, same write ->
    `dispatched`.
  - Paused-proxy guard (Async only; FIFO's own claim guard keeps a paused
    proxy's row unclaimed): returns before the write -> stays `pending`.
    This is precisely the backlog the Owner asked to see.
  - Cleaned-payload early return: returns before the write -> stays whatever
    it already was. An event that expires having never dispatched stays
    `pending` forever at the column level — **not a lie**, because the
    column is not the sole source of the display value (see below).
  - Replay: mints its own `dispatch_uuid` (≠ `ingest_id`), so it never enters
    the write's guarded block. **A replay never touches the original
    event's status column**, confirmed by reading and by test.
  - FIFO backlog wait in `fifo_dispatches`: no code on this path touches
    `webhook_events.status` at all -> stays `pending` until claimed.
- **No `expired` enum case.** The brief's suggestion is taken: `payload_cleaned_at`
  is already the single resolver of the cleaned signal (ADR-014 Decision 7,
  `WebhookEvent`'s own docblock). Spending a third stored value would need a
  second write path (from `PurgeExpiredPayloads`, a wholly separate action)
  to keep it truthful — exactly the multi-writer hazard this brief warns
  against. Instead the read surface (`WebhookEventQueueResource`) computes
  the *displayed* three-state value at read time: `payload_cleaned_at !==
  null ? 'expired' : status`. One authority per signal, no second writer.
- **Backfill** (migration): existing rows read `dispatched` wherever a
  `deliveries` row with `kind = original` exists for them, OR a
  `delivery_attempts` row exists for their `ingest_id` (every such row
  predates the `deliveries` table and the replay feature, so it can only be
  from an original send). Everything else — captured, no send yet — is
  `pending`, correctly.
- **Columns:** proxy name (linked to the proxy) + paused indicator, received
  time, status badge, content type, size. Proxy name/paused are eager-loaded
  via `belongsTo('proxy')->withTrashed()` (a soft-deleted proxy still shows
  its name and reads as unable to ever dispatch again — no separate
  "deleted" badge, out of scope) — one extra join, no N+1. No destination or
  delivery detail: this view exists to answer "is it backlogged and why",
  not to replace the per-proxy events page it links out to.
- **Authorization:** new `ProxyPolicy::viewEventQueue()` — same permission
  (`TeamPermission::ViewProxy`) `ProxyEventController` gates single-proxy
  viewing on, checked at the team level since this page spans every proxy.
  Query scoped by `webhook_events.team_id` explicitly — `WebhookEvent` is
  not one of `ApplyTeamScope`'s auto-scoped models, so this is the same
  explicit-`team_id` pattern `PurgeExpiredPayloads` already uses.
- **Polling** extracted from `proxies/events/Index.vue` into
  `resources/js/composables/useAutoRefreshPolling.ts` (interval, on/off
  toggle, `sessionStorage` key parameterised per page, hidden-tab guard, and
  an optional extra pause predicate for the replay-dialog-open case). Both
  pages now call it; the proxy events page's behaviour is unchanged
  byte-for-byte to a user.
- **New route:** `GET /{current_team}/events` -> `EventQueueController::index`,
  named `events.index`. Nav item added to `AppSidebar.vue`'s
  `currentTeam` block, next to Proxies, `ListOrdered` icon.

## Known ceiling

No filtering/search on this page (status, proxy, date) — Owner asked for
visibility, not drill-through; the per-proxy events page already has filters
for a member who wants to narrow to one proxy. Upgrade path: extend
`EventQueueController` with the same query-param resolver shape
`ProxyEventController` uses, if asked for.

## Needs a browser pass (not done here — no Playwright available)

Nav item renders/links correctly; queue page renders proxy name links,
paused badge, and status badges correctly in both themes; poll toggle
persists across reload and actually stops/resumes polling; empty state.

## Done when

Migration + backfill + status writes + policy + controller + resource +
page + nav item + composable extraction landed, tests green (status
transitions, team scoping), `./vendor/bin/sail test --parallel`, `composer
lint`/`types:check`, and `pnpm types:check`/`format:check`/scoped
`eslint`/`build` (host) all clean.

**Status: done.** `./vendor/bin/sail test --parallel` — 1053 passed, 4956
assertions. `composer lint`/`types:check` clean. `pnpm types:check`/
`format:check`, scoped `eslint`, and `pnpm build` (host) all clean.
