# Review: Retry & replay — item #6

- **Reviewer / date:** Reviewer, 2026-08-22
- **Scope:** T1–T46 on `feat/item-06-retry-replay`, fork point `ec120b3` → HEAD `f5b2a07`
  (46 task commits across ten milestones M1–M10, plus the three planning commits carried on
  the branch). All production and test code for the retry engine, the `deliveries` state
  entity, FIFO composition under retry/replay, GC hold H5, the replay endpoint, the
  stored-event read surface with fetch-on-reveal, the proxy retry-policy form, and the four
  Owner-approved data-model changes. UI in scope (design-06 is PM-approved), so the frontend
  triad was run as well.
- **Inputs verified:** PRD-06 **Approved** (Owner, 2026-08-12; AC1–AC25 incl. the Q-06-01 /
  Q-06-02 rulings rendered in place), design-06 **Approved** (Product Manager, 2026-08-12,
  all five flagged calls accepted), plan-06 (PE self-certified; seven ✋ items separately
  Owner-ratified), **ADR-015 / ADR-016 / ADR-017** (all **Accepted**, Owner, 2026-08-12),
  ADR-003/004/005/009/010/011/012/013/014, `docs/tasks/retry-replay-tasks.md` (T1–T46, **all
  carrying real completion notes**, verified — no `_pending_` remains), Q-06-01/02/03 (all
  RESOLVED), `docs/standards/` (review, coding, testing, planning, documentation,
  architecture, design). Q-07-01(b) (Owner, 2026-08-21) read for the forward-collision ruling
  only; no #7 artifact was modified.
- **Not in scope / not touched:** `docs/status.md` (orchestrator-owned),
  `docs/product/prd-07-*`, `docs/questions/prd-07-*`.

## Summary
This is the strongest implementation the project has produced so far. The hard parts are
genuinely hard and are genuinely right: **every** `deliveries.status` transition is a
compare-and-set on the query builder with no blind `save()` anywhere; `DeliveryExhausted`
fires *iff* the terminal CAS affected a row, so the once-guard and the schedule-guard are the
same primitive; the FIFO settle-or-hold decision reads the deliveries' real terminal state
rather than settling unconditionally; hold **H5** is expressed exactly once inside
`applyHolds()`, so the erase `UPDATE` re-asserts it for free; and every new read or dispatch
path guards `payload_cleaned_at`, never `body === null` — a repository-wide grep confirms it.
Review-04's M-1 fix is intact and unregressed: `AdvanceProxyFifoQueue::getJobMiddleware()`
still pins `WithoutOverlapping` to `->expireAfter(config('ingest.fifo_lease_seconds'))` with
its full rationale. The claim/lease/reaper machinery is otherwise untouched; #6 adds one
status to the row lifecycle and one predicate to the busy gate, exactly as ADR-016 promised.

The T41 defect was caught by acceptance testing, and the fix is **complete**: the
`firstOrCreate` backfill loop in `ProcessIngestedWebhook` is now gated on
`$dispatchUuid === $ingestId`, and I traced every sibling path — `ProxyEventReplayController`
(the only other creator of delivery rows), `DeliverStep` (scoped by `dispatch_uuid`),
`AdvanceProxyFifoQueue` (passes `$row->dispatch_uuid`), `RetryDelivery` (single row) — and
found no second instance of the flaw. Its test is a real ingest → replay → assert flow with
no `Queue::fake()`, asserting both zero extra rows and zero HTTP sends to the unchosen
destination.

The security surface holds up. `ProxyEventPayloadController` is the only content-bearing
response in the feature: `$this->authorize('view', $proxy)` on a scoped-binding route inside
the team group; a cleaned event returns a bodiless 410 rather than `abort()` (correctly — an
`abort()` would render the app's HTML error page, which is itself content this endpoint must
never carry); the three hardening headers are exact; and the `payload.revealed` log carries
`team_id`/`proxy_id`/`event_id`/`ingest_id` and nothing else. No Resource in the branch emits
`body` or `headers` under any state. The `proxy:replay` permission is single-axis with no
`-any` case, granted to Owner/Admin/Member, and `ProxyPolicy::replay()` is a plain
`hasTeamPermission` check with no role literal — AC14 and Q-06-02c mechanized correctly.
`ReplayEventRequest` scopes `destinations.*` to the route-bound proxy's live destinations and
fails closed when the proxy is absent (Laravel maps `->where('proxy_id', null)` to
`whereNull`, which matches no row).

**All five gates are green and reproduce the Senior Developer's claimed numbers exactly.**

Three **Majors** block approval. Each is small and well-scoped: a replay redirect that lands
the user on the wrong page, a config-sanity guard that covers four of six constants, and
missing accessible names on the replay dialog's checkboxes. None is a security or data
defect. Eight Minors and four Nits are non-blocking.

## Gate results (run by the Reviewer)
| Gate | Command | Result |
|---|---|---|
| Backend tests | `./vendor/bin/sail test --parallel` | `{"tool":"paratest","result":"passed","tests":699,"passed":699,"assertions":2587}` |
| Lint | `composer lint` | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` (L7, no baseline) |
| Frontend lint | `pnpm lint:check` | clean (eslint, exit 0) |
| Frontend types | `pnpm types:check` | clean (`vue-tsc --noEmit`, exit 0) |
| Frontend format | `pnpm format:check` | `All matched files use Prettier code style!` |

`pnpm run build` remains unrunnable in this sandbox (Node 21 < required 22) — an environment
limitation, not a failure; Vue SFCs are validated by `vue-tsc` + ESLint instead, per the
standing convention.

## AC coverage (PRD-06, AC1–AC25)
Verified against the running code and named tests — **not** taken from T46's verdict.

| AC | Verified by | Status |
|---|---|---|
| 1 Automatic retry, every proxy, both modes | `RetryEngineTest::test_a_failed_attempt_on_a_simple_mode_proxy_schedules_attempt_2_under_the_system_default`; `DeliverToDestination::settleDelivery()` has no mode branch | Pass |
| 2 Backoff + enhanced-only configurability, defaults, caps | `RetryEngineTest` (limit-2/fixed, unset→5/exponential); `RetryPolicyTest` (clamp, delay table, worst case); `RetryPolicyFormTest` (1–10 bounds, `prohibited_if:mode,simple`) | Pass — but see **Major 2** (backoff collapses to 0 under a blank env value) |
| 3 Retry is per destination | `RetryEngineTest::test_two_destinations_one_fails_only_the_failed_one_is_retried` | Pass |
| 4 Explicit terminal state, never inferred | `deliveries.status = 'failed'` is a stored column; `TerminalStateTest::test_after_the_limit_the_delivery_is_terminal_and_no_further_attempt_is_ever_created` (travels past all schedules, runs sweeps, asserts zero new rows) | Pass |
| 5 Exhaustion emits an event | `TerminalStateTest::test_delivery_exhausted_fires_exactly_once_under_a_racing_duplicate_settle_and_carries_reachable_state`; emission gated on `$affected` at `DeliverToDestination.php:203` | Pass |
| 6 FIFO ordered, head-of-line bounded; Async unaffected | `FifoRetryCompositionTest` — 8 cases incl. sweeper-leaves-held-line-alone, exhausted head settles and line advances, multi-destination hold, racing settler no-op, stuck-hold release, Async interleave | Pass |
| 7 Same record/event stream, exactly-once under redelivery | `RetryEngineTest::test_each_retry_writes_a_new_payload_free_attempt_row_and_fires_the_existing_events`, `..._a_duplicate_retry_delivery_execution_produces_exactly_one_attempt_row` | Pass |
| 8 Upstream sender unaffected | `ReplayTest::test_replay_never_produces_an_ingest_response_and_ingest_stays_unaffected_by_retry_state` — **T46's claim confirmed**: covered by a real test, just not named in a task title | Pass |
| 9 Replay a retained event, both modes | `ReplayTest::test_replay_delivers_for_real_on_both_simple_and_enhanced_proxies` (data-provided over `ProxyMode`) | Pass |
| 10 Target selection — specific or all | `ReplayTest` subset / select-all / trashed / other-proxy cases; `ReplayEventRequest` scoped `Rule::exists` | Pass |
| 11 New dispatch now, same path, joins at back | `ReplayTest::test_replay_runs_through_the_real_pipeline_and_produces_traceable_replay_deliveries`; `FifoRetryCompositionTest::test_order_key_capture_order_is_preserved_and_a_replay_row_joins_after_all_pending_events` | Pass |
| 12 Replays distinguishable + traceable | `deliveries.kind` + `dispatch_uuid` + `webhook_event_id`; grouped on the detail page | Pass |
| 13 Failed replay retries like a live delivery | `ReplayTest::test_a_failed_replay_retries_under_policy_and_can_terminalize_with_delivery_exhausted` | Pass |
| 14 Permission-gated, never role-gated, all three roles | `ReplayTest::test_every_team_role_can_replay_a_proxy_they_did_not_create` (data-provided over `TeamRole`) + non-member denial; `ProxyPolicy::replay()` single-axis | Pass |
| 15 Cleaned event not replayable, and says so | `RetryReplayRetentionInterplayTest::test_replay_of_a_cleaned_event_is_a_validation_error_with_zero_delivery_rows_zero_attempts_zero_http_sends`; UI gates the affordance on `payload_state === 'retained'` on both list and detail | Pass |
| 16 Three payload states visibly distinct | `..._the_three_payload_states_render_distinctly_and_are_never_inferred_from_body`; `StoredPayloadState` mapping is the sole source | Pass |
| 17 Nothing dispatches erased content | `..._retry_delivery_meeting_a_cleaned_parent_mid_schedule_terminalizes_sends_nothing_and_logs_identifiers_only`; guards at pipeline entry, retry executor, replay endpoint (`lockForUpdate`), payload endpoint | Pass |
| 18 Outstanding holds erasure; terminal holds nothing | H5 in `applyHolds()` (`PurgeExpiredPayloads.php:222-232`); `..._h5_an_expired_event_with_a_retrying_delivery_is_not_erased_...`, `..._h5_a_pending_delivery_holds_only_within_the_dispatch_horizon`; `RetryPolicyTest::test_worst_case_span_stays_well_inside_the_retention_window` + two regression-catching guards | Pass |
| 19 No notifications | No listener registered; scope-boundary negative, **no test** | Pass (unverified by test — see Nit 3) |
| 20 No mode toggle | `RetryPolicyFormTest::test_mode_gates_only_the_retry_policy_pair_nothing_else` | Pass |
| 21 No mapping / transformation | Scope-boundary negative, **no test**; branch adds no transform step | Pass (unverified by test) |
| 22 No sensitive-data policy; one content exposure only | `ReadSurfaceRevealTest::test_list_and_detail_never_emit_body_or_headers_under_any_state`; grep confirms no Resource emits either | Pass |
| 23 No analytics surface | **No test.** Traces only to frontend task T35 (client-side aggregate badge), which has no harness — see **Minor 6**; code inspection confirms no stats/count/dashboard beyond per-event state | Pass (unverified by test) |
| 24 No numeric targets | Scope-boundary negative, **no test** | Pass (unverified by test) |
| 25 Masked by default, explicit whole-payload reveal | `ReadSurfaceRevealTest::test_payload_endpoint_retained_cleaned_unknown_cross_team_and_unauthenticated`, `..._a_member_who_did_not_create_the_proxy_can_reveal_its_payload_no_distinct_reveal_permission`; `PayloadViewer.vue` fetch-on-reveal, `v-text` (never `v-html`), `aria-pressed`, re-masks on navigation | Pass |

**Independent verdict on T46's coverage claim.** T46 asserts AC1–AC18, AC20, AC22, AC23,
AC25 all trace to named tests, that AC8 is covered but not task-titled, and that
AC19/AC21/AC24 trace to no test by design. **Confirmed with one correction:** **AC23 does
not trace to a test.** It is named only in frontend task T35's title, and M9 has no test
harness. AC23 belongs in the same bucket as AC19/AC21/AC24 — asserted by inspection, not
verified by an automated gate. Everything else in T46's verdict reproduces, including the
AC8 claim, which I confirmed by reading the test.

## Design-spec conformance (design-06)
| Surface | Result |
|---|---|
| Screen 1 — Show additions (Events button first in header actions; Retry policy card after Destinations, `dl`/`dt`/`dd`, `(default)` annotation, simple-mode note) | Conforms |
| Screen 2 — Events list (table, pagination, three payload badges incl. vocabulary-complete "Not captured", aggregate delivery badge, `View`/`Replay`, muted `Expired` in the Replay slot, empty state, FIFO banner) | Conforms |
| Screen 3 — Event detail (Details/Payload/Delivery cards, Original-vs-Replay grouping, `Attempt N of L`, `Collapsible` attempt history, event-scoped FIFO note, cleaned/not-captured messaging with no reveal control) | Conforms |
| Screen 4 — Replay dialog (plain `Dialog` per PM ruling 1, nothing pre-checked, tri-state Select all, count-bearing Confirm, FIFO note, inline error, Cancel disabled while submitting, reset on reopen) | Conforms **except** the success behaviour (**Major 1**) and checkbox naming (**Major 3**) |
| Screen 5 — Retry policy form section (enhanced-only mount/unmount with data clearing, `w-full sm:w-32` / `sm:w-64`, sentinel-plus-options Select, `aria-describedby` help+error) | Conforms |
| Mode help-text correction under the PM's copy constraint | Conforms — final copy carries no roadmap numbers and does not imply mapping exists |
| Flow C — reveal/hide, re-masks on navigation, `aria-pressed`, `sr-only` live region | Conforms |

**Frontend verification caveat (recorded, not a finding).** This project has no JS test
harness — Vitest is an Owner-deferred backlog item (Option B, 2026-07-31). M9 (T31–T37)
therefore rests on manual verification plus my inspection. Judgment: that gap is **material
for exactly one class of defect — accessibility**, and it produced one here (Major 3), which
an `axe`/`vitest-axe` run would have caught mechanically. Everything else in M9 is either
type-checked (`vue-tsc` covers the prop/resource contracts end-to-end), asserted server-side
(the Inertia prop shape is covered by `ProxyEventIndexTest`/`ProxyEventShowTest`/
`ReadSurfaceRevealTest`), or visually deterministic. I do **not** recommend
blocking #6 on the harness; I recommend the backlog item be re-raised with the a11y case
attached, since this feature is the first to ship a multi-select consequence dialog.

## Findings

### Blockers
None.

### Majors

**Major 1 — A successful replay redirects to the proxy Show page, abandoning the surface the
user acted from.**
*Location:* `app/Http/Controllers/ProxyEventReplayController.php:99` —
`return to_route('proxies.show', ['proxy' => $proxy->id]);`
*Criterion:* design-06 **Flow D step 3 (Success)** — "the page's Delivery card gains a new
**Replay — {time}** group with fresh per-destination attempt rows… no navigation away" — and
**Screen 4 → States → Success** — "the underlying page (**list or detail**) reflects the new
Replay group/attempts on next render (Inertia visit or partial reload — implementation's
choice)". Also T37's own acceptance criterion.
A user who replays from `/proxies/{p}/events/{e}` or from a row on
`/proxies/{p}/events?page=3` is redirected to `/proxies/{p}` — a third page that renders
neither the event, nor the new Replay group, nor their place in the list. This is the finding
behind the flagged **T37/T24 conflict**; my ruling and the reasoning are in *Rulings on the
two flagged conflicts* below. **The fix is one line** in the controller
(`return back();`, or `to_route('proxies.events.show', …)`); no design amendment, no plan
amendment, and no change to `ReplayDialog.vue` is required.

**Major 2 — `RetryPolicy`'s config-sanity guard covers four of six `config('retry.*')`
integers; a blank or non-numeric value for either unguarded key silently collapses every
exponential backoff to zero.**
*Location:* `app/Services/RetryPolicy.php:98-107` — `exponential_multiplier` and
`exponential_max_delay_seconds` are read with a bare `(int) config(...)`, explicitly excluded
from `positiveConfigInt()` by the method's own docblock.
*Criterion:* plan-06 **§Validation → System invariants (binding)** — "`RetryPolicy` clamps
the limit into `[1, max_attempt_limit]` regardless of column content; **config-sanity
`RuntimeException`s on non-positive constants** (review-05 M-1 posture)". Also PRD-06 **AC2**
— "Successive attempts for the same `(event, destination)` are **separated by a backoff
schedule**."
*Verified, not theorised* (`artisan tinker`, this branch):

```
config(['retry.exponential_max_delay_seconds' => 0]);
  → delayBefore(attempt 2..5) == 0, 0, 0, 0     // every retry fires immediately
config(['retry.exponential_multiplier' => 0]);
  → delayBefore(attempt 2..5) == 60, 0, 0, 0    // worstCaseSpan() == 60s
```

Per the standing repo gotcha, `(int) env('KEY', $default)` resolves a blank line (`KEY=`) or
a non-numeric value to `0` — the default is **not** applied. The consequence is a
zero-backoff burst of up to `attempt_limit` real outbound sends to an already-failing
destination, and a `worstCaseSpan()` guard that reports 60 seconds instead of tripping. This
is the same class the Owner accepted a fix for as review-05 M-1; the plan asked for that
posture by name and it was applied to four keys but not these two. **Fix:** route both reads
through `positiveConfigInt()` and add the two guard tests that the other four already have in
`RetryPolicyTest`.

**Major 3 — The replay dialog's destination checkboxes and its "Select all" control have no
programmatic accessible name.**
*Location:* `resources/js/components/ReplayDialog.vue:146-169` — each `Checkbox` is wrapped
in a `<Label>` alongside a sibling `<span>` carrying the destination text; no `for`, no
`id`, no `aria-label`, no `aria-labelledby`.
*Criterion:* design-06 **§Accessibility** — "each `Checkbox` has a **programmatically
associated** `Label` naming its destination (`{METHOD} {url}`) — never a bare icon or
ambiguous target". Also `docs/standards/design.md` → Screen-reader requirements.
*Evidence:* `reka-ui`'s `CheckboxRoot` renders `<button role="checkbox">` (verified in
`node_modules/reka-ui/dist/Checkbox/CheckboxRoot.js`: `role: "checkbox"`, `as` defaults to
`"button"`). HTML-AAM's accessible-name mapping for `button` is aria-labelledby → aria-label
→ **subtree contents** → title; a wrapping `<label>` element is not part of that chain, and
axe-core's `button-name` rule fails on it. The button's subtree contains only the check
indicator icon, so the computed name is empty. A screen-reader user tabbing the dialog hears
"checkbox, not checked" with no indication of which destination they are arming — inside the
one control in this feature that sends real traffic to production endpoints, which the PRD
and design both single out for deliberateness. **Fix:** add
`:aria-label="`${destination.http_method} ${destination.url}`"` to each destination
`Checkbox` and `aria-label="Select all destinations"` to the Select-all `Checkbox` (or
`aria-labelledby` pointing at the sibling `<span>`'s id).

### Minors

**Minor 1 — 30 new `factory()->create(` call sites violate the Quiet-factory standard.**
*Location:* 13 test files; heaviest in `tests/Unit/Models/DeliveryTest.php` (10),
`tests/Unit/Models/FifoDispatchTest.php` (4), `tests/Unit/Models/DeliveryAttemptTest.php` (3),
`tests/Unit/Pipeline/DeliverStepTest.php` (3); plus one each in
`tests/Feature/Retry/RetryDeliveryTest.php:26`,
`tests/Feature/Retry/FifoRetrySettlementTest.php:59`,
`tests/Unit/Actions/SweepDueRetriesTest.php:33`,
`tests/Unit/Actions/SweepStalledFifoDispatchesTest.php:60`,
`tests/Feature/Delivery/DeliverToDestinationTest.php:38`,
`tests/Feature/Ingest/DeliveryIdempotencyTest.php:78`,
`tests/Unit/Actions/DeliverStepTest.php:46`,
`tests/Unit/Pipeline/DeliveryUnitTest.php`, `tests/Unit/Pipeline/PipelineContextTest.php`.
*Criterion:* `docs/standards/testing.md` → **Quiet factory creation (active)**, Owner-adopted
2026-07-31, "Tests **must** create model-factory records with `createQuietly()`… Applies to
all new and modified tests."
Benign in effect (the factories set `team_id` explicitly, so `BelongsToCurrentTeam`'s
`creating` hook is a no-op) — which is why review-04 rated the identical violation **Minor**,
and I am matching that precedent rather than escalating on volume. Two things make it worth
recording anyway: the scale (3 sites at #4 → 30 here), and that
`tests/Unit/Actions/SweepStalledFifoDispatchesTest.php` is a **regression** — it is one of the
three files review-04's Minor #3 explicitly fixed. Mechanical fix: `->create(` → `->createQuietly(`.

**Minor 2 — `SweepDueRetries` dispatches `RetryDelivery` onto a different queue than the
settle path does.**
*Location:* `app/Actions/SweepDueRetries.php:45` — `RetryDelivery::dispatch(...)` with no
`->onQueue(...)`, versus `app/Actions/DeliverToDestination.php:222-224` which pins
`->onQueue(config('ingest.webhooks_queue'))`.
*Criterion:* ADR-015 Decision 5 describes one retry job on the webhooks queue with the
sweeper as its liveness net; plan-06 §Services likewise.
The same job class now lands on two queues depending on which path scheduled it. I checked
whether this could silently disable the liveness net and concluded **it does not**: the repo
commits no worker configuration, and `AdvanceProxyFifoQueue::dispatch()` (pre-existing, #4)
already relies on the `default` queue, so any working deployment must already process it.
Recording it as a consistency follow-up, not a defect: pin the sweeper's dispatch to the same
queue so retry work has one worker pool and one priority.

**Minor 3 — `WebhookEventResource` re-queries the database once per row for state it already
holds.**
*Location:* `app/Http/Resources/WebhookEventResource.php:55` — `app(StoredPayloadLookup::class)
->for($this->ingest_id)` re-selects the `webhook_events` row by `ingest_id` even though
`$this` **is** that model and `$this->payload_cleaned_at` is already loaded; plus
`legacyDeliveries()` (line 77) issues a further per-row query whenever an event has no
`deliveries` rows.
*Criterion:* `docs/standards/architecture.md` → API design; the same per-row-query class the
codebase has been trending away from since #1.
Bounded at 15 rows/page (30 queries worst case on a pre-#6 page), so no correctness or
security impact. Keeping `StoredPayloadLookup` as the single resolver is right and must not
be traded away — the clean fix is a model-taking overload (`forEvent(WebhookEvent): StoredPayloadState`)
inside the same resolver class, which preserves ADR-014 Decision 7's single-seam rule.

**Minor 4 — The payload endpoint has no `NeverCaptured` → 404 branch.**
*Location:* `app/Http/Controllers/ProxyEventPayloadController.php:35-57`.
*Criterion:* plan-06 **§API** — "Retained ⇒ raw bytes…; Cleaned ⇒ **410**; NeverCaptured ⇒
**404**", and ADR-017 Decision 6 ("never captured ⇒ 404").
Today an existing row with `payload_cleaned_at === null` but a NULL `body` would return
**200 with an empty body**, not 404. Unreachable in practice — capture is unconditional at
ingest (#3 AC7) and the scoped binding already 404s an unknown id, which is what the test
`test_payload_endpoint_retained_cleaned_unknown_cross_team_and_unauthenticated` exercises —
but the plan named the branch and the design deliberately kept "Not captured" in the badge
vocabulary to *fail safe*. A three-line `if ($event->body === null) { return response('', 404); }`
after the cleaned guard closes it. (Note this is a `body`-null check used **only** to pick a
status code for a state the cleaned signal has already excluded — it is not a cleaned-state
inference and does not weaken ADR-014 Decision 7.)

**Minor 5 — `DeliveryResource` carries no `created_at`, forcing the event-detail page to
derive replay-group labels and ordering from attempt data.**
*Location:* `app/Http/Resources/DeliveryResource.php`; consumed at
`resources/js/pages/proxies/events/Show.vue:176`.
*Criterion:* design-06 **Screen 3** — "**Replay — {time}** (one group per replay, **newest
first**)".
This is T46's flagged tension #7, escalated to me for a judgment. **My answer: yes,
`DeliveryResource` should gain a real `created_at`.** The present derivation takes the group
label from the earliest attempt's `started_at` and the ordering from the group's highest
`Delivery.id`, which is correct in the common case but degrades to a bare "Replay" label
(no time) whenever a FIFO replay is still queued behind a held line with zero attempts — the
exact scenario the feature exists to make visible. The implementer was right not to patch a
backend field in from a frontend task, and right to pin today's shape explicitly in T43
(`->missing('event.deliveries.0.created_at')`). Routing to the Principal Engineer as a
follow-up; not a #6 blocker.

**Minor 6 — T46's AC-coverage verdict lists AC23 as traced to a named test; it is not.**
*Location:* `docs/tasks/retry-replay-tasks.md:2813-2826`.
*Criterion:* `docs/standards/documentation.md` — accuracy of a completion record.
AC23 is named only in frontend task **T35**'s title, and M9 has no test harness, so no
automated gate asserts it. The correct verdict is that **AC19, AC21, AC23 and AC24** are all
asserted by inspection. The rest of T46's verdict — including the AC8 claim — is accurate and
reproduced. A one-line correction to the completion note.

**Minor 7 — `docs/standards/review.md`'s *active* Severity definitions were edited on this
feature branch by the Principal Engineer with no approval record.**
*Location:* commit `c33f765`, `docs/standards/review.md:5-6` (Major gains "or duplicates or
adds a dependency without an ADR"; Minor gains "including reimplemented existing helpers or
abstractions beyond the plan").
*Criterion:* `docs/standards/review.md`'s own ownership line (Reviewer proposes, Project
Owner approves) and `CLAUDE.md` — "doc corrections → **the owning role** updates the doc".
The edits are benign and I have applied the amended definitions in this review. Flagging the
process, not the content: an `(active)` section of a standard changed hands without its
owning role or an approval stamp, and it happens to be the rubric this gate runs on. Recommend
the Owner either ratify the amendment explicitly or route it back through the Reviewer.

**Minor 8 — T30's mode-switch clearing will be reversed by feature #7; three concrete
obligations should be recorded now.**
See *Rulings on the two flagged conflicts* → **Ruling 2** below. **Not a #6 defect** — no
change requested here.

### Nits

**Nit 1 — `AdvanceProxyFifoQueue::settleOrHold()` settles with a blind `update()` while it
holds with a CAS.** `app/Actions/AdvanceProxyFifoQueue.php:119-122` vs `130-137`. ADR-016
Decision 1 states "All transitions are conditional updates keyed on the prior status". The
blind settle is **pre-existing from #4** (verified against `main`) and is not a #6 regression,
but #6 raises the stakes: if the lease expires and the reaper returns the row to `pending`
before this line runs, the blind update can settle a row another advancer has since re-claimed.
Adding `->where('status', FifoDispatchStatus::Claimed)` to the settle branch costs one line and
makes the ADR's statement true of both branches.

**Nit 2 — `SweepDueRetries` loads all overdue deliveries with an unbounded `->get()`.**
`app/Actions/SweepDueRetries.php:35-39`. `PurgeExpiredPayloads` batches; this does not. No
issue at current volume; worth a `chunkById` before the table grows (plan Risk 10 already
acknowledges unbounded `deliveries` growth).

**Nit 3 — AC19 had a cheap positive proof available and did not take it.** plan-06 §Services
states "`DeliveryExhausted` … no listener at #6 (AC5/AC19)"; a one-line
`$this->assertFalse(Event::hasListeners(DeliveryExhausted::class))` would have turned a
scope-boundary negative into a real guard against #13 work leaking backwards. T46's reasoning
(no code path to exercise) is sound for AC21/AC24; AC19 is the one of the four that had a
mechanism to assert against. Non-blocking.

**Nit 4 — `RetryDelivery::terminalizeCleaned()` writes no `DeliveryAttempt` row.**
`app/Actions/RetryDelivery.php:88-105`. This is T46's flagged tension #2 and I **concur with
the implementer's reading**: AC17's "a cleaned event produces **zero new delivery attempts**
except by rejecting the request cleanly" is literal and binding, `deliveries` has no
`error_summary` column, and ADR-015 Decision 5's "terminalize with an error summary" is best
read as describing the `Log::info('payload.expired', …)` call — which is what was written, with
identifiers only. Recording the concurrence so a later reader does not mistake ADR-015's phrasing
for an unimplemented requirement.

## Rulings on the two flagged conflicts

### Ruling 1 — T37 vs T24 (replay navigation). **Neither artifact needs to give. This is an implementation defect, filed as Major 1.**
The conflict is narrower than it was flagged as, because **design-06 is not univocal and
already delegated the mechanism**. Flow D step 3 says "no navigation away"; Screen 4's
Success state says the underlying page "reflects the new Replay group/attempts on next render
(**Inertia visit or partial reload — implementation's choice, not specified here**)". The
design spec therefore does *not* forbid a redirect. plan-06 §API's "PRG + flash toast" is
consistent with Screen 4, the PE certified the plan as building design-06's surfaces
"and chang[ing] none of them", and Post/Redirect/Get is the app's convention for every write
endpoint. **On mechanism, the plan wins: a redirect is correct, and design-06 Flow D's "no
navigation away" should be read as the looser Screen 4 wording from the same spec.**

**But the destination is wrong under either reading.** Both artifacts require the user to end
up on a page that shows the replay they just made — Flow D names the Delivery card's new
Replay group, Screen 4 names "the underlying page (**list or detail**)". `to_route('proxies.show')`
is a third page that is neither. `back()` — or `to_route('proxies.events.show', …)` — satisfies
the plan (still PRG, still a flash toast, still the house convention) *and* satisfies design-06
(the detail page re-renders with the new Replay group; the list re-renders in place). Nothing
about T37's client code needs to change: `ReplayDialog` already submits with
`preserveScroll: true` and closes on success.

**Does it block?** Yes, as a **Major** — it materially violates an approved design spec and
T37's own acceptance criterion. It is not a Blocker: no PRD acceptance criterion is breached
(AC12's traceability is a data property and is correctly modelled and rendered), nothing is
lost, and the replay itself is correct. The implementer was right to flag rather than resolve
it — a controller change was genuinely outside T37's stated scope — and right to pin today's
behaviour explicitly in `ReplayTest` rather than paper over it. The fix is one line
plus updating that assertion.

### Ruling 2 — T30 vs feature #7 (mode-switch clearing). **Ship #6 as-is. Do not reconcile now.**
T30 clears `retry_attempt_limit`/`retry_backoff_strategy` to NULL on an enhanced→simple
switch. That is **exactly correct** against every artifact governing #6: PRD-06 AC2 ("Simple-mode
proxies have **no** retry configuration — the system default applies, fixed"), design-06 Flow F
step 4, and plan-06 §Validation's `prohibited_if:mode,simple` idiom. Q-07-01(b) (Owner,
2026-08-21) will instead preserve a persisted policy dormant. Four reasons to defer:

1. **Reconciling now would break #6's own approved AC.** Preserving the columns is only half
   the change: Q-07-01(b) consequence (2) requires that "nothing may resolve retry behaviour
   from persisted columns without first checking mode". `RetryPolicy::attemptLimitFor()` reads
   the column unconditionally today, and that is *safe only because the column is guaranteed
   NULL for simple proxies*. Stop clearing without simultaneously mode-gating the resolver, and
   a dormant value silently governs a simple proxy's retry behaviour — a direct AC2 violation
   shipped inside #6.
2. **The Owner already scoped it to #7.** Q-07-01(b)'s own consequence (3) states that
   "`design-06` Flow F's in-form, in-session behaviour is **unchanged and not in conflict**" and
   that the ruling "governs **persistence** only" — issued as direction for PRD-07, whose ACs
   (AC13/AC14) are where it becomes testable. PRD-06 AC20 puts mode-switch consequences outside
   #6 by construction.
3. **Reviewer scope forbids it.** Requesting a behaviour change sourced from a downstream,
   not-yet-approved PRD would be expanding scope beyond PRD-06 — explicitly outside this role.
4. **The harm window is small and bounded.** A user can reach the clearing today via the proxy
   edit form's existing Mode field, so the risk is real but limited to losing two scalar values
   that are re-enterable in seconds, on a form that shows the fields' effect plainly.

**What #6 owes #7 — record these three as named obligations on the #7 task plan:**
(a) stop clearing in `ProxyController::update()` **and** relax `prohibited_if:mode,simple` in
both proxy requests; (b) **simultaneously** mode-gate `RetryPolicy::attemptLimitFor()` /
`strategyFor()` so a simple proxy always resolves the system default regardless of column
content — (a) without (b) is a defect; (c) invert
`RetryPolicyFormTest::test_switching_enhanced_to_simple_on_update_clears_stored_values_to_null`
(its method name asserts the clearing as the expected outcome, so it will need renaming, not
just re-asserting) and add the Show-page suppression Q-07-01(b) consequence (1) requires.
Classified as **Minor 8** — a forward-compatibility note, not a #6 defect.

## Standards checklist
| Area | Result |
|---|---|
| **Security** — never-log list; payload endpoint logs identifiers only; no secret/body/header in any log, Resource, or prop | Pass |
| **Security** — every proxy decision in a Policy on `TeamPermission` via `hasTeamPermission`; no role literal; `proxy:replay` single-axis per AC14 | Pass |
| **Security** — all four new routes inside the `auth`/`verified`/`EnsureTeamMembership`/`ApplyTeamScope` group with `->scopeBindings()`; `{event}` resolves through `Proxy::webhookEvents()`; cross-team/cross-proxy ⇒ 404 | Pass |
| **Security** — validation server-authoritative in Form Requests; `authorize()` returns `true`; controllers call `$this->authorize(...)` | Pass |
| **Security** — content endpoint hardening (`text/plain; charset=utf-8`, `nosniff`, `no-store, private`, 410 for cleaned, text-node rendering, no `v-html`) | Pass |
| **Data / Migrations** — shapes match the Owner-approved verbatim asks (flags 4–7); FK onDelete restrict throughout; index-add-before-drop ordering respected in both non-additive migrations and mirrored in `down()`; enum value appended, never reordered; backfill mechanical and documented | Pass |
| **Data** — persisted enums cast to their enum class; casts via `casts()`; no `deleted_at` on `deliveries` (progress state, not user-owned) | Pass |
| **Backend code** — layer placement; thin controllers; CAS-only status transitions; `DB::transaction` on the multi-write replay path with `lockForUpdate` re-check inside it; `ValidationException::withMessages` for the expired case; no exception/SQL leak to the client | Pass |
| **Backend code** — `$wrap = null` on all three new Resources; PRG + Sonner toast; `__()` on user strings | Pass |
| **Dependencies** — no new Composer or pnpm package; no stack change; no new `ui/*` primitive (`Checkbox`/`Collapsible` first application use, as design-06 anticipated) | Pass |
| **Frontend / a11y** — keyboard operability, `InputError` + `aria-invalid` + `aria-describedby` on both new fields, submit controls disabled during request, single Sonner channel, semantic tokens only, `Label for=` on form fields, `DialogTitle` + `DialogDescription` present, colour never sole carrier | Pass **except** the dialog checkbox names (**Major 3**) |
| **Frontend** — affordances derive from `permissions.canReplayProxy` + payload state; no per-row policy call (ADR-009 Amendment B) | Pass |
| **Testing** — suite green under the Reviewer's own run; no class declares `RefreshDatabase`/`FasterRefreshDatabase` | Pass |
| **Testing** — quiet factory creation | **Fail** — Minor 1 |
| **Toolchain** — all five runnable gates green | Pass |
| **Documentation** — every artifact carries Status/Author/Approval/Handoff; T1–T46 all carry real completion notes; ADR-011 annotated by pointer only, its decision text untouched; superseded positions correctly cross-referenced | Pass |

## Recommendation (initial review, 2026-08-22 — superseded by the Re-review below)
**Request changes.**

Three Majors block approval under `docs/standards/review.md` → Severity definitions. All
three are small, independently fixable, and none touches the data model, the concurrency
design, or the security posture — the parts of this feature that were hardest and are done
well. Concretely, the return path is:

1. **Senior Developer** fixes **Major 1** (one line in `ProxyEventReplayController::store()`,
   plus the corresponding assertion in `ReplayTest`), **Major 2** (route the two
   remaining `config('retry.*')` reads through `positiveConfigInt()`, plus two guard tests
   mirroring the existing four), and **Major 3** (`aria-label`/`aria-labelledby` on the three
   checkbox call sites in `ReplayDialog.vue`). Minors 1–4 are cheap enough to bundle if the
   Owner wants them in the same pass; Minors 5–8 are follow-ups by design.
2. **Reviewer** re-reviews the three fixes only, re-running all five gates.
3. **Project Owner** takes the approval decision. Nothing here requires reopening PRD-06,
   design-06, plan-06, or any of ADR-015/016/017 — Ruling 1 resolves the T37/T24 conflict
   without an artifact amendment, and Ruling 2 defers the #7 collision to #7 as the Owner's
   own Q-07-01(b) already contemplates.

Had these three not been present, the recommendation would have been *Approve with
follow-ups*. I want that on the record: the Majors are defects of finish, not of design.

- **Project Owner decision / date:** _superseded — see Re-review_

## Re-review (2026-08-22)

Focused re-review of the Senior Developer's rework on `feat/item-06-retry-replay` after the
initial **Request changes**. The Owner authorised fixing **the three Majors only**; Minors 1–8
and Nits 1–4 were deliberately left untouched and are **not** re-raised here — they carry
forward as already-recorded follow-ups (enumerated under *What carries forward* below).

**Rework under review:** `e8aef31` (Major 2) → `26dc7a4` (Major 1) → `378a95c` (Major 3), plus
"Rework (review-06 …)" notes appended to T11 / T24 / T37's completion notes in
`docs/tasks/retry-replay-tasks.md`, following the review-04 M-1 precedent. Diff
`f5b2a07..HEAD` is **7 files, +310/−18**. I verified each fix against the mechanism, not the
implementer's account.

### Gate results (re-run by the Reviewer)
| Gate | Command | Result |
|---|---|---|
| Backend tests | `./vendor/bin/sail test --parallel` | `{"tool":"paratest","result":"passed","tests":711,"passed":711,"assertions":2607}` |
| Lint | `composer lint` | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` (L7, no baseline) |
| Frontend lint | `pnpm lint:check` | clean (exit 0) |
| Frontend types | `pnpm types:check` | clean (`vue-tsc --noEmit`, exit 0) |
| Frontend format | `pnpm format:check` | `All matched files use Prettier code style!` |
| **Frontend build** | `pnpm run build` | **`✓ built in 1.22s`** — newly runnable |

The Senior Developer's claimed **711 passed / 2607 assertions** reproduces exactly (+12 tests /
+20 assertions over the initial review's 699/2587, consistent with the +12 tests the three fix
commits add).

**The `pnpm run build` limitation has lifted.** The standing note "unrunnable in this sandbox
(Node 21 < required 22)" is now stale — the host runs **Node v22.23.2**, satisfying
`package.json` `engines.node >= 22`. The build is green. This matters beyond bookkeeping: it is
what made the Major 3 live check trustworthy (see below), since `public/build` held **stale
assets dated 2026-08-12** that predate the entire replay dialog.

### Major 1 (replay redirect) — **RESOLVED**
`ProxyEventReplayController::store()` (`app/Http/Controllers/ProxyEventReplayController.php:99`)
now returns `back()`, with a comment naming the criterion. Verified:

- **Nothing depends on the old target.** The three surviving `to_route('proxies.show', …)` call
  sites are `ProxyController@store`/`@update` and `DestinationController@store` — all pages that
  genuinely own the proxy Show affordance. No replay-path reference remains, in the controller,
  in `ReplayDialog.vue`, or in the tests.
- **Both entry points, live in a real browser.** The server tests use `->from(…)`, which seeds
  only the session's previous URL — it does not exercise the `Referer` path that a real Inertia
  submit actually takes, so I drove the real flow end to end (logged-in Chromium, freshly built
  assets, real POST):

  | Entry point | POST `Referer` | Landed on | Same page? | Toast |
  |---|---|---|---|---|
  | events **Index** | `/…/proxies/2/events` | `/…/proxies/2/events` | **yes** | "Replay started." |
  | event **Show** | `/…/proxies/2/events/2` | `/…/proxies/2/events/2` | **yes** | "Replay started." |

  The dialog closed on success in both cases. This satisfies **Screen 4 → Success** ("the
  underlying page — list or detail — reflects the new Replay group/attempts") and, as it turns
  out, satisfies **Flow D step 3's** stricter "no navigation away" wording *literally* — the URL
  is unchanged — so the fix lands on the safe side of the Ruling 1 ambiguity rather than merely
  the permitted side.
- **The two new tests are real, not restatements.** `ProxyEventReplayControllerTest`
  gains `test_a_replay_from_the_events_index_redirects_back_to_the_index_with_a_success_toast`
  and `…_from_the_event_detail_page_redirects_back_to_the_detail_page_…`, each asserting the
  redirect target **and** `assertInertiaFlash('toast', …)`. The pre-existing happy-path
  assertion and `ReplayTest`'s pinned-behaviour comment were rewritten rather than
  deleted, and the stale "awaiting a ruling" comment is gone.
- **No-referer case — benign.** `back()` resolves `UrlGenerator::previous()`: `Referer` →
  session previous URL → `url('/')`. A browser always sends `Referer` here (the app sets no
  `Referrer-Policy` that would strip it — confirmed by grep, and observed live above). Stripped
  of both, the user lands on `/`, which is a valid page. Degradation, never an error.

### Major 2 (config-sanity guard) — **RESOLVED**
`RetryPolicy::exponentialDelaySeconds()` now routes both keys through `positiveConfigInt()`.
Verified:

- **The guard is total within `RetryPolicy`.** Grepped every `config(` occurrence in the class:
  the only read site is `positiveConfigInt()` itself (`:121`); the remaining hits are docblock
  prose and the exception message. No bare `(int) config(…)` remains. The docblock that
  previously excused the two keys as "engineering constants… read plainly" has been corrected,
  not just overridden in code.
- **Behaviour matches the four pre-existing keys exactly** — same private method, same `< 1`
  rejection threshold, same `RuntimeException` naming key and offending value, same refusal
  wording. This is not a parallel guard; it is the same one.
- **The zero-cap collapse genuinely cannot happen now.** Both `tinker` reproductions from the
  initial finding are pinned as named regression tests
  (`test_a_zero_exponential_max_delay_seconds_no_longer_collapses_every_delay_to_zero`,
  `test_a_zero_exponential_multiplier_no_longer_lets_worst_case_span_under_report`), and the
  new guard coverage goes **further than the original four**: it adds blank-env and
  non-numeric-env cases, which the pre-existing four keys' tests do not have. All ten new tests
  use `createQuietly()` — no new Minor 1 debt.
- **`worstCaseSpan()`'s AC18 bound is unaffected.** For valid config, `positiveConfigInt()`
  returns exactly what `(int) config(…)` returned, so the arithmetic is unchanged. Confirmed
  numerically against the running code: delays **60 / 300 / 1500 / 7500 / 21600 …** (ADR-015
  Decision 4's curve, capping at attempt 6) and `worstCaseSpan() = 117 360 s = 32.6 h` — the
  exact value T11 documents, still far inside the 3-day intermediate bound the guard test
  asserts.
- **The fail-loud posture introduces no new risk class.** A broken value now throws from
  `delayBefore()` mid-settle; that is precisely how `exponential_base_seconds` already behaved
  before this fix, under the Owner-accepted review-05 M-1 posture.
- **Review-05's own guards are unregressed** — `RetentionPolicy::windowFor()` and
  `PurgeExpiredPayloads`'s batch-size / horizon guards are intact and unchanged.

### Major 3 (checkbox accessible names) — **RESOLVED, verified live**

**My call on the verification standard, stated plainly: source-tracing was not sufficient
here, and I would not have signed this off on the trace alone.** The implementer's trace is
correct as far as it goes — I checked it independently and it holds at every link. But an
accessible *name* is a value **computed by the browser** from HTML-AAM's name chain, and this
finding exists precisely because markup that looked correct to a competent reader (a `<Label>`
wrapping a control) computed to empty. Accepting a source trace as proof of a computed name
re-runs the exact reasoning that produced the defect, one level up. That the trace happened to
be right does not make it the right evidence. With the `.env` now fixed by the Owner, the live
check was available and I took it.

Verified in headless Chromium against **freshly built assets** — necessary, since the checked-in
bundle predated the fix and a live check against it would have proved nothing (`grep` for
`"Select all destinations"` in the old bundle: no match; in the rebuilt bundle: present).
Chromium's own ARIA snapshot of the dialog subtree, **identical from both entry points**:

```
- dialog "Replay this event?":
  - group "Choose destinations":
    - checkbox "Select all destinations"
    - checkbox "POST https://cassin.com/possimus-at-unde-maxime-dolores-cumque-similique"
    - checkbox "POST https://reviewer-temp.example.com/hook-b"
  - button "Cancel"
  - button "Replay to 0 destinations" [disabled]
```

Each control is a `<button role="checkbox">` with an empty subtree, carrying the expected
`aria-label`; every expected name resolves to exactly **1** matching element by AX name, and
**checkboxes with an empty accessible name: 0**. A screen-reader user now hears the destination
they are arming, not "checkbox, not checked". Confirmed with a temporary second destination in
place, so the per-row names are demonstrably distinct rather than coincidentally unique.

The fix also stayed disciplined: `Login.vue`'s "Remember me" checkbox has the identical
pre-existing bug and was **correctly left alone**, recorded in T37's notes rather than
opportunistically fixed.

*Observation, not a finding:* the visible `<span>` remains a sibling text node, so a
screen-reader user in browse mode encounters the destination string twice (once as the control's
name, once as adjacent text). `aria-labelledby` pointing at the span would avoid the echo. The
initial review offered `aria-label` and `aria-labelledby` as equally acceptable, so this is
within what I sanctioned — noting it only so a future a11y pass has the context.

*Not re-verified:* label-click forwarding and Space-toggle keyboard operation. Both were
inspection-verified in the initial review and passed; adding an `aria-label` attribute cannot
regress either, and re-running them would have required mutating the dev user's credentials a
second time for no evidentiary gain.

### Scope discipline — **clean**
- The diff is **exactly 7 files**: the three fixed sources, their three test files, and the task
  plan. Nothing unrelated.
- **No Minor or Nit was opportunistically fixed.** `SweepDueRetries` (Minor 2),
  `WebhookEventResource` (Minor 3), `ProxyEventPayloadController` (Minor 4), `DeliveryResource`
  (Minor 5), `AdvanceProxyFifoQueue` (Nit 1) and the 30 `->create(` sites (Minor 1) are all
  absent from the diff and therefore provably unchanged.
- **`docs/status.md`, `docs/product/prd-07-*` and `docs/questions/prd-07-*` were not touched by
  any fix commit** — confirmed against the diff, not the commit messages.
- The task-plan notes are pointer-and-rationale in the review-04 M-1 house style, and are
  candid about what could not be verified at the time rather than overstating it.

### New finding

**Minor 9 — `retry.sweep_grace_seconds` is the one `config('retry.*')` integer still read
without a sanity guard.**
*Location:* `app/Actions/SweepDueRetries.php:33` — `now()->subSeconds((int)
config('retry.sweep_grace_seconds'))`.
*Criterion:* plan-06 **§Validation → System invariants (binding)** — "config-sanity
`RuntimeException`s on non-positive constants (review-05 M-1 posture)".
**This is my own miss, not a regression and not a goalpost move** — it was present at the
initial review and I did not catch it, because I scoped Major 2 to the six curve/limit
constants `RetryPolicy` reads and did not sweep the seventh key, which lives in a different
class. Recording it now for completeness; the Major 2 fix is total *within its own seam*, and
this is a distinct site.
**Why Minor and not a second Major.** A blank or non-numeric env value makes the grace `0`, so
the sweeper stops waiting before re-driving an overdue `retrying` delivery. That does **not**
breach AC2 — `next_attempt_at` and the backoff schedule are still fully honoured; the sweeper
only ever fires for deliveries already past their scheduled time. What is lost is the anti-race
margin, so the sweeper more often double-fires against a still-live delayed job. That race is
already designed for and arbitrated by the `UNIQUE(delivery_id, attempt_number)` create-or-resume
key, which guarantees no duplicate attempt row; the residual cost is job churn and a higher rate
of the at-least-once duplicate send that **plan-06 Risk 1 already accepts**. Zero-collapse of
the backoff curve — what made Major 2 a Major — cannot occur here.
*Suggested fix (follow-up):* the house remedy, a `positiveConfigInt`-style guard at this single
read seam. **Not blocking.**

*Also for the orchestrator, not a finding:* the uncommitted `docs/status.md` edit still cites
"699 passed / 2587 assertions" and describes the T37/T24 conflict as needing a ruling. Both are
now stale (711/2607; Ruling 1 given and implemented). `docs/status.md` is orchestrator-owned —
flagging, not touching.

### What carries forward if the Owner approves
Accepting this feature means accepting these **ten** open follow-ups. None is a security, data
or concurrency defect; none blocks.

| # | Follow-up | Owner / route |
|---|---|---|
| Minor 1 | 30 `factory()->create(` sites should be `createQuietly()`; one file is a review-04 regression | Senior Developer, mechanical |
| Minor 2 | `SweepDueRetries` dispatches `RetryDelivery` off-queue vs the settle path's `webhooks` queue | Senior Developer, consistency |
| Minor 3 | `WebhookEventResource` per-row re-query; fix via `forEvent()` overload inside `StoredPayloadLookup` (keep the single seam) | Senior Developer |
| Minor 4 | Payload endpoint has no `NeverCaptured` ⇒ 404 branch (unreachable today; plan named it) | Senior Developer |
| Minor 5 | `DeliveryResource` should gain a real `created_at` | **Principal Engineer** |
| Minor 6 | T46's verdict lists AC23 as test-traced; it is not (AC19/21/23/24 are inspection-only) | Senior Developer, one line |
| Minor 7 | `docs/standards/review.md` severity definitions amended on this branch with no approval record | **Project Owner** — ratify or revert |
| Minor 8 | Three named obligations #6 owes #7 (stop clearing **and** mode-gate the resolver together; invert the T30 test) | **Feature #7 task plan** |
| **Minor 9** | `retry.sweep_grace_seconds` unguarded (new, above) | Senior Developer |
| Nits 1–4 | Blind FIFO settle (pre-existing from #4); unbounded `SweepDueRetries` `->get()`; AC19's cheap positive proof; `terminalizeCleaned()` concurrence (no action) | Backlog |

Two standing items also remain, unchanged by this rework: the **JS test harness** gap (the a11y
class of defect is the one it would have caught mechanically — Major 3 is now the second
concrete argument for re-raising the Owner-deferred Vitest item), and **AC19/AC21/AC23/AC24**
being asserted by inspection rather than by an automated gate.

### Re-review recommendation
**Approve with follow-ups.**

All three Majors are genuinely closed, each verified against the mechanism rather than the
account: Major 2 by grep + numeric reproduction of the AC18 bound + the two pinned regressions;
Major 1 by a real browser round-trip from both entry points; Major 3 by the browser's own
computed accessibility tree against freshly built assets. The rework stayed exactly in scope,
touched nothing it was not asked to touch, added no new debt, and documented itself honestly —
including its own verification limits. Gates are green at 711/2607, and the frontend build gate
is now runnable and green for the first time.

One new **Minor** (#9) surfaced, which I missed at the initial review; it is a follow-up, not a
blocker, and I have set out above why it is a Minor rather than a second Major. The
recommendation is the one the initial review said it would have reached absent the three Majors.

The approval decision is the **Project Owner's**; the Reviewer does not approve.

- **Project Owner decision / date:** _pending_

## Handoff
- **Inputs:** PRD-06 (Approved, Owner 2026-08-12); design-06 (Approved, PM 2026-08-12);
  plan-06 (PE self-certified, seven ✋ items Owner-ratified); ADR-015/016/017 (Accepted,
  Owner 2026-08-12); ADR-003/004/005/009/010/011/012/013/014; `docs/tasks/retry-replay-tasks.md`
  (T1–T46 with completion notes, incl. T46's nine-item flagged-tension list);
  `docs/standards/{review,coding,testing,planning,documentation,architecture,design}.md`;
  `docs/questions/prd-06-q-06-01/02/03`; `docs/questions/prd-07-q-07-01-mode-switch-consequences.md`
  (read for Ruling 2 only); the branch diff `ec120b3..f5b2a07` (136 files).
- **Outputs:** this review.
- **Dependencies:** none new.
- **Outstanding Questions:** none for the Product Manager or Designer — no requirement gap and
  no design-spec defect was found (Ruling 1 is resolved inside design-06's own text).
  **Minor 5** routes to the **Principal Engineer** as a follow-up (`DeliveryResource.created_at`).
  **Minor 7** routes to the **Project Owner** (ratify or revert the `review.md` amendment).
  **Minor 8**'s three obligations belong on **feature #7's** task plan.
- **Next Agent:** ~~Senior Developer — fix Majors 1–3, then return for re-review.~~
  **Superseded by the Re-review (2026-08-22):** Majors 1–3 verified closed. **Next Agent:
  Project Owner** — takes the approval decision on *Approve with follow-ups*, accepting the ten
  follow-ups tabulated in *What carries forward*. The Reviewer does not approve.

### Re-review handoff (2026-08-22)
- **Inputs:** commits `e8aef31` / `26dc7a4` / `378a95c` and the diff `f5b2a07..HEAD` (7 files);
  T11/T24/T37 rework notes in `docs/tasks/retry-replay-tasks.md`; design-06 Flow D + Screen 4 +
  §Accessibility; plan-06 §Validation; PRD-06 AC2/AC18; `docs/standards/review.md`; review-04's
  re-review as the rework-recording precedent.
- **Outputs:** the *Re-review (2026-08-22)* section above; recommendation revised
  **Request changes → Approve with follow-ups**.
- **Verification performed:** all six previous gates re-run plus `pnpm run build` (now
  runnable — host Node v22.23.2); a live headless-Chromium accessibility-tree check of
  `ReplayDialog.vue` from both entry points against freshly built assets; a real browser replay
  round-trip from both entry points confirming `back()`, the `Referer`, and the success toast;
  numeric confirmation that `worstCaseSpan()` is unchanged at 117 360 s (32.6 h).
- **Environment note:** the live checks required temporary, fully reverted dev-database changes
  (a second destination on proxy 2, a password swap on user 1, and the replay rows/queued jobs
  they produced). All were removed and the original password hash restored; the source tree was
  never modified. The stale `public/build` bundle was rebuilt — `/public/build` is gitignored.
- **Outstanding Questions:** unchanged — **Minor 5** → Principal Engineer; **Minor 7** →
  Project Owner; **Minor 8** → feature #7's task plan. **Minor 9** (new) → Senior Developer,
  non-blocking. `docs/status.md` needs an orchestrator refresh (stale gate numbers; the T37/T24
  conflict it lists as needing a ruling is resolved and implemented).
