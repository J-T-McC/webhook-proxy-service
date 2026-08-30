# Fix: retention-fifo-dispatch-uuid-fixture

- **Date:** 2026-08-21
- **Author:** Senior Developer
- **Reported by:** Project Owner

## Problem
`RetentionInFlightHoldsTest::test_a_hold_that_reappears_between_selection_and_erase_causes_the_erase_to_affect_zero_rows`
failed with `SQLSTATE[HY000]: General error: 1364 Field 'dispatch_uuid' doesn't have a
default value`. Expected: the test's `DB::listen()`-based fault-injection fixture (a raw
`fifo_dispatches` row inserted mid-`PurgeExpiredPayloads` run, simulating a hold that
reappears between selection and the erase compare-and-set) should insert cleanly, exactly
as it did before #6.

## Cause
Feature #6, T6 (`docs/tasks/retry-replay-tasks.md`) made `fifo_dispatches.dispatch_uuid`
`NOT NULL` with no schema default (by design — every row must carry a real dispatch
identity). This test's fixture uses a raw `DB::table('fifo_dispatches')->insert([...])`
that bypasses both the `FifoDispatch` model/factory and `IngestController` (the two places
already updated in T6/T7 to supply `dispatch_uuid`), so the insert never picked up the new
required column.

## Fix
- `tests/Feature/Retention/RetentionInFlightHoldsTest.php` — added
  `'dispatch_uuid' => $event->ingest_id` to the raw `DB::table('fifo_dispatches')->insert([...])`
  fixture inside the `DB::listen()` callback, using the anchoring event's `ingest_id` (the
  same T6/T7 identity invariant every other `fifo_dispatches` row follows) rather than an
  arbitrary UUID. No other line in the callback changed — the raw-insert bypass and the
  fault-injection timing (fired from inside the `select id from webhook_events` query
  listener) are both preserved exactly as before.
- Scanned the full test suite (`grep` for any `insert`/`fifo_dispatches` co-occurrence, not
  just this one call site) for other raw `fifo_dispatches` inserts with the same latent gap:
  none found. `RetentionErasureCompletenessTest`'s two `DB::table('fifo_dispatches')`
  calls are both read-only (`->first()`), unaffected. This was the only raw insert bypassing
  the model/factory anywhere in the suite.
- No production code, migration, or the `NOT NULL` constraint was touched.

## Verification
- `./vendor/bin/sail test --filter RetentionInFlightHoldsTest`: 5 passed / 21
  assertions.
- `./vendor/bin/sail test --parallel` (full suite): **474 passed / 474 total, 1635
  assertions** — fully green (up from 473/474 with 1 error before this fix).
- `composer lint` (Pint): passed.
- `composer types:check` (PHPStan level 7): passed, 0 errors.

## Follow-ups
None.
