<?php

namespace Tests\Unit\Actions;

use App\Actions\AdvanceProxyFifoQueue;
use App\Enums\DeliveryStatus;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Concerns\DrainsQueuedDeliveries;
use Tests\TestCase;

class AdvanceProxyFifoQueueTest extends TestCase
{
    use DrainsQueuedDeliveries;

    /**
     * Invokes the private `settleOrHold()` directly — driving the state
     * machine rather than through a dispatch, because `QUEUE_CONNECTION=sync`
     * makes `dispatch()` run inline and therefore makes parallel fan-out
     * indistinguishable from the inline path under test (ADR-020 §Tests).
     */
    private function invokeSettleOrHold(AdvanceProxyFifoQueue $advancer, FifoDispatch $claimed, int $proxyId): void
    {
        $method = new ReflectionMethod(AdvanceProxyFifoQueue::class, 'settleOrHold');
        $method->setAccessible(true);
        $method->invoke($advancer, $claimed, $proxyId);
    }

    /**
     * A FIFO proxy with one destination and `$count` pending fifo_dispatches rows,
     * whose webhook_event_ids ascend in receive order (evt-1, evt-2, ...).
     *
     * @return array{0: Proxy, 1: Collection<int, FifoDispatch>}
     */
    private function fifoProxyWithPending(int $count): array
    {
        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly();

        $dispatches = collect(range(1, $count))->map(function (int $i) use ($proxy): FifoDispatch {
            $event = WebhookEvent::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $proxy->team_id,
                'ingest_id' => "evt-{$i}",
            ]);

            return FifoDispatch::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $proxy->team_id,
                'webhook_event_id' => $event->id,
            ]);
        });

        return [$proxy, $dispatches];
    }

    public function test_settles_pending_rows_one_at_a_time_in_id_order(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(3);

        foreach (['evt-1', 'evt-2', 'evt-3'] as $position => $expectedIngestId) {
            AdvanceProxyFifoQueue::run($proxy->id);
            // Delivery is queued by reference (ADR-020 Decision 1) — drain it in
            // place, standing in for the real worker, so the row's completion
            // check has something to settle against.
            $this->drainQueuedDeliveries();

            // The expected row is settled and delivered before the next is touched.
            $settled = $dispatches[$position]->fresh();
            $this->assertSame(FifoDispatchStatus::Settled, $settled->status);
            $this->assertNotNull($settled->settled_at);
            $this->assertDatabaseHas('delivery_attempts', ['ingest_id' => $expectedIngestId]);

            // Later rows remain pending until their turn.
            for ($later = $position + 1; $later < 3; $later++) {
                $this->assertSame(FifoDispatchStatus::Pending, $dispatches[$later]->fresh()->status);
            }
        }

        // Deliveries happened in receive order.
        $this->assertSame(
            ['evt-1', 'evt-2', 'evt-3'],
            DeliveryAttempt::orderBy('id')->pluck('ingest_id')->all(),
        );

        // Each successful advance self-dispatches to advance the line.
        AdvanceProxyFifoQueue::assertPushed(3);
    }

    public function test_is_a_no_op_when_no_rows_are_pending(): void
    {
        Queue::fake();
        Http::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);

        AdvanceProxyFifoQueue::run($proxy->id);

        Http::assertNothingSent();
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_is_a_no_op_when_a_live_claim_already_exists(): void
    {
        Queue::fake();
        Http::fake();

        [$proxy, $dispatches] = $this->fifoProxyWithPending(2);

        // Simulate another advancer already holding a live claim on the first row.
        $dispatches[0]->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);

        AdvanceProxyFifoQueue::run($proxy->id);

        // Nothing else claimed, no delivery, no self-dispatch.
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        Http::assertNothingSent();
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_does_not_claim_or_dispatch_for_a_paused_proxy(): void
    {
        // Item #15, Q-15-01(2): a paused proxy's rows must never be claimed.
        Queue::fake();
        Http::fake();

        [$proxy, $dispatches] = $this->fifoProxyWithPending(2);
        // `paused_at` is deliberately not mass-assignable (only the pause action
        // writes it) — forceFill stands in for that action here.
        $proxy->forceFill(['paused_at' => now()])->save();

        AdvanceProxyFifoQueue::run($proxy->id);

        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[0]->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        Http::assertNothingSent();
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_resuming_a_paused_proxy_drains_its_backlog_in_the_same_order_it_would_have_used(): void
    {
        // AC5: order derives from the atomic claim, not from timing — a proxy
        // paused for a while drains in the same order it would have.
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(3);
        $proxy->forceFill(['paused_at' => now()->subDays(7)])->save();

        // While paused, repeated advancer attempts (e.g. a stray self-dispatch
        // or sweeper nudge racing the pause) claim nothing.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[0]->fresh()->status);

        $proxy->forceFill(['paused_at' => null])->save();

        foreach (['evt-1', 'evt-2', 'evt-3'] as $position => $expectedIngestId) {
            AdvanceProxyFifoQueue::run($proxy->id);
            $this->drainQueuedDeliveries();

            $settled = $dispatches[$position]->fresh();
            $this->assertSame(FifoDispatchStatus::Settled, $settled->status);
            $this->assertDatabaseHas('delivery_attempts', ['ingest_id' => $expectedIngestId]);
        }

        $this->assertSame(
            ['evt-1', 'evt-2', 'evt-3'],
            DeliveryAttempt::orderBy('id')->pluck('ingest_id')->all(),
        );
    }

    public function test_the_claim_commits_before_the_outbound_delivery_fires(): void
    {
        Queue::fake();

        [$proxy, $dispatches] = $this->fifoProxyWithPending(1);
        $dispatchId = $dispatches[0]->id;

        // The ambient level outside any of the action's own transactions (the test
        // harness's own wrapping transaction, if any, is already open at this point).
        $ambientTransactionLevel = DB::transactionLevel();

        // At the moment of the outbound send, the claim transaction must be closed
        // (back to the ambient level) and the row already held — never `claimed`
        // with the row lock still implicated — the row lock is never held across
        // the network call (ADR-005 (a)). Since ADR-020 Decision 1 the send itself
        // happens in a separately-queued `DeliverToDestination`, drained below, by
        // which point the advancer has already moved the row to `awaiting_retry`
        // (Decision 2/3): the row is never reaped as `claimed` while a delivery for
        // it is in flight.
        Http::fake(function () use ($dispatchId, $ambientTransactionLevel) {
            $this->assertSame($ambientTransactionLevel, DB::transactionLevel());
            $fresh = FifoDispatch::query()->findOrFail($dispatchId);
            $this->assertSame(FifoDispatchStatus::AwaitingRetry, $fresh->status);

            return Http::response('ok', 200);
        });

        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
    }

    public function test_claims_the_lowest_id_not_the_lowest_webhook_event_id(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly();

        $oldEvent = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'evt-old',
        ]);

        $newerEvent = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'evt-newer',
        ]);

        // A genuinely older PENDING row (arrived after $oldEvent, before the replay).
        $genuinelyOlderPending = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $newerEvent->id,
        ]);

        // A replay of the OLD event: created LAST (fresh, highest id — back of the
        // line, AC11), but it carries the OLD event's low webhook_event_id.
        $replay = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $oldEvent->id,
            'dispatch_uuid' => (string) Str::uuid(),
        ]);

        // Sanity on the fixture: the divergence this test proves actually exists.
        $this->assertLessThan($replay->id, $genuinelyOlderPending->id);
        $this->assertLessThan($genuinelyOlderPending->webhook_event_id, $replay->webhook_event_id);

        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        // The lowest-id row is claimed first — NOT the lowest-webhook_event_id row
        // (the replay does not jump the queue, ADR-016 Decision 3 / AC11).
        $this->assertSame(FifoDispatchStatus::Settled, $genuinelyOlderPending->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $replay->fresh()->status);
        $this->assertDatabaseHas('delivery_attempts', ['ingest_id' => 'evt-newer']);
        $this->assertDatabaseMissing('delivery_attempts', ['ingest_id' => 'evt-old']);
    }

    public function test_settles_and_advances_when_every_destination_settles_immediately(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->count(2)->for($proxy)->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'evt-1',
        ]);
        $dispatch = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
        ]);

        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        // Every destination succeeded on attempt 1 — no non-terminal deliveries
        // remain, so the row settles and the line advances exactly as before #6.
        $fresh = $dispatch->fresh();
        $this->assertSame(FifoDispatchStatus::Settled, $fresh->status);
        $this->assertNotNull($fresh->settled_at);
        $this->assertSame(2, Delivery::where('dispatch_uuid', $dispatch->dispatch_uuid)
            ->where('status', DeliveryStatus::Succeeded)->count());
        AdvanceProxyFifoQueue::assertPushed(1);
    }

    public function test_holds_the_line_when_a_delivery_is_left_non_terminal(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('nope', 500)]);

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly();

        $event = WebhookEvent::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'ingest_id' => 'evt-1',
        ]);
        $dispatch = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
        ]);

        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        // Attempt 1 failed but is below the retry limit — the delivery is left
        // `retrying` (non-terminal), so the row holds: claimed -> awaiting_retry,
        // no lease, no self-dispatch (ADR-016 Decision 1).
        $fresh = $dispatch->fresh();
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $fresh->status);
        $this->assertNull($fresh->claimed_at);
        $this->assertNull($fresh->lease_expires_at);
        $this->assertNull($fresh->settled_at);
        $this->assertSame(
            DeliveryStatus::Retrying,
            Delivery::where('dispatch_uuid', $dispatch->dispatch_uuid)->firstOrFail()->status,
        );
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    public function test_a_held_awaiting_retry_row_blocks_the_next_claim(): void
    {
        Queue::fake();
        Http::fake();

        [$proxy, $dispatches] = $this->fifoProxyWithPending(2);

        // The head is between attempts: held, no lease (ADR-016 Decision 1).
        $dispatches[0]->update(['status' => FifoDispatchStatus::AwaitingRetry]);

        AdvanceProxyFifoQueue::run($proxy->id);

        // The second row is never claimed while the head is held — even though no
        // lease is live to trip the pre-#6 busy check.
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        Http::assertNothingSent();
        AdvanceProxyFifoQueue::assertNotPushed();
    }

    // --- ADR-020 Decision 3: settle-or-hold's race safety, driven directly ---

    /**
     * The shape that matters (ADR-020 §Tests): a claimed row whose deliveries
     * all reach terminal state BETWEEN the existence check and the hold being
     * published must still end `settled`, with exactly one advance dispatched
     * — never parked in `awaiting_retry`. Injected via `DB::listen()` at the
     * exact moment the hold-publish UPDATE fires, so the delivery settles in
     * the precise window the re-check exists to cover.
     */
    public function test_settle_or_hold_re_check_catches_a_delivery_that_settles_in_the_hold_publish_window(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $dispatch = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);
        $delivery = Delivery::factory()->create([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $dispatch->dispatch_uuid,
            'status' => DeliveryStatus::Pending,
        ]);

        $flipped = false;
        DB::listen(function ($query) use ($delivery, &$flipped): void {
            if ($flipped
                || ! str_contains($query->sql, 'update `fifo_dispatches`')
                || ! in_array('awaiting_retry', $query->bindings, true)
            ) {
                return;
            }

            $flipped = true;

            // The delivery settles in the exact window between the existence
            // check (which just saw it `pending`) and the hold being published.
            Delivery::query()->whereKey($delivery->id)->update([
                'status' => DeliveryStatus::Succeeded->value,
                'next_attempt_at' => null,
            ]);
        });

        $this->invokeSettleOrHold(new AdvanceProxyFifoQueue, $dispatch, $proxy->id);

        $this->assertTrue($flipped, 'Precondition: the hold-publish query fired and the race was injected.');
        $this->assertSame(FifoDispatchStatus::Settled, $dispatch->fresh()->status);
        AdvanceProxyFifoQueue::assertPushed(1, fn ($job, array $params) => $params[0] === $proxy->id);
    }

    /**
     * A stale advancer — one whose in-memory `$claimed` row no longer matches
     * the row's real, current status (reaped and/or re-claimed by another
     * advancer since) — must not settle it. The settle path is a
     * compare-and-set keyed on the expected prior status, never a blind
     * update by primary key (today's bug, ADR-020 §Question).
     */
    public function test_settle_or_hold_a_stale_advancer_cannot_settle_a_row_it_no_longer_holds(): void
    {
        Queue::fake();

        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        $destination = Destination::factory()->for($proxy)->createQuietly();
        $event = WebhookEvent::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $proxy->team_id]);
        $dispatch = FifoDispatch::factory()->createQuietly([
            'proxy_id' => $proxy->id,
            'team_id' => $proxy->team_id,
            'webhook_event_id' => $event->id,
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);
        // Every delivery of the dispatch is already terminal — the direct
        // settle branch (not the hold branch) is the one under test.
        Delivery::factory()->createQuietly([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'destination_id' => $destination->id,
            'webhook_event_id' => $event->id,
            'dispatch_uuid' => $dispatch->dispatch_uuid,
            'status' => DeliveryStatus::Succeeded,
        ]);

        // Simulate the row having ALREADY been reaped and re-claimed by another
        // advancer while this advancer was still working — its in-memory
        // `$dispatch` object still reads `claimed`, but the real row has moved on.
        FifoDispatch::query()->whereKey($dispatch->id)->update([
            'status' => FifoDispatchStatus::Pending,
            'claimed_at' => null,
            'lease_expires_at' => null,
        ]);

        $this->invokeSettleOrHold(new AdvanceProxyFifoQueue, $dispatch, $proxy->id);

        $this->assertSame(
            FifoDispatchStatus::Pending,
            $dispatch->fresh()->status,
            'A stale advancer must not flip a row it no longer holds.',
        );
        AdvanceProxyFifoQueue::assertNotPushed();
    }
}
