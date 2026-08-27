# ADR-020: FIFO advancer job duration and claim-lease safety — parallel fan-out inside an event, a race-safe settle-or-hold, and an ordered lease/timeout/`retry_after` rule (partially supersedes ADR-011 Decision 2, amends ADR-016 Decision 1)

- **Status:** **Proposed** — Project Owner approval required. Two gates, both at
  § Owner-approval flags: (a) the security consequence of putting a FIFO proxy's outbound
  payload onto the Redis queue, and (b) the partial supersession of a named position of
  ADR-011 (Accepted, Owner 2026-08-04). **No code may be written against this ADR until it
  is Accepted.**
- **Author:** Principal Engineer
- **Date:** 2026-08-26
- **Feature:** none — this is a latent correctness defect in shipped behaviour, found while
  configuring Laravel Horizon on branch `feat/horizon` (PR #18, unmerged). Horizon does not
  cause it; Horizon made an existing mismatch visible.
- **Relationship to prior ADRs:** **partially supersedes ADR-011** (one named position, P4 —
  see § Positions superseded) and **amends ADR-016 Decision 1** (the meaning of the
  `awaiting_retry` hold, and the concurrency safety of the settle-or-hold decision). Every
  other position of both ADRs stands, Accepted and operative, and is relied on here: the
  claim-based single advancer, the atomic `FOR UPDATE` claim, the lease plus sweeper liveness
  net, `WithoutOverlapping` as a reducer and not the ordering guard, dispatch-by-reference at
  the pipeline entry, the sidecar-table placement, the `awaiting_retry` line hold, the
  row-`id` order key, and the three sweeper passes.
- **Companions:** ADR-005 (the dispatch-timing seam and its four guardrails) · ADR-015 (the
  retry machinery whose waits the hold represents) · ADR-003 (payload-free attempt records)

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
does.** `DeliverStep` loses its `processing_mode` branch entirely: every delivery, in both
modes, is `DeliverToDestination::dispatch($unit)->onQueue(config('ingest.webhooks_queue'))->afterCommit()`.
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

## Positions superseded and amended

| Prior position (verbatim) | Superseded / amended to |
|---|---|
| **P4 — ADR-011 Decision 2:** the advancer "processes that one event **to settlement**, marks it `settled`, then self-dispatches to advance" | The advancer *initiates* the event's delivery and hands the settle-and-advance decision to whichever actor completes the dispatch's last delivery. The **guarantee** the position served — event 2 is not claimed until event 1 has fully settled — is unchanged and is what decisions 2 and 3 preserve. Only the actor changes. ADR-016 Decision 1 had already made this position conditionally untrue for the retry case; decision 1 here makes the held branch the ordinary path. |
| **ADR-016 Decision 1 (amended, not superseded):** the advancer transitions to `awaiting_retry` "for the dispatch's in-progress retry schedule", and the status is described throughout as representing "head is between attempts" | `awaiting_retry` represents "head is not yet settled" — which includes a first attempt still in flight or still queued, as well as a backoff wait. The lifecycle, the transitions, the busy gate, the three sweeper passes and the GC hold H2 interaction are all unchanged and all remain correct under the wider reading. No enum value, column value, or migration changes. Additionally, the settle-or-hold decision becomes concurrency-safe per decision 3; ADR-016's description of it is otherwise unchanged. |

Explicitly **not** superseded or amended, verified one at a time so a later reader can check
rather than trust:

- **ADR-011 Decision 1** (`processing_mode` enum on `proxies`) — untouched. The column keeps
  its meaning; it simply stops selecting an inline-versus-queued delivery path and continues
  to select whether the proxy owns `fifo_dispatches` rows at all.
- **ADR-011 Decision 2's remaining content** — the sidecar table, the atomic `FOR UPDATE`
  claim as the correctness primitive, the lease plus sweeper as the liveness net, and
  `WithoutOverlapping` as a thundering-herd reducer rather than the guard. All relied on here.
- **ADR-011 Decision 3** (pipeline entry dispatched by reference; per-destination delivery
  carries its `DeliveryUnit` including the pipeline's *output* payload, so a later mapped
  payload from #8 flows to delivery unchanged) — untouched, and load-bearing: it is why
  decision 1 cannot be made cheaper by dispatching delivery by reference. See § Alternatives.
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
- **Dispatch `DeliverToDestination` by reference for FIFO, to keep payloads off the queue.**
  Would sidestep the security consequence at § Owner-approval flags (a), but ADR-011 Decision
  3 requires per-destination delivery to carry the pipeline's *output* payload precisely so a
  later mapped payload (#8) reaches the destination; a by-reference delivery job would have to
  re-read the raw capture and would deliver the unmapped body. It would also make FIFO and
  Async's attempt-1 paths diverge again, which is the divergence this ADR is removing.
  Rejected — the exposure is named and put to the Owner instead.
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
- **Security (Owner-gated ✋ — see flag (a)).** Decision 1 puts a FIFO proxy's outbound payload
  onto the Redis queue, inside the serialized `DeliveryUnit`, and therefore into `failed_jobs`
  if such a job fails. **FIFO proxies today do not have this exposure**: their advancer job
  carries only an integer proxy id, the pipeline entry is dispatched by reference, and the
  payload never leaves the worker process. Async proxies have carried it since #4. The
  exposure class is the one the Owner accepted at Q-05-06 D2 / ruling E1 on 2026-08-25,
  mitigated by the daily `queue:prune-failed --hours 168`, with the real fix owned by #10. This
  ADR does not create a new class of exposure; it extends an accepted one to a mode that was
  incidentally free of it, and that is the Owner's call rather than the Principal Engineer's.
- **Code — the complete change set.** Named precisely enough to implement, and nothing outside
  this list:
  - `app/Actions/DeliverStep.php` — remove the `processing_mode` branch and the inline
    `DeliverToDestination::run($unit)` call; always dispatch onto
    `config('ingest.webhooks_queue')` with `afterCommit()`. The `ProcessingMode` import and the
    `$async` local go with it. The docblock's description of the two modes is rewritten.
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

## Owner-approval flags (✋)

Two items. Both must be ruled before any code is written; the rest of this ADR is not
separately actionable, since it is one change.

1. **Extending an accepted security exposure to FIFO proxies.** Decision 1 causes a FIFO
   proxy's outbound payload to be serialized into the Redis queue in every
   `DeliverToDestination` job, and into `failed_jobs` if such a job fails outside
   `DeliverToDestination`'s own `try`. FIFO proxies currently have no such exposure; Async
   proxies have had it since #4. The exposure class was accepted by the Project Owner on
   2026-08-25 (Q-05-06 D2, ruling E1) with the daily seven-day `queue:prune-failed` as the
   stated mitigation and #10 named as the owner of the real fix. **What is being approved is
   the extension of that accepted exposure to FIFO proxies, not a new class of exposure.** The
   alternative that would avoid it — dispatching delivery by reference — is rejected at
   § Alternatives for breaking ADR-011 Decision 3.
2. **Partial supersession of an Accepted ADR.** Position P4 of ADR-011 Decision 2 (Accepted,
   Project Owner, 2026-08-04), together with the amendment to ADR-016 Decision 1 (Accepted,
   Project Owner, 2026-08-12), per § Positions superseded. ADR-011 and ADR-016 keep their
   files, their status and their full text; each gains an inline annotation pointing here,
   phrased as a pending supersession until this ADR is Accepted.

Explicitly **not** in either gate, verified item by item: no new table, column, index or enum
value; no change to any existing column's type or meaning; no backfill; no migration at all;
no new dependency; no change to `docs/stack/stack.md`; no change to any configuration
**value** (only comments); no change to the retry policy, its curves, or any scheduled cadence;
no change to PRD-06 AC6 or to any acceptance criterion of any feature. The FIFO guarantee
stated at the head of this document is unchanged in all six of its points.

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
- **The `docs/fixes/` record is the Senior Developer's**, per this project's convention, and is
  written on implementation rather than here.
