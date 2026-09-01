# Fix: dispatched-output-redelivery-row-assertion

- **Date:** 2026-08-31
- **Author:** Senior Developer
- **Reported by:** CI (intermittent failure on PR #57, which is unrelated to it)

## Problem
`Tests\Feature\Retention\DispatchedOutputTest::test_the_raw_webhook_events_row_and_output_row_count_are_unchanged_by_a_redelivery`
failed intermittently in CI and passed on a re-run, with a diff naming one column:

```
- 'updated_at' => '2026-09-01 05:07:04'
+ 'updated_at' => '2026-09-01 05:07:05'
```

Nothing about the change under test was involved. The failure was time-dependent:
whether it happened turned on how long the re-invocation took relative to the
second boundary.

## Cause
The test asserted whole-row equality on `webhook_events` across a redelivery, on
the stated premise that the output step never writes that row. The premise is
narrower than the code. `ProcessIngestedWebhook` re-runs the original-dispatch
block on a redelivery — the dispatch UUID equals the ingest id, so the block is
reached again — and that block ends in

```php
WebhookEvent::query()->whereKey($event->id)->update(['status' => WebhookEventStatus::Dispatched]);
```

which re-sets `status` to the value the row already holds. An Eloquent builder
`update()` stamps `updated_at` whether or not any column value changed, so the
row is written on every redelivery. The values all stay the same; only the
timestamp moves, and only visibly so when the redelivery lands in a later clock
second than the capture. Sub-second, the two timestamps are identical and the
assertion passes — which is why the test held from the day `status` was added
(migration `2026_08_29_000001_add_status_to_webhook_events_table`) until a slow
CI runner exposed it.

## Fix
Assert the invariant that actually holds. The test now travels one second before
the redelivery, so the `updated_at` difference is deterministic rather than
incidental, compares every column except `updated_at`, and asserts separately
that the event is still `dispatched`. The excluded column is named in a comment
with the reason, so the exclusion cannot be read as a workaround for a bug.

What the test proves is unchanged and now stated precisely: a redelivery never
rewrites the captured record — body, headers, method, content type, byte size and
received-at all stay exactly as captured — and it updates rather than duplicates
the output row.

## Verification
With the time travel in place, the previous whole-row assertion fails on every
run rather than occasionally (checked by restoring it temporarily). Suite
1165/1165, Pint and PHPStan clean.
