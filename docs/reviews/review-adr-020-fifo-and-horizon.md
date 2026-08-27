# Review: Laravel Horizon and ADR-020 (FIFO parallel fan-out, by-reference delivery jobs) — branch `feat/horizon`, PR #18

- **Reviewer / date:** Reviewer, 2026-08-26
- **Scope:** `feat/horizon` at `cc2422d`, diff `main...feat/horizon` — 13 commits,
  45 files changed, +3367/−209. No migration, no data-model change, one new
  Composer dependency (`laravel/horizon ^5.48`).
  Two pieces reviewed together because the second exists only because the first
  exposed it:
  1. **Horizon** — commits `b98a8ab`, `2a101d5`, `3a3304c`.
  2. **ADR-020 and its implementation** — commits `c7e6d55`, `6e0cbdf`, `4105eff`,
     `70d667a`, `bbc7762`.
  Also on the branch: `docs/status.md` updates and four `chore:` commits of
  agent-memory files.
- **Inputs verified:**
  `docs/architecture/adr-020-fifo-advancer-job-duration-and-claim-lease-safety.md`
  (**Accepted, Project Owner, 2026-08-26**, through Revisions A and B — the
  specification this implementation is reviewed against, in particular its
  § Decision 1–9 and its § Impact change set) ·
  `docs/architecture/adr-011-per-proxy-fifo-dispatch-mechanism.md` (Decisions 2 and 3,
  partially superseded here) ·
  `docs/architecture/adr-016-fifo-composition-under-retry-and-replay.md`
  (Decision 1, amended here) · ADR-013 (divergence-gated dispatched-output store) ·
  ADR-014 Decision 7 (the cleaned-parent guard) · ADR-015 Decision 5 (no payload
  bytes in a delivery job) · `docs/product/prd-06-retry-replay.md` (AC6, AC10, AC17) ·
  `docs/design/design-06-retry-replay.md` (Screen 2, "FIFO head-of-line note") ·
  `docs/fixes/fifo-advancer-duration-and-settle-race.md` ·
  `docs/stack/stack.md` · `docs/status.md` · `docs/standards/`.

  **No PRD, design spec or task plan exists for this branch, and none is expected.**
  This is Owner-directed operational work carried deliberately outside the dev-team
  pipeline, plus one architectural ruling it uncovered. ADR-020 § Impact is the
  acceptance criteria. That is recorded here so a later reader does not read the
  absence of a task plan as a skipped gate.

## Gate results (run by the Reviewer, not taken from the notes)

| Gate | Command | Result |
|---|---|---|
| Backend suite | `./vendor/bin/sail test --parallel` | **880 passed, 4112 assertions** — exactly as claimed |
| Code style | `composer lint` | `{"tool":"pint","result":"passed"}` |
| Static analysis | `composer types:check` | `{"tool":"phpstan","result":"passed","errors":0}` (level 7) |

Frontend gates were not run: this branch changes no frontend source file.

**A green suite is deliberately given little weight in this review.** `phpunit.xml`
sets `QUEUE_CONNECTION=sync`, which makes `dispatch()` run inline, so the FIFO
acceptance suite passes whether or not parallel fan-out is correct. ADR-020 § Tests
names this as a hazard rather than a reassurance, and this review treats it that way:
every conclusion below rests on reading the code and on tests that drive the state
machine directly, not on the suite being green.

## Does the FIFO settlement guarantee still hold? — the central question

**Yes. I am confident the guarantee holds, and this section records exactly what
convinced me, so the reasoning can be checked rather than trusted.**

The guarantee under review is ADR-020 § "The FIFO guarantee this ADR is written
against", points 1–6, which restate PRD-06 AC6 and ADR-011 Decision 2. The two
load-bearing points are 3 ("the line advances on settlement, not on dispatch") and
4 ("a retrying event holds the line for its whole retry schedule").

### What the code actually does

`app/Actions/AdvanceProxyFifoQueue.php:39` — `handle()` claims a row, runs
`ProcessIngestedWebhook`, then calls `settleOrHold()`. Since
`app/Actions/DeliverStep.php:44` now only enqueues, `ProcessIngestedWebhook` returns
with every delivery of the dispatch still `pending`.

`app/Actions/DeliverToDestination.php:315` — `settleFifoLineIfComplete()` treats
`pending` and `retrying` as the open statuses. `app/Actions/AdvanceProxyFifoQueue.php:161`
— `hasNonTerminalDeliveries()` uses `DeliveryStatus::isTerminal()`. Both therefore
count a merely-enqueued delivery as open. That is the single fact the whole guarantee
turns on, and it is correct: **an enqueued-but-unattempted delivery holds the line
exactly as a retrying one does.** Point 3's distinction between "settled" and
"enqueued" is preserved because the advancer's completion test is a query over
delivery *state*, never over what it dispatched.

`claimNext()` (`AdvanceProxyFifoQueue.php:66`) is unchanged, and its busy gate still
treats an `awaiting_retry` row as busy. So while event 1's deliveries are in flight,
event 2 cannot be claimed — by the advancer's self-dispatch, by the sweeper's nudge
(pass (b) excludes any proxy with a held row), or by a concurrent advancer.

`SweepStalledFifoDispatches` pass (a) reaps only `claimed` rows past a lease; an
`awaiting_retry` row has no lease and is structurally invisible to it. Pass (c)
releases a held row only when the dispatch has zero `pending`/`retrying` deliveries —
the same predicate. So the liveness net cannot advance the line early either.

### The four-way interleaving, walked

Both actors that can close out a held row — the advancer's `settleOrHold()` and a
delivery's `settleFifoLineIfComplete()` — are compare-and-sets keyed on an expected
prior status, and each dispatches the next advancer **only** when its CAS affected a
row. I walked all four orderings of "last delivery settles" against "advancer
publishes the hold":

1. **All deliveries terminal before the advancer's first check.** First check is
   false, `settleAndAdvance($id, Claimed, …)` CASes `claimed → settled` and advances.
   The delivery's own `settleFifoLineIfComplete()` found the row `claimed`, so its CAS
   (keyed on `awaiting_retry`) affected zero rows and did not advance. **One advance.**
2. **Last delivery settles between the first check and the hold-publish.** The
   delivery's CAS again finds `claimed` and affects zero rows. The hold-publish
   succeeds, the re-check is false, `settleAndAdvance($id, AwaitingRetry, …)` settles
   and advances. **One advance.** This is the case the re-check exists for, and it is
   why the ADR requires the hold to be published *before* the re-check.
3. **Last delivery settles after the hold-publish.** Its CAS finds `awaiting_retry`,
   succeeds, settles and advances. The advancer's re-check may or may not see it; if it
   does, its own CAS keyed on `awaiting_retry` affects zero rows because the row is
   already `settled`. **One advance.**
4. **Deliveries still open at the re-check.** The row stays `awaiting_retry`; whichever
   delivery settles last later takes case 3. **One advance, later.**

In every ordering the hold path and the settle path are mutually exclusive by
construction, and the advance is dispatched exactly once. **The hold and settle paths
cannot both fire.**

### The race the rewrite was written to close

The old settle branch was `$claimed->update([...])` — a blind update by primary key on
a stale in-memory model. A late-finishing advancer whose claim had been reaped could
flip a row another advancer was actively working straight to `settled` and dispatch the
next advancer, starting event 2 while event 1 was still being delivered. The new
`settleAndAdvance()` (`AdvanceProxyFifoQueue.php:174`) is
`->whereKey($id)->where('status', $from)->update(...)` with the dispatch gated on
`$affected > 0`. **That closes the race rather than relocating it**: there is no
remaining path from a stale advancer to either a status flip or an advance dispatch.
`tests/Unit/Actions/AdvanceProxyFifoQueueTest.php::test_settle_or_hold_a_stale_advancer_cannot_settle_a_row_it_no_longer_holds`
pins it, and I confirmed by inspection that this test genuinely fails against the old
blind update: the row it drives is `pending`, all deliveries terminal, so the old code
would have flipped it to `settled` and pushed an advancer, and the test asserts neither
happens.

### What this analysis does not cover, stated plainly

PHPUnit on a single connection cannot interleave two live claim transactions, so none of
the above is demonstrated under real concurrency. This is the standing review-04 backlog
item, and ADR-020 § Impact acknowledges it rather than claiming coverage it does not
have. My conclusion rests on the compare-and-set structure being total — every write to
`fifo_dispatches.status` on this path is keyed on an expected prior value — which is a
property of the code that does not depend on the interleaving being reproducible in a
test.


## The acceptance-suite blast radius

ADR-020 predicted the FIFO acceptance tests would pass unchanged. They did not, because
`Queue::fake()` cannot be scoped to exclude a single action: Laravel's job-class matching
tests `$job instanceof $class` against the pushed `JobDecorator` wrapper, which is never an
instance of the wrapped action. Every test faking the queue therefore also captured the new
`DeliverToDestination` push. The developer's answer was
`tests/Concerns/DrainsQueuedDeliveries.php`, applied across twelve test classes.

That is the widest change on this branch and the place a weakened test would most easily
hide, so it was checked directly rather than taken on the developer's word.

**The trait is a faithful stand-in for a worker.** It drains via
`Queue::pushed(ActionManager::$jobDecorator, ...)`, filters on `$job->decorates(DeliverToDestination::class)`,
and invokes `asJob(...$job->getParameters())` — the same entry point `JobDecorator::handle()`
calls in production, with the same arguments. It does not reach past the queue to call
`handle(DeliveryUnit $unit)` directly, so the by-reference resolution path is exercised
rather than bypassed.

**Method-by-method audit.** Every test method across the twelve classes was classified by
whether it calls `Queue::fake()` **in its own body** (a class-level or comment-level mention
does not count), whether it drives delivery through the now-queued path
(`ProcessIngestedWebhook`, `DeliverStep`, or an HTTP post), and whether it asserts
delivery-state outcomes (`DeliveryAttempt`, `DeliveryStatus`, `delivery_attempts`).

The failure mode being hunted was a test that fakes the queue, drives the queued path,
asserts a delivery outcome, and never drains — which would now assert a state that no longer
occurs, and pass vacuously.

**Result: no such test exists.** Three candidates surfaced and all three are false positives
on inspection:

- `RetryEngineAcceptanceTest::test_two_destinations_one_fails_only_the_failed_one_is_retried`
  — no `Queue::fake()` at all; the match came from other methods in the same class. Runs
  inline under the sync driver, so its assertions are genuine.
- `FifoRetryCompositionAcceptanceTest::test_an_async_proxys_two_events_retries_interleave_freely_and_never_delay_each_other`
  — the match was the **comment** "No Queue::fake()", which documents precisely why the test
  does not need to drain.
- `ReplayAcceptanceTest::test_a_redelivered_replay_processing_job_creates_no_duplicate_delivery_rows_or_attempts`
  — likewise no `Queue::fake()`; both attempt-count assertions and the idempotency re-run are
  real.

Tests driven by `RetryDelivery` or `SweepDueRetries` need no drain and correctly have none:
those paths were already by-reference before this change and still execute inline.

**No finding.** The drain calls are placed after the triggering call and before the
assertions in every case examined, and no test asserts delivery state it can no longer
reach.

## The three superseded tests

ADR-020 named two; the developer superseded three and recorded the reason for each. All are
in `tests/Unit/Actions/DeliverStepTest.php`.

**1. `test_fifo_proxy_runs_each_delivery_inline_without_queueing` → `test_fifo_proxy_also_dispatches_each_delivery_onto_the_webhooks_queue`.**
At least as strong. The old test asserted the negative that nothing was queued, which is the
exact behaviour ADR-020 removes; the replacement asserts two pushes **and pins the queue
name** via `assertPushedOn(config('ingest.webhooks_queue'), 2)`, which the original could not
have checked. Legitimate supersession.

**2. `test_builds_exactly_n_units_each_carrying_the_matching_delivery_rows_id` → `test_pushes_the_delivery_id_and_attempt_number_one_for_each_delivery_no_delivery_unit`.**
Stronger. It asserts the same identity mapping — every delivery row's id appears exactly
once — at the job-parameter level rather than on a constructed `DeliveryUnit`, and
additionally pins `$params[1] === 1`, so a future change that dispatched a wrong attempt
number would now fail here. It also incidentally guards Decision 9: the parameters are read
positionally as scalars, so a model argument would break it.

**3. `test_fifo_one_destination_failing_does_not_abort_the_loop` → `test_fifo_one_destination_failing_does_not_prevent_the_others_dispatching`. Weaker in isolation — recorded as an observation, not a defect.**

The original drove an actual transport failure against one destination and asserted the
remaining destinations were still delivered. The replacement fakes no failure at all: it
creates three destinations and asserts `assertPushed(3)`, which makes it a near-duplicate of
the first test with a different count. The word "failing" in its name is no longer
represented by anything the test does.

This is defensible and is not being raised as a finding to fix, for two reasons, both
checked rather than assumed:

- **The property became structurally unreachable at this layer.** `DeliverStep` now only
  dispatches; no HTTP send happens inside its loop, so there is no in-loop transport error
  left for a destination to fail with. A test cannot exercise a failure mode the code can no
  longer enter.
- **The behaviour is still covered where it can actually occur.**
  `RetryEngineAcceptanceTest::test_two_destinations_one_fails_only_the_failed_one_is_retried`
  drives a genuine 500 against one of two destinations and asserts the failing delivery
  reaches `Failed` with two attempts while the succeeding one reaches `Succeeded` with one.
  That is the real property, tested end to end, and it runs inline rather than under a fake.

**Nit:** the replacement's name promises a failure scenario it does not contain. Renaming it
to describe what it asserts — that every destination is dispatched independently — would stop
a future reader trusting coverage that lives elsewhere.

## The timing chain and its enforcement

Four values must stay ordered for this branch to be correct:
`DeliverToDestination::TIMEOUT_SECONDS` (15) < every supervisor `timeout` (60) <
`ingest.fifo_lease_seconds` (90) < `queue.connections.redis.retry_after` (180).

Two of those links are correctness, not tuning. If `retry_after` fell below a worker
`timeout`, Redis would re-reserve a job the worker still holds and a second worker would
re-send webhooks the first had already delivered. If the `default` supervisor's `timeout`
rose above the lease, a live advancer's claim would become reapable by the sweeper while it
was still running — the original defect ADR-020 exists to close.

`tests/Unit/Config/QueueTimingTest.php` enforces both, and — the specific thing worth
checking — it does **not** stop at `horizon.defaults`. It reconstructs each environment's
effective configuration by merging every `horizon.environments.*` override over the
per-supervisor defaults, exactly as Horizon itself resolves them, and asserts against the
merged result. An override that raised `timeout` in production alone, leaving `defaults`
untouched, would therefore be caught. That is the realistic way this regresses, and it is
covered. Verified by running it: 2 tests, 12 assertions, passing.

**No finding.**

## Horizon dashboard access

`App\Http\Middleware\AuthenticateHorizon` checks HTTP Basic credentials against
`horizon.basic_auth.*`, fails closed when either is empty, and runs both `hash_equals`
comparisons unconditionally rather than behind `&&`, so response time does not vary with how
much of a credential was correct.

The part that could have failed silently is the second path. Horizon guards its own routes
with the `viewHorizon` gate independently of the middleware listed in `config/horizon.php`,
so a gate returning `true` would leave the dashboard open to anyone the moment that
middleware entry was removed or reordered. It does not:

```php
Gate::define('viewHorizon', function ($user = null) {
    return AuthenticateHorizon::passes(request());
});
```

Both paths consult the same predicate, and the `$user` argument is deliberately ignored —
this project has no superadmin role, so being an authenticated team member must not by itself
confer operational access. `tests/Feature/Horizon/HorizonDashboardAccessTest.php` covers the
unconfigured, wrong-username, wrong-password, missing-header and authenticated-user cases.

**No finding.**

## The Owner's security requirement: no payload in the queue

The requirement is that the payload must never sit in plaintext in a long-term store. The
Owner rejected encrypting the queue message and required the payload removed from it
instead — which also resolves a second objection they raised, that a queue driver's message
size limit (SQS caps at 256 KiB against this application's 50 MiB inbound cap) makes
payload-in-job unportable.

**Every dispatch on this branch carries scalars.** Enumerated rather than sampled:

- `DeliverStep.php:46` — `DeliverToDestination::dispatch($deliveryId, 1)`
- `DeliverToDestination.php:291` — `RetryDelivery::dispatch($delivery->id, $nextAttemptNumber)`
- `DeliverToDestination.php:327` — `AdvanceProxyFifoQueue::dispatch($delivery->proxy_id)`

A search for any dispatch of a `DeliveryUnit` returns nothing. The queue entry point is
`DeliverToDestination::asJob(int $deliveryId, int $attemptNumber)`, typed to integers, so a
model cannot be passed without changing the signature. That matters more than it looks:
`JobDecorator` applies `SerializesModels` to top-level parameters, so a model argument would
silently opt into hydration — the outcome ADR-020 Decision 9 rejected — with no error raised.
The scalar type hint is what makes that a compile-time impossibility rather than a convention.

`tests/Unit/Actions/DeliverStepTest.php:129` asserts the property directly and correctly:
it plants distinctive random markers in both the event body and a header value, dispatches,
and asserts neither string appears in `serialize($job)`. Asserting against the **serialized**
form rather than the in-memory object is the distinction that makes this test meaningful —
an in-memory check would pass even if the payload were being serialized into the queue.

**No finding.** The requirement is met, and header values are covered alongside payload
bytes.

## Findings

| # | Severity | Finding |
|---|---|---|
| 1 | Nit | **Fixed.** `test_fifo_one_destination_failing_does_not_prevent_the_others_dispatching` (`tests/Unit/Actions/DeliverStepTest.php`) named a failure scenario it no longer contains. The property is structurally unreachable at this layer and is covered at acceptance level; only the name misled. Renamed to `test_fifo_dispatches_every_destination_independently`, which describes what it asserts. Its docblock already recorded the supersession and is unchanged. |

No Majors. No Minors.

## Recommendation

**Approve.**

The central question — whether parallel fan-out breaks the FIFO settlement guarantee — is
answered in the affirmative for the guarantee: event 2 still cannot begin until every
delivery of event 1 is terminal, and a retrying destination still holds the line for its
backoff. The rewritten `settleOrHold()` closes the race it was written to close rather than
relocating it, because every write to `fifo_dispatches.status` on that path is now keyed on
an expected prior value.

Two things this review could not establish, stated plainly rather than papered over:

1. **No test on this branch demonstrates real concurrency.** PHPUnit on a single connection
   cannot interleave two live claim transactions. The conclusion above rests on the
   compare-and-set structure being total — a property of the code, not of a reproduction.
   This is the standing review-04 backlog item and ADR-020 acknowledges it rather than
   claiming coverage it does not have.
2. **`QUEUE_CONNECTION=sync` means a green suite is weak evidence for this change
   specifically.** Jobs run inline under test, so the queued and inline paths are
   indistinguishable to most of the suite. The two reflection-driven tests in
   `AdvanceProxyFifoQueueTest` were written precisely because of this, and they do drive the
   state machine directly rather than through a dispatch. They are the only tests here whose
   passing is meaningful evidence about the race.

Gates at time of writing, run rather than taken from the developer's notes: `composer lint`
passes, `composer types:check` (PHPStan level 7) passes with zero errors,
`./vendor/bin/sail test --parallel` is 880 passed of 880, 4112 assertions.
