# Fix: fifo-advancer-duration-and-settle-race

- **Date:** 2026-08-26
- **Author:** Senior Developer
- **Reported by:** ADR-020 (Principal Engineer, Accepted — Project Owner, 2026-08-26), found while configuring Horizon on `feat/horizon` (PR #18)

## Problem
In FIFO mode `DeliverStep` sent to each destination inline and sequentially, so one
`AdvanceProxyFifoQueue` job performed N sequential outbound HTTP sends of up to 15
seconds each. `AdvanceProxyFifoQueue` stamps its claim lease
(`ingest.fifo_lease_seconds`, 90s default) once at claim time and never renews it,
while `SweepStalledFifoDispatches` reaps any `claimed` row past its lease every
minute. The Horizon `default` supervisor's `timeout` was therefore under two
constraints at once — above the longest legitimate job (`N × 15`) and below the
lease (90) — jointly unsatisfiable for N ≥ 5 destinations. Three further defects sat
alongside it:

- `AdvanceProxyFifoQueue::settleOrHold()`'s settle branch was a blind `update()` by
  primary key, not a compare-and-set — unlike its hold branch, which was correctly
  keyed on `->where('status', Claimed)`. Under parallel fan-out this lets a stale
  advancer flip a row another advancer has re-claimed and is actively working
  straight to `settled`, advancing the line while the first advancer's event is still
  being delivered.
- `ingest.fifo_lease_seconds` was read as a bare `(int) config(...)` in two places
  with no positivity guard. A blank `INGEST_FIFO_LEASE_SECONDS=` casts to `0`, making
  every claim instantly reapable AND making `WithoutOverlapping->expireAfter(0)` mean
  no lock expiry at all — a permanently leaked per-proxy lock on an ungraceful worker
  crash, exactly the deadlock the advancer's own docblock warns about.
- The advancer's `WithoutOverlapping` middleware never called `->dontRelease()`, so a
  redundant advancer that lost the lock was released back onto the queue and then
  failed `MaxAttemptsExceeded` under `tries => 1`, landing in `failed_jobs` — contrary
  to the docblock's and `config/horizon.php`'s claim that it is "simply dropped."

## Cause
The inline sequential fan-out had no requirement behind it (the Owner confirmed
ordering is required only between events on a proxy, never between destinations
within one event) and was quietly relied on to make `settleOrHold()`'s read-then-write
race-free: under inline delivery, every attempt-1 send had already completed by the
time the advancer reached `settleOrHold()`, so there was nothing left to race against.
Parallel fan-out removes that accidental guarantee.

## Fix
Per ADR-020 (§Decision, full reasoning and alternatives there):

- `app/Actions/DeliverStep.php` — removed the `processing_mode` branch entirely.
  Every delivery, in both modes, is now `DeliverToDestination::dispatch($delivery->id, 1)`
  onto `config('ingest.webhooks_queue')`, `afterCommit()`. The advancer's own work is
  now bounded by local database/CPU time, making the `timeout` constraint satisfiable
  again with a large margin — the deletion is the fix; no new mechanism was needed
  because `AdvanceProxyFifoQueue`'s `awaiting_retry` hold and
  `DeliverToDestination::settleFifoLineIfComplete()` were already written mode-neutral
  (ADR-016 Decision 1).
- `app/Services/DeliveryUnitResolver.php` (new) — the single resolver of a
  `DeliveryUnit` from `(Delivery, attemptNumber)`, extracted from what was inline in
  `RetryDelivery::handle()`: guards `payload_cleaned_at`, loads the destination
  `withTrashed()`, takes headers from the captured event, and resolves bytes via
  `StoredPayloadLookup::dispatchedBytesFor()`. Returns `null` (never an empty payload)
  to signal a cleaned parent. Used by both delivery entry points so attempt 1 and
  attempts 2..N are provably identical.
- `app/Actions/DeliverToDestination.php` — gained `asJob(int $deliveryId, int $attemptNumber)`,
  the by-reference queue entry point `JobDecorator::handle()` calls in preference to
  `handle()`. Resolves via the shared resolver; a cleaned parent terminalizes via a
  new `terminalizeCleaned()` that reuses the existing `transition()` CAS (already
  keyed on `pending`/`retrying`, so correct for attempt 1 without a separate status
  set). `handle(DeliveryUnit $unit)` and its send/settle/FIFO-completion logic are
  unchanged.
- `app/Actions/RetryDelivery.php` — its inline unit-building block now calls the
  shared resolver. Its `retrying` guard, stale-fire return and
  `terminalizeCleaned()` are unchanged; it was not merged into `DeliverToDestination`.
- `app/Actions/AdvanceProxyFifoQueue.php` — `settleOrHold()` rewritten per ADR-020
  Decision 3: publish the hold (CAS `claimed → awaiting_retry`) before re-checking,
  then settle if clear. The settle path (`settleAndAdvance()`) is now a CAS keyed on
  the expected prior status (`claimed` or `awaiting_retry`), and the next advance is
  dispatched only if the CAS affected a row. Added a private `leaseSeconds()` guard
  (mirroring `RetryPolicy::positiveConfigInt()`) as the only reader of
  `ingest.fifo_lease_seconds`; both call sites (`claimNext()`, `getJobMiddleware()`)
  route through it. Added `->dontRelease()` to the `WithoutOverlapping` middleware.
  Rewrote the docblock comment describing the advancer as returning "only once the
  whole event has been delivered," which decision 1 makes false.
- `app/Actions/SweepStalledFifoDispatches.php` — docblock only, widening the
  stuck-hold pass's description from "a `RetryDelivery` execution" to "a
  `DeliverToDestination` execution — attempt 1 or a retry."
- `config/horizon.php`, `config/queue.php`, `config/ingest.php` — comments only,
  pointing at ADR-020 and correcting the `supervisor-default` comment's now-false
  claim about N sequential HTTP sends. No configuration **values** changed: 15, 60,
  90 and 180 all stand.

## Verification
- Superseded (not weakened, per ADR-020) — replaced rather than deleted, and the
  reason recorded in each test file:
  - `tests/Unit/Actions/DeliverStepTest.php::test_fifo_proxy_runs_each_delivery_inline_without_queueing`
    → `test_fifo_proxy_also_dispatches_each_delivery_onto_the_webhooks_queue`.
  - `tests/Unit/Actions/DeliverStepTest.php::test_builds_exactly_n_units_each_carrying_the_matching_delivery_rows_id`
    → `test_pushes_the_delivery_id_and_attempt_number_one_for_each_delivery_no_delivery_unit`.
  - `tests/Unit/Actions/DeliverStepTest.php::test_fifo_one_destination_failing_does_not_abort_the_loop`
    → `test_fifo_one_destination_failing_does_not_prevent_the_others_dispatching` (with
    nothing running inline any more, there is no in-loop transport error left to
    survive; this is now the plain FIFO mirror of the existing Async case).
- New tests:
  - `tests/Unit/Actions/DeliverStepTest.php::test_no_queued_delivery_jobs_serialized_form_contains_payload_bytes_or_header_values`
    — asserts against the *serialized* pushed job, not the in-memory object.
  - `tests/Unit/Services/DeliveryUnitResolverTest.php` — attempt 1 and a retry
    resolve identical bytes on both sides of ADR-013's divergence gate (diverged
    `dispatched_payloads` row, and no row at all), plus the cleaned-parent-resolves-null
    and destination-`withTrashed()`/headers cases.
  - `tests/Feature/Delivery/DeliverToDestinationTest.php::test_as_job_resolves_by_reference_and_delivers_exactly_like_run`
    and `::test_as_job_on_a_cleaned_parent_terminalizes_without_sending_or_writing_an_attempt`
    — the cleaned-parent branch, newly reachable on attempt 1.
  - `tests/Unit/Actions/AdvanceProxyFifoQueueTest.php::test_settle_or_hold_re_check_catches_a_delivery_that_settles_in_the_hold_publish_window`
    and `::test_settle_or_hold_a_stale_advancer_cannot_settle_a_row_it_no_longer_holds`
    — decision 3's race and the stale-advancer CAS, driven directly against the
    private `settleOrHold()` (via reflection) rather than through `dispatch()`, because
    `QUEUE_CONNECTION=sync` in `phpunit.xml` makes a dispatch run inline and therefore
    indistinguishable from the pre-fix inline path.
  - `tests/Unit/Config/QueueTimingTest.php` — L1 (`retry_after` exceeds every
    supervisor's `timeout` on the redis connection) and L2 (`supervisor-default`'s
    `timeout` stays below `ingest.fifo_lease_seconds`), asserted across
    `horizon.defaults` and every `horizon.environments.*` override.
  - `tests/Unit/Config/IngestConfigTest.php` — four `positiveConfigInt`-style guard
    tests for `ingest.fifo_lease_seconds` (zero, negative, blank env, non-numeric
    env), mirroring `RetryConfigTest`/`RetryPolicyTest`'s existing pattern.
- Adapted (pre-existing tests that needed adjustment to keep testing what they
  claim, not weakened): every FIFO-mode Feature/Unit test that used `Queue::fake()`
  now also captures the newly-queued `DeliverToDestination` push (Laravel's
  job-class fake scoping — `Queue::fake([Class::class])`/`->except([...])` — does not
  match lorisleiva's `JobDecorator` wrapper via `instanceof`, confirmed empirically).
  Added `tests/Concerns/DrainsQueuedDeliveries.php` (generalising a pattern already
  established in `ProcessingModeSwitchAcceptanceTest`) to run a captured delivery job
  in place, and applied it in: `AdvanceProxyFifoQueueTest`,
  `FifoLivenessAcceptanceTest`, `FifoRetryCompositionAcceptanceTest`,
  `RetentionInFlightHoldsAcceptanceTest`, `CleanedStateReaderGuardAcceptanceTest`,
  `RetryEngineAcceptanceTest`, `ModeGatedRetryInheritanceAcceptanceTest`,
  `RetryReplayRetentionInterplayAcceptanceTest`, `ReplayAcceptanceTest`,
  `TerminalStateAcceptanceTest`, `ModeSwitchSafetyAcceptanceTest`,
  `ProcessingModeSwitchAcceptanceTest`. One test
  (`FifoRetryCompositionAcceptanceTest::test_the_heads_first_attempt_failing_holds_the_line_and_the_sweeper_leaves_it_alone`)
  had silently stopped exercising its own premise (attempt 1 actually failing) without
  going red, because it never asserted the delivery's status — restored the missing
  assertion alongside the drain.
- `composer lint` (Pint): passed.
- `composer types:check` (PHPStan level 7): passed, 0 errors.
- `./vendor/bin/sail test --parallel` (full suite): **880 passed / 880, 4112
  assertions** — fully green (865 baseline + 15 new).

## Follow-ups
None. The standing caveat ADR-020 records: PHPUnit on a single connection cannot
interleave two genuinely concurrent claim transactions, so a real-concurrency
integration test for the single-advancer window remains a backlog item; the two new
`settleOrHold` tests drive the state machine deterministically instead, which is the
achievable substitute.
