<?php

namespace Tests\Feature\Ingest;

use App\Actions\AdvanceProxyFifoQueue;
use App\Actions\SweepStalledFifoDispatches;
use App\Enums\FifoDispatchStatus;
use App\Enums\ProcessingMode;
use App\Models\DeliveryAttempt;
use App\Models\Destination;
use App\Models\FifoDispatch;
use App\Models\Proxy;
use App\Models\WebhookEvent;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrainsQueuedDeliveries;
use Tests\TestCase;

/**
 * End-to-end proof of the FIFO claim's correctness under contention and the
 * sweeper's liveness net (ADR-005 (a)/(b)), complementing the
 * AdvanceProxyFifoQueue / SweepStalledFifoDispatches unit tests.
 *
 * Fixtures create their pending rows in the same order as their events, so the
 * advancer's `id`-ordered scan (T16, ADR-016 Decision 3) settles them
 * identically to the pre-#6 `webhook_event_id`-ordered scan — no fixture
 * change needed here; the order-key change itself is unit-tested in
 * `AdvanceProxyFifoQueueTest`.
 */
class FifoLivenessTest extends TestCase
{
    use DrainsQueuedDeliveries;

    /**
     * A FIFO proxy with one destination and `$count` pending rows ordered evt-1..N.
     *
     * @return array{0: Proxy, 1: Collection<int, FifoDispatch>}
     */
    private function fifoProxyWithPending(int $count): array
    {
        $proxy = Proxy::factory()->createQuietly(['processing_mode' => ProcessingMode::Fifo]);
        Destination::factory()->for($proxy)->createQuietly(['url' => 'https://fifo.test/hook']);

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

    public function test_a_second_advancer_respects_an_existing_live_claim(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(2);

        // Advancer #1 already holds a live claim on the first row (in flight).
        $dispatches[0]->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(90),
        ]);

        // Advancer #2 runs concurrently: it must NOT claim the second row.
        AdvanceProxyFifoQueue::run($proxy->id);

        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        $this->assertSame(1, FifoDispatch::where('proxy_id', $proxy->id)->where('status', FifoDispatchStatus::Claimed)->count());
        Http::assertNothingSent();
    }

    public function test_no_two_rows_are_ever_claimed_simultaneously_under_contention(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(2);

        // Advancer #1 claims row 1 and dispatches its delivery by reference
        // (ADR-020 Decision 1) — the row holds (`awaiting_retry`) rather than
        // settling immediately, since delivery has not run yet.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->assertSame(FifoDispatchStatus::AwaitingRetry, $dispatches[0]->fresh()->status);

        // While row 1's delivery is still in flight (not yet drained), a
        // concurrent advancer #2 fires for the same proxy. The held row keeps
        // the busy gate shut — #2 must not claim row 2.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->assertSame(
            FifoDispatchStatus::Pending,
            $dispatches[1]->fresh()->status,
            'A concurrent advancer must not claim the next row while one is in flight.',
        );

        // Row 1's delivery settles (drained in place, standing in for the real
        // worker); the line advances and row 2 remains untouched until its turn.
        $this->drainQueuedDeliveries();
        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[1]->fresh()->status);
        $this->assertSame(1, DeliveryAttempt::count());
    }

    public function test_two_runs_on_a_single_pending_row_deliver_it_exactly_once(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(1);

        AdvanceProxyFifoQueue::run($proxy->id);
        // A redundant concurrent advancer finds the row already held and no-ops.
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
        $this->assertSame(1, DeliveryAttempt::count());
        Http::assertSentCount(1);
    }

    public function test_an_orphaned_claim_is_reaped_and_the_line_advances(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(1);

        // A worker crashed mid-event: the row is claimed with an EXPIRED lease.
        $dispatches[0]->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinute(),
        ]);

        // The sweeper reaps the orphaned claim and nudges the idle proxy.
        SweepStalledFifoDispatches::run();

        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[0]->fresh()->status);
        AdvanceProxyFifoQueue::assertPushed(fn ($job, array $params) => $params[0] === $proxy->id);

        // The nudged advancer settles the reaped row (line advances again).
        AdvanceProxyFifoQueue::run($proxy->id);
        $this->drainQueuedDeliveries();

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
        $this->assertSame(1, DeliveryAttempt::count());
    }

    /**
     * Review-04 finding #1 (Major): under an ungraceful worker crash (SIGKILL/OOM)
     * the advancer's `WithoutOverlapping` lock leaks with no TTL, and the sweeper's
     * re-dispatched advancer can never reacquire it — the FIFO line deadlocks. The
     * fix gives the lock an explicit TTL equal to the FIFO lease so the leaked lock
     * self-heals within the same window the sweeper waits on.
     *
     * This drives the real sync queue (no `Queue::fake`) so the sweeper's dispatched
     * advancer actually runs through the production `WithoutOverlapping` middleware.
     */
    public function test_a_leaked_overlap_lock_self_heals_within_the_lease_and_the_line_advances(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        [$proxy, $dispatches] = $this->fifoProxyWithPending(1);

        // A worker was SIGKILLed mid-event: its DB claim is stranded (expired lease)
        // AND its per-proxy overlap lock leaked (still held; TTL not yet elapsed).
        $dispatches[0]->update([
            'status' => FifoDispatchStatus::Claimed,
            'claimed_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinute(),
        ]);

        // Acquire the leaked lock via the *exact* production key + TTL the advancer's
        // middleware uses, so the re-dispatched advancer contends against it for real.
        /** @var WithoutOverlapping $overlap */
        $overlap = (new AdvanceProxyFifoQueue)->getJobMiddleware($proxy->id)[0];

        // The fix: the overlap lock carries an explicit TTL equal to the FIFO lease
        // (without it the lock never expires and the deadlock below is permanent).
        $this->assertSame((int) config('ingest.fifo_lease_seconds'), $overlap->expiresAfter);

        $lockKey = $overlap->getLockKey(AdvanceProxyFifoQueue::makeJob($proxy->id));
        $leaked = Cache::lock($lockKey, (int) config('ingest.fifo_lease_seconds'));
        $this->assertTrue($leaked->get(), 'Precondition: the leaked lock is held.');

        // Sweep #1: reaps the stranded claim to `pending` and dispatches the advancer,
        // which runs through the middleware and is BLOCKED by the leaked lock. Without
        // the TTL fix this is the permanent deadlock — the line cannot advance.
        SweepStalledFifoDispatches::run();

        $this->assertSame(FifoDispatchStatus::Pending, $dispatches[0]->fresh()->status);
        $this->assertSame(0, DeliveryAttempt::count(), 'The leaked lock blocks the line.');

        // The lock's TTL elapses and it self-heals — no manual key clear in production.
        $leaked->forceRelease();

        // Sweep #2: dispatches the advancer again; now it acquires the (expired) lock,
        // claims the reaped row, delivers, and settles it — the FIFO line advances.
        SweepStalledFifoDispatches::run();

        $this->assertSame(FifoDispatchStatus::Settled, $dispatches[0]->fresh()->status);
        $this->assertSame(1, DeliveryAttempt::count());
        Http::assertSentCount(1);
    }
}
