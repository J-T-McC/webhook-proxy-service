# Fix: async-replay-dispatch-horizon-gap

- **Date:** 2026-08-25
- **Author:** Senior Developer
- **Reported by:** Principal Engineer

## Problem
An Async replay dispatches by reference — `ProxyEventReplayController::store()`
(`app/Http/Controllers/ProxyEventReplayController.php:94`) does
`ProcessIngestedWebhook::dispatch($event->ingest_id, $dispatchUuid)`, so the job
re-reads the parent `webhook_events` row when it eventually runs. The only thing
protecting that read hop from a concurrent GC pass was hold H5's `pending`-delivery
age qualifier, gated by `retention.dispatch_horizon_minutes` — **60 minutes**.

A replay queued behind roughly an hour of worker backlog, on an event that expires
before the job runs, silently strands: the user sees "Replay started.", the
`ProcessIngestedWebhook` job's eventual run finds the parent already erased and
no-ops (AC17's guard), and the replay's `deliveries` rows sit `pending` forever
(nothing terminalizes a stranded Async `pending` delivery — see
`docs/questions/prd-05-q-05-05-async-partial-fanout-hold-gap.md`). That violates
PRD-06 AC18. FIFO replays are fully protected by H2's unconditional hold on any
non-`settled` `fifo_dispatches` row — the asymmetry was accidental.

Full analysis, options, and ruling: `docs/questions/prd-05-q-05-05-async-partial-fanout-hold-gap.md`
(RESOLVED, Principal Engineer, 2026-08-25), instance (b). Per that ruling, no ADR,
data-model change, or requirement change is implicated — this is a config default
plus one companion invariant, both inside the Principal Engineer's authority.

Rider (same branch, separate commit): `routes/console.php` did not schedule
`queue:prune-failed`, so `failed_jobs` rows — which hold a plaintext copy of a
queued Async delivery unit on any uncaught throw (`docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md`,
PRD-05 deferred concern D2, ruling E1) — persisted indefinitely with no bound at
all.

## Cause
`config/retention.php:45`'s `dispatch_horizon_minutes` default (60) was sized
against H4 (an Async event's *original* dispatch, whose `deliveries`/
`delivery_attempts` rows are created at capture time, so they age alongside the
30-day retention window) and was never re-examined against H5's `pending` clause
for a **replay** dispatch, whose `deliveries` row is created at *replay* time —
minutes to hours old when GC evaluates it, not thirty days old. A horizon shorter
than one GC cycle (24h) leaves a real window in which a backlogged replay job can
be erased out from under it.

Separately, `RetentionPolicy::windowFor()` is `public` and overridable (a V5
extension point), so any future per-team retention-window lever could shrink the
window below the horizon without any guard noticing — review-05 Nit 9.

## Chosen behaviour
Exactly the Principal Engineer's Option 3, implemented as specified:

1. **`retention.dispatch_horizon_minutes` default raised 60 → 1440** (one full GC
   cycle — the daily 02:00 pass cadence). Any dispatch, original or replay, now has
   at least 24 hours of queue latency to survive before H4/H5 can release it — three
   orders of magnitude beyond normal operation, comfortably beyond a working day's
   outage, and still bounded at 1/30 of the default 30-day retention window (down
   from a 720× safety margin to 30×, per the ruling).
2. **A fail-loud horizon-vs-window invariant**, added to
   `PurgeExpiredPayloads::purgeForTeam()` beside `RetentionPolicy::cutoffFor($team)`
   (`requireHorizonBelowWindow()`): the resolved horizon must be strictly less than
   the team's resolved retention window, or `RuntimeException`. Placed here —
   against the *resolved* window, once per team, matching the existing resolve-once
   shape — rather than at the `retention.dispatch_horizon_minutes` config read,
   specifically so a future per-team `RetentionPolicy::windowFor()` override (V5)
   cannot bypass it by skipping straight to `cutoffFor()`. **Closes review-05
   Nit 9.** Compares resolved *durations* (`$horizonMinutes` vs.
   `$this->policy->windowFor($team)->totalMinutes`) rather than the `$cutoff`/
   `$horizon` time points — the latter are each derived from a separate `now()`
   call a few microseconds apart, which would let an exactly-equal horizon and
   window slip past the guard on timing noise.
3. **`config/retention.php`'s docblock corrected** to name both H4 and H5, and to
   state that H5's `pending` clause is now the config's only materially load-bearing
   role in practice: for any event that has already cleared H1 (past the retention
   window), H4's age branch is trivially satisfied once the horizon is bound below
   the window, so H4 reduces to its `whereExists` branch (event has any
   `delivery_attempts` row at all).
4. **Rider:** `routes/console.php` now schedules `queue:prune-failed --hours=168`
   (7 days) daily. Chosen value: 7 days gives on-call a full week — weekends
   included — to notice and triage a failure before its `failed_jobs` record is
   pruned (D2 item 4's diagnosability requirement), while still bounding what was
   previously an *unbounded* plaintext retention down to roughly 1/4 of the 30-day
   payload retention window. This is the agreed mitigation per ruling E1
   (`docs/questions/prd-05-q-05-06-failed-jobs-payload-reach.md`) — it bounds
   exposure, it does not scrub or encrypt anything; that is roadmap #10's work.

### Consequence for the existing H4 acceptance test
`RetentionInFlightHoldsTest::test_h4_horizon_hold_blocks_erasure_until_past_the_dispatch_horizon_when_no_attempts_exist`
manufactured its scenario by setting the horizon (35 days) *above* the retention
window (30 days) to decouple the two — exactly the configuration
`requireHorizonBelowWindow()` now refuses to run with. This is not a numeric
tuning problem: with the invariant enforced, an event cleared by H1 (past the
window) is, by construction, always also past a horizon that is necessarily
shorter, so H4's age branch can no longer be exercised in isolation under any
valid configuration — precisely the "H4 reduces in practice to its `whereExists`
branch" consequence the Principal Engineer's ruling already names. Replaced with
`test_h4_horizon_hold_never_blocks_an_expired_zero_attempt_event_once_the_horizon_is_bound_below_the_retention_window`,
which pins that dominance relationship directly under a valid config instead of
via an invariant-violating one. No requirement, interface, or data model changed;
this is test-only.

## Fix
- `config/retention.php` — `dispatch_horizon_minutes` default `60` → `1440`;
  docblock corrected to name H4 and H5 and describe H4's reduction.
- `app/Actions/PurgeExpiredPayloads.php` — added `requireHorizonBelowWindow()`,
  called once per team in `purgeForTeam()`; class docblock updated to describe it.
- `routes/console.php` — added the `queue:prune-failed --hours=168` daily schedule
  entry.
- Checked every other consumer of `retention.dispatch_horizon_minutes`
  (`RetentionInFlightHoldsTest`, `RetryReplayRetentionInterplayTest`,
  `PurgeExpiredPayloadsTest`) — all read the value via `config()` rather than
  pinning `60`, so they were unaffected by the default change, per the ruling's own
  claim (confirmed, not assumed) — with the single exception above.

## Verification
- New regression tests, confirmed to fail against the pre-fix code (60-minute
  default, no invariant, no schedule entry) and pass after:
  - `tests/Feature/Retention/RetryReplayRetentionInterplayTest::test_an_async_replay_dispatch_survives_queue_backlog_past_the_old_sixty_minute_horizon`
    — the actual reported bug: an Async replay's `deliveries` row backdated 90
    minutes (past the old 60-minute horizon, inside the new 1440-minute one) must
    not be erased by a GC pass before its `ProcessIngestedWebhook` job can run.
    Confirmed failing before the fix (`Failed asserting that true is false.`
    against `$this->isCleaned($event)`).
  - `tests/Unit/Actions/PurgeExpiredPayloadsTest::test_run_throws_when_dispatch_horizon_minutes_is_at_or_above_the_resolved_retention_window`
    and `::test_a_dispatch_horizon_minutes_strictly_below_the_resolved_retention_window_is_allowed`
    — the new invariant. Confirmed the first failed before the fix
    (`Failed asserting that exception of type "RuntimeException" is thrown.`).
  - `tests/Feature/Queue/PruneFailedJobsScheduleTest` (both tests) — the prune
    schedule's registration/cadence/argument, and the command's own effect at that
    argument. Confirmed the registration test failed before the rider
    (`Expected queue:prune-failed to be scheduled. Failed asserting that null is
    not null.`).
  - `tests/Unit/Config/RetentionConfigTest::test_dispatch_horizon_minutes_defaults_to_1440_when_env_not_set`
    — updated from the old pinned `60`.
  - `tests/Feature/Retention/RetentionInFlightHoldsTest::test_h4_horizon_hold_never_blocks_an_expired_zero_attempt_event_once_the_horizon_is_bound_below_the_retention_window`
    — replaces the invariant-violating H4 test (see above).
- `./vendor/bin/sail test --parallel` (full suite): **721 passed / 721, 2629
  assertions** — fully green.
- `composer lint` (Pint): passed.
- `composer types:check` (PHPStan level 7): passed, 0 errors.

## Follow-ups
None beyond what `docs/questions/prd-05-q-05-05-async-partial-fanout-hold-gap.md`
already records as owned by the Principal Engineer (the plan-05 §Q-05-03(i) and
§Services documentation pointers, and the `docs/status.md` row #5 update) — those
are documentation follow-ups on that question doc, not implementation, and are
explicitly out of scope for this fast-path fix.
