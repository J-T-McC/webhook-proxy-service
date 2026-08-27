# ADR-020: FIFO advancer job duration and claim-lease safety — parallel fan-out inside an event, by-reference delivery jobs, a race-safe settle-or-hold, and an ordered lease/timeout/`retry_after` rule (partially supersedes ADR-011 Decisions 2 and 3, amends ADR-016 Decision 1)

- **Status:** **Accepted — Project Owner, 2026-08-26.** The single remaining gate is approved:
  the partial supersession of ADR-011 positions **P4** (Decision 2) and **P5** (Decision 3),
  both Accepted, Owner 2026-08-04, together with the **amendment to ADR-016 Decision 1**
  (Accepted, Owner 2026-08-12). The Owner separately confirmed the FIFO parallel fan-out half,
  and Decisions 7 and 8 stand as ruled. **Nothing on this ADR remains outstanding with the
  Project Owner.** Implementation may proceed.
  - **Revised 2026-08-26 (Revision A)** in response to two Project Owner requirements — the
    payload must never be plaintext in a long-term store, and the job must not be exposed to a
    queue driver's message-size limit. The original gate (a), which asked the Owner to *accept*
    an outbound payload on the Redis queue, was **withdrawn** rather than re-put: Decision 7
    removes the exposure instead of trading it. See § Revision A.
  - **Revised 2026-08-26 (Revision B)** to rule the Project Owner's `SerializesModels` question,
    added as **Decision 9**. No gate; the Owner explicitly left the choice of mechanism to the
    Principal Engineer with the Decision 7 shape as the stated fallback. See § Revision B.
- **Author:** Principal Engineer
- **Date:** 2026-08-26
- **Feature:** none — this is a latent correctness defect in shipped behaviour, found while
  configuring Laravel Horizon on branch `feat/horizon` (PR #18, unmerged). Horizon does not
  cause it; Horizon made an existing mismatch visible.
- **Relationship to prior ADRs:** **partially supersedes ADR-011** (two named positions, P4 and
  P5 — see § Positions superseded) and **amends ADR-016 Decision 1** (the meaning of the
  `awaiting_retry` hold, and the concurrency safety of the settle-or-hold decision). Every
  other position of both ADRs stands, Accepted and operative, and is relied on here: the
  claim-based single advancer, the atomic `FOR UPDATE` claim, the lease plus sweeper liveness
  net, `WithoutOverlapping` as a reducer and not the ordering guard, dispatch-by-reference at
  the pipeline entry, the sidecar-table placement, the `awaiting_retry` line hold, the
  row-`id` order key, and the three sweeper passes.
- **Companions:** ADR-005 (the dispatch-timing seam and its four guardrails) · ADR-015 (the
  retry machinery whose waits the hold represents, and whose Decision 5 already forbids payload
  bytes in a delivery job's arguments) · ADR-013 (the divergence-gated dispatched-output store
  that makes a by-reference delivery job resolve totally) · ADR-014 and ADR-010 Amendment B
  (at-rest encryption of the three payload columns, and the binding `APP_PREVIOUS_KEYS` rule) ·
  ADR-003 (payload-free attempt records)

## Revision B — the `SerializesModels` question, ruled (2026-08-26)

Revision A is unchanged by this section; it is recorded below, after this one.

On approving the gate, the Project Owner raised one design question and one argument bearing
on it:

> "we could potnetially use serlize models on the job which should auto hydrate it, otherwise do
> what you were planning"

> "we could also potentially add a caching layer later if we hydrate our own way rather than
> using serlize"

**Ruled: plain identifiers. Decision 7's `(int $deliveryId, int $attemptNumber)` shape stands
unchanged, and Decision 9 below records why — including, deliberately, the parts where
`SerializesModels` turns out to work.** The honest finding is not that the trait is broken. It
is that it does work, and still buys nothing here while costing a seam.

| Prior position | Now |
|---|---|
| Decision 7 specifies `(deliveryId, attemptNumber)`; no position on `SerializesModels` | Unchanged, and now **held against the alternative** by **Decision 9**, which evaluates the trait on its merits and records the outcome so the question is not reopened |
| § Alternatives has no entry for model serialization | Gains one, losing on **grounds that will still read as reasons in a year** — it cannot carry the whole resolution, it splits one resolution across two mechanisms, it moves a class of failure outside code the application owns, and it forecloses the seam the Owner named |

## Revision A — what the Owner's two requirements changed here (2026-08-26)

The first version of this ADR asked the Project Owner to **accept** a security consequence:
that making FIFO fan-out queued would put the outbound payload onto the Redis queue, as Async
has done since #4. The Owner did not trade it away. Two requirements came back instead, and
together they change the shape of the answer rather than merely its cost.

> "unless we can encrypt the payload in the queue itself. We want to ensure that the payload
> is never available in plaintext in a long term store."

> "some queue drivers have size limits. holding the payload in the job params can cause issues
> if the driver is changed to ses or something else"

The two pull against each other if the payload stays in the job — `ShouldBeEncrypted` inflates
the serialized payload by roughly a third before the envelope, so satisfying the first makes the
second worse. A fix that trades one Owner requirement for the other is not a fix. Both are
satisfied at once by removing the payload from the job entirely, which is what Revision A does.

| Prior position | Now |
|---|---|
| Decision 1 makes FIFO fan-out queued, and the delivery job carries the payload in its arguments, as Async already does | Decision 1 is unchanged in what it does to FIFO ordering and job duration, but is now paired with **Decision 7**: **no queued job carries payload bytes in its arguments, in either mode.** The delivery job carries `(deliveryId, attemptNumber)` and resolves the bytes on the worker |
| § Alternatives: "Dispatch `DeliverToDestination` by reference for FIFO, to keep payloads off the queue" — **rejected** for breaking ADR-011 Decision 3 | **ADOPTED**, and extended to Async. The rejection was wrong: ADR-013's divergence-gated dispatched-output store already resolves the pipeline's *output* payload durably and totally, and `RetryDelivery` has resolved attempts 2..N that way in production since #6. ADR-011 Decision 3's **guarantee** is preserved; only its **mechanism** is superseded |
| Owner gate (a): accept a payload-on-the-queue exposure for FIFO proxies | **Withdrawn.** There is no queue-payload exposure left to accept, on either mode. Decision 7 *removes* an exposure Async has carried since #4 rather than extending it |
| Owner gate (b): partial supersession of ADR-011 P4 plus the ADR-016 Decision 1 amendment | **Unchanged in kind, widened by one position** — ADR-011 Decision 3 becomes **P5**. Still the only gate |
| No position on inbound body size versus queue-driver message limits | **Decision 8** states the ceiling as an operational limit and records that Async has been exposed to it since #4 |

Everything not listed above is unchanged, including the six-point FIFO guarantee below, which
Revision A does not touch.

## The FIFO guarantee this ADR is written against

Stated plainly, so it can be confirmed or corrected rather than inferred. This is the
guarantee as the Project Owner stated it on 2026-08-26 ("two events come in, event one is
processed, after all destinations have been dispatched, event 2 is processed. destination
dispatching does not need to be fifo, that can always be async", clarified on the meaning of
"dispatched" with the single word "Delivered"), and it is the guarantee ADR-011 Decision 2
and ADR-016 Decision 1 already implement.

1. **Ordering is per proxy, and only in FIFO mode.** An Async proxy guarantees nothing about
   the relative order of two events. FIFO proxies own `fifo_dispatches` rows; Async proxies
   never do, so a FIFO proxy's line never slows an Async proxy and vice versa.
2. **Ordering is between events, not between destinations.** Within one event, the
   destinations may be delivered in any order, in parallel, and are not ordered relative to
   one another. There is no per-destination line and no per-`(proxy, destination)` ordering
   guarantee — ADR-005 named that as a possible future refinement and ADR-011 explicitly did
   not build it.
3. **The line advances on settlement, not on dispatch.** Event 2 is not claimed until every
   one of event 1's deliveries has reached a terminal state. Terminal means either delivered
   successfully, or failed after exhausting its retry policy. It does **not** mean "the
   delivery jobs have been enqueued", and it does not mean "the first attempt has been made".
4. **A retrying event holds the line for its whole retry schedule.** While any destination of
   event 1 is still `retrying` — including while it is merely waiting out a backoff with no
   work in progress — event 2 does not start. Under ADR-015's configured caps that wait is
   bounded at roughly 32.6 hours in the worst case. This is deliberate: it is PRD-06 AC6, and
   it is the reason `awaiting_retry` exists as a state at all.
5. **A permanent failure does not wedge the line.** If one destination of event 1 exhausts its
   retries and fails permanently while the others succeed, the line advances as soon as the
   last delivery becomes terminal, whatever mixture of outcomes that is. A permanently failed
   delivery is terminal, so it releases the line exactly like a successful one. Event 2 is
   then delivered to **all** of the proxy's live destinations, including the one that failed
   for event 1 — a destination is never skipped, quarantined, or held back on account of a
   previous event's outcome.
6. **The observable consequence at a destination.** Because the line waits for settlement, at
   any single destination event 1's final attempt completes before event 2's first attempt
   begins. That property is what distinguishes this product from a dispatch-ordered one, and
   this ADR preserves it.

Points 3 and 4 are the settlement-ordered reading. The alternative — dispatch-ordered, where
event 2 starts once event 1's jobs are enqueued — would lose point 6 and would be a
requirements change against PRD-06 AC6. **It is not proposed here and nothing in this ADR
changes points 1 through 6.** What changes is only *how* the work inside one event is
executed, which no requirement constrains.

## Question

In FIFO mode `DeliverStep` does not fan out. It calls `DeliverToDestination::run($unit)`
inline for each of the dispatch's `deliveries` rows, in sequence, so one
`AdvanceProxyFifoQueue` job performs N sequential outbound HTTP sends, each capped at
`DeliverToDestination::TIMEOUT_SECONDS = 15`. The advancer stamps its claim lease exactly
once, at claim time — `'lease_expires_at' => now()->addSeconds((int) config('ingest.fifo_lease_seconds'))`,
90 seconds by default — and nothing renews it for the remainder of the job.
`SweepStalledFifoDispatches` runs every minute and resets every `claimed` row whose
`lease_expires_at` has passed back to `pending`, clearing the claim. It cannot check whether
a worker is still running that job, and it does not try to.

A proxy with roughly seven or more destinations, or fewer destinations that are slow,
therefore produces an advancer job that legitimately outlives its own claim lease. Three
things follow, and the ADR has to answer all three.

1. **Is a duplicate outbound send reachable?** If a stale claim is reaped while its advancer
   is still delivering, a second advancer can claim the same `fifo_dispatches` row and re-run
   the same dispatch concurrently with the first.
2. **What should bound the advancer's duration?** Either the job must be made short, or the
   lease must be made long enough to cover it, or the reap must learn something it currently
   cannot know.
3. **How should the three timing values be ordered, and where should that ordering live?**
   `ingest.fifo_lease_seconds` (90), the Horizon per-supervisor worker `timeout` (60 on both
   supervisors), and `queue.connections.redis.retry_after` (180) are currently ordered
   correctly, but the ordering is stated only in a configuration comment and is enforced by
   nothing.

### Is a duplicate send reachable? The full trace

Traced end to end rather than assumed, because the answer decides whether a fix is warranted
at all.

**The concurrency window opens.** Advancer A1 claims row F at t=0 and stamps a lease expiring
at t=90. `WithoutOverlapping("proxy:{id}")` is given `->expireAfter((int) config('ingest.fifo_lease_seconds'))`,
so A1's overlap lock expires at t≈90 as well — deliberately, because a lock TTL longer than
the lease would re-open the deadlock the advancer's own docblock describes. The consequence
is that the overlap lock offers **no protection at all** past the moment the lease expires:
it is the same instant by design. `SweepStalledFifoDispatches` pass (a) then resets F to
`pending` on its next tick, and pass (b) immediately dispatches a fresh advancer A2 for the
proxy. A2 acquires the now-free lock, claims F, and runs
`ProcessIngestedWebhook::run($ingestId, $dispatchUuid)` for **the same `dispatch_uuid`** —
the same row, so the same UUID.

**What the idempotency guard does and does not stop.** The unique index on
`delivery_attempts` is `UNIQUE(delivery_id, attempt_number)` (ADR-015 Decision 2; the
`(ingest_id, destination_id, attempt_number)` key named in ADR-011 Decision 4 was dropped by
the #6 migration, ADR-016 P3). It prevents a duplicate attempt **row**. It does not prevent a
duplicate **send**, because `DeliverToDestination::handle()` looks the row up first and
branches on its status:

- A destination A1 already **finished** has a terminal attempt row. `resume()` returns
  immediately without sending. Correct, and no duplicate.
- A destination A1 has **not yet reached** has no attempt row. A2 creates it and sends. When
  A1 arrives later it finds A2's row; if A2 finished it, A1 skips.
- A destination A1 is **currently sending to** has an attempt row still in status
  `dispatched` — created before the HTTP call and settled only after the response. `resume()`
  treats a `dispatched` row as a crashed worker's leftover and **re-drives it on the same
  row**, which means it performs the HTTP send. That is a genuine duplicate outbound request,
  concurrent with A1's in-flight one.

The resume rule cannot distinguish "a worker died mid-send" from "a worker is sending right
now", and it was never meant to: ADR-011 Decision 4 scopes it to the queue's inherent
at-least-once redelivery, which is sequential. Under two concurrently live advancers it is
the wrong rule, and there is no other guard behind it.

**And a duplicate send is not the worst of it.** Two further consequences fall out of the same
window, both of which break the guarantee stated above.

- `AdvanceProxyFifoQueue::settleOrHold()`'s settle branch is a **blind update by primary key**
  (`$claimed->update([...])`), not a compare-and-set — unlike its hold branch, which is
  correctly keyed on `->where('status', Claimed)`. So A1, finishing late, can flip a row that
  A2 has re-claimed and is actively working straight to `settled` and then dispatch the next
  advancer. Event 2 begins while event 1 is still being delivered.
- Even without that, whichever advancer finishes first settles the row and advances the line,
  so event 2 starts while the other advancer is still sending event 1. Point 6 of the
  guarantee is lost.

**Is the window open today?** No — and what closes it is exactly the value the Owner set
deliberately while adding Horizon. Both supervisors carry `timeout => 60`, which is below the
90-second lease, so a worker is killed 30 seconds before its claim becomes reapable. The
lease is stamped a moment *after* the job starts, so the kill strictly precedes the expiry
with margin, and the sweeper only reaps expired claims. A killed worker leaves no concurrent
process behind, so the second advancer runs alone. **The defect is real in the code and is
currently prevented only by a configuration value.** The Owner briefly raised that value to
300 while accommodating long FIFO jobs — which would have opened the window wide — and
reverted it on finding the lease. That is precisely how fragile an unenforced invariant is.

Two caveats on "the window is closed today":

- Under the `sync` queue connection there is no worker timeout at all. The test suite runs
  `QUEUE_CONNECTION=sync` (`phpunit.xml`), and a local developer without a worker runs the
  advancer in the request process while the scheduler runs the sweeper in another. `.env.example`
  sets `QUEUE_CONNECTION=redis`, so this is not the deployed shape, but it is a real shape.
- The timeout that closes the concurrency window simultaneously **guarantees** that any FIFO
  proxy with five or more destinations has its advancer killed mid-delivery, every time
  (60 seconds admits four 15-second sends plus one in flight). The line then stalls until the
  lease expires and the next sweep tick reaps it, the re-driven advancer re-sends the
  destination that was in flight when the process died, and the event completes over several
  sweep cycles. That re-send is the accepted at-least-once property, not a new defect — but a
  configuration that is only safe because it deterministically kills legitimate work is not a
  configuration to leave in place.

**The precise statement of the bug**, which is also why no amount of tuning fixes it: the
`default` supervisor's `timeout` is subject to two constraints at once. It must exceed the
longest single unit of work the supervisor may run, or legitimate work is killed. It must
stay below `ingest.fifo_lease_seconds`, or a live worker's claim can be reaped. With inline
sequential fan-out, the first constraint reads `timeout > N × 15` and the second reads
`timeout < 90`. **For N ≥ 5 the two are jointly unsatisfiable** unless the lease is also
raised — and raising the lease lengthens the outage that a genuinely crashed worker inflicts
on that proxy's line, because the sweeper cannot reap until the lease expires. The
configuration is not merely undocumented; it is over-constrained.

## Decision

**(1) In FIFO mode, destination fan-out becomes parallel and queued, exactly as Async already
does.** *(Amended 2026-08-26 — Revision A: what is dispatched is now a reference, not a payload.
Read this decision together with Decision 7, which governs what the job carries.)*
`DeliverStep` loses its `processing_mode` branch entirely: every delivery, in both
modes, is `DeliverToDestination::dispatch($delivery->id, 1)->onQueue(config('ingest.webhooks_queue'))->afterCommit()`.
The advancer job's work becomes bounded by database and CPU time — load the captured event,
load the proxy, create or find the dispatch's `deliveries` rows, run the pipeline, enqueue N
jobs — rather than by N remote endpoints. It no longer contains a single outbound HTTP call.

This is what makes the two constraints on `timeout` jointly satisfiable again with a large
margin, and it is the reason this ADR does not need to change the lease, renew the lease, or
teach the sweeper anything new. The lease keeps its original and correct meaning: how long a
claim is honoured before it is presumed orphaned.

**(2) Event ordering is unchanged, and stays settlement-ordered.** Points 1 through 6 of the
guarantee above all hold after decision 1. The mechanism that holds the line is the one
ADR-016 Decision 1 already built: the advancer transitions its claimed row to
`awaiting_retry` when the dispatch still has non-terminal deliveries, and
`DeliverToDestination::settleFifoLineIfComplete()` compare-and-sets `awaiting_retry → settled`
and nudges the advancer when the last open delivery of the dispatch settles. That path
already fires on every terminal transition regardless of processing mode — it was written
mode-neutral — so **no new settle-and-advance mechanism is required**. What changes is only
which branch is the common case: under inline delivery the advancer usually settled the row
itself, and under parallel fan-out it will almost always hold, with the last delivery doing
the settling.

`awaiting_retry` accordingly means **"this head is not yet settled"**, not specifically
"between retry attempts". The `FifoDispatchStatus` docblock already reads that way
("`awaiting_retry` while its dispatch has at least one non-terminal delivery after the
claimed run completes"), so no enum value, column value, or migration changes. ADR-016
Decision 1's narrower prose is amended to match; see § Positions superseded.

**(3) `settleOrHold()` becomes race-safe: publish the hold first, then re-check.** Under
inline delivery this decision was concurrency-free — see § Reasoning for why — and under
parallel fan-out it is not. The required shape, precisely:

```php
private function settleOrHold(FifoDispatch $claimed, int $proxyId): void
{
    if (! $this->hasNonTerminalDeliveries($claimed->dispatch_uuid)) {
        $this->settleAndAdvance($claimed->id, FifoDispatchStatus::Claimed, $proxyId);

        return;
    }

    // Publish the hold BEFORE re-checking. `settleFifoLineIfComplete` settles
    // only a row it finds in `awaiting_retry`, so a delivery that settles while
    // this row is still `claimed` cannot advance the line — the re-check below
    // is what covers that instant. Ordering the two the other way round leaves
    // the same gap it is meant to close.
    $held = FifoDispatch::query()
        ->whereKey($claimed->id)
        ->where('status', FifoDispatchStatus::Claimed)
        ->update([
            'status' => FifoDispatchStatus::AwaitingRetry,
            'claimed_at' => null,
            'lease_expires_at' => null,
        ]) > 0;

    if (! $held) {
        return;
    }

    if (! $this->hasNonTerminalDeliveries($claimed->dispatch_uuid)) {
        $this->settleAndAdvance($claimed->id, FifoDispatchStatus::AwaitingRetry, $proxyId);
    }
}

private function settleAndAdvance(int $id, FifoDispatchStatus $from, int $proxyId): void
{
    $affected = FifoDispatch::query()
        ->whereKey($id)
        ->where('status', $from)
        ->update(['status' => FifoDispatchStatus::Settled, 'settled_at' => now()]);

    if ($affected > 0) {
        static::dispatch($proxyId);
    }
}
```

Two properties this shape must preserve, and which a reimplementation must not lose. The
settle path is now a **compare-and-set keyed on the expected prior status**, not a blind
update, and the advance is dispatched **only if the compare-and-set affected a row** — so a
stale advancer can neither settle a row another advancer holds nor double-advance the line.
And the hold is **published before the re-check**, which is what makes the window airtight
rather than merely narrower.

**(4) The three timing values are ordered by an explicit rule, and the rule is enforced by a
test rather than remembered.** The full chain, with the current values:

```
DeliverToDestination::TIMEOUT_SECONDS (15)
    <  every Horizon supervisor `timeout` (60)
    <  ingest.fifo_lease_seconds (90)
    <  queue.connections.redis.retry_after (180)
```

Three links, and they are not all the same kind of constraint. Stating which is which matters,
because a future change will need to know what it is allowed to trade.

- **L1 — correctness.** `retry_after` must exceed every supervisor `timeout` on that
  connection. Otherwise the queue makes a reserved job visible again while a worker is still
  running it, and a second worker picks it up. Laravel's documented rule; here it would mean
  either two live advancers on one proxy or two live `DeliverToDestination` jobs for one
  attempt.
- **L2 — correctness, and specific to this project.** The `timeout` of the supervisor serving
  the queue that carries `AdvanceProxyFifoQueue` must stay **below**
  `ingest.fifo_lease_seconds`. Otherwise a live advancer's claim becomes reapable while it is
  still running, which is the entire defect this ADR addresses. Decision 1 makes the
  constraint comfortable rather than tight, but it does not make it optional: it remains the
  backstop for the unexpected long job — a stalled database, a very large captured body, a
  slow Redis — that decision 1 does not rule out.
- **L3 — fitness, not correctness.** Every supervisor's `timeout` must exceed the longest
  single unit of work it may legitimately run. On the `webhooks` supervisor that is
  `DeliverToDestination::TIMEOUT_SECONDS` plus connection setup. On the `default` supervisor,
  after decision 1, it is the advancer's bounded local work. Violating L3 kills legitimate
  work; it does not corrupt state.

Enforcement is a new configuration test, `tests/Unit/Config/QueueTimingTest.php`, asserting L1
and L2 against the real resolved configuration — including every `horizon.environments.*.*`
override, not just `horizon.defaults.*`, since an override is exactly where a future edit
would land. A runtime assertion in a service provider was considered and rejected: these are
static values, and a boot-time throw would take an application down for a configuration
nobody touched in that deployment.

The existing explanatory comments in `config/horizon.php` and `config/queue.php` stay and
gain a pointer to this ADR. They are the right place for the reasoning; the test is what makes
the rule survive an editor who does not read them.

**(5) `ingest.fifo_lease_seconds` gets the fail-loud guard the `retry.*` keys already have.**
A blank `INGEST_FIFO_LEASE_SECONDS=` in an environment file yields `(int) '' === 0`, and zero
is uniquely destructive here because it is read in two places with opposite failure modes: the
claim's `lease_expires_at` becomes `now()`, so every claim is reapable the instant it is
made, **and** `WithoutOverlapping->expireAfter(0)` means no expiry at all in
`Illuminate\Cache\RedisLock`, so an ungracefully killed worker leaks the per-proxy lock
permanently. That is exactly the deadlock the advancer's own docblock warns about, reached by
an empty environment variable. The remedy is the house `positiveConfigInt()` idiom already
applied to all seven `retry.*` keys (review-07 Minor 9): a single reader that throws rather
than coercing, with `AdvanceProxyFifoQueue`'s two `(int) config('ingest.fifo_lease_seconds')`
reads and `SweepStalledFifoDispatches` going through it.

**(6) The advancer's `WithoutOverlapping` middleware gets `->dontRelease()`.** Both the
advancer's docblock and `config/horizon.php`'s production comment state that "a redundant
advancer that loses the lock is simply dropped". That is not what the code does:
`WithoutOverlapping`'s `$releaseAfter` defaults to `0`, so a losing advancer is released back
onto the queue immediately, and with the supervisors' `tries => 1` it then fails as
`MaxAttemptsExceeded` and lands in `failed_jobs`. No payload is exposed (the advancer job
carries only an integer proxy id) and liveness is unaffected, so this is hygiene rather than
correctness — but it makes two comments true, and it stops routine operation filling the
failed-jobs table and Horizon's failed list. Decision 1 makes redundant advancers somewhat
more common, which is why it is settled here rather than left.

**(7) No queued job carries payload bytes in its arguments, in either processing mode. The
delivery job carries `(deliveryId, attemptNumber)` and resolves the bytes on the worker.**
*(Added 2026-08-26 — Revision A.)*

This is not new machinery. It is the shape **`RetryDelivery` has used in production since #6**,
applied to attempt 1, and it is what **ADR-015 Decision 5** already requires of every attempt
from the second onwards: "No payload bytes are ever carried in this job's own arguments — only
`$deliveryId`/`$attemptNumber`." Attempt 1 is the anomaly, not the target.

**What the worker resolves, and why the resolution is total.** `StoredPayloadLookup::dispatchedBytesFor($event)`
returns `dispatched_payloads.body` when it is non-NULL, and `webhook_events.body` otherwise. The
fallback is not a guess: ADR-013 Decision 2 makes `body IS NULL` a **positive assertion that the
dispatched output was byte-identical to the raw capture**, and the same holds for the no-row case,
because `CaptureDispatchedStep` is enhanced-mode-only while every payload-mutating step
(`NormalizeStep` #9, `MapStep` #8) is *also* enhanced-mode-only and is composed **before** it. So
the pair `(webhook_events.body, dispatched_payloads.body)` durably determines the dispatched
output in every case that can arise, and the reference always resolves. The rest of the
`DeliveryUnit` is equally derivable: `headers` from the captured event row, `destination` loaded
`withTrashed()` from the delivery row, `method` from that destination, `teamId`/`proxyId`/`ingestId`
from the delivery and its event. `RetryDelivery::handle()` already builds exactly this.

**Two binding constraints follow, and a future item that breaks either breaks delivery silently.**

- **`CaptureDispatchedStep` must remain the last pipeline step before `DeliverStep`.** It is what
  makes `dispatched_payloads` hold the *final* output rather than an intermediate one.
- **No payload-mutating step may exist outside enhanced mode.** A simple-mode transform step would
  produce output that no store records, and the reference would resolve to the raw capture —
  delivering the untransformed body with no error anywhere. `PipelineFactory`'s commented
  insertion contract already places every future transform inside the enhanced branch; this makes
  that placement load-bearing rather than incidental.

**The cleaned-parent guard is mandatory and its behaviour is already ruled.** Because resolution
now happens on the worker rather than at dispatch, the delivery job must check
`payload_cleaned_at !== null` before reading `body` — ADR-014 Decision 7, binding, and the reason
`StoredPayloadLookup::dispatchedBytesFor()` documents that its caller must guard. On a cleaned
parent the job takes the `RetryDelivery::terminalizeCleaned()` path: compare-and-set the delivery
to `failed` (keyed on `pending`/`retrying`, so it is correct for attempt 1 as well), emit
`DeliveryExhausted` iff the compare-and-set affected a row, log `payload.expired` with identifiers
only, and **make no attempt and write no attempt row** — PRD-06 AC17's posture, applied to
attempt 1 for the first time. Guarding on `body === null` remains forbidden.

**How reachable is that branch, honestly.** For an **original** dispatch it is unreachable in
practice: hold H1 forbids erasing an event until it is past its 30-day retention window, and no
attempt-1 job survives 30 days in a queue. For a **replay** of an event already close to its
cutoff it is reachable in a narrow case — an Async replay delivery still `pending` (queued, never
started, so no `delivery_attempts` row exists for H3 to hold on) and older than
`retention.dispatch_horizon_minutes`, at which point H5's `pending` clause and H3 both release.
The branch exists for that case. **Note that today, with the bytes travelling in the job, that
same case delivers a payload the retention policy has already erased** — which is a worse outcome
than refusing, so Decision 7 improves retention fidelity here rather than degrading it.

**Costs, stated rather than glossed.** The payload is read from the database once per destination
instead of once per event, and decrypted once per read, where today the pipeline reads and
decrypts it once and passes it in memory. That is the price. It is paid against MySQL rather than
Redis, and it replaces N *writes* of the same bytes into Redis that Async performs today, so the
total I/O is not obviously worse and the Redis memory pressure is strictly better.

**(8) The inbound body cap versus queue-driver message limits is stated as an operational limit,
and the pre-existing Async exposure is recorded as pre-existing.** *(Added 2026-08-26 —
Revision A.)*

`config('ingest.max_body_bytes')` defaults to **52,428,800 bytes (50 MiB)** — itself flagged in
that file as a deliberately high placeholder, not a risk-tuned value. Amazon SQS caps a single
message at **256 KiB**, roughly **200× smaller**. Redis's 512 MB string limit and the database
driver's `longText` payload column both tolerate 50 MiB, which is exactly why the mismatch is
invisible today and would surface as a hard failure — not a degradation — on the first oversized
message after a driver migration.

The position: **after Decision 7 the queue message is two integers, so no queue driver's message
limit constrains this application's payload size at all.** The inbound cap becomes a property of
the capture store (`LONGBLOB`, 4 GiB ceiling) and of the outbound HTTP send, both of which are
driver-independent. That is the durable answer, and it is a reason to prefer Decision 7 over
encryption independently of security: encryption would have made the size problem worse, since
the `encrypted` cast's base64-and-envelope overhead inflates the serialized job by roughly a
third before the payload is JSON-encoded into the queue message.

**Recorded as pre-existing, not introduced here:** Async fan-out has carried the payload in the
job since #4, so **this application is already exposed to any queue driver's message-size limit
today, on every Async proxy.** That is a live latent limitation in shipped code. Decision 7
closes it as a side effect; it is named separately so that a later reader does not attribute it
to the FIFO change, and so that it is not silently folded into an unrelated approval.

**(9) The delivery job carries plain identifiers, not a `SerializesModels`-hydrated model.**
*(Added 2026-08-26 — Revision B, ruling the Project Owner's question.)*

**The trait is not broken here, and the ruling does not rest on pretending it is.** Verified in
vendor rather than assumed, because `ShouldBeEncrypted` had already proved that a Laravel queue
feature can be a silent no-op under `lorisleiva/laravel-actions`:

- **It survives the decorator.** `Lorisleiva\Actions\Decorators\JobDecorator` *does*
  `use SerializesModels` (aliasing `__serialize`/`__unserialize`), and its own
  `serializeProperties()` runs `array_walk($this->parameters, fn (&$v) => $this->getSerializedPropertyValue($v))`.
  So a **top-level** model argument is replaced by a `ModelIdentifier` and rehydrated on the
  worker. Unlike `ShouldBeEncrypted`, this one works.
- **It would not defeat the security or size property.** `ModelIdentifier` holds the class name,
  the key, the queueable relations and the connection — **no attributes**. A `Delivery`
  parameter would put an id in the message, not a row.
- **It would not break on soft deletes.** `Model::newQueryForRestoration()` is
  `newQueryWithoutScopes()->whereKey($ids)`, so restoration bypasses **every** global scope,
  soft-delete and team scope alike. The concern that a trashed parent would resolve to nothing
  is unfounded — and in any case `Delivery` has neither `SoftDeletes` nor a registered
  `TeamScope` (`ApplyTeamScope` covers `Proxy`, `Destination` and `DeliveryAttempt` only).
- **It would not swallow the cleaned-parent branch.** That branch is not a missing model.
  Erase-in-place keeps the `webhook_events` row (ADR-014 P1 as narrowed), `deliveries` rows are
  never deleted anywhere, and the branch is entered by *reading* `payload_cleaned_at` inside the
  handler. The re-fetch succeeds; the guard then fires exactly as Decision 7 specifies.

**One thing to note about the trait's failure mode anyway, because it is worse than it looks and
is easy to mis-state.** `CallQueuedHandler::call()` catches `ModelNotFoundException` from
unserialization and delegates to `handleModelNotFound()`, which reads
**`$job->payload()['deleteWhenMissingModels'] ?? false`** — the JSON envelope field, populated
from the action's `jobDeleteWhenMissingModels` property, absent by default. So the default is
**not** a silent delete: the job **fails** into `failed_jobs`. Either way the handler never runs
— no compare-and-set, no `DeliveryExhausted`, no `payload.expired` log — and the `deliveries` row
is left `pending` forever, which is the known "nothing terminalizes a stranded Async `pending`
delivery" gap reached by a new route. It is not reachable today. It is the shape of the risk that
matters: **a class of resolution failure handled by the framework, before and outside any code
this application owns.**

**Why plain identifiers win — four reasons, none of them a vendor quirk.**

1. **The trait cannot carry the whole resolution, so it removes nothing.** Even with a hydrated
   `Delivery`, the worker must still resolve the payload bytes from `dispatched_payloads` /
   `webhook_events`, the headers from the captured event, and the destination `withTrashed()`.
   The shared resolver Decision 7 requires is needed either way. The trait would replace exactly
   one line of it — `Delivery::query()->find($deliveryId)` — and would do so *outside* the
   resolver, splitting one act of resolution across two mechanisms with different failure
   handling. That is strictly more moving parts for strictly less.
2. **It would re-open the very inconsistency Decision 7 closes.** `RetryDelivery` carries
   `(int $deliveryId, int $attemptNumber)` under ADR-015 Decision 5 and has since #6. Hydrating
   attempt 1 differently would leave attempt 1 and attempts 2..N in different shapes again, and
   would give one resolver two call shapes.
3. **Every resolution outcome should be handled where the application can see it.** With plain
   identifiers, a missing row, a cleaned parent and a trashed destination are all decided inside
   code this project owns, with the compare-and-set, the event and the log that each case is
   specified to produce — which is exactly what `RetryDelivery` already demonstrates. With the
   trait, one of those outcomes is decided by the framework before the handler is entered.
4. **Extensibility — the Owner's own argument, and the one that will read best in a year.**
   `SerializesModels` hard-codes `newQueryWithoutScopes()->whereKey()->useWritePdo()->firstOrFail()`
   with no interception seam short of overriding `newQueryForRestoration()` on the model or
   `restoreModel()` on the job. An explicit resolver is a **single place every payload read
   passes through**, which is where any future strategy — a cache, a read-replica route, a
   different store — would be introduced without touching either call site. Note in passing that
   `useWritePdo()` means the trait's re-fetch always reads the write connection, which would
   silently defeat replica routing on this path.

**A boundary to record now rather than discover later, since the Owner raised caching as a
"potentially, later".** Nothing about a cache is designed, specified or scheduled here, and
Decision 9 exists to keep that door open rather than to walk through it. What is worth recording
is which side of the at-rest-encryption line a future cache would fall on, because that is the
question that would otherwise be answered under time pressure. The Project Owner has stated the
principle:

> "a cache can be a short term store. we want encryption on long term storage. We can define
> rules later, but if a cache is living for minutes or an hour, we should be ok without worrying
> about at rest encryption"

So **lifetime is the discriminator**, and a short-lived cache of resolved payload bytes sits on
the *short-term* side and would not require at-rest encryption. The refinement this makes to
Revision A's definition is folded in there rather than restated here. **The Owner has explicitly
deferred the detailed rules**, so no threshold is chosen and none is implied: a future cache is
its own decision, and the one input it will need from the Owner is where the line sits in
duration — see the note under § Impact, *Which stores are "long-term"*.

## Positions superseded and amended

| Prior position (verbatim) | Superseded / amended to |
|---|---|
| **P4 — ADR-011 Decision 2:** the advancer "processes that one event **to settlement**, marks it `settled`, then self-dispatches to advance" | The advancer *initiates* the event's delivery and hands the settle-and-advance decision to whichever actor completes the dispatch's last delivery. The **guarantee** the position served — event 2 is not claimed until event 1 has fully settled — is unchanged and is what decisions 2 and 3 preserve. Only the actor changes. ADR-016 Decision 1 had already made this position conditionally untrue for the retry case; decision 1 here makes the held branch the ordinary path. |
| **P5 — ADR-011 Decision 3** *(added 2026-08-26 — Revision A)*: "Per-destination `DeliverToDestination` continues to carry its `DeliveryUnit` (including the pipeline's *output* payload) so a later mapped payload (#8) flows to delivery unchanged" | The delivery job carries `(deliveryId, attemptNumber)`; the pipeline's **output** payload is resolved on the worker from `dispatched_payloads.body`, falling back to `webhook_events.body` under ADR-013 Decision 2's divergence gate. The **guarantee** is preserved and strengthened: a mapped payload still reaches the destination unchanged, and it now does so by reading the very store that records what was dispatched, rather than by a second copy that has to be kept in agreement with it. The sentence's other half — "the pipeline entry is dispatched by reference" — is untouched. This also brings attempt 1 into line with **ADR-015 Decision 5**, which already forbids payload bytes in a retry job's arguments; no ADR-015 position changes. |
| **ADR-016 Decision 1 (amended, not superseded):** the advancer transitions to `awaiting_retry` "for the dispatch's in-progress retry schedule", and the status is described throughout as representing "head is between attempts" | `awaiting_retry` represents "head is not yet settled" — which includes a first attempt still in flight or still queued, as well as a backoff wait. The lifecycle, the transitions, the busy gate, the three sweeper passes and the GC hold H2 interaction are all unchanged and all remain correct under the wider reading. No enum value, column value, or migration changes. Additionally, the settle-or-hold decision becomes concurrency-safe per decision 3; ADR-016's description of it is otherwise unchanged. |

Explicitly **not** superseded or amended, verified one at a time so a later reader can check
rather than trust:

- **ADR-011 Decision 1** (`processing_mode` enum on `proxies`) — untouched. The column keeps
  its meaning; it simply stops selecting an inline-versus-queued delivery path and continues
  to select whether the proxy owns `fifo_dispatches` rows at all.
- **ADR-011 Decision 2's remaining content** — the sidecar table, the atomic `FOR UPDATE`
  claim as the correctness primitive, the lease plus sweeper as the liveness net, and
  `WithoutOverlapping` as a thundering-herd reducer rather than the guard. All relied on here.
- **ADR-011 Decision 3's first half** (the pipeline entry is dispatched by reference and rebuilds
  its `PipelineContext` from the durable capture) — untouched and relied on. Its second half is
  **P5 above**; the first version of this ADR wrongly listed the whole decision as untouched and
  used it to reject by-reference delivery. *(Corrected 2026-08-26 — Revision A.)*
- **ADR-013 Decisions 2 and 3** (the divergence gate — `dispatched_payloads.body IS NULL` means
  the output was identical to the raw capture — and `StoredPayloadLookup` as the only interpreter
  of that NULL) — untouched, and now load-bearing for Decision 7: the gate is precisely what makes
  a reference resolve in the common case rather than only when the payload diverged.
- **ADR-015 Decision 5** (retry jobs carry no payload bytes) — untouched and extended, not
  superseded. Decision 7 applies the same rule to attempt 1.
- **ADR-014 Decision 7** (guard on `payload_cleaned_at`, never on `body === null`) — untouched,
  and now applies to one additional caller.
- **ADR-010 Amendment B's binding `APP_PREVIOUS_KEYS` rule** and the three encrypted columns it
  spans — untouched. Decision 7 adds no fourth encrypted store and no new key-lifecycle surface.
- **ADR-011 Decision 4 / ADR-016 P3** — the idempotency mechanism and its
  `UNIQUE(delivery_id, attempt_number)` key. Unchanged. Its scope is unchanged too: it guards
  the queue's sequential at-least-once redelivery, and it is not a concurrency guard. Decision
  1 removes the situation in which it was being asked to be one.
- **ADR-016 Decisions 2, 3, 4 and 5** — no `dead_lettered` status, the row-`id` order key with
  `dispatch_uuid` as the identity key, the three sweeper passes, and Async being untouched by
  construction. All unchanged. Sweeper pass (c) in particular keeps its role and is not
  widened: it covers a crash between publishing the hold and the re-check, which is what it was
  designed for, rather than becoming the routine cover for a race.
- **ADR-012 hold H2** — every non-`settled` `fifo_dispatches` status holds payload erasure.
  Unchanged and still correct: under decision 1 the row is non-`settled` from capture until
  the last delivery settles, exactly as before, so no dispatch's payload becomes erasable
  while a send is in flight. The set of instants at which a row is `claimed` versus
  `awaiting_retry` shifts; the set of instants at which it is `settled` does not.
- **ADR-003** — payload-free attempt records. Unchanged; nothing here writes a payload to
  `delivery_attempts`.
- **ADR-015** — the retry policy, schedule, terminal state and `SweepDueRetries`. Unchanged;
  decision 1 does not move a single retry schedule, and `RetryDelivery` already dispatches by
  reference and re-sends stored dispatched bytes.

## Alternatives

Each of the shapes considered, and why it loses.

- **Renew the claim lease periodically during the inline loop (a heartbeat).** Keeps the long
  job and adds a mechanism to keep telling the sweeper about it. It needs a renewal point
  inside a synchronous loop, which means the renewal cadence and the per-send timeout become
  coupled; it weakens the reaper's rule from "an expired lease means an orphan" to "an expired
  lease means an orphan or a heartbeat that was late"; and it does nothing for the case it
  most needs to cover, an ungracefully killed worker, whose heartbeat simply stops. ADR-016
  already rejected overloading the lease with a second meaning, for the same reason. Rejected.
- **Derive the lease from the destination count and the per-send timeout.** Makes the lease
  data-dependent and per-row, and requires the worker timeout to become per-job and derived
  too, since Horizon's `timeout` is per-supervisor. It also inverts the cost: a proxy with
  fifty destinations would get a twelve-and-a-half-minute lease, which is exactly how long
  that proxy's line would be parked after a genuine crash. It raises a ceiling that decision 1
  removes. Rejected.
- **Bound the number of inline sends per advancer job and resume across jobs.** This is a
  resumable partial-dispatch state machine — new state, a new resume point, and a new class
  of "which destinations were already sent" question — invented to preserve an ordering
  property inside an event that no requirement asks for. Materially more machinery than
  decision 1, which is a deletion. Rejected.
- **Make the sweeper's reap conditional on something stronger than elapsed time** (worker
  liveness, a process registry, a Horizon API check). The sweeper deliberately knows nothing
  about workers; giving it that knowledge is the heartbeat option wearing different clothes,
  with a new dependency on the supervisor's own state. Rejected.
- **Accept the behaviour and document a supported destination ceiling.** With the current
  values the ceiling is about four destinations in FIFO mode, which is a product restriction
  no PRD contains and which a member would hit by adding a fifth destination in the UI with no
  warning. Rejected.
- **Raise the worker timeout above the lease** — the change that surfaced this, briefly made
  and reverted. It resolves L3 by breaking L2, which is to say it converts a killed job into a
  concurrent one. Rejected, and recorded by name so it is not re-proposed as the obvious fix.
- **Dispatch-ordered FIFO: advance the line once event 1's delivery jobs are enqueued.** This
  would make the advancer short *and* remove the hold, but it changes the product — event 2
  could reach a destination while event 1 is still retrying to it, losing point 6 of the
  guarantee — and it contradicts PRD-06 AC6, which the Owner approved on 2026-08-12 and
  reaffirmed on 2026-08-26 with the single word "Delivered". Rejected as a requirements
  change that is not the Principal Engineer's to make.
- **A new `in_flight` status distinct from `awaiting_retry`.** Semantically tidier than
  widening one status's meaning, but it costs a migration and an enum value, which is a
  data-model change and therefore an Owner gate, to record a distinction no consumer acts on:
  every reader of `fifo_dispatches` — the busy gate, all three sweeper passes, and GC hold
  H2 — treats "held" identically however the head got there. That is precisely the
  no-consumer bookkeeping ADR-016 Decision 2 rejected when it declined `dead_lettered`.
  Rejected, consistently.
- **Dispatch `DeliverToDestination` by reference, to keep payloads off the queue.**
  **[ADOPTED as Decision 7 — this bullet's original "rejected" position is superseded,
  Revision A, 2026-08-26. Recorded rather than deleted, because the error is instructive.]**
  The original analysis rejected this on the ground that ADR-011 Decision 3 requires the
  delivery job to carry the pipeline's *output* payload so a mapped payload (#8) reaches the
  destination, and that a by-reference job "would have to re-read the raw capture and would
  deliver the unmapped body". **That last clause is false**, and it is false because of
  ADR-013, which the original analysis did not walk. The dispatched-output store is
  divergence-gated: a NULL `body` is a positive assertion that the output *was* the raw
  capture, not an absence of information. So a reference resolves the output in every case,
  not only when it diverged — which is exactly why `RetryDelivery` has resolved attempts
  2..N this way in production since #6 without ever re-applying a transform. The
  guarantee ADR-011 Decision 3 protects survives; only its mechanism is superseded (P5).
- **`SerializesModels` on the delivery job — pass the `Delivery` model and let the trait
  rehydrate it.** *(Added 2026-08-26 — Revision B; the Project Owner's suggestion, ruled at
  Decision 9.)* **Rejected, but not because it fails.** Verified in vendor: unlike
  `ShouldBeEncrypted`, this trait *does* survive `JobDecorator`, it puts only a `ModelIdentifier`
  — class, key, relations, connection, no attributes — into the message, its restoration bypasses
  every global scope so soft deletes are a non-issue, and it would **not** swallow the
  cleaned-parent branch, which is a present row with a timestamp set rather than a missing model.
  It loses on four other grounds, set out at Decision 9: it cannot carry the payload, headers or
  destination, so the shared resolver is needed regardless and the trait would only relocate one
  line of it outside that resolver; it would put attempt 1 back out of step with the
  `(deliveryId, attemptNumber)` shape ADR-015 Decision 5 fixes for attempts 2..N; it moves one
  class of resolution failure into `CallQueuedHandler::handleModelNotFound()`, before any code
  this application owns, where the outcome is a failed job rather than the specified
  terminalize-and-emit; and it hard-codes Eloquent's own re-fetch with no seam, foreclosing the
  extension point the Owner named. The Owner offered it as "use the more idiomatic mechanism if
  it genuinely works" with the plain-identifier shape as the stated fallback; the fallback is
  taken.
- **`ShouldBeEncrypted` on the delivery job, keeping the payload in the job arguments.** The
  Owner's own first suggestion, and it fails on three counts, any one of which is sufficient.
  **(a) It is not a one-line adoption in this codebase.** `Illuminate\Queue\Queue::jobShouldBeEncrypted()`
  tests the object *being serialized*, which for a `lorisleiva/laravel-actions` action is
  `Lorisleiva\Actions\Decorators\JobDecorator` — a vendor class that does not implement
  `ShouldBeEncrypted`, declares a fixed set of public properties (`$tries`, `$maxExceptions`,
  `$timeout`, `$deleteWhenMissingModels`) that does not include `shouldBeEncrypted`, and
  forwards only an explicit allow-list of action properties. Putting the interface on
  `DeliverToDestination` would have **no effect whatsoever**, silently. Reaching it means
  either overriding `makeJob()` per action to return a project-owned decorator subclass, or
  replacing `ActionManager::$jobDecorator` globally — which would encrypt every action job in
  the application, with no per-job granularity. Bespoke machinery wrapped around a vendor
  internal, to protect something that does not need to be there at all.
  **(b) It makes the Owner's second requirement worse.** The `encrypted` cast's base64 and
  envelope overhead inflates the serialized command by roughly a third before it is
  JSON-encoded into the queue message, so it moves the payload further above any driver's
  message ceiling rather than below it.
  **(c) It creates a new key-lifecycle failure class.** An `APP_KEY` rotation would leave every
  queued job and every `failed_jobs` row undecryptable — a live job that cannot be run and a
  failed job that cannot be retried. ADR-010 Amendment B's binding `APP_PREVIOUS_KEYS` rule
  covers *stored rows* and is discharged by a future re-encryption pass over them; a queue
  message has no such pass and no row to re-encrypt. That would need a **new** operational
  rule, which Decision 7 avoids needing at all. Rejected on all three.
- **Encrypt the payload into the job by hand** (encrypt in `DeliverStep`, decrypt in
  `DeliverToDestination`, bypassing `ShouldBeEncrypted`). Removes objection (a) and keeps (b)
  and (c) intact, while hand-rolling a second encryption seam alongside the Eloquent casts that
  already exist for the same bytes. Rejected.
- **Keep the payload in the job but cap FIFO/Async payload size to the smallest supported
  driver's message limit.** Would make the size problem tractable by making the product
  smaller: 256 KiB against a configured inbound cap of 50 MiB is a 200× reduction in what the
  application accepts, which is a requirements change and not the Principal Engineer's to make.
  It also leaves the plaintext-in-queue exposure entirely unaddressed. Rejected.
- **Do nothing, and rely on `timeout < lease`.** The invariant does hold today. But it is
  undocumented outside one configuration comment, enforced by nothing, and it is only safe
  because it deterministically kills legitimate work on any FIFO proxy with five or more
  destinations. Rejected on the second half.

## Reasoning

- **Decision 1 removes the cause rather than accommodating it.** Every other candidate takes
  "one advancer job performs N remote calls" as fixed and negotiates around it. That premise
  is the problem, and no requirement supports it: the Owner has stated that ordering is
  required between events, not between destinations within an event. The change is a deletion
  — one branch and one mode read leave `DeliverStep` — not new machinery.
- **The mechanism decision 1 depends on already exists and already works.** ADR-016 Decision 1
  built `settleFifoLineIfComplete()` for the retry case, and wrote it mode-neutral: it fires on
  any terminal delivery transition, checks the dispatch's siblings, and compare-and-sets the
  held row. It has been in production behaviour since #6. Decision 1 does not add a settlement
  path; it makes the existing one the ordinary one. This is the strongest argument for
  preferring it: the change is smaller than it looks because #6 already built the half that
  matters.
- **What the inline loop was quietly relying on, which is the finding worth recording.**
  `settleOrHold()` is not concurrency-safe, and inline delivery is what guaranteed there was
  no concurrency to be safe against. When `ProcessIngestedWebhook::run()` returns under inline
  fan-out, every attempt-1 send has already completed, so each delivery is already terminal or
  already `retrying` with a *delayed* `RetryDelivery` — and `RetryPolicy::delayBefore()` routes
  every curve constant through `positiveConfigInt()`, so the shortest possible delay is one
  second. Nothing could settle a delivery of that dispatch in the microseconds between
  `settleOrHold()`'s existence check and its update. Under parallel fan-out that is no longer
  true and the read-then-write becomes a routine race, whose losing outcome is a row parked in
  `awaiting_retry` with every delivery already terminal and nobody left to settle it. Sweeper
  pass (c) does release it, but up to a minute later, on every event — a stall the inline path
  never had. Decision 3 closes it in the advancer instead, and leaves pass (c) as the crash
  net it was designed to be.
- **Publishing the hold before re-checking is what makes decision 3 airtight, and the order is
  not interchangeable.** After the transition to `awaiting_retry` is committed, any delivery
  that settles will find the row in the state `settleFifoLineIfComplete()` acts on, so it can
  settle the line itself. Before the transition it cannot, because its compare-and-set is keyed
  on `awaiting_retry` and affects zero rows. The re-check therefore only has to cover the
  instants before the row was published — and it does, because it reads the same delivery
  states after the publish. Both actors compare-and-set on the same row, so exactly one wins,
  and the advance is gated on winning.
- **A stale advancer's re-run is harmless after decision 3, and this is worth stating because
  it is the path that used to produce duplicate sends.** If an advancer dies after claiming and
  before publishing the hold, the row stays `claimed` until the lease expires, the sweeper
  reaps it, and a new advancer re-runs `ProcessIngestedWebhook` for the same `dispatch_uuid`.
  `Delivery::firstOrCreate` on `(dispatch_uuid, destination_id)` creates no duplicate rows, and
  each re-dispatched `DeliverToDestination` for attempt 1 finds a terminal attempt row and
  skips. The dispatch converges with no duplicate send. The one residual is a delivery whose
  attempt-1 row is still `dispatched` at that moment — a job queued behind a deep backlog for
  longer than the lease — which resumes and re-sends. That is the same accepted at-least-once
  property the system already carries for Async, reached by a new trigger; it is named here
  rather than hidden, and it is bounded by L1 and L2 holding.
- **The timing rule is stated as three links of different kinds because a future change needs
  to know which it may trade.** L1 and L2 are correctness and are tested. L3 is fitness and is
  a judgement about the workload — it is what decision 1 changes, by shrinking the `default`
  supervisor's longest legitimate unit of work from N remote calls to local work. Recording
  them as one undifferentiated "ordering convention" would invite someone to satisfy the
  arithmetic while breaking the meaning.
- **Decision 7 satisfies both Owner requirements with one change, and satisfies each of them
  better than a change aimed at it alone would.** *(Added 2026-08-26 — Revision A.)* Encryption
  would have addressed the security requirement while worsening the size one, and would have
  added a fourth ciphertext location with no re-encryption path. A size-only fix — capping the
  payload, or choosing drivers that tolerate 50 MiB — would have addressed neither the security
  requirement nor the portability one durably. Removing the payload from the job addresses both
  by deletion, and it is the only candidate that leaves the application's supported payload size
  independent of the queue driver entirely.
- **The strongest evidence that Decision 7 is right is that the codebase already agrees with
  it.** ADR-015 Decision 5 forbids payload bytes in a retry job's arguments and `RetryDelivery`
  has honoured that in production since #6, resolving every attempt from the second onwards
  through `StoredPayloadLookup`. Attempt 1 is the only delivery job in the system that does
  otherwise, and it does so because #1 built it before either the dispatched-output store or the
  retry path existed. Decision 7 is the removal of an inconsistency, not the introduction of a
  pattern — which is also why the change is small: the resolver being extracted already exists,
  inline, in a class that has been exercised by the suite since #6.
- **The one thing Decision 7 makes newly load-bearing should be watched.** The reference resolves
  totally only because every payload-mutating step is enhanced-mode-only and
  `CaptureDispatchedStep` is composed after all of them. That was previously a tidy property of
  `PipelineFactory`; it is now a correctness invariant, and violating it would deliver the wrong
  bytes with no error raised anywhere. It is stated as a binding constraint in Decision 7 and
  restated under § Constrained, and #8 and #9 are the items that will meet it.
- **Decisions 5 and 6 are settled here rather than deferred** because both live in the two
  files this ADR already changes, both concern the same lease and the same lock, and decision
  5 in particular protects the invariant decision 4 is enforcing — a guarded lease is worth
  more than a tested ordering between values one of which can silently become zero.

## Impact

- **No data-model change.** No new table, column, index, enum value or persisted value. Nothing
  to migrate, nothing to backfill, nothing to roll back in the database. This is stated
  explicitly because it is what makes the whole change revertible by reverting a commit.
- **No new dependency.** Nothing added to `composer.json` or `package.json`. Within
  `docs/stack/stack.md` as it stands.
- **Security — strictly reduced, and no longer an Owner gate.** *(Rewritten 2026-08-26 —
  Revision A; the original text asked the Owner to accept an exposure that Decision 7 removes.)*
  After Decision 7 **no payload byte enters any queue, in either processing mode**. The change
  therefore *closes* an exposure Async has carried since #4 rather than extending one to FIFO.
  A change that strictly reduces plaintext exposure and adds no new store is an ordinary
  technical decision, not a security approval — so the original flag (a) is withdrawn rather
  than re-put in a different form. Three consequences worth naming because they are wins that
  fall out rather than things that had to be designed:
  - The captured **headers** stop travelling in the job too. They are encrypted at rest in
    `webhook_events.headers` (ADR-014 Decision 2) but are plaintext inside the serialized
    `DeliveryUnit` today. Decision 7 removes that copy. This is not a substitute for #10's
    header policy and does not descope it.
  - **`failed_jobs` and Horizon's own job records stop containing payloads.** The job payload
    becomes two integers, so an exception trace cannot carry payload content regardless of
    `zend.exception_ignore_args`.
  - The **destination URL** stops travelling in the job as a serialized `Destination` model.

- **Which stores are "long-term" for the purpose of the Owner's requirement, and where the
  payload lives after this change.** *(Added 2026-08-26 — Revision A. The Owner's phrasing —
  "never available in plaintext in a **long term** store" — turns on this distinction, so it is
  enumerated rather than assumed.)* A store is treated as long-term here if it survives the
  process that wrote it **and** retains content under a policy rather than for the duration of
  one unit of work.

  *(Refined 2026-08-26 — Revision B, on the Project Owner's ruling that "a cache can be a short
  term store … if a cache is living for minutes or an hour, we should be ok without worrying
  about at rest encryption".)* **Duration is part of the definition, not merely a consequence of
  it.** A store is long-term when it survives the writing process **and** retains content for
  materially longer than the work that produced it — so a retention window measured in days is
  long-term, and a store whose entries expire in minutes to an hour is short-term and does not
  carry the at-rest-encryption requirement. Both criteria matter: surviving the process is what
  distinguishes a store from memory, and duration is what distinguishes a durable record from a
  transient one. Every row of the table below is decided the same way under either reading, which
  is why the refinement changes no entry.

  **The Owner has deferred the detailed rules, and this ADR does not invent one.** No threshold
  is chosen here. Stated plainly so it is not mistaken for an oversight: **the boundary needs a
  number to be usable as a test**, and that number is the Project Owner's to set at the point a
  concrete short-lived store is actually proposed — at which point it would be decided against
  that store's real retention rather than in the abstract. Until then the principle governs and
  the two ends of the range are unambiguous.

  | Store | Long-term? | Payload after Decision 7 |
  |---|---|---|
  | `webhook_events.body` / `.headers` | Yes — 30-day retention window | Present, **encrypted at rest** (ADR-010 Amendment B, ADR-014 Decision 2), erased in place by GC |
  | `dispatched_payloads.body` | Yes — same lifecycle | Present when the output diverged, **encrypted at rest**, erased by the same pass |
  | `failed_jobs.payload` (database) | Yes — pruned at 7 days by the daily `queue:prune-failed --hours 168` | **Absent.** Two integers |
  | Horizon's Redis job records (`recent`/`completed` 60 min; `failed`/`monitored` 7 days, `horizon.trim`) | Yes — a **second** independent 7-day retention of the same job payload, which `queue:prune-failed` does not touch and Horizon trims itself | **Absent.** Two integers |
  | Redis queue list / reserved entry for a job about to run | **No** — it survives the writing process but is held only for the life of that unit of work, even where Redis persistence (RDB/AOF) puts it on disk meanwhile | **Absent.** Two integers |
  | Worker process memory; the outbound HTTPS request | No | Present, necessarily. Out of scope of the requirement |

  **So the requirement is met.** The only long-term stores that hold the payload are the two the
  application deliberately owns, both already encrypted at rest under an Accepted position, and
  both reachable by ADR-012's erase-in-place. Nothing else holds it. Verified rather than
  assumed on the Horizon side: `Laravel\Horizon\JobPayload` stores the raw payload string
  unchanged and `RedisJobRepository` writes `$payload->value` verbatim — Horizon never decrypts,
  never unserializes the command, and derives its tags from the in-memory job object before
  serialization, so it neither stores nor displays anything the queue does not already hold.

- **Key rotation needs no new rule.** *(Added 2026-08-26 — Revision A, answering the Owner's
  question directly.)* Because no queue message holds ciphertext, an `APP_KEY` rotation cannot
  strand an in-flight job or make a `failed_jobs` row unretriable. The key-lifecycle surface is
  unchanged at the three encrypted columns across two tables that ADR-014 already enumerates,
  under ADR-010 Amendment B's binding rule — a prior key is never dropped from
  `APP_PREVIOUS_KEYS` until a re-encryption pass has rehashed every row. `config/app.php`
  already wires `previous_keys` from `APP_PREVIOUS_KEYS`. **This ADR adds nothing to that
  surface and imposes no new operational constraint.** Had the encryption alternative been
  taken, it would have added a fourth ciphertext location with no row to re-encrypt and would
  have needed a new rule.

- **Retention interaction — no bypass, and one ordering worth writing down.** *(Added
  2026-08-26 — Revision A.)* After Decision 7 the question mostly dissolves: neither
  `failed_jobs` nor Horizon's records contain a payload, so ADR-012's erase-in-place is not
  bypassed by them and needs no new position. The ordering that made it safe even before this
  change should still be stated, because it is a fourth undocumented dependency between
  independently-tunable values: `queue:prune-failed`'s hard-coded 168 hours in
  `routes/console.php` must stay **below** the resolved retention window
  (`retention.days`, default 30), or a failed job's copy of a payload would outlive the erase
  that was supposed to destroy it. It does today, by 23 days. `RETENTION_DAYS` is
  env-overridable and documented as dev/test convenience only, so the inversion is reachable
  locally and not in a deployed configuration. Named here rather than tested, because after
  Decision 7 there is no payload in `failed_jobs` for the inversion to expose.
- **Code — the complete change set.** Named precisely enough to implement, and nothing outside
  this list:
  - `app/Actions/DeliverStep.php` — remove the `processing_mode` branch and the inline
    `DeliverToDestination::run($unit)` call; always dispatch **by reference** onto
    `config('ingest.webhooks_queue')` with `afterCommit()`. *(Amended 2026-08-26 — Revision A:
    by reference, not with a `DeliveryUnit`.)* The step no longer builds `DeliveryUnit`s at all,
    so the `DeliveryUnit` and `ProcessingMode` imports and the `$async` local all go. It still
    iterates the dispatch's `deliveries` rows with `->with(['destination' => …withTrashed()])`
    only if it still needs the relation; after this change it needs nothing but each row's `id`,
    so the eager load can go too. The docblock's description of the two modes is rewritten.
  - **A single shared resolver that builds a `DeliveryUnit` from `(Delivery, int $attemptNumber)`**
    — the block that exists today inline in `RetryDelivery::handle()`: guard the parent event's
    `payload_cleaned_at`, load the destination `withTrashed()`, take headers from the captured
    event, and take the bytes from `StoredPayloadLookup::dispatchedBytesFor()`. Extracting it is
    what keeps attempt 1 and attempts 2..N provably identical rather than similar. Exact
    placement is the implementer's call — a small service alongside `StoredPayloadLookup`, which
    must itself stay single-purpose and must remain the only interpreter of
    `dispatched_payloads.body IS NULL` (ADR-013 Decision 3). What is **required**: one resolver,
    used by both entry points, that signals "parent cleaned" distinguishably from "resolved" and
    never returns an empty payload to mean either.
  - `app/Actions/DeliverToDestination.php` — gains a by-reference entry point taking
    `(int $deliveryId, int $attemptNumber)`, which resolves the unit through that resolver and
    then runs the existing logic unchanged. The cleaned branch terminalizes per
    `RetryDelivery::terminalizeCleaned()`, with the compare-and-set keyed on `pending` **and**
    `retrying` so it is correct for attempt 1; no attempt row is written and no send is made.
    The existing `handle(DeliveryUnit $unit)` behaviour — attempt-row create-or-resume, send,
    settle, FIFO completion check — is **not** otherwise changed by this ADR.
  - `app/Actions/RetryDelivery.php` — its inline unit-building block is replaced by a call to
    the shared resolver. Its `retrying` status guard, its stale-fire early return and its
    `terminalizeCleaned()` semantics are unchanged. **`RetryDelivery` is not merged into
    `DeliverToDestination`**: its guard is specific to attempts 2..N and collapsing the two
    would widen this change well past the defect it fixes.
  - `app/Pipeline/DeliveryUnit.php` — unchanged. It remains the in-process input to a send; it
    simply stops being serialized into a queue message.
  - **No `SerializesModels` on any of it** *(added 2026-08-26 — Revision B, Decision 9)*: the
    delivery job's arguments are two integers, and every model is loaded inside the shared
    resolver. `JobDecorator` applies the trait to top-level parameters automatically, so passing
    a model would silently opt in — which is exactly why the argument list must stay scalar.
    This is a constraint on the implementation, not a preference.
  - `app/Actions/AdvanceProxyFifoQueue.php` — `settleOrHold()` per decision 3, including the
    `settleAndAdvance()` helper and the compare-and-set on the settle path; the two
    `(int) config('ingest.fifo_lease_seconds')` reads route through the decision 5 guard;
    `->dontRelease()` on the `WithoutOverlapping` instance; the class docblock's "the proxy is
    FIFO, so DeliverStep runs delivery inline and this returns only once the whole event has
    been delivered" is now false and must be rewritten.
  - The decision 5 guard itself — a single fail-loud reader for `ingest.fifo_lease_seconds`,
    following `RetryPolicy::positiveConfigInt()`. Placement is the implementer's call between a
    small service and a private helper shared by the advancer and the sweeper; what is
    required is that it is the *only* read site and that a blank, zero, negative or
    non-numeric value throws rather than coercing.
  - `app/Actions/SweepStalledFifoDispatches.php` — no behavioural change; its docblock
    references to `awaiting_retry` as a retry-specific state are widened to match decision 2.
  - `app/Enums/FifoDispatchStatus.php` — docblock only, and only if the implementer judges the
    current wording insufficiently explicit; it already reads correctly.
  - `config/horizon.php`, `config/queue.php`, `config/ingest.php` — comments only. Each of the
    three gains a pointer to this ADR; `config/horizon.php`'s `supervisor-default` block keeps
    its explanation of L2 and drops the sentence about N sequential HTTP sends, which decision
    1 makes false. **No value changes**: 15, 60, 90 and 180 all stay exactly as they are.
- **Tests — what must be added, and what legitimately changes.**
  - `tests/Unit/Actions/DeliverStepTest.php::test_fifo_proxy_runs_each_delivery_inline_without_queueing`
    asserts the behaviour this ADR reverses. It is **superseded, not weakened** — it should be
    replaced by a test asserting that a FIFO proxy pushes one `DeliverToDestination` per
    delivery onto the webhooks queue, mirroring the existing Async case. Note there is a second
    file, `tests/Unit/Pipeline/DeliverStepTest.php`, of the same class name in a different
    namespace; check both.
  - `test_builds_exactly_n_units_each_carrying_the_matching_delivery_rows_id` asserts against
    `$params[0]` being a `DeliveryUnit`. It is **superseded, not weakened**: the assertion
    becomes that the pushed arguments are the delivery id and attempt number. *(Added
    2026-08-26 — Revision A.)*
  - **A test that no queued job's arguments contain payload bytes** — the direct expression of
    Decision 7 and of the Owner's requirement, and the one that will still be there in a year.
    Assert against the *serialized* job payload rather than the in-memory object, since that is
    what reaches the store: capture what is pushed and assert the distinctive body string does
    not appear anywhere in it. Worth asserting for the captured **header** values too.
    *(Added 2026-08-26 — Revision A.)*
  - **A test that attempt 1 and a retry resolve identical bytes**, covering both sides of the
    divergence gate: an enhanced-mode proxy whose dispatched output diverged (resolves
    `dispatched_payloads.body`) and a simple-mode proxy with no `dispatched_payloads` row at all
    (resolves `webhook_events.body`). This is the property that makes the reference total, and
    it is the one a future simple-mode transform step would break. *(Added 2026-08-26 —
    Revision A.)*
  - **A test for the cleaned-parent branch at attempt 1**: a delivery whose parent event is
    already cleaned must end `failed` with `DeliveryExhausted` emitted once, **zero
    `delivery_attempts` rows written and zero HTTP sends** — the AC17 posture, now reachable on
    attempt 1 for the first time. *(Added 2026-08-26 — Revision A.)*
  - A new test for decision 3's re-check: drive the state machine directly rather than through
    a dispatch, because `QUEUE_CONNECTION=sync` in `phpunit.xml` makes `dispatch()` run inline
    and therefore makes parallel fan-out indistinguishable from the inline path under test.
    The shape that matters: a `claimed` row whose deliveries all reach terminal state *between*
    the existence check and the transition must end `settled` with exactly one advancer
    dispatched, not parked in `awaiting_retry`.
  - A test that a stale advancer cannot settle a row it no longer holds: a `fifo_dispatches`
    row moved to `pending` or re-`claimed` must not be flipped to `settled` by the earlier
    advancer's `settleOrHold`, and no advancer is dispatched.
  - `tests/Unit/Config/QueueTimingTest.php` — new, asserting L1 and L2 from resolved
    configuration across `horizon.defaults` **and** every `horizon.environments.*` override.
  - Guard tests for decision 5 mirroring the `retry.*` ones: blank, zero, negative and
    non-numeric each throw. `tests/Unit/Config/IngestConfigTest.php` is the existing home for
    `ingest.*` config assertions.
  - The FIFO ordering and liveness acceptance tests (`tests/Feature/Ingest/FifoOrderingAcceptanceTest.php`,
    `FifoLivenessAcceptanceTest.php`, `tests/Feature/Retry/FifoRetrySettlementTest.php`,
    `FifoRetryCompositionAcceptanceTest.php`) should pass unchanged, because under `sync` a
    dispatch runs inline. **That is a hazard, not a reassurance**: their passing does not
    evidence that parallel fan-out is correct. Any that changes must be reported with the
    reason, and none may be weakened to reach green.
- **The standing caveat the review-04 follow-up already records** — PHPUnit on a single
  connection cannot interleave two live claim transactions, so a real-concurrency integration
  test for the single-advancer window remains a backlog item. Decision 3's tests drive the
  state machine deterministically instead, which is the achievable substitute, and the ADR says
  so rather than claiming coverage it does not have.
- **Operationally.** FIFO delivery latency now depends on the `webhooks` queue's depth, which
  it did not before. At most one event per FIFO proxy is in flight, so the added load is at
  most N jobs per proxy at a time. The `webhooks` supervisor already autoscales to 20
  processes in production while `supervisor-default` scales to 8; decision 1 moves work from
  the smaller pool to the larger one, which is the right direction.
- **Constrained, for later items.** `DeliverStep` remains the terminal pipeline step. When #12
  adds `ChangeDetectStep` as the enhanced-mode tail stage, it must not assume that deliveries
  have completed when it runs — which was already true for Async proxies and is now true for
  every proxy, so decision 1 removes a mode-dependent trap rather than creating one.
  *(Added 2026-08-26 — Revision A, and binding on #8, #9 and #10.)* Two further constraints,
  restated here because breaking either produces a wrong delivery with no error raised:
  **`CaptureDispatchedStep` must remain the last pipeline step before `DeliverStep`**, and **no
  payload-mutating step may be composed outside the enhanced-mode branch**. `PipelineFactory`'s
  commented insertion contract already satisfies both; after Decision 7 that placement is a
  correctness requirement rather than a convention. A future requirement for a simple-mode
  transform is a requirements conversation with the Product Manager *and* a change to this ADR,
  not a composition tweak. Also: **no queued job may be given payload bytes as an argument** —
  the rule ADR-015 Decision 5 states for retries, now general.

## Owner-approval flags (✋)

*(Rewritten 2026-08-26 — Revision A. The original flag (a) is **withdrawn**: it asked the Owner
to accept a payload-on-the-queue exposure, and Decision 7 removes the exposure instead of
trading it. Nothing replaces it — there is no residual queue-payload exposure to approve.)*

**One item — ~~outstanding~~ APPROVED (Project Owner, 2026-08-26).** Nothing on this ADR remains
outstanding with the Owner. The heading is kept rather than deleted, per house convention: a
struck-through gate with its approval date is the record.

1. **~~Partial supersession of an Accepted ADR.~~ APPROVED — Project Owner, 2026-08-26.**
   Positions **P4** (Decision 2) and **P5**
   (Decision 3) of ADR-011 (Accepted, Project Owner, 2026-08-04), together with the amendment
   to ADR-016 Decision 1 (Accepted, Project Owner, 2026-08-12), per § Positions superseded.
   ADR-011 and ADR-016 keep their files, their status and their full text; each gains an inline
   annotation pointing here, now recording the approval and its date rather than a pending
   supersession.

   Two things the Owner should weigh with it, neither of which is a separate gate.
   **First, P5 changes shipped Async behaviour**, not only the FIFO path this ADR was opened
   for. Async has dispatched attempt 1 with the payload in the job since #4; after this it
   dispatches by reference like every other attempt. That is a behaviour change to working code,
   and it is in the gate because it supersedes a ratified position — not because it carries risk
   the ADR has not addressed. **Second, the direction of travel on security is downward**: the
   change removes plaintext payload and plaintext captured headers from the queue, from
   `failed_jobs` and from Horizon's records, and adds no store and no key-lifecycle surface.

Explicitly **not** in the gate, verified item by item: no new table, column, index or enum
value; no change to any existing column's type or meaning; no backfill; no migration at all;
no new dependency; no change to `docs/stack/stack.md`; no change to any configuration
**value** (only comments); no change to the retry policy, its curves, or any scheduled cadence;
no change to PRD-06 AC6 or to any acceptance criterion of any feature. Added at Revision A, and
equally verified: **no new at-rest copy of payload or header content anywhere**; no new
encrypted store and so no fourth position on the ADR-010 Amendment B key surface; no new
operational rule; no change to ADR-012's holds H0–H5 or to any retention value. The FIFO
guarantee stated at the head of this document is unchanged in all six of its points.

## What is not decided here, and whose call it is

- **The product question was raised and answered, so nothing is outstanding.** "After all
  destinations have been dispatched" admits a dispatch-ordered reading under which event 2
  begins once event 1's jobs are enqueued. The Project Owner answered "Delivered" on
  2026-08-26, which is the settlement-ordered reading the system already implements and which
  PRD-06 AC6 already requires. **No requirements change follows and nothing returns to the
  Product Manager or the Project Owner on that point.** It is recorded here because a later
  reader will meet the same ambiguity in the same sentence.
- **No user-visible copy changes**, so nothing goes to the Designer. FIFO and Async remain the
  same two selectable modes with the same meanings; nothing in the proxy form, the proxy show
  page or the events list describes how fan-out is executed.
- **The encryption question was ruled, not escalated.** *(Added 2026-08-26 — Revision A.)* The
  Owner asked whether the payload can be encrypted in the queue. The answer is that it can be
  removed from the queue instead, which satisfies the stated requirement more completely and
  costs less, so no encryption decision is put back to the Owner. Should the Owner nonetheless
  want queue-payload encryption as defence in depth on some *other* job, the findings at
  § Alternatives apply: it is not a one-line interface adoption under `lorisleiva/laravel-actions`,
  and it would need a new key-rotation rule.
- **The 50 MiB inbound body cap is not this ADR's to set.** *(Added 2026-08-26 — Revision A.)*
  `config/ingest.php` already flags it as a deliberately high placeholder to be revisited before
  MVP. Decision 8 removes the *queue driver* from the list of things that constrain it, which is
  the technical half; choosing the number is a product decision for the Product Manager and the
  Project Owner, and is unchanged by this ADR.
- **The pre-existing Async queue-size exposure is recorded, not fixed by fiat.** Decision 8
  states it as a live latent limitation in shipped code since #4. Decision 7 closes it, but the
  finding stands on its own and should be visible in `docs/status.md` independently of whether
  this ADR is Accepted — that file is the Orchestrator's.
- **The `docs/fixes/` record is the Senior Developer's**, per this project's convention, and is
  written on implementation rather than here.
